// Builds every Volta_Analytics_New DB tab beyond Daily Mail / Sales Analyze: Logistics Daily, Marketing/Leads,
// Operations, Customers Analyze, Collections Analyze and Portfolio Analyze. Each group is its own IIFE below
// (self-contained: reads its own *_*.tsv extracts, writes its own <prefix>_data.json, and injects its own
// `const <PREFIX>_JSON = ...;` + CSS/nav/page/render blocks into the dashboard HTML, env DASH_HTML overridable,
// default deals_amount_migration.html), run in this order: Logistics, Marketing, Operations, Customers,
// Collections, Portfolio — matching the nav group order. `node build_logistics.js` builds all six in one pass;
// `bash pull_new.sh` extracts all six groups' source data in one pass too.
// and injects it into deals_amount_migration.html as `const LOGI_JSON = …;`. On the first run it also adds the
// "Logistics" nav group, the page markup, the frozen-spreadsheet CSS and the render code — all ported from the
// original Volta_Analytics tab (same table shapes and colors). Idempotent.
const fs = require('fs');
const path = require('path');
const CUTOVER = '2026-08-31';

function parseTsv(file) {
  let c = fs.readFileSync(path.join(__dirname, file), 'utf8');
  if (c.charCodeAt(0) === 0xFEFF) c = c.slice(1);
  const lines = c.split(/\r?\n/).filter(l => l.length);
  if (!lines.length) return [];
  const header = lines[0].split('\t');
  return lines.slice(1).map(line => { const cols = line.split('\t'); const o = {}; header.forEach((h, i) => o[h] = cols[i]); return o; });
}
const num = v => (v === '' || v === 'NULL' || v === undefined) ? null : +v;

// ---- mapping sheet classifier (categoryEn level) + new-DB category chains, same as build_sales.js
const mappingPath = fs.existsSync(path.join(__dirname, 'product_mapping.json')) ? path.join(__dirname, 'product_mapping.json') : path.join(__dirname, '..', 'src', 'product_mapping.json');
const MAP = {};
for (const [k, v] of Object.entries(JSON.parse(fs.readFileSync(mappingPath, 'utf8')))) MAP[k.trim()] = v;
function lookup(raw) {
  const name = String(raw || '').trim();
  if (name === '' || name === 'none') return null;
  if (MAP[name]) return MAP[name];
  for (const part of name.split(',').map(s => s.trim()).filter(Boolean).reverse()) if (MAP[part]) return MAP[part];
  return null;
}
const cats = {};
for (const r of parseTsv('new_categories.tsv')) cats[r.id] = { parent: r.parent_id, name: (r.name || '').trim() };
function depth(id) { let d = 0, c = cats[id]; while (c && c.parent && cats[c.parent]) { d++; c = cats[c.parent]; } return d; }
const productCats = {};
for (const r of parseTsv('new_product_categories.tsv')) (productCats[r.product_id] ||= []).push(r.category_id);
function rawCategory(pid) {
  const ids = (productCats[pid] || []).slice().sort((a, b) => depth(a) - depth(b));
  return ids.map(id => cats[id] && cats[id].name).filter(n => n && n !== 'none').join(',');
}
const goodsType = pid => { const m = lookup(rawCategory(pid)); const s = m && String(m.categoryEn || '').trim(); return (s && s.toLowerCase() !== 'none') ? s : 'Uncategorized'; };

// ---- series
const pendingRows = parseTsv('logi_pending.tsv');
const pending = { dates: pendingRows.map(r => r.d), upTo1: pendingRows.map(r => num(r.upTo1)), oneTo5: pendingRows.map(r => num(r.oneTo5)), over5: pendingRows.map(r => num(r.over5)) };
const deliveryRows = parseTsv('logi_delivery.tsv');
const delivery = {
  dates: deliveryRows.map(r => r.d), upTo1: deliveryRows.map(r => num(r.upTo1)), oneTo5: deliveryRows.map(r => num(r.oneTo5)), over5: deliveryRows.map(r => num(r.over5)),
  onHold: deliveryRows.map(() => null), delivered: deliveryRows.map(r => num(r.delivered)), avgDeliveryTime: deliveryRows.map(r => num(r.avgDays)),
};

// ---- Orders by City / Goods Type, by day (same population + entered/delivered rules as the Delivery Status table)
const DASH = String.fromCharCode(8212);
const cityOrder = ['Tbilisi', 'Other Cities', 'Without City'];
const dayEnd = d => d + ' 23:59:59.999';
const orders = parseTsv('logi_orders.tsv').map(r => ({ entered: r.entered, delivered: (r.delivered_at === '' || r.delivered_at === 'NULL') ? null : r.delivered_at, city: r.city_grp, goods: goodsType(r.top_product_id) }));
const seriesDates = delivery.dates;
function seriesFor(keyOf, labels, title, headLabel, sortByToday) {
  const groups = new Map();
  for (const l of labels || []) groups.set(l, { nd: seriesDates.map(() => 0), all: seriesDates.map(() => 0) });
  for (const o of orders) {
    const k = keyOf(o);
    if (!groups.has(k)) groups.set(k, { nd: seriesDates.map(() => 0), all: seriesDates.map(() => 0) });
    const g = groups.get(k);
    seriesDates.forEach((d, i) => {
      if (o.entered > dayEnd(d)) return;
      g.all[i]++;
      if (o.delivered === null || o.delivered > dayEnd(d)) g.nd[i]++;
    });
  }
  // summary: last day's not-delivered count + share, and orders that entered the module in the last day's calendar month + share
  const lastDay = seriesDates.at(-1), month = lastDay.slice(0, 7);
  for (const o of orders) { if (o.entered.slice(0, 7) === month) groups.get(keyOf(o)).month = (groups.get(keyOf(o)).month || 0) + 1; }
  let rows = [...groups.entries()].map(([label, g]) => ({ label, nd: g.nd, all: g.all, month: g.month || 0 }));
  if (sortByToday) rows.sort((a, b) => (a.label === 'Uncategorized') - (b.label === 'Uncategorized') || b.all.at(-1) - a.all.at(-1));
  const sum = key => seriesDates.map((_, i) => rows.reduce((t, r) => t + r[key][i], 0));
  const ndTot = rows.reduce((t, r) => t + r.nd.at(-1), 0), monthTot = rows.reduce((t, r) => t + r.month, 0);
  return { title, headLabel, dates: seriesDates,
    daily: [
      { label: 'Not Delivered Orders ' + DASH + ' by day', rows: rows.map(r => ({ label: r.label, vals: r.nd })), total: sum('nd') },
      { label: 'ALL Orders in the logistics module ' + DASH + ' by day', rows: rows.map(r => ({ label: r.label, vals: r.all })), total: sum('all') },
    ],
    summary: { lastDay, month,
      rows: rows.map(r => ({ label: r.label, nd: r.nd.at(-1), ndShare: ndTot ? r.nd.at(-1) / ndTot : 0, month: r.month, monthShare: monthTot ? r.month / monthTot : 0 })),
      total: { label: 'Total', nd: ndTot, ndShare: ndTot ? 1 : 0, month: monthTot, monthShare: monthTot ? 1 : 0 } } };
}
const byCity = seriesFor(o => o.city, cityOrder, 'Orders by City', 'City', false);
const byGoods = seriesFor(o => o.goods, null, 'Orders by Goods Type', 'Goods Type', true);

const STATUS_LABEL = { 20: 'დაწყებული / Started', 25: 'მოძიება / Procuring', 30: 'მზადაა მომწოდებელთან / Ready at vendor', 35: 'აღებულია მომწოდებლისგან / Collected', 40: 'საწყობისკენ / To warehouse', 45: 'საწყობშია / Warehouse', 48: 'ნაწილობრივ მზადაა / Partially ready', 50: 'გასაგზავნად მზადაა / Ready to ship', 60: 'გზაშია / Out for delivery', 80: 'მიტანილი / Delivered', 81: 'გატანილი / Picked up' };
// CRM status-by-day tables (labels = the names the CRM's Logistics page uses); status of an entity on day D = last history event up to end of D
const STATUS_SECTIONS = {
  orders: { entity: '4', title: 'Orders by logistics status', codes: [[20, 'Started'], [25, 'Procuring'], [30, 'Ready at vendor'], [35, 'Collecting'], [40, 'Collected'], [45, 'At warehouse'], [48, 'Partially ready (mixed lines)'], [50, 'Ready to ship'], [60, 'Out for delivery'], [80, 'Delivered'], [81, 'Picked up']] },
  lines: { entity: '1', title: 'Order lines by fulfillment status', codes: [[20, 'Awaiting procurement'], [25, 'Ordered from vendor'], [30, 'Ready at vendor'], [35, 'Collection scheduled'], [40, 'Collected'], [45, 'At warehouse'], [50, 'Ready for dispatch'], [60, 'Out for delivery'], [80, 'Delivered'], [81, 'Picked up']] },
  collections: { entity: '2', title: 'Vendor collections by status', codes: [[35, 'Scheduled'], [40, 'Collected (on the way)'], [45, 'Received at warehouse']] },
};
const statusRaw = parseTsv('logi_status.tsv');
const statusDates = [...new Set(statusRaw.map(r => r.d))].sort();
const statusByDay = { dates: statusDates };
for (const [key, sec] of Object.entries(STATUS_SECTIONS)) {
  const rows = statusRaw.filter(r => r.entity_type === sec.entity);
  const extra = [...new Set(rows.map(r => +r.status))].filter(c => !sec.codes.some(([code]) => code === c)).sort((a, b) => a - b).map(c => [c, 'Status ' + c]);
  const out = [];
  for (const [code, label] of [...sec.codes, ...extra]) {
    const vals = statusDates.map(d => { const r = rows.find(x => x.d === d && +x.status === code); return r ? +r.n : 0; });
    if (vals.some(v => v > 0)) out.push({ code, label, vals });
  }
  statusByDay[key] = { title: sec.title, rows: out, total: statusDates.map((_, i) => out.reduce((t, r) => t + r.vals[i], 0)) };
}
// memo row for the orders table: module orders not yet activated (status 11) at the end of each day
{ const m = {}; for (const r of parseTsv('logi_not_activated.tsv')) m[r.d] = +r.n; statusByDay.notActivated = statusDates.map(d => m[d] || 0); }
const openCases = parseTsv('logi_open.tsv').map(r => ({
  customer: r.customer, waitingFrom: r.waiting_from,
  status: (r.logistics_status === '' || r.logistics_status === 'NULL') ? 'ლოგისტიკა არ დაწყებულა / Not started' : (STATUS_LABEL[r.logistics_status] || ('სტატუსი ' + r.logistics_status)),
  city: r.city || '–', orderNum: +r.order_id,
}));

const payload = { pending, delivery, byCity, byGoods, openCases, statusByDay, cutover: CUTOVER, generatedAt: new Date().toISOString().slice(0, 16).replace('T', ' ') + ' UTC' };
fs.writeFileSync(path.join(__dirname, 'logistics_data.json'), JSON.stringify(payload));
console.log('pending days:', pending.dates.length, '| delivery days:', delivery.dates.length, '| last pending:', pending.upTo1.at(-1), pending.oneTo5.at(-1), pending.over5.at(-1), '| city ND/all today:', byCity.daily[0].total.at(-1), byCity.daily[1].total.at(-1), '| goods rows:', byGoods.daily[0].rows.length, '| open cases:', openCases.length, '| status days:', statusDates.length, 'orders/lines/collections rows:', statusByDay.orders.rows.length, statusByDay.lines.rows.length, statusByDay.collections.rows.length);

// ---------------- inject into the HTML ----------------
const htmlPath = process.env.DASH_HTML ? path.resolve(process.env.DASH_HTML) : path.join(__dirname, 'deals_amount_migration.html');
let html = fs.readFileSync(htmlPath, 'utf8');
const __hadCRLF = html.includes('\r\n'); if (__hadCRLF) html = html.replace(/\r\n/g, '\n'); // tolerate a git-checked-out CRLF file (Windows); every marker below assumes plain LF
const must = (n, what) => { if (!html.includes(n)) throw new Error('anchor not found: ' + what); };

const CSS_MARK = '/* --- logistics daily (ported) --- */';
const CSS_END = '/* --- /logistics daily --- */';
// Volta brand colours (logo): lime #c2ff00 on navy #1a1a34. Every rule sets background AND colour together — the
// dashboard renders in light and dark themes, and these tables use a fixed palette rather than the theme tokens.
const css = `
${CSS_MARK}
table.logi-table { border-collapse: collapse; font-size: 11.5px; table-layout: auto; }
table.logi-table td { padding: 5px 8px; border: 1px solid #d4d4de; text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; color: #1a1a34; background: #fff; }
table.logi-table td:first-child { text-align: left; font-variant-numeric: normal; position: sticky; left: 0; z-index: 2; box-shadow: 1px 0 0 #d4d4de; }
table.logi-table tr.logi-title td { background: #1a1a34; color: #c2ff00; font-weight: 700; text-align: left; border-color: #1a1a34; }
table.logi-table tr.logi-title td:first-child { z-index: 3; }
table.logi-table tr.logi-head td { background: #2b2b4f; color: #fff; font-weight: 700; border-color: #2b2b4f; }
table.logi-table tr.logi-total td { background: #c2ff00; color: #1a1a34; font-weight: 700; }
table.logi-table tr.logi-light td { background: #f5ffd6; color: #1a1a34; }
table.logi-table tr.logi-white td { background: #fff; color: #1a1a34; }
table.logi-table tr.logi-plain td { background: #fff; color: #1a1a34; font-style: italic; }
table.logi-table tr.logi-today td:first-child { font-style: italic; }
table.logi-table tr.logi-sub td { background: #e9e9f1; color: #1a1a34; font-weight: 700; text-align: left; }
table.logi-table td.logi-extra-first { border-left: 3px solid #1a1a34; }
table.logi-table td.logi-extra { font-style: italic; }
.logi-group { border: 2px solid #1a1a34; border-radius: 12px; padding: 10px; display: flex; flex-direction: column; gap: 12px; background: var(--surface-1); margin: 6px 0 14px; }
.logi-group-title { background: #1a1a34; color: #c2ff00; font-weight: 700; font-size: 13px; padding: 7px 12px; border-radius: 8px; }
.logi-group .report-card, .logi-group .table-card { margin: 0; }
.logi-mini-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media (max-width: 760px) { .logi-mini-row { grid-template-columns: 1fr; } }
table.logi-mini { border-collapse: collapse; font-size: 12px; width: 100%; }
table.logi-mini td { padding: 7px 10px; border: none; border-bottom: 1px solid #e3e3ea; text-align: right; font-variant-numeric: tabular-nums; }
table.logi-mini td:first-child { text-align: left; font-variant-numeric: normal; }
table.logi-mini col.logi-mini-name { width: 46%; }
table.logi-mini tr.logi-mini-title td { background: #1a1a34; color: #c2ff00; font-weight: 700; text-align: left; }
table.logi-mini tr.logi-mini-head td { background: #2b2b4f; color: #fff; font-weight: 700; }
table.logi-mini tr.logi-mini-data td { color: #3a3a55; background: #fff; }
table.logi-mini tr.logi-mini-data td:first-child { color: #1a1a34; }
table.logi-mini tr.logi-mini-data.logi-mini-alt td { background: #f5ffd6; }
table.logi-mini tr.logi-mini-total td { color: #1a1a34; font-weight: 700; background: #c2ff00; border-bottom: none; }
table.logi-mini tr.logi-mini-total td:first-child { color: #1a1a34; }
.logi-open-title { color: var(--text-primary); font-weight: 700; font-size: 13px; margin: 4px 0 -6px; }
table.logi-open { border-collapse: collapse; font-size: 12px; width: 100%; }
table.logi-open td { padding: 7px 10px; border-bottom: 1px solid #e3e3ea; text-align: left; color: #1a1a34; background: #fff; }
table.logi-open tr:nth-child(odd) td { background: #f5ffd6; color: #1a1a34; }
table.logi-open tr.logi-open-head td { background: #1a1a34; color: #c2ff00; font-weight: 700; }
.table-card { background: var(--surface-1); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
${CSS_END}`;
{
  // idempotent: replace a previously injected CSS block (old builds ended it at the .table-card rule, new ones carry CSS_END)
  const NL = String.fromCharCode(10);
  const cs = html.indexOf(NL + CSS_MARK);
  if (cs >= 0) {
    let ce = html.indexOf(NL + CSS_END, cs);
    if (ce >= 0) ce += (NL + CSS_END).length;
    else { const tc = html.indexOf('.table-card {', cs); if (tc < 0) throw new Error('logistics css end not found'); ce = html.indexOf(NL, tc); }
    html = html.slice(0, cs) + html.slice(ce);
  }
  must('.report-scroll-top > div { height: 1px; }', 'css end');
  html = html.replace('.report-scroll-top > div { height: 1px; }', '.report-scroll-top > div { height: 1px; }' + css);
}

if (!html.includes('data-page="logistics"')) {
  const navAnchor = `        <button class="soon">Income/Delinq: Category, Subcategory, Brand, Product (მალე)</button>
      </div>
    </div>`;
  must(navAnchor, 'nav anchor');
  html = html.replace(navAnchor, navAnchor + `
    <div class="nav-group">
      <div class="nav-group-title">Logistics</div>
      <div class="nav-group-items">
        <button data-page="logistics">Logistics Daily</button>
      </div>
    </div>`);
}

// idempotent: a previously injected page block / JS block is removed first, so re-running the build refreshes the
// markup and render code too (not only the LOGI_JSON data line)
const JS_MARK = '/* ---------- Logistics Daily (ported from Volta_Analytics) ---------- */';
// own explicit end marker (like every other group's JS_MARK/JS_END pair) — this used to be found by
// matching the literal text '})();', back when the render function was an immediately-invoked function
// expression; since it became `window.__registerPage([...], function () {...});` (staggered tab
// loading, 2026-09-10) it no longer ends that way, and the generic '})();' pattern started matching
// the next unrelated IIFE in the file instead (the theme toggle's), silently deleting everything
// between them on every rebuild. An explicit marker can't drift out of sync with the render code again.
const JS_MARK_END = '/* ---------- /Logistics Daily (ported from Volta_Analytics) ---------- */';
{
  const NL = String.fromCharCode(10);
  const PAGE_START = NL + '<div class="page" data-page="logistics" id="page-logistics">', PAGE_END = NL + '</div>' + NL + '</div>' + NL;
  const ps = html.indexOf(PAGE_START);
  if (ps >= 0) { const pe = html.indexOf(PAGE_END, ps); if (pe < 0) throw new Error('page-logistics end not found'); html = html.slice(0, ps) + html.slice(pe + PAGE_END.length); }
  const JS_END = NL + JS_MARK_END + NL;
  const js0 = html.indexOf(NL + JS_MARK);
  if (js0 >= 0) { const je = html.indexOf(JS_END, js0); if (je < 0) throw new Error('logistics JS end not found — deals_amount_migration.html needs the one-time JS_MARK_END insert (see 2026-09-10 notes)'); html = html.slice(0, js0) + html.slice(je + JS_END.length); }
}
if (!html.includes('id="page-logistics"')) {
  const page = `
<div class="page" data-page="logistics" id="page-logistics">
<div class="wrap">
  <p class="section-title">Logistics Daily &mdash; PO &amp; Order Fulfillment</p>
  <div class="banner" id="logiBanner"></div>

  <div class="report-card">
    <div class="report-scroll-top" id="logisticsSalesScrollTop"><div></div></div>
    <div class="report-scroll" id="logisticsSalesScrollBody">
      <table class="logi-table" id="logisticsSalesTable"><tbody></tbody></table>
    </div>
  </div>
  <p class="note">Sales &ndash; Pending Status = loan applications submitted from 1 September 2026 on that are still in the "Pending" status (<code>crm_order_status = 4</code>) at the end of each day, by how long they have been open (days since submission). The CRM's own Pending figure is ~360 higher because it also counts applications from December 2025 &ndash; August 2026 migrated from the old CRM that were never closed; those are left out here on purpose. Reconstructed exactly from the CRM's status-change log, so every day since the cutover is real history, not a snapshot; the last column is today as of the last refresh.</p>

  <div class="report-card">
    <div class="report-scroll-top" id="logisticsScrollTop"><div></div></div>
    <div class="report-scroll" id="logisticsScrollBody">
      <table class="logi-table" id="logisticsTable"><tbody></tbody></table>
    </div>
  </div>
  <p class="note">Not Delivered = active orders in the CRM logistics module with no delivered / picked-up event yet at the end of that day (an order enters the series on its sale date, or on the day the module picked it up if that was later); age buckets (&le;1 day / 1&ndash;5 days / &gt;5 days) are measured from the sale date. Delivered = orders whose first delivered or picked-up event (CRM logistics status 80/81) fell on that day; Average Delivery Time = average days from sale to that event. "On Hold" has no equivalent in the new CRM yet and stays blank. All rows are reconstructed from the CRM's shipment-status history.</p>

  <p class="logi-open-title">Open Cases &mdash; Still Waiting for Delivery (today)</p>
  <div class="table-card">
    <table class="logi-open" id="logisticsOpenCasesTable"><tbody></tbody></table>
  </div>
  <p class="note">The 10 oldest active orders (by sale date) with no delivered / picked-up event &mdash; the customers who have been waiting longest. Status = the CRM logistics stage of the order.</p>

  <p class="logi-open-title">CRM Logistics &mdash; Status by Day</p>
  <div class="report-card">
    <div class="report-scroll-top" id="logisticsStatusOrdersScrollTop"><div></div></div>
    <div class="report-scroll" id="logisticsStatusOrdersScrollBody">
      <table class="logi-table" id="logisticsStatusOrdersTable"><tbody></tbody></table>
    </div>
  </div>
  <div class="report-card">
    <div class="report-scroll-top" id="logisticsStatusLinesScrollTop"><div></div></div>
    <div class="report-scroll" id="logisticsStatusLinesScrollBody">
      <table class="logi-table" id="logisticsStatusLinesTable"><tbody></tbody></table>
    </div>
  </div>
  <div class="report-card">
    <div class="report-scroll-top" id="logisticsStatusCollScrollTop"><div></div></div>
    <div class="report-scroll" id="logisticsStatusCollScrollBody">
      <table class="logi-table" id="logisticsStatusCollTable"><tbody></tbody></table>
    </div>
  </div>
  <p class="note">The same stages the CRM's Logistics page shows ("Lines by status" and the order / collection stages), as they stood at the end of each day. Orders = one row per order in the logistics module (<code>crm_order_logistics</code>); Order lines = each product line's own fulfillment stage (<code>crm_line_fulfillment</code>, what the CRM's "Lines by status" counts); Vendor collections = pickup runs from vendors (<code>crm_vendor_collections</code>). "Partially ready" = an order whose lines are at different stages. Reconstructed from the CRM's shipment-status history. Same population as every table on this tab (orders in the logistics module in the CRM statuses Signed or Active), so the Orders total equals ALL Orders in the city / goods tables and Delivered + Picked up equals ALL Orders minus Not Delivered. "Signed, not yet Active" = the CRM's Signed status: the contract is signed and logistics has started, the deal moves to Active a few hours later (on average 4 hours, at most a few days); it is a memo line inside the Total, not an addition to it. Lines outnumber orders because an order with several products has one line per product.</p>

  <div class="logi-group">
    <div class="logi-group-title">Orders by City</div>
    <div class="report-card">
      <div class="report-scroll-top" id="logisticsCityNdScrollTop"><div></div></div>
      <div class="report-scroll" id="logisticsCityNdScrollBody"><table class="logi-table" id="logisticsCityNdTable"><tbody></tbody></table></div>
    </div>
    <div class="report-card">
      <div class="report-scroll-top" id="logisticsCityAllScrollTop"><div></div></div>
      <div class="report-scroll" id="logisticsCityAllScrollBody"><table class="logi-table" id="logisticsCityAllTable"><tbody></tbody></table></div>
    </div>
  </div>
  <div class="logi-group">
    <div class="logi-group-title">Orders by Goods Type</div>
    <div class="report-card">
      <div class="report-scroll-top" id="logisticsGoodsNdScrollTop"><div></div></div>
      <div class="report-scroll" id="logisticsGoodsNdScrollBody"><table class="logi-table" id="logisticsGoodsNdTable"><tbody></tbody></table></div>
    </div>
    <div class="report-card">
      <div class="report-scroll-top" id="logisticsGoodsAllScrollTop"><div></div></div>
      <div class="report-scroll" id="logisticsGoodsAllScrollBody"><table class="logi-table" id="logisticsGoodsAllTable"><tbody></tbody></table></div>
    </div>
  </div>
  <p class="note">City = the order's shipping address (Tbilisi / other cities / no city recorded). Goods Type = the product's top-level category from the mapping sheet (the old sheet's Soft / Medium / Heavy weight classes do not exist in the new catalog); each order is counted once, under the goods type of its highest-value product line. Not Delivered = orders in the module with no delivered / picked-up event yet at the end of that day; ALL Orders = every order that had entered the logistics module by the end of that day (delivered ones included). Same rules as the Delivery Status table, so the Not Delivered totals match it day by day. The last columns of each table carry the shares: each row's share of the total on the last day, and (in the ALL Orders table) the orders that entered the module during the last day's calendar month with their share.</p>
</div>
</div>
`;
  const anchor = `</div><!-- /page dailystats -->`;
  must(anchor, 'page anchor');
  // append after the last sales page so the nav order and DOM order agree
  const catBrandEnd = `<table class="rpt rpt-pivot sm-table" id="categoryBrandTable"><tbody></tbody></table>
    </div>
  </div>
</div>
</div>
`;
  must(catBrandEnd, 'categorybrand page end');
  html = html.replace(catBrandEnd, catBrandEnd + page);
}

const dataLine = 'const LOGI_JSON = ' + JSON.stringify(payload) + ';';
if (/^const LOGI_JSON = .*;$/m.test(html)) html = html.replace(/^const LOGI_JSON = .*;$/m, dataLine);
else {
  must('const generatedAt = REPORT_JSON.generatedAt;', 'report consts');
  html = html.replace('const generatedAt = REPORT_JSON.generatedAt;', 'const generatedAt = REPORT_JSON.generatedAt;\n' + dataLine);
}

if (!html.includes(JS_MARK)) {
  const js = `
${JS_MARK}
window.__registerPage(['logistics'], function () {
  const L = LOGI_JSON;
  const dayLabelL = d => { const [, m, day] = d.split('-').map(Number); return MONTH_NAMES[m - 1] + ' ' + day; };
  document.getElementById('logiBanner').innerHTML = '<b>წყარო:</b> ახალი CRM-ის ლოგისტიკის მოდული (VoltaStoreDB: <code>crm_order_logistics</code>, <code>crm_shipment_status_history</code>, სტატუსების ლოგი). ისტორია ' + L.cutover + '-დან ლოგებიდან ზუსტადაა აღდგენილი; ძველი ცხრილების (Google Sheet) წინა ისტორია ძველ დაშბორდზე რჩება. ლოგისტიკის მოდული 2 სექტემბრიდან მუშაობს; ითვლება ყველა შეკვეთა, რომელიც მოდულშია, CRM სტატუსით Signed ან Active; ეს ზუსტად CRM-ის ლოჯისტიკის გვერდის პოპულაციაა.';

  function renderPending() {
    const h = L.pending, n = h.dates.length;
    const cell = v => (v === null || v === undefined) ? '<td>&ndash;</td>' : '<td>' + fmt(v) + '</td>';
    const rowHtml = (cls, label, vals) => '<tr class="' + cls + '"><td>' + label + '</td>' + vals.map(cell).join('') + '</tr>';
    const total = h.dates.map((_, i) => (h.upTo1[i] || 0) + (h.oneTo5[i] || 0) + (h.over5[i] || 0));
    const heads = h.dates.map((d, i) => '<td>' + dayLabelL(d) + (i === n - 1 ? ' (today)' : '') + '</td>').join('');
    let html = '<tr class="logi-title"><td colspan="' + (n + 1) + '">SALES &mdash; Pending Status</td></tr>';
    html += '<tr class="logi-head"><td>Status</td>' + heads + '</tr>';
    html += rowHtml('logi-total', 'Sales &ndash; Pending Status (Pending loan applications)', total);
    html += rowHtml('logi-white', 'Up to 1 day', h.upTo1);
    html += rowHtml('logi-light', '1 to 5 days', h.oneTo5);
    html += rowHtml('logi-white', '&gt;5 days', h.over5);
    document.getElementById('logisticsSalesTable').querySelector('tbody').innerHTML = html;
  }
  function renderDelivery() {
    const h = L.delivery, n = h.dates.length;
    const cell = (v, isAvg) => (v === null || v === undefined) ? '<td>&ndash;</td>' : '<td>' + (isAvg ? Number(v).toFixed(1) : fmt(v)) + '</td>';
    const rowHtml = (cls, label, vals, isAvg) => '<tr class="' + cls + '"><td>' + label + '</td>' + vals.map(v => cell(v, isAvg)).join('') + '</tr>';
    const notDelivered = h.dates.map((_, i) => (h.upTo1[i] || 0) + (h.oneTo5[i] || 0) + (h.over5[i] || 0) + (h.onHold[i] || 0));
    const heads = h.dates.map((d, i) => '<td>' + dayLabelL(d) + (i === n - 1 ? ' (today)' : '') + '</td>').join('');
    let html = '<tr class="logi-title"><td colspan="' + (n + 1) + '">LOGISTICS &mdash; Delivery Status</td></tr>';
    html += '<tr class="logi-head"><td>Status</td>' + heads + '</tr>';
    html += rowHtml('logi-total', 'Logistics &ndash; Number of Not Delivered Orders', notDelivered);
    html += rowHtml('logi-white', 'Up to 1 day', h.upTo1);
    html += rowHtml('logi-light', '1 to 5 days', h.oneTo5);
    html += rowHtml('logi-white', '&gt;5 days', h.over5);
    html += rowHtml('logi-white', 'On Hold', h.onHold);
    html += rowHtml('logi-plain', 'Delivered', h.delivered);
    html += rowHtml('logi-plain', 'Average Delivery Time', h.avgDeliveryTime, true);
    document.getElementById('logisticsTable').querySelector('tbody').innerHTML = html;
  }
  function renderOpen() {
    let html = '<tr class="logi-open-head"><td>Customer</td><td>Waiting from</td><td>Status</td><td>City</td><td>Order #</td></tr>';
    if (!L.openCases.length) html += '<tr><td colspan="5">No open cases.</td></tr>';
    L.openCases.forEach(c => { html += '<tr><td>' + c.customer + '</td><td>' + dayLabelL(c.waitingFrom) + '</td><td>' + c.status + '</td><td>' + c.city + '</td><td>' + c.orderNum + '</td></tr>'; });
    document.getElementById('logisticsOpenCasesTable').querySelector('tbody').innerHTML = html;
  }
  function renderStatus(tableId, sec) {
    const S = L.statusByDay || { dates: [] }, n = S.dates.length;
    if (!sec || !n) { document.getElementById(tableId).querySelector('tbody').innerHTML = '<tr><td>No status history yet.</td></tr>'; return; }
    const rowHtml = (cls, label, vals) => '<tr class="' + cls + '"><td>' + label + '</td>' + vals.map(v => '<td>' + (v ? fmt(v) : '&ndash;') + '</td>').join('') + '</tr>';
    const heads = S.dates.map((d, i) => '<td>' + dayLabelL(d) + (i === n - 1 ? ' (today)' : '') + '</td>').join('');
    let html = '<tr class="logi-title"><td colspan="' + (n + 1) + '">' + sec.title + '</td></tr>';
    html += '<tr class="logi-head"><td>Status</td>' + heads + '</tr>';
    sec.rows.forEach((r, i) => { html += rowHtml(i % 2 ? 'logi-light' : 'logi-white', r.label, r.vals); });
    html += rowHtml('logi-total', 'Total', sec.total);
    if (tableId === 'logisticsStatusOrdersTable' && S.notActivated) html += rowHtml('logi-plain', 'of which Signed, not yet Active (CRM status)', S.notActivated);
    document.getElementById(tableId).querySelector('tbody').innerHTML = html;
  }
  function renderDaily(tableId, data, sec, kind) {
    const S = data.summary, n = data.dates.length, monthLabel = MONTH_NAMES[Number(S.month.slice(5, 7)) - 1] + ' ' + S.month.slice(0, 4);
    const monthOf = {}; S.rows.forEach(r => { monthOf[r.label] = r; });
    const lastTot = sec.total[n - 1] || 0;
    const extraHead = kind === 'nd' ? ['Share ' + dayLabelL(S.lastDay)] : ['Share ' + dayLabelL(S.lastDay), monthLabel + ' (month)', 'Share ' + monthLabel];
    const extras = (vals, m) => kind === 'nd' ? [pct(lastTot ? vals[n - 1] / lastTot : 0)] : [pct(lastTot ? vals[n - 1] / lastTot : 0), fmt(m.month || 0), pct(m.monthShare || 0)];
    const cell = v => '<td>' + (v ? fmt(v) : '&ndash;') + '</td>';
    const exCell = (v, i) => '<td class="logi-extra' + (i === 0 ? ' logi-extra-first' : '') + '">' + v + '</td>';
    const rowHtml = (cls, label, vals, ex) => '<tr class="' + cls + '"><td>' + label + '</td>' + vals.map(cell).join('') + ex.map(exCell).join('') + '</tr>';
    const heads = data.dates.map((d, i) => '<td>' + dayLabelL(d) + (i === n - 1 ? ' (today)' : '') + '</td>').join('') + extraHead.map(exCell).join('');
    let html = '<tr class="logi-title"><td colspan="' + (n + 1 + extraHead.length) + '">' + sec.label + '</td></tr>';
    html += '<tr class="logi-head"><td>' + data.headLabel + '</td>' + heads + '</tr>';
    sec.rows.forEach((r, i) => { html += rowHtml(i % 2 ? 'logi-light' : 'logi-white', r.label, r.vals, extras(r.vals, monthOf[r.label] || {})); });
    html += rowHtml('logi-total', 'Total', sec.total, extras(sec.total, S.total));
    document.getElementById(tableId).querySelector('tbody').innerHTML = html;
  }
  function renderGroup(prefix, data) {
    renderDaily(prefix + 'NdTable', data, data.daily[0], 'nd');
    renderDaily(prefix + 'AllTable', data, data.daily[1], 'all');
  }
  renderPending(); renderDelivery(); renderGroup('logisticsCity', L.byCity); renderGroup('logisticsGoods', L.byGoods); renderOpen();
  renderStatus('logisticsStatusOrdersTable', (L.statusByDay || {}).orders); renderStatus('logisticsStatusLinesTable', (L.statusByDay || {}).lines); renderStatus('logisticsStatusCollTable', (L.statusByDay || {}).collections);
  window.logisticsScrollUpdaters = [setupTopScrollSync('logisticsSalesScrollTop', 'logisticsSalesScrollBody'), setupTopScrollSync('logisticsScrollTop', 'logisticsScrollBody'),
    setupTopScrollSync('logisticsCityNdScrollTop', 'logisticsCityNdScrollBody'), setupTopScrollSync('logisticsCityAllScrollTop', 'logisticsCityAllScrollBody'),
    setupTopScrollSync('logisticsGoodsNdScrollTop', 'logisticsGoodsNdScrollBody'), setupTopScrollSync('logisticsGoodsAllScrollTop', 'logisticsGoodsAllScrollBody'),
    setupTopScrollSync('logisticsStatusOrdersScrollTop', 'logisticsStatusOrdersScrollBody'), setupTopScrollSync('logisticsStatusLinesScrollTop', 'logisticsStatusLinesScrollBody'), setupTopScrollSync('logisticsStatusCollScrollTop', 'logisticsStatusCollScrollBody')];
});
${JS_MARK_END}
`;
  must('// ---- top-level page nav (grows as more reports get added) ----', 'nav handler');
  html = html.replace('// ---- top-level page nav (grows as more reports get added) ----', js + '// ---- top-level page nav (grows as more reports get added) ----');
  must("  if (btn.dataset.page === 'subcategoryanalyze') subcategoryScrollUpdate();", 'nav updaters');
  if (!html.includes("btn.dataset.page === 'logistics'")) html = html.replace("  if (btn.dataset.page === 'subcategoryanalyze') subcategoryScrollUpdate();", "  if (btn.dataset.page === 'subcategoryanalyze') subcategoryScrollUpdate();\n  if (btn.dataset.page === 'logistics' && window.logisticsScrollUpdaters) window.logisticsScrollUpdaters.forEach(f => f());");
}

if (__hadCRLF) html = html.replace(/\n/g, '\r\n');
fs.writeFileSync(htmlPath, html);
fs.writeFileSync(path.join(__dirname, 'extracted.js'), html.match(/<script>([\s\S]*)<\/script>/)[1]);
console.log('html bytes:', html.length);

// ================ Marketing — Leads ================
(function () {
// Marketing -> Leads for Volta_Analytics_New DB: turns the mkt_*.tsv extracts (pull_mkt.sh, daily aggregates of
// volta_leads) into mkt_data.json and injects it into the dashboard HTML (env DASH_HTML, default
// deals_amount_migration.html) as `const MKT_JSON = …;` plus, on every run, the "Marketing" nav group, the Leads page
// markup, a tiny CSS block and the render code. Idempotent: each run removes the previously injected blocks first.
// Every table comes in two shapes: by day (MKT_DAY_START .. today) and by month (first lead month .. current month MTD).
const fs = require('fs');
const path = require('path');
const CUTOVER = '2026-08-31';
const DAY_START = '2026-08-01';
const ARROW = String.fromCharCode(8594);

function parseTsv(file) {
  let c = fs.readFileSync(path.join(__dirname, file), 'utf8');
  if (c.charCodeAt(0) === 0xFEFF) c = c.slice(1);
  const lines = c.split(/\r?\n/).filter(l => l.length);
  if (!lines.length) return [];
  const header = lines[0].split('\t');
  return lines.slice(1).map(line => { const cols = line.split('\t'); const o = {}; header.forEach((h, i) => o[h] = cols[i]); return o; });
}
const num = v => (v === '' || v === 'NULL' || v === undefined) ? 0 : +v;

// ---- calendar: the conversion extract is calendar-driven (one row per day from the first lead day through END)
const conv = parseTsv('mkt_conv.tsv');
if (!conv.length) throw new Error('mkt_conv.tsv is empty');
const allDays = conv.map(r => r.d);
const leadsStart = allDays[0], today = allDays.at(-1);
const days = allDays.filter(d => d >= DAY_START);
const months = [...new Set(allDays.map(d => d.slice(0, 7)))].sort();
const mtd = months.at(-1);

// ---- generic day/month series builder: recs = [{d, key, n}]
function seriesOf(recs, keys, labelOf) {
  const byKey = {};
  for (const r of recs) { (byKey[r.key] ||= {}); byKey[r.key][r.d] = (byKey[r.key][r.d] || 0) + r.n; }
  const extra = Object.keys(byKey).filter(k => !keys.includes(k)).sort();
  const order = [...keys, ...extra];
  const dayVals = k => days.map(d => (byKey[k] && byKey[k][d]) || 0);
  const monthVals = k => months.map(m => allDays.filter(d => d.slice(0, 7) === m).reduce((t, d) => t + ((byKey[k] && byKey[k][d]) || 0), 0));
  return { order, dayRows: order.map(k => ({ key: k, label: labelOf(k), vals: dayVals(k) })), monthRows: order.map(k => ({ key: k, label: labelOf(k), vals: monthVals(k) })) };
}
const sumRows = (rows, n) => Array.from({ length: n }, (_, i) => rows.reduce((t, r) => t + r.vals[i], 0));
const last7 = vals => vals.slice(-7).reduce((a, b) => a + b, 0);
const total = vals => vals.reduce((a, b) => a + b, 0);
const sh = (a, b) => b ? a / b : 0;
// trailing share columns: day tables -> share of the last day, last-7-days count, share of the last 7 days;
// month tables -> share of the MTD month, all-time total, share of the all-time total
function withExtras(rows, tot, isDay) {
  const mk = vals => isDay ? [{ pct: sh(vals.at(-1), tot.at(-1)) }, { n: last7(vals) }, { pct: sh(last7(vals), last7(tot)) }]
                           : [{ pct: sh(vals.at(-1), tot.at(-1)) }, { n: total(vals) }, { pct: sh(total(vals), total(tot)) }];
  return { rows: rows.map(r => ({ ...r, ex: mk(r.vals) })), total: { vals: tot, ex: mk(tot) } };
}
const dayExtraHeads = ['Share {last}', 'Last 7 days', 'Share 7 days'];
const monthExtraHeads = ['Share {last}', 'Total', 'Share total'];

// ---- (a) leads by current status. Row order = the CRM's crm_marketing_statuses (id order); every status is shown even at zero.
const statusCat = parseTsv('mkt_statuses.tsv').map(r => ({ key: r.name.toLowerCase(), label: r.name }));
const statusRaw = parseTsv('mkt_status.tsv');
const statusLabel = k => { const c = statusCat.find(s => s.key === k); return c ? c.label : ('Status "' + k + '"'); };
const st = seriesOf(statusRaw.map(r => ({ d: r.d, key: r.status, n: num(r.n) })), statusCat.map(s => s.key), statusLabel);
const memoDef = [['assigned', 'of which assigned to a sales manager'], ['repeat_', 'of which repeat submissions (phone already sent an earlier lead)']];
const memo = memoDef.map(([col, label]) => { const s = seriesOf(statusRaw.map(r => ({ d: r.d, key: 'x', n: num(r[col]) })), ['x'], () => label); return { label, day: s.dayRows[0].vals, month: s.monthRows[0].vals }; });
function statusPack(rows, isDay) {
  const tot = sumRows(rows, isDay ? days.length : months.length);
  const p = withExtras(rows, tot, isDay);
  p.memos = memo.map(m => { const vals = isDay ? m.day : m.month; return { label: m.label, vals, ex: isDay ? [{ pct: sh(vals.at(-1), tot.at(-1)) }, { n: last7(vals) }, { pct: sh(last7(vals), last7(tot)) }] : [{ pct: sh(vals.at(-1), tot.at(-1)) }, { n: total(vals) }, { pct: sh(total(vals), total(tot)) }] }; });
  return p;
}
const status = { title: 'New leads by status (current status of the lead)', headLabel: 'Status', day: statusPack(st.dayRows, true), month: statusPack(st.monthRows, false) };

// ---- (b) leads by the last form step reached
const stepLabel = k => ({ 1: 'Step 1 (contact details)', 2: 'Step 2', 3: 'Step 3 (form completed)' })[k] || ('Step ' + k);
const sp = seriesOf(parseTsv('mkt_step.tsv').map(r => ({ d: r.d, key: r.step, n: num(r.n) })), ['1', '2', '3'], stepLabel);
const step = { title: 'New leads by form step reached', headLabel: 'Last step', day: withExtras(sp.dayRows, sumRows(sp.dayRows, days.length), true), month: withExtras(sp.monthRows, sumRows(sp.monthRows, months.length), false) };

// ---- (c) leads by city group
const cityOrder = ['Tbilisi', 'Batumi', 'Kutaisi', 'Rustavi', 'Gori', 'Zugdidi', 'Other Cities', 'Without City'];
const ct = seriesOf(parseTsv('mkt_city.tsv').map(r => ({ d: r.d, key: r.city_grp, n: num(r.n) })), cityOrder, k => k);
const city = { title: 'New leads by city', headLabel: 'City', day: withExtras(ct.dayRows, sumRows(ct.dayRows, days.length), true), month: withExtras(ct.monthRows, sumRows(ct.monthRows, months.length), false) };

// ---- (d) lead -> application conversion (counts + ratio rows)
const convCols = [['leads', 'Leads created'], ['matched', 'Phone matched to an order or customer (any date)'], ['before_', 'of which already a customer (order before the lead)'],
  ['apps', 'Applications after the lead (any time)'], ['apps30', 'Applications within 30 days of the lead'], ['deals', 'Loans issued after the lead']];
const convSeries = {};
for (const [col, label] of convCols) { const s = seriesOf(conv.map(r => ({ d: r.d, key: 'x', n: num(r[col]) })), ['x'], () => label); convSeries[col] = { label, day: s.dayRows[0].vals, month: s.monthRows[0].vals }; }
function convPack(isDay) {
  const v = col => isDay ? convSeries[col].day : convSeries[col].month;
  const leads = v('leads');
  const agg = isDay ? last7 : total;
  const nRow = col => ({ label: convSeries[col].label, kind: 'n', vals: v(col), ex: [{ n: agg(v(col)) }] });
  const pRow = (label, col) => ({ label, kind: 'pct', vals: leads.map((l, i) => sh(v(col)[i], l)), ex: [{ pct: sh(agg(v(col)), agg(leads)) }] });
  return { rows: [nRow('leads'), nRow('matched'), nRow('before_'), nRow('apps'), pRow('Lead ' + ARROW + ' application rate (any time)', 'apps'),
    nRow('apps30'), pRow('Lead ' + ARROW + ' application rate (30 days)', 'apps30'), nRow('deals'), pRow('Lead ' + ARROW + ' loan rate', 'deals')],
    extraHeads: isDay ? ['Last 7 days'] : ['Total'] };
}
const convT = { title: 'Lead ' + ARROW + ' application conversion (by lead creation date)', headLabel: 'Metric', day: convPack(true), month: convPack(false) };

const payload = { days, months, mtd, today, dayStart: DAY_START, leadsStart, cutover: CUTOVER, dayExtraHeads, monthExtraHeads, status, step, city, conv: convT,
  generatedAt: new Date().toISOString().slice(0, 16).replace('T', ' ') + ' UTC' };
fs.writeFileSync(path.join(__dirname, 'mkt_data.json'), JSON.stringify(payload));
console.log('days:', days.length, days[0], '..', today, '| months:', months.length, months[0], '..', mtd, '| leads total:', total(convSeries.leads.month), '| status rows:', status.day.rows.length, '| today leads:', convSeries.leads.day.at(-1), '| apps total:', total(convSeries.apps.month), '| deals total:', total(convSeries.deals.month));

// ---------------- inject into the HTML ----------------
const htmlPath = process.env.DASH_HTML ? path.resolve(process.env.DASH_HTML) : path.join(__dirname, 'deals_amount_migration.html');
let html = fs.readFileSync(htmlPath, 'utf8');
const __hadCRLF = html.includes('\r\n'); if (__hadCRLF) html = html.replace(/\r\n/g, '\n'); // tolerate a git-checked-out CRLF file (Windows); every marker below assumes plain LF
const NL = String.fromCharCode(10);
const must = (n, what) => { if (!html.includes(n)) throw new Error('anchor not found: ' + what); };
function removeBetween(startMark, endMark, what) {
  const s = html.indexOf(startMark);
  if (s < 0) return;
  const e = html.indexOf(endMark, s);
  if (e < 0) throw new Error(what + ' end marker not found');
  html = html.slice(0, s) + html.slice(e + endMark.length);
}

// CSS (only what the shared logi-* classes lack: a tinted italic row for ratio lines; background AND colour set together)
const CSS_MARK = '/* --- mkt --- */', CSS_END = '/* --- /mkt --- */';
removeBetween(NL + CSS_MARK, NL + CSS_END, 'mkt css');
{
  const css = NL + CSS_MARK + NL + 'table.logi-table tr.mkt-pct td { background: #e9e9f1; color: #1a1a34; font-style: italic; }' + NL + CSS_END;
  const a = '.report-scroll-top > div { height: 1px; }';
  must(a, 'css anchor');
  html = html.replace(a, a + css);
}

// nav group (static: inserted once)
if (!html.includes('data-page="leads"')) {
  const navEnd = '  </div>' + NL + '</div>' + NL + NL + '<div class="page active" data-page="report">';
  must(navEnd, 'nav end');
  html = html.replace(navEnd, `    <div class="nav-group">
      <div class="nav-group-title">Marketing</div>
      <div class="nav-group-items">
        <button data-page="leads">Leads</button>
      </div>
    </div>
` + navEnd);
}

// page (re-injected on every run)
const PAGE_START = '<!-- mkt-page-start -->', PAGE_END = '<!-- mkt-page-end -->';
removeBetween(PAGE_START, PAGE_END + NL, 'mkt page');
{
  const card = id => `    <div class="report-card">
      <div class="report-scroll-top" id="${id}ScrollTop"><div></div></div>
      <div class="report-scroll" id="${id}ScrollBody"><table class="logi-table" id="${id}Table"><tbody></tbody></table></div>
    </div>`;
  const group = (title, id) => `  <div class="logi-group">
    <div class="logi-group-title">${title}</div>
${card(id + 'Day')}
${card(id + 'Month')}
  </div>`;
  const page = NL + PAGE_START + `
<div class="page" data-page="leads" id="page-leads">
<div class="wrap">
  <p class="section-title">Marketing &mdash; Leads</p>
  <div class="banner" id="mktBanner"></div>

${group('Leads by status', 'mktStatus')}
  <p class="note"><b>Flow.</b> New leads = rows of <code>volta_leads</code> (the pre-application lead form on the website) counted on the day / month they were created (<code>created_at</code>, UTC day like the rest of the dashboard). Rows = the lead's <b>current</b> status in the CRM (<code>volta_leads.status</code>, the CRM's marketing statuses New / Contacted / Qualified / Won / Lost): the table does not record when a lead changed status &mdash; a lead created in June and contacted in September is counted as Contacted in June. The CRM only started working leads on 2026-08-31 (the first sales-manager assignments), so practically every earlier lead still says New. Memo rows: leads with a sales manager (<code>crm_sales_manager_id</code>), and leads whose phone number had already sent a lead before (repeat submissions are counted as leads in every row). Deleted leads (CRM lead.delete) disappear from the table retroactively. Day tables: share of the last day, plus the last 7 days and their share; month tables: share of the current month, plus the all-time total and its share.</p>

${group('Leads by form step', 'mktStep')}
  <p class="note"><b>Flow.</b> The same leads by the last step of the lead form the visitor reached (<code>last_step</code> 1&ndash;3; step 3 = the form was completed). From 2026-09-02 no lead reaches step 2 or 3 any more: the form was changed with the new CRM and now records one step only, so the step split is only meaningful up to 2026-09-01. Keyed to the lead creation date.</p>

${group('Leads by city', 'mktCity')}
  <p class="note"><b>Flow.</b> The same leads by the city the visitor typed (free text: Georgian and Latin spellings are folded together; the six biggest cities are shown, everything else is Other Cities, empty = Without City). Keyed to the lead creation date.</p>

${group('Lead &rarr; application conversion', 'mktConv')}
  <p class="note"><b>Flow, keyed to the lead creation date.</b> The CRM does not link a lead to the application it turned into (<code>orders.crm_source_lead_id</code> is never filled), so the link is made by <b>phone number</b>: a lead is matched to every application (<code>orders</code>) whose billing-address phone (<code>addresses</code>, <code>order_billing</code>) or customer record (<code>customers.phone</code> / <code>volta_leads.customer_id</code>) carries the same number. Applications after the lead = at least one application created at or after the lead; within 30 days = created within 30 days of the lead (the last 30 days of leads are still open, so their 30-day rate is incomplete); Loans issued = one of those applications became a loan (<code>crm_order_status</code> 5 Active or 99 single payment, or <code>crm_active = 1</code>) &mdash; the lead is counted once whichever way it matched. "Already a customer" = the phone had an application before the lead (an existing customer using the form). Rates are the share of that day's / month's leads. Applications before 2026-08-31 are the rows migrated from the old CRM, whose <code>created_at</code> can be a day off; the phone match itself is exact. A lead whose applicant used another phone number is not matched, so the rates are a floor, not a ceiling. Day tables: last 7 days; month tables: all-time total.</p>
</div>
</div>
` + PAGE_END + NL;
  const li = html.indexOf('id="page-logistics"');
  if (li < 0) throw new Error('anchor not found: page-logistics');
  const si = html.slice(li).search(/<script[\s>]/);
  if (si < 0) throw new Error('anchor not found: script after page-logistics');
  html = html.slice(0, li + si) + page.replace(/^\n/, '') + html.slice(li + si);
}

// data line
const dataLine = 'var MKT_JSON = ' + JSON.stringify(payload) + ';';
if (/^(?:const|var) MKT_JSON = .*;$/m.test(html)) html = html.replace(/^(?:const|var) MKT_JSON = .*;$/m, () => dataLine);
else {
  must('const generatedAt = REPORT_JSON.generatedAt;', 'report consts');
  html = html.replace('const generatedAt = REPORT_JSON.generatedAt;', 'const generatedAt = REPORT_JSON.generatedAt;' + NL + dataLine);
}

// render code (re-injected on every run)
const JS_MARK = '/* ---------- mkt ---------- */', JS_END = '/* ---------- /mkt ---------- */';
removeBetween(JS_MARK, JS_END + NL, 'mkt js');
{
  const js = NL + JS_MARK + `
window.__registerPage(['leads'], function () {
  const M = MKT_JSON;
  const dayLabelM = d => { const [, m, day] = d.split('-').map(Number); return MONTH_NAMES[m - 1] + ' ' + day; };
  const monthLabelM = m => MONTH_NAMES[Number(m.slice(5, 7)) - 1] + ' ' + m.slice(0, 4);
  document.getElementById('mktBanner').innerHTML = '<b>წყარო:</b> ახალი CRM-ის ლიდების ცხრილი (VoltaStoreDB: <code>volta_leads</code> &mdash; საიტის წინასწარი განაცხადის ფორმა), კონვერსიისთვის <code>orders</code>, <code>addresses</code>, <code>customers</code>. ლიდები ' + M.leadsStart + '-დან არსებობს (ფორმის ამოქმედების დღე), ისტორია რეალურია პირველივე დღიდან; სტატუსი ლიდის <b>ამჟამინდელი</b> სტატუსია (სტატუსის ცვლილების ისტორია ბაზაში არ ინახება), ლიდებზე მუშაობა CRM-ში ' + M.cutover + '-დან დაიწყო. დღეების ცხრილები ' + M.dayStart + '-დან დღემდე, თვეების &mdash; ' + monthLabelM(M.months[0]) + '-დან მიმდინარე თვემდე (MTD). <b>Source:</b> the new CRM lead table (<code>volta_leads</code>); statuses are the lead\\'s current status, keyed to the day the lead was created; leads exist from ' + M.leadsStart + ', the CRM started working them on ' + M.cutover + '. Single lead source so far (<code>application_form</code>), so there is no by-source table.';

  const cellN = v => '<td>' + (v ? fmt(v) : '&ndash;') + '</td>';
  const cellP = v => '<td>' + (v ? pct(v) : '&ndash;') + '</td>';
  const exCell = (e, i) => '<td class="logi-extra' + (i === 0 ? ' logi-extra-first' : '') + '">' + (e.pct !== undefined ? (e.pct ? pct(e.pct) : '&ndash;') : (e.n ? fmt(e.n) : '&ndash;')) + '</td>';
  const rowHtml = (cls, r) => '<tr class="' + cls + '"><td>' + r.label + '</td>' + r.vals.map(r.kind === 'pct' ? cellP : cellN).join('') + (r.ex || []).map(exCell).join('') + '</tr>';
  function render(id, sec, isDay) {
    const S = isDay ? sec.day : sec.month, cols = isDay ? M.days : M.months, n = cols.length;
    const lastLabel = isDay ? dayLabelM(cols[n - 1]) : monthLabelM(cols[n - 1]);
    const heads = cols.map((c, i) => '<td>' + (isDay ? dayLabelM(c) + (i === n - 1 ? ' (today)' : '') : monthLabelM(c) + (i === n - 1 ? ' (MTD)' : '')) + '</td>').join('');
    const exHeads = (S.extraHeads || (isDay ? M.dayExtraHeads : M.monthExtraHeads)).map(h => h.replace('{last}', lastLabel));
    let h = '<tr class="logi-title"><td colspan="' + (n + 1 + exHeads.length) + '">' + sec.title + ' &mdash; by ' + (isDay ? 'day' : 'month') + '</td></tr>';
    h += '<tr class="logi-head"><td>' + sec.headLabel + '</td>' + heads + exHeads.map((x, i) => '<td class="logi-extra' + (i === 0 ? ' logi-extra-first' : '') + '">' + x + '</td>').join('') + '</tr>';
    let alt = 0;
    S.rows.forEach(r => { if (r.kind === 'pct') h += rowHtml('mkt-pct', r); else h += rowHtml((alt++ % 2) ? 'logi-light' : 'logi-white', r); });
    if (S.total) h += rowHtml('logi-total', { label: 'Total', vals: S.total.vals, ex: S.total.ex });
    (S.memos || []).forEach(r => { h += rowHtml('logi-plain', r); });
    document.getElementById(id + 'Table').querySelector('tbody').innerHTML = h;
  }
  const ids = [['mktStatus', M.status], ['mktStep', M.step], ['mktCity', M.city], ['mktConv', M.conv]];
  ids.forEach(([id, sec]) => { render(id + 'Day', sec, true); render(id + 'Month', sec, false); });
  window.mktScrollUpdaters = ids.flatMap(([id]) => [setupTopScrollSync(id + 'DayScrollTop', id + 'DayScrollBody'), setupTopScrollSync(id + 'MonthScrollTop', id + 'MonthScrollBody')]);
});
` + JS_END + NL;
  const a = '// ---- top-level page nav (grows as more reports get added) ----';
  must(a, 'nav handler');
  html = html.replace(a, js.replace(/^\n/, '') + a);
}

// nav handler line (once)
{
  const a = "  if (btn.dataset.page === 'subcategoryanalyze') subcategoryScrollUpdate();";
  must(a, 'nav updaters');
  const line = "  if (btn.dataset.page === 'leads' && window.mktScrollUpdaters) window.mktScrollUpdaters.forEach(f => f());";
  if (!html.includes(line)) html = html.replace(a, a + NL + line);
}

if (__hadCRLF) html = html.replace(/\n/g, '\r\n');
fs.writeFileSync(htmlPath, html);
console.log('html:', htmlPath, 'bytes:', html.length);
})();

// ================ Operations — Applications / Committee ================
(function () {
// Operations (Applications + Committee) for Volta_Analytics_New DB: turns the ops_*.tsv extracts (pull_ops.sh) into
// ops_data.json and injects it into the dashboard HTML (env DASH_HTML, default deals_amount_migration.html) as
// `const OPS_JSON = …;` plus the "Operations" nav group (Applications / Committee), the two page divs, a tiny CSS block
// and the render IIFE. Idempotent: every block is removed and re-injected on each run. Reuses the Logistics tab's
// logi-* classes (Volta logo palette), no new palette.
const fs = require('fs');
const path = require('path');
const CUTOVER = '2026-08-31';        // migration day: application rows carry real dates from here
const HISTORY_START = '2026-09-01';  // first status-change event in crm_activity_log (2026-09-01 08:23 UTC)
const MONTH_START = '2026-01';

function parseTsv(file) {
  let c = fs.readFileSync(path.join(__dirname, file), 'utf8');
  if (c.charCodeAt(0) === 0xFEFF) c = c.slice(1);
  const lines = c.split(/\r?\n/).filter(l => l.length);
  if (!lines.length) return [];
  const header = lines[0].split('\t');
  return lines.slice(1).map(line => { const cols = line.split('\t'); const o = {}; header.forEach((h, i) => o[h] = cols[i]); return o; });
}
const DASH = String.fromCharCode(8211);

// ---- status vocabulary. Verified from the CRM's own wording in crm_activity_log.summary ("სტატუსის შეცვლა: <name>")
// for every status that appears in the live flow; 4/5/11 from the CRM Applications page (memory); 99 = single payment
// (verified earlier against 39 legacy ids). 1 = legacy code on 3,047 migrated 2019-2024 loans (all closed; never used
// since the cutover). 3 and 14 appear only on old migrated rows and have no log wording — left unverified.
const STATUS = [
  [4,  'Pending',                               'ახალი / Pending (CRM Applications page)', true],
  [7,  'In processing',                         'დამუშავების პროცესი', true],
  [8,  'At committee',                          'კომიტეტზე გაგზავნა', true],
  [15, 'Clarification needed',                  'დასაზუსტებელია საკითხი', true],
  [16, 'Approved by committee',                 'დამტკიცებულია', true],
  [17, 'Disbursement in process',               'გაცემის პროცესი', true],
  [9,  'Approved ' + DASH + ' invoice & contract draft sent', 'დამტკიცებული, გაიგზავნა ინვოისი და ხელშეკრულების ნიმუში', true],
  [10, 'Contract sent for signing',             'ხელშეკრულება გაიგზავნა ხელმოსაწერად', true],
  [11, 'Signed',                                'ხელმოწერილია', true],
  [5,  'Active',                                'მიმდინარე (log code 1 = activation)', true],
  [1,  'Active (legacy code, migrated loans)',  'migrated 2019-2024 loans, all closed', true],
  [99, 'Single payment',                        'ერთიანი გადახდა', true],
  [6,  'Rejected',                              'უარი განვადებაზე', true],
  [12, 'Customer declined',                     'კლიენტმა უარი განაცხადა განვადებაზე', true],
  [13, 'Expired',                               'განვადების განაცხადს ვადა ამოეწურა', true],
  [14, 'Status 14 (unverified)',                'no CRM wording found; migrated rows only', false],
  [3,  'Status 3 (unverified)',                 'no CRM wording found; migrated rows only', false],
];
const LABEL = {}; STATUS.forEach(([c, l]) => LABEL[c] = l);
const label = c => LABEL[c] || ('Status ' + c + ' (unverified)');

// ---- calendars
const addDay = (d, n) => { const t = new Date(d + 'T00:00:00Z'); t.setUTCDate(t.getUTCDate() + n); return t.toISOString().slice(0, 10); };
function dayRange(a, b) { const out = []; for (let d = a; d <= b; d = addDay(d, 1)) out.push(d); return out; }
function monthRange(a, b) { const out = []; let [y, m] = a.split('-').map(Number); const [by, bm] = b.split('-').map(Number); while (y < by || (y === by && m <= bm)) { out.push(y + '-' + String(m).padStart(2, '0')); m++; if (m > 12) { m = 1; y++; } } return out; }

const queueRows = parseTsv('ops_queue.tsv');
const END = queueRows.reduce((m, r) => r.d > m ? r.d : m, CUTOVER);   // calendar end = today (pull_ops.sh END)
const DAYS = dayRange(CUTOVER, END);            // state-based day tables
const HDAYS = dayRange(HISTORY_START, END);     // log-based day tables
const MONTHS = monthRange(MONTH_START, END.slice(0, 7));
const HMONTHS = monthRange(HISTORY_START.slice(0, 7), END.slice(0, 7));
const idx = arr => { const m = {}; arr.forEach((k, i) => m[k] = i); return m; };
const DI = idx(DAYS), HDI = idx(HDAYS), MI = idx(MONTHS), HMI = idx(HMONTHS);
const zeros = arr => arr.map(() => 0);
const sumRows = (rows, n) => Array.from({ length: n }, (_, i) => rows.reduce((t, r) => t + (r.vals[i] || 0), 0));

// ---- 1. applications by application date x current status (state)
const state = parseTsv('ops_apps_state.tsv').map(r => ({ d: r.d, m: r.d.slice(0, 7), st: +r.st, uw: +r.uw, n: +r.n }));
function statusSeries(keys, ki, keyOf) {
  const by = {};
  for (const r of state) { const k = keyOf(r); if (!(k in ki)) continue; (by[r.st] ||= zeros(keys))[ki[k]] += r.n; }
  const rows = STATUS.map(([c]) => c).filter(c => by[c]).map(c => ({ code: c, label: label(c), vals: by[c] }));
  Object.keys(by).map(Number).filter(c => !LABEL[c]).forEach(c => rows.push({ code: c, label: label(c), vals: by[c] }));
  return { keys, rows, total: sumRows(rows, keys.length) };
}
const appsByStatus = { day: statusSeries(DAYS, DI, r => r.d), month: statusSeries(MONTHS, MI, r => r.m) };

// funnel by application date (state): where the applications submitted in that period stand today
const FUNNEL = [
  ['apps',     'Applications submitted',                        r => true],
  ['approved', 'Approved by committee (underwriting status 16)', r => r.uw === 16],
  ['signed',   'Signed or Active (status 11 / 5 / 1)',           r => [11, 5, 1].includes(r.st)],
  ['active',   'of which Active (status 5 / 1)',                 r => [5, 1].includes(r.st)],
  ['open',     'Still in process (4 / 7 / 8 / 15 / 16 / 17 / 9 / 10)', r => [4, 7, 8, 15, 16, 17, 9, 10].includes(r.st)],
  ['rejected', 'Rejected (status 6)',                            r => r.st === 6],
  ['declined', 'Customer declined (status 12)',                  r => r.st === 12],
  ['expired',  'Expired (status 13)',                            r => r.st === 13],
  ['other',    'Other (single payment 99 / unverified 3, 14)',   r => [99, 3, 14].includes(r.st)],
];
function funnelSeries(keys, ki, keyOf) {
  const rows = FUNNEL.map(([key, lab]) => ({ key, label: lab, vals: zeros(keys) }));
  for (const r of state) { const k = keyOf(r); if (!(k in ki)) continue; FUNNEL.forEach(([, , test], j) => { if (test(r)) rows[j].vals[ki[k]] += r.n; }); }
  return { keys, rows };
}
const funnel = { day: funnelSeries(DAYS, DI, r => r.d), month: funnelSeries(MONTHS, MI, r => r.m) };

// underwriting outcome by application date (state of crm_underwriter_status_id today)
const UWOUT = [
  ['apps', 'Applications submitted', r => true],
  ['uw16', 'Approved (16)', r => r.uw === 16],
  ['uw6',  'Rejected (6) ' + DASH + ' at committee or before it', r => r.uw === 6],
  ['uw15', 'Clarification needed (15)', r => r.uw === 15],
  ['uw0',  'No underwriting decision yet', r => r.uw === 0],
];
function uwSeries(keys, ki, keyOf) {
  const rows = UWOUT.map(([key, lab]) => ({ key, label: lab, vals: zeros(keys) }));
  for (const r of state) { const k = keyOf(r); if (!(k in ki)) continue; UWOUT.forEach(([, , test], j) => { if (test(r)) rows[j].vals[ki[k]] += r.n; }); }
  return { keys, rows };
}
const uwOutcome = { day: uwSeries(DAYS, DI, r => r.d), month: uwSeries(MONTHS, MI, r => r.m) };

// ---- 2. flow: status-change events by day (from crm_activity_log)
const flow = parseTsv('ops_flow.tsv').map(r => ({ d: r.d, m: r.d.slice(0, 7), f: +r.f, t: +r.t, n: +r.n, apps: +r.apps }));
// in the log, to=1 is the activation event (the order is then stored as crm_order_status 5 = Active)
const FLOW_LABEL = { 1: 'Active (activated; stored as status 5)' };
function flowSeries(keys, ki, keyOf) {
  const by = {};
  for (const r of flow) { const k = keyOf(r); if (!(k in ki)) continue; (by[r.t] ||= zeros(keys))[ki[k]] += r.n; }
  const rows = STATUS.map(([c]) => c).filter(c => by[c]).map(c => ({ code: c, label: FLOW_LABEL[c] || label(c), vals: by[c] }));
  Object.keys(by).map(Number).filter(c => !LABEL[c]).forEach(c => rows.push({ code: c, label: label(c), vals: by[c] }));
  return { keys, rows, total: sumRows(rows, keys.length) };
}
const flowByStatus = { day: flowSeries(HDAYS, HDI, r => r.d), month: flowSeries(HMONTHS, HMI, r => r.m) };

// ---- 3. committee decisions (events out of status 8) + queue stock
const queue = {};
for (const r of queueRows) (queue[r.status] ||= {})[r.d] = +r.n;
function decisionSeries(keys, ki, keyOf, lastDayOfKey) {
  const pick = (f, t) => { const v = zeros(keys); for (const r of flow) { const k = keyOf(r); if (k in ki && (f === null || r.f === f) && (t === null || r.t === t)) v[ki[k]] += r.n; } return v; };
  const approved = pick(8, 16), returned = pick(8, 15), rejected = pick(8, 6);
  const rows = [{ key: 'approved', label: 'Approved (8 ' + String.fromCharCode(8594) + ' 16)', vals: approved }, { key: 'returned', label: 'Returned for clarification (8 ' + String.fromCharCode(8594) + ' 15)', vals: returned }, { key: 'rejected', label: 'Rejected (8 ' + String.fromCharCode(8594) + ' 6)', vals: rejected }];
  const total = sumRows(rows, keys.length);
  const stock = st => keys.map(k => { const d = lastDayOfKey(k); return (queue[st] && queue[st][d]) || 0; });
  const memo = [
    { key: 'rate', label: 'Approval rate (approved / decisions)', vals: total.map((t, i) => t ? approved[i] / t : null), fmt: 'pct' },
    { key: 'sent', label: 'Sent to committee (' + String.fromCharCode(8594) + ' 8, all)', vals: pick(null, 8) },
    { key: 'resent', label: 'of which resubmitted after clarification (15 ' + String.fromCharCode(8594) + ' 8)', vals: pick(15, 8) },
    { key: 'queue8', label: 'At committee at end of period (stock, status 8)', vals: stock('8') },
    { key: 'queue15', label: 'Awaiting clarification at end of period (stock, status 15)', vals: stock('15') },
  ];
  return { keys, rows, total, memo };
}
const lastDayInMonth = m => { const ds = HDAYS.filter(d => d.slice(0, 7) === m); return ds[ds.length - 1]; };
const decisions = { day: decisionSeries(HDAYS, HDI, r => r.d, d => d), month: decisionSeries(HMONTHS, HMI, r => r.m, lastDayInMonth) };

// ---- 4. decisions per underwriter (actor of the events out of status 8)
const uw = parseTsv('ops_uw.tsv').map(r => ({ d: r.d, m: r.d.slice(0, 7), id: +r.actor_id, name: r.actor, t: +r.t, n: +r.n }));
const actors = [...new Map(uw.map(r => [r.id, r.name])).entries()].map(([id, name]) => ({ id, name, n: uw.filter(x => x.id === id).reduce((t, x) => t + x.n, 0) })).sort((a, b) => b.n - a.n);
const perUwDay = { keys: HDAYS, rows: actors.map(a => ({ label: a.name, vals: HDAYS.map(d => uw.filter(x => x.id === a.id && x.d === d).reduce((t, x) => t + x.n, 0)) })) };
perUwDay.total = sumRows(perUwDay.rows, HDAYS.length);
const cellsFor = (rows) => HMONTHS.map(m => { const dec = rows.filter(x => x.m === m); const s = t => dec.filter(x => x.t === t).reduce((a, x) => a + x.n, 0); const ap = s(16), ret = s(15), rej = s(6), all = ap + ret + rej; return { dec: all, ap, ret, rej, rate: all ? ap / all : null }; });
const perUwMonth = { keys: HMONTHS, rows: actors.map(a => ({ label: a.name, cells: cellsFor(uw.filter(x => x.id === a.id)) })), total: { label: 'Total', cells: cellsFor(uw) } };

// ---- 5. rejection reasons (→ 6): committee (from 8 / 15) by reason; all rejections by stage
const REASONS = [
  ['შეუსაბამო მონაცემები', 'Inconsistent data'], ['დასაფარია მიმდინარე', 'Existing loan must be repaid first'], ['გადახდისუუნარო', 'Insolvent'],
  ['მოვალეთა რეესტრი', "Debtors' registry"], ['ხიშნიკი', 'ხიშნიკი'], ['დუბლირებული განაცხადი', 'Duplicate application'],
  ['პროდუქციის არ ქონა', 'Product unavailable'], ['პროდუქტის არ ქონა', 'Product unavailable'], ['ვერ ვუკავშირდები', 'Cannot reach the customer'], ['შეუსაბამო მონაცემები.', 'Inconsistent data'],
];
const reasonKey = txt => { const t = (txt || '').trim(); if (!t) return '(no reason recorded)'; const hit = REASONS.find(([g]) => g === t); return hit ? (hit[0] === hit[1] ? hit[0] : hit[0] + ' / ' + hit[1]) : 'Other (free text)'; };
const reasons = parseTsv('ops_reasons.tsv').map(r => ({ d: r.d, m: r.d.slice(0, 7), f: +r.f, key: reasonKey(r.reason), n: +r.n }));
function reasonSeries(keys, ki, keyOf, filter) {
  const by = {};
  for (const r of reasons) { const k = keyOf(r); if (!(k in ki) || !filter(r)) continue; (by[r.key] ||= zeros(keys))[ki[k]] += r.n; }
  const rows = Object.entries(by).map(([lab, vals]) => ({ label: lab, vals })).sort((a, b) => (a.label.startsWith('Other') || a.label.startsWith('(no')) - (b.label.startsWith('Other') || b.label.startsWith('(no')) || b.vals.reduce((t, v) => t + v, 0) - a.vals.reduce((t, v) => t + v, 0));
  return { keys, rows, total: sumRows(rows, keys.length) };
}
const committeeReasons = { day: reasonSeries(HDAYS, HDI, r => r.d, r => r.f === 8 || r.f === 15), month: reasonSeries(HMONTHS, HMI, r => r.m, r => r.f === 8 || r.f === 15) };
const STAGE = { 4: 'from Pending (4) ' + DASH + ' screened out by sales', 7: 'from In processing (7)', 8: 'at committee (8)', 15: 'after clarification request (15)', 9: 'after approval (9)', 16: 'after approval (16)', 17: 'after approval (17)', 10: 'after approval (10)' };
function stageSeries(keys, ki, keyOf) {
  const by = {};
  for (const r of reasons) { const k = keyOf(r); if (!(k in ki)) continue; const lab = STAGE[r.f] || ('from status ' + r.f); (by[lab] ||= zeros(keys))[ki[k]] += r.n; }
  const order = Object.values(STAGE); const rows = Object.entries(by).map(([lab, vals]) => ({ label: lab, vals })).sort((a, b) => (order.indexOf(a.label) + 1 || 99) - (order.indexOf(b.label) + 1 || 99));
  return { keys, rows, total: sumRows(rows, keys.length) };
}
const rejectionsByStage = { day: stageSeries(HDAYS, HDI, r => r.d), month: stageSeries(HMONTHS, HMI, r => r.m) };

const payload = {
  cutover: CUTOVER, historyStart: HISTORY_START, monthStart: MONTH_START, end: END,
  statusLegend: STATUS.map(([code, lab, crm, verified]) => ({ code, label: lab, crm, verified })),
  appsByStatus, funnel, uwOutcome, flowByStatus, decisions, perUwDay, perUwMonth, committeeReasons, rejectionsByStage,
  generatedAt: new Date().toISOString().slice(0, 16).replace('T', ' ') + ' UTC',
};
fs.writeFileSync(path.join(__dirname, 'ops_data.json'), JSON.stringify(payload));
const last = a => a[a.length - 1];
console.log('days:', DAYS.length, '(history', HDAYS.length + ')', '| months:', MONTHS.join(','), '| apps today/MTD:', last(appsByStatus.day.total), last(appsByStatus.month.total),
  '| decisions MTD:', last(decisions.month.total), 'approved', last(decisions.month.rows[0].vals), '| underwriters:', actors.length, '| queue 8/15 today:', last(decisions.day.memo[3].vals), last(decisions.day.memo[4].vals));

// ---------------- inject into the HTML ----------------
const htmlPath = path.isAbsolute(process.env.DASH_HTML || '') ? process.env.DASH_HTML : path.join(__dirname, process.env.DASH_HTML || 'deals_amount_migration.html');
let html = fs.readFileSync(htmlPath, 'utf8');
const __hadCRLF = html.includes('\r\n'); if (__hadCRLF) html = html.replace(/\r\n/g, '\n'); // tolerate a git-checked-out CRLF file (Windows); every marker below assumes plain LF
const NL = String.fromCharCode(10);
const must = (n, what) => { if (!html.includes(n)) throw new Error('anchor not found: ' + what); };
function removeBlock(startMark, endMark, leadNl) {
  const s = html.indexOf((leadNl ? NL : '') + startMark);
  if (s < 0) return;
  const e = html.indexOf(endMark, s);
  if (e < 0) throw new Error('block end not found for ' + startMark);
  html = html.slice(0, s) + html.slice(e + endMark.length);
}

// CSS (only what the logi-* classes lack: an italic rate row)
const CSS_MARK = '/* --- ops --- */', CSS_END = '/* --- /ops --- */';
removeBlock(CSS_MARK, CSS_END, true);
const cssAnchor = '.report-scroll-top > div { height: 1px; }';
must(cssAnchor, 'css anchor');
html = html.replace(cssAnchor, cssAnchor + NL + CSS_MARK + NL + 'table.logi-table tr.ops-rate td { background: #fff; color: #1a1a34; font-style: italic; }' + NL + 'table.logi-table tr.ops-rate td:first-child { font-weight: 700; }' + NL + CSS_END);

// nav group
const NAV_START = '<!-- ops-nav-start -->', NAV_END = '<!-- ops-nav-end -->';
// static: inserted once (so the group keeps its place in the nav on later data-only rebuilds)
if (!html.includes(NAV_START)) {
const navAnchor = '  </div>' + NL + '</div>' + NL + NL + '<div class="page active" data-page="report">';
must(navAnchor, 'nav anchor');
html = html.replace(navAnchor, NAV_START + NL + `    <div class="nav-group">
      <div class="nav-group-title">Operations</div>
      <div class="nav-group-items">
        <button data-page="opsapplications">Applications</button>
        <button data-page="opscommittee">Committee</button>
      </div>
    </div>` + NL + NAV_END + NL + navAnchor);
}

// pages
const PAGE_START = '<!-- ops-page-start -->', PAGE_END = '<!-- ops-page-end -->';
removeBlock(PAGE_START, PAGE_END + NL, false);
const card = (id, extraClass) => `  <div class="report-card${extraClass ? ' ' + extraClass : ''}">
    <div class="report-scroll-top" id="${id}Top"><div></div></div>
    <div class="report-scroll" id="${id}Body"><table class="logi-table" id="${id}"><tbody></tbody></table></div>
  </div>`;
const pair = (id, title) => `  <div class="logi-group">
    <div class="logi-group-title">${title}</div>
${card(id + 'Day')}
${card(id + 'Month')}
  </div>`;
const pages = `
${PAGE_START}
<div class="page" data-page="opsapplications" id="page-opsapplications">
<div class="wrap">
  <p class="section-title">Operations &mdash; Applications</p>
  <div class="banner" id="opsAppsBanner"></div>

${pair('opsAppsStatus', 'Applications by current status')}
  <p class="note"><b>Flow, keyed to the application date</b> (<code>orders.created_at</code>, UTC calendar day like every other tab): the loan applications submitted on that day / in that month, split by the status they are in <i>today</i> (<code>orders.crm_order_status</code>) &mdash; a snapshot of where each cohort stands, not what happened on that day. Rows are the CRM statuses (legend below); the last columns give each status's share of the last day / of the current month. Month columns start at January 2026: the application rows exist for all of 2026 (migrated from the old CRM), only the status <i>history</i> is missing before September. Applications here run slightly above the Daily Mail tab for the migrated months (e.g. January 1,858 vs 1,817) because Daily Mail takes those months from the old database.</p>

${pair('opsFlow', 'Status changes ' + DASH + ' applications entering each status')}
  <p class="note"><b>Flow, keyed to the day of the status change</b>: how many status changes moved an application <i>into</i> each status that day / month, from the CRM's own audit trail (<code>crm_activity_log</code>, <code>action = 'installment.status_change'</code>, JSON <code>metadata.to</code>). An application that passes through several statuses counts once in each; one that is returned and resubmitted counts twice in the same status. The trail starts on 1 September 2026 &mdash; nothing before it exists in the new database, so the month series has only the months since then.</p>

${pair('opsFunnel', 'Funnel by application date ' + DASH + ' where the applications stand today')}
  <p class="note"><b>State, keyed to the application date</b>: of the applications submitted that day / month, how many are approved by the committee today (<code>crm_underwriter_status_id = 16</code>), signed or active (<code>crm_order_status</code> 11 / 5 / 1), still in process, rejected, declined by the customer or expired. The last column is the share of that day's / month's applications. Recent columns are still moving (the cohort is being worked); older months are settled. "Signed or Active" contains "of which Active"; the remaining rows (still in process, rejected, declined, expired, other) add up to the applications.</p>

  <p class="logi-open-title">Status codes &mdash; what was verified</p>
  <div class="table-card"><table class="logi-mini" id="opsLegend"><colgroup><col class="logi-mini-name"></colgroup><tbody></tbody></table></div>
  <p class="note">English labels are ours; the CRM wording is the exact text the CRM writes into the audit trail on each status change ("სტატუსის შეცვლა: &hellip;"), so every status that occurs in the live flow is verified from the system itself. Pending / Signed / Active are the names the CRM's Applications page shows. Codes 3 and 14 occur only on migrated rows and have no wording anywhere in the database &mdash; they are shown as unverified. Observed order of the flow: 4 &rarr; 7 &rarr; 8 &rarr; 16 &rarr; 17 &rarr; 9 &rarr; 10 &rarr; 11 &rarr; Active (log code 1, stored as 5); 8 &rarr; 15 &rarr; 8 is a return for clarification; 6 / 12 / 13 are the terminal negative outcomes.</p>
</div>
</div>
<div class="page" data-page="opscommittee" id="page-opscommittee">
<div class="wrap">
  <p class="section-title">Operations &mdash; Committee</p>
  <div class="banner" id="opsCommBanner"></div>

${pair('opsDecisions', 'Committee decisions by decision date')}
  <p class="note"><b>Flow, keyed to the decision timestamp</b> in the audit trail: every status change <i>out of</i> "At committee" (8) &mdash; Approved (&rarr; 16), Returned for clarification (&rarr; 15) or Rejected (&rarr; 6). The lime row is the total of the three; the approval rate is approved &divide; decisions. Memo rows: applications sent to the committee that day (&rarr; 8, including resubmissions after a clarification), and the <b>stock</b> at the end of the day / month &mdash; applications still sitting at the committee (status 8) or waiting for the requested clarification (status 15), reconstructed from the trail (today's figures equal the live CRM counts). <code>orders.crm_approve_date</code> is not written by the new CRM (it is NULL on 183 of 189 post-cutover approvals), so the audit-trail timestamp is the only decision date. One application can carry more than one decision (returned, then approved): 489 decisions on 426 applications so far.</p>

${pair('opsUwOutcome', 'Underwriting outcome by application date')}
  <p class="note"><b>State, keyed to the application date</b>: the applications submitted that day / month by their underwriting status <i>today</i> (<code>orders.crm_underwriter_status_id</code>: 16 approved, 15 clarification needed, 6 rejected, NULL = no decision recorded). Last column = share of that period's applications. Caution on the "Rejected (6)" row: the CRM stamps underwriting status 6 on every rejection, including the ones sales managers make before the case reaches the committee (status 4 &rarr; 6 and 7 &rarr; 6), so it is "rejected at any stage", not "rejected by the committee" &mdash; the committee's own rejections are in the decisions table above and the reasons table below.</p>

${pair('opsPerUw', 'Decisions per underwriter')}
  <p class="note"><b>Flow, keyed to the decision timestamp</b>: the same decisions out of status 8, by the CRM user who made them (audit-trail actor, joined to <code>crm_users</code>; the two people with the Underwriter role make nearly all of them, the odd sales-manager row is a case closed by sales while it sat at the committee). The day table counts decisions; the month table splits them into approved / returned / rejected with the approval rate. <code>orders.crm_underwriter_id</code> is deliberately not used: the CRM also writes it with whichever sales manager rejects a case before committee ("Underwriter assigned by deciding the case"), so it would attribute pre-committee rejections to sales staff.</p>

${pair('opsReasons', 'Committee rejection reasons')}
  <p class="note"><b>Flow, keyed to the rejection timestamp</b>: rejections made at the committee stage (from status 8, or from 15 after a clarification request) by the reason the underwriter selected &mdash; the text after "მიზეზი:" in the audit-trail summary (the same value lands in <code>orders.crm_reason</code>). Standard reasons are listed by their CRM wording with an English gloss; anything typed free-hand is "Other (free text)". Last column = share of the period's committee rejections.</p>

${pair('opsStages', 'All rejections by stage')}
  <p class="note"><b>Flow, keyed to the rejection timestamp</b>: every status change to Rejected (6), by the status the application was in when it was rejected &mdash; screened out by sales while Pending (4) or In processing (7), rejected by the committee (8) or after a clarification request (15), or cancelled after approval (16 / 17 / 9 / 10, typically duplicates). Puts the committee's rejections in proportion to the rest.</p>
</div>
</div>
${PAGE_END}`;
must('id="page-logistics"', 'logistics page');
{
  const li = html.indexOf('id="page-logistics"');
  const si = html.indexOf('<script>', li);
  if (si < 0) throw new Error('script tag after page-logistics not found');
  html = html.slice(0, si) + pages.replace(/^\n/, '') + NL + html.slice(si);
}

// data line
const dataLine = 'var OPS_JSON = ' + JSON.stringify(payload) + ';';
if (/^(?:const|var) OPS_JSON = .*;$/m.test(html)) html = html.replace(/^(?:const|var) OPS_JSON = .*;$/m, () => dataLine);
else { must('const generatedAt = REPORT_JSON.generatedAt;', 'report consts'); html = html.replace('const generatedAt = REPORT_JSON.generatedAt;', 'const generatedAt = REPORT_JSON.generatedAt;' + NL + dataLine); }

// render IIFE (no backticks / ${ inside: it lives in a template literal)
const JS_MARK = '/* ---------- ops ---------- */', JS_END = '/* ---------- /ops ---------- */';
removeBlock(JS_MARK, JS_END, true);
const js = `
${JS_MARK}
window.__registerPage(['opsapplications', 'opscommittee'], function () {
  const O = OPS_JSON, ARROW = String.fromCharCode(8594);
  const dayLabel = d => { const p = d.split('-').map(Number); return MONTH_NAMES[p[1] - 1] + ' ' + p[2]; };
  const monthLabel = m => MONTH_NAMES[Number(m.slice(5, 7)) - 1] + ' ' + m.slice(0, 4);
  const keyLabel = (k, i, n) => k.length === 7 ? (monthLabel(k) + (i === n - 1 ? ' (MTD)' : '')) : (dayLabel(k) + (i === n - 1 ? ' (today)' : ''));
  const shareHead = keys => (keys[keys.length - 1].length === 7 ? 'Share ' : 'Share ') + keyLabel(keys[keys.length - 1], keys.length - 1, keys.length).replace(' (MTD)', '').replace(' (today)', '');
  const num = v => (v === null || v === undefined) ? '&ndash;' : (v === 0 ? '&ndash;' : fmt(v));
  const cell = (v, f) => '<td>' + (f === 'pct' ? (v === null || v === undefined ? '&ndash;' : pct(v)) : num(v)) + '</td>';
  const exCell = (v, first) => '<td class="logi-extra' + (first ? ' logi-extra-first' : '') + '">' + (v === null || v === undefined ? '&ndash;' : pct(v)) + '</td>';
  const tbody = id => document.getElementById(id).querySelector('tbody');
  const banner = (id, txt) => { document.getElementById(id).innerHTML = txt; };

  // generic series table: rows with vals over keys; share column = each row's last value / denominator's last value
  function renderSeries(id, title, headLabel, keys, rows, opts) {
    opts = opts || {};
    const n = keys.length, denom = opts.denom || null, shareLabel = opts.shareLabel || shareHead(keys);
    const heads = keys.map((k, i) => '<td>' + keyLabel(k, i, n) + '</td>').join('') + '<td class="logi-extra logi-extra-first">' + shareLabel + '</td>';
    const rowHtml = (cls, r) => '<tr class="' + cls + '"><td>' + r.label + '</td>' + r.vals.map(v => cell(v, r.fmt)).join('') + ((r.fmt === 'pct' || r.noShare) ? '<td class="logi-extra logi-extra-first"></td>' : exCell(denom && denom[n - 1] ? (r.vals[n - 1] || 0) / denom[n - 1] : null, true)) + '</tr>';
    let h = '<tr class="logi-title"><td colspan="' + (n + 2) + '">' + title + '</td></tr><tr class="logi-head"><td>' + headLabel + '</td>' + heads + '</tr>';
    if (opts.totalFirst && opts.total) h += rowHtml('logi-total', { label: opts.totalLabel || 'Total', vals: opts.total });
    rows.forEach((r, i) => { h += rowHtml(r.fmt === 'pct' ? 'ops-rate' : (r.cls || (i % 2 ? 'logi-light' : 'logi-white')), r); });
    if (!opts.totalFirst && opts.total) h += rowHtml('logi-total', { label: opts.totalLabel || 'Total', vals: opts.total });
    (opts.memo || []).forEach(r => { h += rowHtml(r.fmt === 'pct' ? 'ops-rate' : 'logi-plain', Object.assign({ noShare: true }, r)); });
    if (!rows.length) h += '<tr class="logi-white"><td colspan="' + (n + 2) + '">No data yet.</td></tr>';
    tbody(id).innerHTML = h;
  }
  const pairRender = (id, title, headLabel, data, optsOf) => { renderSeries(id + 'Day', title + ' ' + String.fromCharCode(8212) + ' by day', headLabel, data.day.keys, data.day.rows, optsOf(data.day)); renderSeries(id + 'Month', title + ' ' + String.fromCharCode(8212) + ' by month', headLabel, data.month.keys, data.month.rows, optsOf(data.month)); };

  // banners (Georgian + English, like Logistics)
  const histTxt = 'სტატუსების ისტორია (აუდიტის ლოგი) ' + O.historyStart + '-დან არსებობს; განაცხადების სტრიქონები 2026 წლის იანვრიდან. / Status history exists from ' + O.historyStart + '; application rows from January 2026. Days are UTC calendar days, as on every other tab.';
  banner('opsAppsBanner', '<b>წყარო:</b> ახალი CRM (VoltaStoreDB): <code>orders</code> = განაცხადები (<code>created_at</code> = განაცხადის თარიღი, <code>crm_order_status</code> = მიმდინარე სტატუსი), სტატუსების ცვლილებები <code>crm_activity_log</code>-იდან. ' + histTxt);
  banner('opsCommBanner', '<b>წყარო:</b> საკრედიტო კომიტეტის (ანდერრაითინგის) გადაწყვეტილებები ახალი CRM-ის აუდიტის ლოგიდან (<code>crm_activity_log</code>: სტატუსი 8 &rarr; 16 დამტკიცება, &rarr; 15 დასაზუსტებელი, &rarr; 6 უარი) და <code>orders.crm_underwriter_status_id</code>. ' + histTxt);

  // Applications page
  pairRender('opsAppsStatus', 'Applications by current status', 'Current status', O.appsByStatus, s => ({ total: s.total, denom: s.total }));
  pairRender('opsFlow', 'Applications entering each status', 'Entered status', O.flowByStatus, s => ({ total: s.total, denom: s.total, totalLabel: 'Total status changes' }));
  pairRender('opsFunnel', 'Funnel by application date', 'Stage today', O.funnel, s => ({ denom: s.rows[0].vals, shareLabel: '% of applications' }));
  {
    let h = '<tr class="logi-mini-title"><td colspan="3">CRM status codes</td></tr><tr class="logi-mini-head"><td>Label used here</td><td>Code</td><td>CRM wording / basis</td></tr>';
    O.statusLegend.forEach((s, i) => { h += '<tr class="logi-mini-data' + (i % 2 ? ' logi-mini-alt' : '') + '"><td>' + s.label + (s.verified ? '' : ' <i>(unverified)</i>') + '</td><td>' + s.code + '</td><td style="text-align:left">' + s.crm + '</td></tr>'; });
    tbody('opsLegend').innerHTML = h;
  }
  // Committee page
  pairRender('opsDecisions', 'Committee decisions', 'Decision', O.decisions, s => ({ total: s.total, denom: s.total, totalLabel: 'Total decisions', memo: s.memo }));
  pairRender('opsUwOutcome', 'Underwriting outcome by application date', 'Underwriting status today', O.uwOutcome, s => ({ denom: s.rows[0].vals, shareLabel: '% of applications' }));
  renderSeries('opsPerUwDay', 'Decisions per underwriter ' + String.fromCharCode(8212) + ' by day', 'Decided by', O.perUwDay.keys, O.perUwDay.rows, { total: O.perUwDay.total, denom: O.perUwDay.total, totalLabel: 'Total decisions' });
  {
    const P = O.perUwMonth, n = P.keys.length, cols = ['Decisions', 'Approved', 'Returned', 'Rejected', 'Approval rate'];
    let h = '<tr class="logi-title"><td colspan="' + (1 + 5 * n) + '">Decisions per underwriter ' + String.fromCharCode(8212) + ' by month</td></tr>';
    h += '<tr class="logi-sub"><td>Month</td>' + P.keys.map((k, i) => '<td colspan="5" style="text-align:center">' + keyLabel(k, i, n) + '</td>').join('') + '</tr>';
    h += '<tr class="logi-head"><td>Decided by</td>' + P.keys.map(() => cols.map((c, j) => '<td' + (j === 0 ? ' class="logi-extra-first"' : '') + '>' + c + '</td>').join('')).join('') + '</tr>';
    const rowHtml = (cls, r) => '<tr class="' + cls + '"><td>' + r.label + '</td>' + r.cells.map(c => '<td class="logi-extra-first">' + num(c.dec) + '</td><td>' + num(c.ap) + '</td><td>' + num(c.ret) + '</td><td>' + num(c.rej) + '</td><td class="logi-extra">' + (c.rate === null ? '&ndash;' : pct(c.rate)) + '</td>').join('') + '</tr>';
    P.rows.forEach((r, i) => { h += rowHtml(i % 2 ? 'logi-light' : 'logi-white', r); });
    h += rowHtml('logi-total', P.total);
    tbody('opsPerUwMonth').innerHTML = h;
  }
  pairRender('opsReasons', 'Committee rejection reasons', 'Reason', O.committeeReasons, s => ({ total: s.total, denom: s.total, totalLabel: 'Committee rejections' }));
  pairRender('opsStages', 'Rejections by stage', 'Rejected while', O.rejectionsByStage, s => ({ total: s.total, denom: s.total, totalLabel: 'All rejections' }));

  const ids = ['opsAppsStatus', 'opsFlow', 'opsFunnel', 'opsDecisions', 'opsUwOutcome', 'opsPerUw', 'opsReasons', 'opsStages'];
  window.opsScrollUpdaters = [];
  ids.forEach(id => ['Day', 'Month'].forEach(s => window.opsScrollUpdaters.push(setupTopScrollSync(id + s + 'Top', id + s + 'Body'))));
});
${JS_END}`;
const navJsAnchor = '// ---- top-level page nav (grows as more reports get added) ----';
must(navJsAnchor, 'nav handler');
html = html.replace(navJsAnchor, js.replace(/^\n/, '') + NL + navJsAnchor);
const updAnchor = "  if (btn.dataset.page === 'subcategoryanalyze') subcategoryScrollUpdate();";
must(updAnchor, 'nav updaters');
for (const pid of ['opsapplications', 'opscommittee']) {
  const line = "  if (btn.dataset.page === '" + pid + "' && window.opsScrollUpdaters) window.opsScrollUpdaters.forEach(f => f());";
  if (!html.includes(line)) html = html.replace(updAnchor, updAnchor + NL + line);
}

if (__hadCRLF) html = html.replace(/\n/g, '\r\n');
fs.writeFileSync(htmlPath, html);
console.log('html:', htmlPath, 'bytes:', html.length);
})();

// ================ Customers — Customers Analyze ================
(function () {
// Customers Analyze for Volta_Analytics_New DB: turns the cust_*.tsv extracts (pull_cust.sh) into cust_data.json and
// injects them into the dashboard HTML (env DASH_HTML, default deals_amount_migration.html) as `const CUST_JSON = …;`
// together with the "Customers" nav group, the page markup, the CSS and the render code. Idempotent: every block it adds
// sits between its own markers and is removed and re-inserted on each run. Same injection pattern as build_logistics.js.
const fs = require('fs');
const path = require('path');
const CUTOVER = '2026-08-31';

function parseTsv(file) {
  let c = fs.readFileSync(path.join(__dirname, file), 'utf8');
  if (c.charCodeAt(0) === 0xFEFF) c = c.slice(1);
  const lines = c.split(/\r?\n/).filter(l => l.length);
  if (!lines.length) return [];
  const header = lines[0].split('\t');
  return lines.slice(1).map(line => { const cols = line.split('\t'); const o = {}; header.forEach((h, i) => o[h] = cols[i]); return o; });
}
const num = v => (v === '' || v === 'NULL' || v === undefined) ? null : +v;
const isNull = v => v === '' || v === 'NULL' || v === undefined;
const strip = v => v.replace(/^\d+:/, '');          // '3:25 - 34' -> '25 - 34' (the prefix only fixes the sort order)
const sortKey = v => { const m = /^(\d+):/.exec(v); return m ? +m[1] : null; };

// city labels: the shipping-address city is Georgian; show it with the English name where known
const CITY_EN = { 'თბილისი': 'Tbilisi', 'ბათუმი': 'Batumi', 'რუსთავი': 'Rustavi', 'ქუთაისი': 'Kutaisi', 'ზუგდიდი': 'Zugdidi', 'გორი': 'Gori', 'მცხეთა': 'Mtskheta', 'ფოთი': 'Poti', 'ქობულეთი': 'Kobuleti', 'ოზურგეთი': 'Ozurgeti', 'გარდაბანი': 'Gardabani', 'ხაშური': 'Khashuri', 'სენაკი': 'Senaki', 'გურჯაანი': 'Gurjaani', 'სამტრედია': 'Samtredia', 'ბოლნისი': 'Bolnisi', 'წყალტუბო': 'Tskaltubo', 'საგარეჯო': 'Sagarejo', 'კასპი': 'Kaspi', 'ზესტაფონი': 'Zestafoni', 'თელავი': 'Telavi', 'დუშეთი': 'Dusheti', 'ახალციხე': 'Akhaltsikhe', 'მარნეული': 'Marneuli', 'ქარელი': 'Kareli', 'ლანჩხუთი': 'Lanchkhuti', 'ჭიათურა': 'Chiatura', 'ტყიბული': 'Tkibuli', 'ხონი': 'Khoni', 'ბორჯომი': 'Borjomi', 'აბაშა': 'Abasha', 'მარტვილი': 'Martvili', 'წალენჯიხა': 'Tsalenjikha', 'სიღნაღი': 'Sighnaghi', 'ლაგოდეხი': 'Lagodekhi', 'ყვარელი': 'Kvareli', 'ახმეტა': 'Akhmeta', 'ხობი': 'Khobi', 'ვანი': 'Vani', 'თერჯოლა': 'Terjola', 'საჩხერე': 'Sachkhere', 'ხარაგაული': 'Kharagauli', 'ბაღდათი': 'Baghdati', 'ონი': 'Oni', 'ამბროლაური': 'Ambrolauri', 'მესტია': 'Mestia', 'ახალქალაქი': 'Akhalkalaki', 'თეთრიწყარო': 'Tetritskaro', 'წალკა': 'Tsalka', 'დმანისი': 'Dmanisi', 'ჩხოროწყუ': 'Chkhorotsku', 'ცაგერი': 'Tsageri', 'ლენტეხი': 'Lentekhi', 'ადიგენი': 'Adigeni', 'ასპინძა': 'Aspindza', 'ნინოწმინდა': 'Ninotsminda', 'თიანეთი': 'Tianeti', 'ხელვაჩაური': 'Khelvachauri', 'ქედა': 'Keda', 'შუახევი': 'Shuakhevi', 'ხულო': 'Khulo', 'სურამი': 'Surami', 'წნორი': 'Tsnori' };
const cityLabel = v => CITY_EN[v] ? CITY_EN[v] + ' (' + v + ')' : v;
const RISK_ORDER = { Low: 1, Medium: 2, High: 3 };

// ---- dimension catalogue (labels/notes live here so the render code stays generic)
// The web application form carried these fields on ~95% of web applications from March to August 2026; the new form used
// since the 2026-08-31 cutover asks them on only a minority (~15% of September applications). Verified 2026-09-09 by month.
const FORM_NOTE = ' Present on ~95% of web applications March&ndash;August 2026; since the 31 Aug 2026 cutover the new form records it on only a minority of applications (~15% in September), so the latest month is thin.';
const DIMS = [
  { key: 'gender', title: 'Gender', head: 'Gender', unspecified: 'Unspecified', monthly: true,
    def: 'Gender as answered on the application form (<code>volta_application_data.gender</code>; migrated applications carry the old CRM value, web applications the form value); the customer account\'s gender is the fallback.' },
  { key: 'age', title: 'Age', head: 'Age band', unspecified: 'No birth date', monthly: true,
    def: 'Age from the birth date on the application form (<code>birth_date</code>; the account\'s <code>date_of_birth</code> as fallback) &mdash; never from the ID number. Customer columns = age today; the by-month table = age on the application date. Ages outside 14&ndash;100 are treated as missing.' },
  { key: 'city', title: 'Geography (city of the shipping address)', head: 'City', unspecified: 'No city', monthly: true, label: cityLabel, sort: 'count',
    def: 'City of the order\'s shipping address (<code>addresses.city</code>, <code>address_type = order_shipping</code>) &mdash; the 15 most frequent cities, the rest grouped as Other cities. The new DB has no region field.' },
  { key: 'emp', title: 'Employment status', head: 'Employment', unspecified: 'Not on the form', monthly: true, sort: 'count',
    def: 'Employment status selected on the web application form (<code>employment_status</code>: private sector / self-employed / public sector / unemployed). The field exists only on applications submitted through the web form since 12 March 2026; migrated applications do not carry it, hence "Not on the form".' + FORM_NOTE },
  { key: 'soc', title: 'Social status', head: 'Social status', unspecified: 'Not on the form', monthly: true, sort: 'count',
    def: 'Social status selected on the web application form (<code>social_status</code>: housewife / student / pensioner; left empty = none of these). On the form since 12 March 2026, like employment status.' + FORM_NOTE },
  { key: 'sal', title: 'Monthly income (declared salary, GEL)', head: 'Salary band', unspecified: 'Not on the form', monthly: true,
    def: 'Salary declared on the web application form (<code>salary</code>, a number since February 2026; for ~1,200 March&ndash;April 2026 applications a range picked from a list, mapped to the same bands). Self-declared, not verified. Migrated applications do not carry it.' + FORM_NOTE },
  { key: 'src', title: 'How the customer heard about Volta', head: 'Source', unspecified: 'Not on the form', monthly: true, sort: 'count',
    def: '"How did you hear about us" on the web application form (<code>about_us_source</code>), on the form since January 2026. "Used Volta before" is the applicant\'s own answer &mdash; compare it with the New / Returning tables, which are computed from the application history.' + FORM_NOTE },
  { key: 'risk', title: 'Risk status (CRM)', head: 'Risk status', unspecified: 'Not rated', monthly: true, sort: 'risk',
    def: 'CRM risk status of the loan (<code>orders.crm_risk_status</code>: დაბალი / low = Low, საშუალო / medium = Medium, მაღალი = High; 0 / empty = not rated). It is set when a loan is issued, so applications that never became a loan are mostly "Not rated". Customer columns use the status of the customer\'s active loan (else the latest rated loan).' },
  { key: 'pos', title: 'Job title (free text, top 15)', head: 'Job title', unspecified: 'Not on the form', monthly: false, sort: 'count',
    def: 'Job title typed on the application form (<code>position</code>, ~7,600 distinct raw values) &mdash; the 15 most frequent exact values, everything else under Other. Not clustered on purpose (spelling variants stay separate).' },
  { key: 'rating', title: 'Customer rating (CRM profile, 1&ndash;5)', head: 'Rating', unspecified: 'Not rated', monthly: false,
    def: 'Star rating on the CRM customer profile (<code>crm_customer_profile.rating</code>, migrated from the old CRM; 0 = not rated), linked to the customer through the account or the ID number. Only a small part of the base is rated &mdash; see the coverage line.' },
];

// ---- customer-level tables
const dimsRaw = parseTsv('cust_dims.tsv');
const monthRaw = parseTsv('cust_month.tsv');
const summary = parseTsv('cust_summary.tsv')[0];
const S = { customers: +summary.customers, activeCustomers: +summary.active_customers, loanCustomers: +summary.loan_customers, applications: +summary.applications, loans: +summary.loans,
  customersBeforeSeries: +summary.customers_before_series, appsBeforeSeries: +summary.apps_before_series, firstApp: summary.first_app.slice(0, 10), lastApp: summary.last_app.slice(0, 16),
  appsWithoutPid: +summary.apps_without_pid, activeLoans: +summary.active_loans, appsSinceCutover: +summary.apps_since_cutover };
const months = [...new Set(monthRaw.map(r => r.m))].sort();
const curMonth = months.at(-1);

function rowsFor(dim) {
  const rows = dimsRaw.filter(r => r.dim === dim.key);
  const mrows = monthRaw.filter(r => r.dim === dim.key);
  const mtd = {}; for (const r of mrows) if (r.m === curMonth) mtd[r.val] = (mtd[r.val] || 0) + +r.n;
  const label = v => isNull(v) ? dim.unspecified : (dim.label ? dim.label(strip(v)) : strip(v));
  let out = rows.map(r => ({ raw: r.val, label: label(r.val), all: +r.all_c, active: +r.active_c, loan: +r.loan_c, mtd: mtd[r.val] || 0, unspecified: isNull(r.val), other: r.val === 'Other cities' || r.val === 'Other' }));
  // ordering: prefixed keys by prefix; counts descending; risk Low/Medium/High; Unspecified (and Other) always last
  out.sort((a, b) => (a.unspecified - b.unspecified) || (a.other - b.other) || (dim.sort === 'risk' ? RISK_ORDER[a.raw] - RISK_ORDER[b.raw]
    : dim.sort === 'count' ? b.all - a.all : (sortKey(a.raw) ?? 0) - (sortKey(b.raw) ?? 0) || a.label.localeCompare(b.label)));
  const tot = k => out.reduce((t, r) => t + r[k], 0);
  const cov = k => { const t = tot(k); const u = out.filter(r => r.unspecified).reduce((s, r) => s + r[k], 0); return t ? (t - u) / t : 0; };
  return { rows: out, total: { all: tot('all'), active: tot('active'), loan: tot('loan'), mtd: tot('mtd') }, coverage: { all: cov('all'), active: cov('active'), mtd: cov('mtd') } };
}
function monthFor(dim) {
  const mrows = monthRaw.filter(r => r.dim === dim.key);
  const vals = rowsFor(dim).rows.map(r => r.raw);
  const rows = vals.map(v => { const vec = months.map(m => { const r = mrows.find(x => x.val === v && x.m === m); return r ? +r.n : 0; }); return { raw: v, vals: vec }; });
  // keep only rows that occur in the series (a value seen only before 2024 would be all zeros)
  const label = v => isNull(v) ? dim.unspecified : (dim.label ? dim.label(strip(v)) : strip(v));
  const kept = rows.filter(r => r.vals.some(x => x > 0)).map(r => ({ label: label(r.raw), unspecified: isNull(r.raw), vals: r.vals }));
  return { rows: kept, total: months.map((_, i) => kept.reduce((t, r) => t + r.vals[i], 0)) };
}
const dims = DIMS.map(d => ({ key: d.key, title: d.title, head: d.head, def: d.def, unspecified: d.unspecified, monthly: d.monthly, ...rowsFor(d), months: d.monthly ? monthFor(d) : null }));

// ---- new vs returning (by month and by day)
const TYPES = ['1:New', '2:Returning (earlier application only)', '3:Returning (previous loan)'];
function series(raw, keyCol) {
  const keys = [...new Set(raw.map(r => r[keyCol]))].sort();
  const pick = (t, k, col) => { const r = raw.find(x => x[keyCol] === k && x.type === t); return r ? +r[col] : 0; };
  const rows = TYPES.map(t => ({ label: strip(t), apps: keys.map(k => pick(t, k, 'n')), loans: keys.map(k => pick(t, k, 'loan_n')), active: keys.map(k => pick(t, k, 'active_n')) }));
  const sum = col => keys.map((_, i) => rows.reduce((s, r) => s + r[col][i], 0));
  return { keys, rows, total: { apps: sum('apps'), loans: sum('loans'), active: sum('active') } };
}
const newret = { month: series(parseTsv('cust_newret_month.tsv'), 'm'), day: series(parseTsv('cust_newret_day.tsv'), 'd') };

// ---- applications / loans per customer
const histRaw = parseTsv('cust_hist.tsv');
const hist = {};
for (const kind of ['apps', 'loans']) {
  const rows = histRaw.filter(r => r.kind === kind).map(r => ({ label: r.bucket, all: +r.n, active: +r.active_c })).sort((a, b) => (parseInt(a.label) || 0) - (parseInt(b.label) || 0));
  hist[kind] = { rows, total: { all: rows.reduce((t, r) => t + r.all, 0), active: rows.reduce((t, r) => t + r.active, 0) } };
}

// ---- payment behaviour (crm_customer_payment_stats)
const payRaw = parseTsv('cust_paystats.tsv');
const WORST = { 0: 'Not computed', 1: 'Max 3 days late', 2: '4 - 9 days late', 3: '10 - 30 days late', 4: '31 - 60 days late', 5: '61 - 90 days late', 6: '90+ days late' };
function payTable(metric, labelOf) {
  const rows = payRaw.filter(r => r.metric === metric).map(r => ({ raw: r.val, label: labelOf(r.val), n: +r.n })).sort((a, b) => (sortKey(a.raw) ?? +a.raw) - (sortKey(b.raw) ?? +b.raw));
  return { rows, total: rows.reduce((t, r) => t + r.n, 0) };
}
const cov = {}; for (const r of payRaw.filter(r => r.metric === 'coverage')) cov[r.val] = r.n;
const paystats = { worst: payTable('worst', v => WORST[v] || ('Bucket ' + v)), dpd: payTable('dpd', strip), ontime: payTable('ontime', strip),
  coverage: { rows: +cov.stats_rows, activeByAccount: +cov.active_customers_by_account, activeWithStats: +cov.active_customers_with_stats, from: cov.computed_from, to: cov.computed_to } };

const payload = { summary: S, months, curMonth, dims, newret, hist, paystats, cutover: CUTOVER, dayStart: newret.day.keys[0], generatedAt: new Date().toISOString().slice(0, 16).replace('T', ' ') + ' UTC' };
fs.writeFileSync(path.join(__dirname, 'cust_data.json'), JSON.stringify(payload));
console.log('customers:', S.customers, '| active:', S.activeCustomers, '| applications:', S.applications, '| months:', months[0], '..', curMonth, '| days:', newret.day.keys.length, '| dims:', dims.map(d => d.key + ':' + d.rows.length).join(' '));

// ---------------- inject into the HTML ----------------
const htmlPath = process.env.DASH_HTML ? path.resolve(process.env.DASH_HTML) : path.join(__dirname, 'deals_amount_migration.html');
let html = fs.readFileSync(htmlPath, 'utf8');
const __hadCRLF = html.includes('\r\n'); if (__hadCRLF) html = html.replace(/\r\n/g, '\n'); // tolerate a git-checked-out CRLF file (Windows); every marker below assumes plain LF
const NL = String.fromCharCode(10);
const must = (n, what) => { if (!html.includes(n)) throw new Error('anchor not found: ' + what); };
function removeBetween(startMark, endMark, what) {
  const s = html.indexOf(startMark);
  if (s < 0) return;
  const e = html.indexOf(endMark, s);
  if (e < 0) throw new Error(what + ' end marker not found');
  html = html.slice(0, s) + html.slice(e + endMark.length);
}

// CSS (only what the shared logi-* classes do not already give: the KPI strip and the coverage line)
const CSS_MARK = '/* --- cust --- */', CSS_END = '/* --- /cust --- */';
const css = `
${CSS_MARK}
.cust-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin: 6px 0 14px; }
.cust-kpi { background: #1a1a34; color: #c2ff00; border-radius: 10px; padding: 10px 12px; }
.cust-kpi .cust-kpi-v { font-size: 20px; font-weight: 700; color: #c2ff00; }
.cust-kpi .cust-kpi-l { font-size: 11px; color: #fff; margin-top: 2px; }
.cust-cov { color: var(--text-muted); font-size: 11.5px; margin: -4px 0 12px; font-style: italic; }
.cust-def { color: var(--text-muted); font-size: 12px; margin: 0 0 6px; }
.cust-block { margin-bottom: 18px; }
${CSS_END}`;
removeBetween(NL + CSS_MARK, NL + CSS_END, 'cust css');
must('.report-scroll-top > div { height: 1px; }', 'css anchor');
html = html.replace('.report-scroll-top > div { height: 1px; }', '.report-scroll-top > div { height: 1px; }' + css);

// nav group (added once)
if (!html.includes('data-page="customers"')) {
  const navEnd = '  </div>' + NL + '</div>' + NL + NL + '<div class="page active" data-page="report">';
  must(navEnd, 'nav end');
  html = html.replace(navEnd, `    <div class="nav-group">
      <div class="nav-group-title">Customers</div>
      <div class="nav-group-items">
        <button data-page="customers">Customers Analyze</button>
      </div>
    </div>
` + navEnd);
}

// page markup (replaced on every run)
const PAGE_START = '<!-- cust-page-start -->', PAGE_END = '<!-- cust-page-end -->';
removeBetween(NL + PAGE_START, PAGE_END + NL, 'cust page');
{
  const dimBlocks = DIMS.map(d => `
  <div class="cust-block" id="custDim_${d.key}">
    <div class="logi-group">
      <div class="logi-group-title">${d.title}</div>
      <p class="cust-def">${d.def}</p>
      <div class="table-card"><table class="logi-mini" id="custTable_${d.key}"><tbody></tbody></table></div>
      <p class="cust-cov" id="custCov_${d.key}"></p>${d.monthly ? `
      <div class="report-card">
        <div class="report-scroll-top" id="custMonth_${d.key}_Top"><div></div></div>
        <div class="report-scroll" id="custMonth_${d.key}_Body"><table class="logi-table" id="custMonthTable_${d.key}"><tbody></tbody></table></div>
      </div>` : ''}
    </div>
  </div>`).join('');
  const page = `
${PAGE_START}
<div class="page" data-page="customers" id="page-customers">
<div class="wrap">
  <p class="section-title">Customers Analyze &mdash; who applies and who borrows</p>
  <div class="banner" id="custBanner"></div>
  <div class="cust-kpis" id="custKpis"></div>
  <p class="note">Customer = a distinct person, identified by the personal ID typed on the application form, else the ID number on the customer account (together <span id="custPidCov"></span> of applications; the account id or the e-mail for the rest). Application = one <code>orders</code> row by its application date (<code>created_at</code>). Active loan = <code>crm_active = 1</code> and not a pending application. Loan = an application that became an installment or single-payment sale (<code>crm_order_status</code> 5 / 99, a closed loan, or an active one). Every table is an aggregate &mdash; no names, phones or ID numbers are stored in this page.</p>

  <div class="logi-group">
    <div class="logi-group-title">New vs returning applicants &mdash; by month</div>
    <div class="report-card">
      <div class="report-scroll-top" id="custNrMonthTop"><div></div></div>
      <div class="report-scroll" id="custNrMonthBody"><table class="logi-table" id="custNrMonthTable"><tbody></tbody></table></div>
    </div>
    <p class="note" id="custNrMonthNote"></p>
    <div class="logi-group-title">New vs returning applicants &mdash; by day</div>
    <div class="report-card">
      <div class="report-scroll-top" id="custNrDayTop"><div></div></div>
      <div class="report-scroll" id="custNrDayBody"><table class="logi-table" id="custNrDayTable"><tbody></tbody></table></div>
    </div>
    <p class="note" id="custNrDayNote"></p>
  </div>

  <div class="logi-group">
    <div class="logi-group-title">Applications and loans per customer</div>
    <div class="logi-mini-row">
      <div class="table-card"><table class="logi-mini" id="custHistApps"><tbody></tbody></table></div>
      <div class="table-card"><table class="logi-mini" id="custHistLoans"><tbody></tbody></table></div>
    </div>
    <p class="note">How many applications (left) and how many loans (right) each customer has had, all time. A customer with 0 loans applied but never borrowed. "With active loan" = customers in that bucket who have an active loan today.</p>
  </div>
${dimBlocks}
  <div class="logi-group">
    <div class="logi-group-title">Payment behaviour (CRM customer payment statistics)</div>
    <div class="logi-mini-row">
      <div class="table-card"><table class="logi-mini" id="custPayWorst"><tbody></tbody></table></div>
      <div class="table-card"><table class="logi-mini" id="custPayDpd"><tbody></tbody></table></div>
    </div>
    <div class="logi-mini-row" style="margin-top:12px">
      <div class="table-card"><table class="logi-mini" id="custPayOntime"><tbody></tbody></table></div>
      <div></div>
    </div>
    <p class="note" id="custPayNote"></p>
  </div>
</div>
</div>
${PAGE_END}`;
  // right before the first <script> after the logistics page
  const lp = html.indexOf('id="page-logistics"');
  if (lp < 0) throw new Error('page-logistics not found');
  const sp = html.indexOf('<script>', lp);
  if (sp < 0) throw new Error('script after page-logistics not found');
  html = html.slice(0, sp) + page.replace(/^\n/, '') + NL + NL + html.slice(sp);
}

// data line
const dataLine = 'var CUST_JSON = ' + JSON.stringify(payload) + ';';
if (/^(?:const|var) CUST_JSON = .*;$/m.test(html)) html = html.replace(/^(?:const|var) CUST_JSON = .*;$/m, () => dataLine);
else { must('const generatedAt = REPORT_JSON.generatedAt;', 'report consts'); html = html.replace('const generatedAt = REPORT_JSON.generatedAt;', 'const generatedAt = REPORT_JSON.generatedAt;' + NL + dataLine); }

// render code (replaced on every run)
const JS_MARK = '/* ---------- cust ---------- */', JS_END = '/* ---------- /cust ---------- */';
removeBetween(NL + JS_MARK, JS_END, 'cust js');   // the newline after JS_END stays: it is the one before the nav-handler comment
const js = `
${JS_MARK}
window.__registerPage(['customers'], function () {
  const C = CUST_JSON, S = C.summary;
  const monthLabel = m => MONTH_NAMES[Number(m.slice(5, 7)) - 1] + ' ' + m.slice(0, 4);
  const dayLabel = d => { const [, m, day] = d.split('-').map(Number); return MONTH_NAMES[m - 1] + ' ' + day; };
  const n = v => (v === null || v === undefined) ? '&ndash;' : (v ? fmt(v) : '&ndash;');
  const p = (a, b) => b ? pct(a / b) : '&ndash;';
  const curLabel = monthLabel(C.curMonth);
  document.getElementById('custBanner').innerHTML = '<b>წყარო:</b> ახალი ბაზა (VoltaStoreDB): განაცხადები <code>orders</code>-დან განაცხადის თარიღით, დემოგრაფია განაცხადის ფორმიდან (<code>volta_application_data</code>: სქესი, დაბადების თარიღი, დასაქმება, ხელფასი, სოციალური სტატუსი, წყარო), ქალაქი მიწოდების მისამართიდან, რისკი და რეიტინგი CRM-იდან, გადახდის ქცევა <code>crm_customer_payment_stats</code>-იდან. მომხმარებელი = ერთი პირი (განაცხადში ჩაწერილი პირადი ნომრით); არცერთი ცხრილი არ შეიცავს სახელებს, ტელეფონებს ან პირად ნომრებს. თვეების სერია 2024 იანვრიდან, დღეების სერია ' + dayLabel(C.dayStart) + '-დან; განაცხადის დრო ' + C.cutover + '-ის შემდეგ ზუსტია (ახალი CRM), მანამდე ძველი CRM-იდან გადმოტანილი თარიღებია (დღის დონეზე &plusmn;1 დღე შესაძლებელია). ვებ-ფორმის ველები (დასაქმება, ხელფასი, სოციალური სტატუსი, წყარო) მხოლოდ 2026 წლიდან არსებობს, ამიტომ ძველ განაცხადებზე &laquo;Not on the form&raquo; წერია; 31 აგვისტოდან ახალი ფორმა ამ ველებს განაცხადების მხოლოდ მცირე ნაწილზე (~15%) აფიქსირებს, ამიტომ ბოლო თვე ამ ცხრილებში თხელია. <b>Source:</b> new DB only; identity = the ID number typed on the application; monthly series from Jan 2024, daily from ' + dayLabel(C.dayStart) + '; timestamps exact from the ' + C.cutover + ' cutover, migrated (&plusmn;1 day) before it. Generated ' + C.generatedAt + '.';
  document.getElementById('custPidCov').textContent = pct((S.applications - S.appsWithoutPid) / S.applications);
  document.getElementById('custKpis').innerHTML = [[S.customers, 'Customers (all time)'], [S.activeCustomers, 'With an active loan'], [S.loanCustomers, 'Ever had a loan'], [S.applications, 'Applications (all time)'], [S.loans, 'Loans (all time)'], [S.activeLoans, 'Active loans']]
    .map(([v, l]) => '<div class="cust-kpi"><div class="cust-kpi-v">' + fmt(v) + '</div><div class="cust-kpi-l">' + l + '</div></div>').join('');

  // ---- customer-level dimension tables (logi-mini)
  function renderDim(d) {
    const T = d.total;
    let h = '<tr class="logi-mini-title"><td colspan="8">' + d.title + '</td></tr>';
    h += '<tr class="logi-mini-head"><td>' + d.head + '</td><td>Customers</td><td>%</td><td>With active loan</td><td>%</td><td>Applications ' + curLabel + ' (MTD)</td><td>%</td><td>Active-loan share</td></tr>';
    d.rows.forEach((r, i) => { h += '<tr class="logi-mini-data' + (i % 2 ? ' logi-mini-alt' : '') + '"><td>' + r.label + '</td><td>' + n(r.all) + '</td><td>' + p(r.all, T.all) + '</td><td>' + n(r.active) + '</td><td>' + p(r.active, T.active) + '</td><td>' + n(r.mtd) + '</td><td>' + p(r.mtd, T.mtd) + '</td><td>' + p(r.active, r.all) + '</td></tr>'; });
    h += '<tr class="logi-mini-total"><td>Total</td><td>' + n(T.all) + '</td><td>' + p(T.all, T.all) + '</td><td>' + n(T.active) + '</td><td>' + p(T.active, T.active) + '</td><td>' + n(T.mtd) + '</td><td>' + p(T.mtd, T.mtd) + '</td><td>' + p(T.active, T.all) + '</td></tr>';
    document.getElementById('custTable_' + d.key).querySelector('tbody').innerHTML = h;
    document.getElementById('custCov_' + d.key).innerHTML = 'Coverage (share with a value): ' + pct(d.coverage.all) + ' of customers, ' + pct(d.coverage.active) + ' of active-loan customers, ' + pct(d.coverage.mtd) + ' of ' + curLabel + ' applications' + (d.coverage.all < 0.3 ? ' &mdash; <b>low coverage, read the distribution of the rated part only</b>' : '') + '.';
    if (!d.monthly) return;
    const M = d.months, k = C.months.length, last = M.total[k - 1] || 0, from12 = Math.max(0, k - 12);
    const sum12 = v => v.slice(from12).reduce((a, b) => a + b, 0), tot12 = sum12(M.total);
    const ex = (v, i) => '<td class="logi-extra' + (i === 0 ? ' logi-extra-first' : '') + '">' + v + '</td>';
    const extras = v => [p(v[k - 1], last), n(sum12(v)), p(sum12(v), tot12)].map(ex).join('');
    const heads = C.months.map((m, i) => '<td>' + monthLabel(m) + (i === k - 1 ? ' (MTD)' : '') + '</td>').join('') + ['Share ' + curLabel, 'Last 12 months', 'Share 12 m'].map(ex).join('');
    let mh = '<tr class="logi-title"><td colspan="' + (k + 4) + '">Applications by month &mdash; ' + d.title + ' (flow: applications submitted that month)</td></tr>';
    mh += '<tr class="logi-head"><td>' + d.head + '</td>' + heads + '</tr>';
    M.rows.forEach((r, i) => { mh += '<tr class="' + (i % 2 ? 'logi-light' : 'logi-white') + '"><td>' + r.label + '</td>' + r.vals.map(v => '<td>' + n(v) + '</td>').join('') + extras(r.vals) + '</tr>'; });
    mh += '<tr class="logi-total"><td>Total applications</td>' + M.total.map(v => '<td>' + n(v) + '</td>').join('') + extras(M.total) + '</tr>';
    document.getElementById('custMonthTable_' + d.key).querySelector('tbody').innerHTML = mh;
  }
  C.dims.forEach(renderDim);

  // ---- new vs returning
  function renderNr(tableId, D, labelOf, lastName) {
    const k = D.keys.length, last = D.total.apps[k - 1] || 0, lastLoan = D.total.loans[k - 1] || 0;
    const ex = (v, i) => '<td class="logi-extra' + (i === 0 ? ' logi-extra-first' : '') + '">' + v + '</td>';
    const heads = D.keys.map((x, i) => '<td>' + labelOf(x) + (i === k - 1 ? ' (' + lastName + ')' : '') + '</td>').join('') + ex('Share ' + labelOf(D.keys[k - 1]), 0);
    const row = (cls, label, vals, tot) => '<tr class="' + cls + '"><td>' + label + '</td>' + vals.map(v => '<td>' + n(v) + '</td>').join('') + ex(p(vals[k - 1], tot), 0) + '</tr>';
    let h = '<tr class="logi-title"><td colspan="' + (k + 2) + '">Applications by customer type (flow: applications submitted in the period)</td></tr>';
    h += '<tr class="logi-head"><td>Customer type</td>' + heads + '</tr>';
    D.rows.forEach((r, i) => { h += row(i % 2 ? 'logi-light' : 'logi-white', r.label, r.apps, last); });
    h += row('logi-total', 'Total applications', D.total.apps, last);
    h += '<tr class="logi-sub"><td colspan="' + (k + 2) + '">of which became a loan (same applications, by their outcome so far)</td></tr>';
    D.rows.forEach((r, i) => { h += row(i % 2 ? 'logi-light' : 'logi-white', r.label + ' &rarr; loan', r.loans, lastLoan); });
    h += row('logi-total', 'Total loans', D.total.loans, lastLoan);
    h += '<tr class="logi-plain"><td>Loan conversion, all types</td>' + D.keys.map((_, i) => '<td>' + p(D.total.loans[i], D.total.apps[i]) + '</td>').join('') + ex('', 0) + '</tr>';
    h += '<tr class="logi-plain"><td>Loan conversion, new applicants</td>' + D.keys.map((_, i) => '<td>' + p(D.rows[0].loans[i], D.rows[0].apps[i]) + '</td>').join('') + ex('', 0) + '</tr>';
    document.getElementById(tableId).querySelector('tbody').innerHTML = h;
  }
  renderNr('custNrMonthTable', C.newret.month, monthLabel, 'MTD');
  renderNr('custNrDayTable', C.newret.day, dayLabel, 'today');
  document.getElementById('custNrMonthNote').innerHTML = 'New = the person\\'s first application ever (identity = the ID number on the form). Returning (earlier application only) = had applied before but had never had a loan. Returning (previous loan) = had at least one loan before this application. Keyed to the application date (<code>orders.created_at</code>); months from Jan 2024 (' + fmt(S.appsBeforeSeries) + ' older applications back to ' + S.firstApp + ' are counted in the customer totals and in the "returning" history, not in the columns). Loan conversion = loans so far &divide; applications of that period &mdash; recent months are still converting.';
  document.getElementById('custNrDayNote').innerHTML = 'Same definitions by day, from ' + dayLabel(C.dayStart) + ' to today. Application timestamps are exact from the ' + C.cutover + ' cutover (created in the new CRM); before it they are the dates migrated from the old CRM, which can differ from the old application date by a day. By-day tables are not built for the demographic dimensions &mdash; with ~100&ndash;150 applications a day the daily split by age or city is noise; use the by-month tables above.';

  // ---- histograms
  function renderHist(tableId, H, title, head) {
    let h = '<tr class="logi-mini-title"><td colspan="5">' + title + '</td></tr><tr class="logi-mini-head"><td>' + head + '</td><td>Customers</td><td>%</td><td>With active loan</td><td>%</td></tr>';
    H.rows.forEach((r, i) => { h += '<tr class="logi-mini-data' + (i % 2 ? ' logi-mini-alt' : '') + '"><td>' + r.label + '</td><td>' + n(r.all) + '</td><td>' + p(r.all, H.total.all) + '</td><td>' + n(r.active) + '</td><td>' + p(r.active, H.total.active) + '</td></tr>'; });
    h += '<tr class="logi-mini-total"><td>Total</td><td>' + n(H.total.all) + '</td><td>' + p(H.total.all, H.total.all) + '</td><td>' + n(H.total.active) + '</td><td>' + p(H.total.active, H.total.active) + '</td></tr>';
    document.getElementById(tableId).querySelector('tbody').innerHTML = h;
  }
  renderHist('custHistApps', C.hist.apps, 'Applications per customer (all time)', 'Applications');
  renderHist('custHistLoans', C.hist.loans, 'Loans per customer (all time)', 'Loans');

  // ---- payment behaviour
  function renderPay(tableId, P, title, head) {
    let h = '<tr class="logi-mini-title"><td colspan="3">' + title + '</td></tr><tr class="logi-mini-head"><td>' + head + '</td><td>Customers</td><td>%</td></tr>';
    P.rows.forEach((r, i) => { h += '<tr class="logi-mini-data' + (i % 2 ? ' logi-mini-alt' : '') + '"><td>' + r.label + '</td><td>' + n(r.n) + '</td><td>' + p(r.n, P.total) + '</td></tr>'; });
    h += '<tr class="logi-mini-total"><td>Total</td><td>' + n(P.total) + '</td><td>' + p(P.total, P.total) + '</td></tr>';
    document.getElementById(tableId).querySelector('tbody').innerHTML = h;
  }
  const PS = C.paystats;
  renderPay('custPayWorst', PS.worst, 'Worst delinquency ever (max days late)', 'Worst bucket');
  renderPay('custPayDpd', PS.dpd, 'Currently overdue (days past due today)', 'Days past due');
  renderPay('custPayOntime', PS.ontime, 'On-time payment rate (settled instalments)', 'On-time rate');
  document.getElementById('custPayNote').innerHTML = '<b>Coverage:</b> <code>crm_customer_payment_stats</code> is a per-customer cache the new CRM computes when it opens a customer (computed ' + PS.coverage.from + ' to ' + PS.coverage.to + '), so it holds ' + fmt(PS.coverage.rows) + ' customers &mdash; ' + fmt(PS.coverage.activeWithStats) + ' of the ' + fmt(PS.coverage.activeByAccount) + ' active-loan customers with an account (' + pct(PS.coverage.activeWithStats / PS.coverage.activeByAccount) + '), and it is not a random sample (customers staff looked at). Read it as a distribution of the computed part, not of the whole book. Worst bucket = the CRM\\'s <code>worst_bucket</code> code 1&ndash;6, decoded from the <code>max_days_late</code> ranges found in each bucket (assumption: the code names the band of the worst lateness). Days past due = <code>current_dpd</code> where <code>currently_overdue = 1</code>. On-time rate = <code>on_time_rate</code>, only meaningful where at least one instalment has been settled (<code>settled_count &gt; 0</code>).';

  window.custScrollUpdaters = [setupTopScrollSync('custNrMonthTop', 'custNrMonthBody'), setupTopScrollSync('custNrDayTop', 'custNrDayBody')]
    .concat(C.dims.filter(d => d.monthly).map(d => setupTopScrollSync('custMonth_' + d.key + '_Top', 'custMonth_' + d.key + '_Body')));
});
${JS_END}`;
must('// ---- top-level page nav (grows as more reports get added) ----', 'nav handler');
html = html.replace('// ---- top-level page nav (grows as more reports get added) ----', js.replace(/^\n/, '') + NL + '// ---- top-level page nav (grows as more reports get added) ----');
must("  if (btn.dataset.page === 'subcategoryanalyze') subcategoryScrollUpdate();", 'nav updaters');
if (!html.includes("btn.dataset.page === 'customers'")) html = html.replace("  if (btn.dataset.page === 'subcategoryanalyze') subcategoryScrollUpdate();", "  if (btn.dataset.page === 'subcategoryanalyze') subcategoryScrollUpdate();" + NL + "  if (btn.dataset.page === 'customers' && window.custScrollUpdaters) window.custScrollUpdaters.forEach(f => f());");

if (__hadCRLF) html = html.replace(/\n/g, '\r\n');
fs.writeFileSync(htmlPath, html);
console.log('html:', htmlPath, 'bytes:', html.length);
})();

// ================ Collections — Collections Analyze ================
(function () {
// Collections Analyze for Volta_Analytics_New DB: turns the coll_*.tsv extracts (pull_coll.sh) into coll_data.json and
// injects it into the dashboard HTML (env DASH_HTML, default deals_amount_migration.html) as `const COLL_JSON = …;`,
// together with the "Collections" nav group, the page markup, a small CSS block and the render IIFE. Idempotent:
// every block is removed and re-injected on each run. Modelled on build_logistics.js.
const fs = require('fs');
const path = require('path');
const CUTOVER = '2026-08-31';
const MSTART = '2023-01';

function parseTsv(file) {
  let c = fs.readFileSync(path.join(__dirname, file), 'utf8');
  if (c.charCodeAt(0) === 0xFEFF) c = c.slice(1);
  const lines = c.split(/\r?\n/).filter(l => l.length);
  if (!lines.length) return [];
  const header = lines[0].split('\t');
  return lines.slice(1).map(line => { const cols = line.split('\t'); const o = {}; header.forEach((h, i) => o[h] = cols[i]); return o; });
}
const num = v => (v === '' || v === 'NULL' || v === undefined) ? null : +v;
const r2 = v => Math.round(v * 100) / 100;

// ---- calendars: days = cutover .. last day in the overdue series (today); months = MSTART .. current month
const odRows = parseTsv('coll_od_rows.tsv'), odLoans = parseTsv('coll_od_loans.tsv');
const days = [...new Set(odRows.map(r => r.d))].sort();
const today = days.at(-1);
const months = [];
for (let m = MSTART; m <= today.slice(0, 7); ) { months.push(m); let [y, mo] = m.split('-').map(Number); mo++; if (mo > 12) { mo = 1; y++; } m = y + '-' + String(mo).padStart(2, '0'); }
const zeros = n => Array.from({ length: n }, () => 0);
const dIdx = {}; days.forEach((d, i) => dIdx[d] = i);
const mIdx = {}; months.forEach((m, i) => mIdx[m] = i);

// ---- overdue portfolio (stock, end of day)
const BUCKETS = [[1, '1–3 days'], [2, '4–10 days'], [3, '11–30 days'], [4, '31–60 days'], [5, '61–90 days'], [6, '90+ days']];
const od = { days, buckets: BUCKETS.map(([, l]) => l), rowsByBucket: BUCKETS.map(() => zeros(days.length)), amtByBucket: BUCKETS.map(() => zeros(days.length)),
  rows: zeros(days.length), amt: zeros(days.length), amtActive: zeros(days.length), amtLegacy: zeros(days.length), rowsActive: zeros(days.length), rowsLegacy: zeros(days.length),
  loansByWb: BUCKETS.map(() => zeros(days.length)), loans: zeros(days.length), loansActive: zeros(days.length), loansLegacy: zeros(days.length), pm: {} };
for (const r of odRows) {
  const i = dIdx[r.d], b = +r.b - 1, rows = +r.rows_od, amt = +r.amt, act = r.act === '1';
  od.rowsByBucket[b][i] += rows; od.amtByBucket[b][i] += amt; od.rows[i] += rows; od.amt[i] += amt;
  if (act) { od.amtActive[i] += amt; od.rowsActive[i] += rows; } else { od.amtLegacy[i] += amt; od.rowsLegacy[i] += rows; }
}
const pmRows = parseTsv('coll_pm.tsv');
const pmName = {}; for (const p of pmRows) pmName[p.pm] = p.name;
for (const r of odLoans) {
  const i = dIdx[r.d], b = +r.wb - 1, loans = +r.loans, amt = +r.amt, act = r.act === '1';
  od.loansByWb[b][i] += loans; od.loans[i] += loans; if (act) od.loansActive[i] += loans; else od.loansLegacy[i] += loans;
  const pm = od.pm[r.pm] ||= { name: pmName[r.pm] || ('PM ' + r.pm), loans: zeros(days.length), amt: zeros(days.length), amt90: zeros(days.length), loans90: zeros(days.length) };
  pm.loans[i] += loans; pm.amt[i] += amt; if (+r.wb === 6) { pm.amt90[i] += amt; pm.loans90[i] += loans; }
}
for (const k of Object.keys(od)) if (Array.isArray(od[k]) && typeof od[k][0] === 'number') od[k] = od[k].map(r2);
for (const b of od.amtByBucket) b.forEach((v, i) => b[i] = r2(v));
for (const p of Object.values(od.pm)) { p.amt = p.amt.map(r2); p.amt90 = p.amt90.map(r2); }
// month-end view of the same stock: the last day of each calendar month present in the day series (cutover month = 31 Aug, current month = today)
const monthEnds = []; const seen = {};
for (let i = days.length - 1; i >= 0; i--) { const m = days[i].slice(0, 7); if (!seen[m]) { seen[m] = true; monthEnds.unshift({ m, d: days[i], i }); } }
od.monthEnds = monthEnds;

// ---- cash collected (flow) by payment date, day + month, with scheduled dues on the same key
const PAYK = ['n', 'loans', 'amt', 'prin', 'pen', 'adv', 'bog', 'tbc', 'tbcpay', 'other'];
function paySeries(rows, keys, idx) {
  const s = {}; for (const k of PAYK) s[k] = zeros(keys.length);
  for (const r of rows) { const i = idx[r[keys === days ? 'd' : 'm']]; if (i === undefined) continue; for (const k of PAYK) s[k][i] += +r[k]; }
  for (const k of PAYK) s[k] = s[k].map(r2);
  return s;
}
const payDay = paySeries(parseTsv('coll_pay_day.tsv'), days, dIdx), payMonth = paySeries(parseTsv('coll_pay_month.tsv'), months, mIdx);
const dueDayRows = parseTsv('coll_due_day.tsv'), dueMonthRows = parseTsv('coll_due_month.tsv');
const DUEK = ['rows_due', 'amt', 'loans', 'ontime_rows', 'ontime_amt', 'late_rows', 'late_amt', 'open_rows', 'open_amt'];
function dueSeries(rows, keys, idx, keyName) {
  const s = {}; for (const k of DUEK) s[k] = zeros(keys.length);
  for (const r of rows) { const i = idx[r[keyName]]; if (i === undefined) continue; for (const k of DUEK) if (r[k] !== undefined) s[k][i] += +r[k]; }
  for (const k of DUEK) s[k] = s[k].map(r2);
  return s;
}
const dueDay = dueSeries(dueDayRows, days, dIdx, 'd'), dueMonth = dueSeries(dueMonthRows, months, mIdx, 'm');
// due-date performance is only knowable for due dates since the cutover: month view = the day rows summed per month
const perfMonths = [...new Set(days.map(d => d.slice(0, 7)))];
const perfMonth = {}; for (const k of DUEK) perfMonth[k] = zeros(perfMonths.length);
dueDayRows.forEach(r => { const i = perfMonths.indexOf(r.d.slice(0, 7)); if (i < 0) return; for (const k of DUEK) perfMonth[k][i] += +r[k]; });
for (const k of DUEK) perfMonth[k] = perfMonth[k].map(r2);
const reversed = { n: zeros(days.length), amt: zeros(days.length), nMonth: zeros(months.length), amtMonth: zeros(months.length) };
for (const r of parseTsv('coll_reversed.tsv')) { const i = dIdx[r.d], j = mIdx[r.d.slice(0, 7)]; if (i !== undefined) { reversed.n[i] += +r.n; reversed.amt[i] += +r.amt; } if (j !== undefined) { reversed.nMonth[j] += +r.n; reversed.amtMonth[j] += +r.amt; } }

// ---- collections activity (flow), long format -> metric series by day and by month (months present in the day window)
const actRows = parseTsv('coll_activity.tsv');
const activity = { days, months: perfMonths, metrics: {} };
for (const r of actRows) {
  const m = activity.metrics[r.metric] ||= { day: zeros(days.length), month: zeros(perfMonths.length), amtDay: zeros(days.length), amtMonth: zeros(perfMonths.length) };
  const i = dIdx[r.d], j = perfMonths.indexOf(r.d.slice(0, 7)); if (i === undefined) continue;
  m.day[i] += +r.n; m.amtDay[i] += +r.amt; if (j >= 0) { m.month[j] += +r.n; m.amtMonth[j] += +r.amt; }
}

// ---- today's snapshots
const todayI = days.length - 1;
const pm = pmRows.map(p => ({ pm: p.pm, name: p.name, activeLoans: +p.active_loans, openLoans: +p.open_loans, outstanding: +p.outstanding,
  odLoans: od.pm[p.pm] ? od.pm[p.pm].loans[todayI] : 0, odAmt: od.pm[p.pm] ? od.pm[p.pm].amt[todayI] : 0, od90Amt: od.pm[p.pm] ? od.pm[p.pm].amt90[todayI] : 0 }));
const status = parseTsv('coll_status.tsv').map(r => ({ id: +r.sid, name: r.name, loans: +r.loans, outstanding: +r.outstanding, odLoans: +r.od_loans, odAmt: +r.od_amt }));
const CPS_LABEL = { 0: 'Never overdue / not scored', 1: '1–3 days', 2: '4–10 days', 3: '11–30 days', 4: '31–60 days', 5: '61–90 days', 6: '90+ days' };
const cps = parseTsv('coll_cps.tsv').map(r => ({ wb: +r.wb, label: CPS_LABEL[+r.wb] || ('Bucket ' + r.wb), customers: +r.customers, curOd: +r.cur_od, onTimeRate: num(r.on_time_rate), avgDaysLate: num(r.avg_days_late),
  promisesMade: +r.promises_made, promisesKept: +r.promises_kept, promisesBroken: +r.promises_broken, totalPaid: +r.total_paid, penaltyPaid: +r.penalty_paid, computedAt: r.computed_at }));
const meta = {}; for (const r of parseTsv('coll_meta.tsv')) meta[r.t] = +r.n;

const payload = { cutover: CUTOVER, mstart: MSTART, today, days, months, od, payDay, payMonth, dueDay, dueMonth, perfMonths, perfMonth, reversed, activity, pm, status, cps, meta,
  generatedAt: new Date().toISOString().slice(0, 16).replace('T', ' ') + ' UTC' };
fs.writeFileSync(path.join(__dirname, 'coll_data.json'), JSON.stringify(payload));
console.log('days:', days.length, days[0], '->', today, '| months:', months.length, '| overdue today: rows', od.rows.at(-1), 'amt', od.amt.at(-1), 'loans', od.loans.at(-1),
  '| collected today', payDay.amt.at(-1), '| month cols', months.at(-1), payMonth.amt.at(-1), '| activity metrics', Object.keys(activity.metrics).length, '| pm', pm.length, 'status', status.length, 'cps', cps.length);

// ---------------- inject into the HTML ----------------
const htmlPath = process.env.DASH_HTML ? path.resolve(process.env.DASH_HTML) : path.join(__dirname, 'deals_amount_migration.html');
let html = fs.readFileSync(htmlPath, 'utf8');
const __hadCRLF = html.includes('\r\n'); if (__hadCRLF) html = html.replace(/\r\n/g, '\n'); // tolerate a git-checked-out CRLF file (Windows); every marker below assumes plain LF
const NL = String.fromCharCode(10);
const must = (n, what) => { if (!html.includes(n)) throw new Error('anchor not found: ' + what); };
// idempotence: both marks are matched WITH their leading newline and the trailing newline after the end mark is kept, so a
// remove + re-insert leaves the surrounding lines byte-identical (same convention as build_logistics.js)
function removeBetween(startMark, endMark, what) {
  const s = html.indexOf(NL + startMark);
  if (s < 0) return;
  const e = html.indexOf(NL + endMark, s);
  if (e < 0) throw new Error(what + ' end marker not found');
  html = html.slice(0, s) + html.slice(e + (NL + endMark).length);
}

// CSS — only what the logi-* classes do not already give us (KPI strip + mini-table grid); every rule sets background AND colour
const CSS_MARK = '/* --- coll --- */', CSS_END = '/* --- /coll --- */';
const css = `
${CSS_MARK}
.coll-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin: 8px 0 14px; }
.coll-kpi { background: #1a1a34; color: #c2ff00; border-radius: 10px; padding: 10px 12px; }
.coll-kpi .coll-kpi-label { color: #fff; font-size: 11px; font-weight: 600; opacity: .85; }
.coll-kpi .coll-kpi-value { color: #c2ff00; font-size: 20px; font-weight: 700; font-variant-numeric: tabular-nums; margin-top: 2px; }
.coll-kpi .coll-kpi-sub { color: #fff; font-size: 11px; opacity: .8; margin-top: 2px; }
.coll-mini-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media (max-width: 900px) { .coll-mini-row { grid-template-columns: 1fr; } }
.coll-sub-title { color: var(--text-primary); font-weight: 700; font-size: 13px; margin: 10px 0 -4px; }
${CSS_END}`;
removeBetween(CSS_MARK, CSS_END, 'coll css');
must('.report-scroll-top > div { height: 1px; }', 'css anchor');
html = html.replace('.report-scroll-top > div { height: 1px; }', '.report-scroll-top > div { height: 1px; }' + css);

// nav group
const NAV_START = '<!-- coll-nav-start -->', NAV_END = '<!-- coll-nav-end -->';
// static: inserted once (so the group keeps its place in the nav on later data-only rebuilds)
if (!html.includes(NAV_START)) {
  const navAnchor = '  </div>' + NL + '</div>' + NL + NL + '<div class="page active" data-page="report">';
  must(navAnchor, 'nav anchor');
  html = html.replace(navAnchor, `    ${NAV_START}
    <div class="nav-group">
      <div class="nav-group-title">Collections</div>
      <div class="nav-group-items">
        <button data-page="collections">Collections Analyze</button>
      </div>
    </div>
    ${NAV_END}
` + navAnchor);
}

// page
const PAGE_START = '<!-- coll-page-start -->', PAGE_END = '<!-- coll-page-end -->';
removeBetween(PAGE_START, PAGE_END, 'coll page');
{
  const card = (id) => `  <div class="report-card">
    <div class="report-scroll-top" id="${id}ScrollTop"><div></div></div>
    <div class="report-scroll" id="${id}ScrollBody"><table class="logi-table" id="${id}Table"><tbody></tbody></table></div>
  </div>`;
  const page = `
${PAGE_START}
<div class="page" data-page="collections" id="page-collections">
<div class="wrap">
  <p class="section-title">Collections Analyze &mdash; Payments, Overdue Portfolio &amp; Collection Activity</p>
  <div class="banner" id="collBanner"></div>
  <div class="coll-kpis" id="collKpis"></div>

  <p class="coll-sub-title">Cash Collected &mdash; by day (flow, by payment date)</p>
${card('collPayDay')}
  <p class="coll-sub-title">Cash Collected &mdash; by month</p>
${card('collPayMonth')}
  <p class="note" id="collPayNote"></p>

  <p class="coll-sub-title">Overdue Portfolio &mdash; by day (stock, end of day)</p>
${card('collOdDay')}
  <p class="coll-sub-title">Overdue Portfolio &mdash; by month (stock, month end)</p>
${card('collOdMonth')}
  <p class="note" id="collOdNote"></p>

  <p class="coll-sub-title">Overdue Loans &mdash; by portfolio manager (stock, end of day)</p>
${card('collPmDay')}
  <p class="note" id="collPmNote"></p>

  <p class="coll-sub-title">Due-Date Performance &mdash; by due date (schedule rows due that day)</p>
${card('collDueDay')}
  <p class="coll-sub-title">Due-Date Performance &mdash; by due month</p>
${card('collDueMonth')}
  <p class="note" id="collDueNote"></p>

  <p class="coll-sub-title">Collection Activity &mdash; by day (flow)</p>
${card('collActDay')}
  <p class="coll-sub-title">Collection Activity &mdash; by month</p>
${card('collActMonth')}
  <p class="note" id="collActNote"></p>

  <p class="coll-sub-title">Today's snapshots</p>
  <div class="coll-mini-row">
    <div class="table-card"><table class="logi-mini" id="collPmTable"><colgroup><col class="logi-mini-name"></colgroup><tbody></tbody></table></div>
    <div class="table-card"><table class="logi-mini" id="collStatusTable"><colgroup><col class="logi-mini-name"></colgroup><tbody></tbody></table></div>
  </div>
  <div class="coll-mini-row" style="margin-top:14px">
    <div class="table-card"><table class="logi-mini" id="collCpsTable"><colgroup><col class="logi-mini-name"></colgroup><tbody></tbody></table></div>
    <div class="table-card"><table class="logi-mini" id="collMetaTable"><colgroup><col class="logi-mini-name"></colgroup><tbody></tbody></table></div>
  </div>
  <p class="note" id="collSnapNote"></p>
</div>
</div>
${PAGE_END}
`;
  const li = html.indexOf('id="page-logistics"'); if (li < 0) throw new Error('page-logistics not found');
  const si = html.indexOf('<script>', li); if (si < 0) throw new Error('script after page-logistics not found');
  html = html.slice(0, si) + page.replace(/^\n/, '') + html.slice(si);
}

// data line
const dataLine = 'var COLL_JSON = ' + JSON.stringify(payload) + ';';
if (/^(?:const|var) COLL_JSON = .*;$/m.test(html)) html = html.replace(/^(?:const|var) COLL_JSON = .*;$/m, () => dataLine);
else { must('const generatedAt = REPORT_JSON.generatedAt;', 'report consts'); html = html.replace('const generatedAt = REPORT_JSON.generatedAt;', () => 'const generatedAt = REPORT_JSON.generatedAt;' + NL + dataLine); }

// render IIFE
const JS_MARK = '/* ---------- coll ---------- */', JS_END = '/* ---------- /coll ---------- */';
removeBetween(JS_MARK, JS_END, 'coll js');
const js = `
${JS_MARK}
window.__registerPage(['collections'], function () {
  const C = COLL_JSON, n = C.days.length, DASH = '&ndash;';
  const dayLabel = d => { const [, m, day] = d.split('-').map(Number); return MONTH_NAMES[m - 1] + ' ' + day; };
  const monthLabel = m => MONTH_NAMES[Number(m.slice(5, 7)) - 1] + ' ' + m.slice(0, 4);
  const money = v => (v === null || v === undefined) ? DASH : fmt(v);
  const cnt = v => (v === null || v === undefined || v === 0) ? DASH : fmt(v);
  const sum = arr => arr.reduce((t, v) => t + (v || 0), 0);
  const cell = (v, kind) => '<td>' + (kind === 'pct' ? (v === null || v === undefined ? DASH : pct(v)) : kind === 'cnt' ? cnt(v) : kind === 'num1' ? (v === null || v === undefined ? DASH : Number(v).toFixed(1)) : money(v)) + '</td>';
  const exCell = (v, i) => '<td class="logi-extra' + (i === 0 ? ' logi-extra-first' : '') + '">' + v + '</td>';
  // generic series table: cols = [{label}], rows = [{cls, label, vals, kind, share}] ; share = row value in the last column ÷ base last value
  function renderSeries(id, title, headLabel, cols, rows, shareBase, shareLabel) {
    const k = cols.length;
    const heads = cols.map(c => '<td>' + c + '</td>').join('') + (shareLabel ? exCell(shareLabel, 0) : '');
    let h = '<tr class="logi-title"><td colspan="' + (k + 1 + (shareLabel ? 1 : 0)) + '">' + title + '</td></tr>';
    h += '<tr class="logi-head"><td>' + headLabel + '</td>' + heads + '</tr>';
    rows.forEach(r => {
      if (r.sub) { h += '<tr class="logi-sub"><td colspan="' + (k + 1 + (shareLabel ? 1 : 0)) + '">' + r.label + '</td></tr>'; return; }
      const ex = shareLabel ? exCell(r.share ? pct(shareBase && shareBase[k - 1] ? (r.vals[k - 1] || 0) / shareBase[k - 1] : 0) : DASH, 0) : '';
      h += '<tr class="' + r.cls + '"><td>' + r.label + '</td>' + r.vals.map(v => cell(v, r.kind)).join('') + ex + '</tr>';
    });
    document.getElementById(id + 'Table').querySelector('tbody').innerHTML = h;
  }
  const dayCols = C.days.map((d, i) => dayLabel(d) + (i === n - 1 ? ' (today)' : ''));
  const monthCols = C.months.map((m, i) => monthLabel(m) + (i === C.months.length - 1 ? ' (MTD)' : ''));
  const alt = i => i % 2 ? 'logi-light' : 'logi-white';
  const ratio = (a, b) => a.map((v, i) => b[i] ? v / b[i] : null);

  document.getElementById('collBanner').innerHTML = '<b>წყარო:</b> ახალი CRM-ის სესხების მოდული (VoltaStoreDB: <code>crm_payments</code> გადახდები, <code>crm_installment_schedules</code> გადახდის გრაფიკი, <code>crm_payment_events</code> გადახდა/დაფარვის ლოგი, <code>crm_case_calls</code> / <code>crm_sms_history</code> / <code>crm_promises</code> / <code>crm_activity_log</code> კოლექშენის აქტივობა). '
    + '<b>რა პერიოდია რეალური:</b> გადახდები და გრაფიკის ვადები 2023 იანვრიდან სრულადაა მიგრირებული (თვეების ცხრილები აქედან იწყება); ვადაგადაცილების დღიური ისტორია, ვადის დაცვა და კოლექშენის აქტივობა მხოლოდ ' + C.cutover + '-დან არსებობს (CRM-ის ლოგები აქ იწყება), ამიტომ დღეების ცხრილები ' + C.cutover + '-დან დღემდე მიდის. '
    + 'Amounts in GEL. Day = <code>payment_date</code> / <code>schedule_date</code> (calendar dates); activity timestamps are grouped by their UTC date. Last refresh: ' + C.generatedAt + '.';

  // KPI strip (today)
  const O = C.od, t = n - 1;
  const kpis = [
    ['Overdue amount (today)', fmt(O.amt[t]) + ' GEL', fmt(O.rows[t]) + ' schedule rows'],
    ['Overdue loans (today)', fmt(O.loans[t]), fmt(O.loansActive[t]) + ' on CRM-active loans'],
    ['90+ days overdue', fmt(O.amtByBucket[5][t]) + ' GEL', fmt(O.loansByWb[5][t]) + ' loans'],
    ['Collected yesterday', fmt(C.payDay.amt[t - 1] || 0) + ' GEL', fmt(C.payDay.n[t - 1] || 0) + ' payments'],
    ['Collected ' + monthLabel(C.months[C.months.length - 1]) + ' (MTD)', fmt(C.payMonth.amt.at(-1)) + ' GEL', 'due ' + fmt(C.dueMonth.amt.at(-1)) + ' GEL'],
    ['On-time rate since cutover', pct(sum(C.perfMonth.ontime_rows) / Math.max(1, sum(C.perfMonth.rows_due))), 'of schedule rows due since ' + dayLabel(C.cutover)],
  ];
  document.getElementById('collKpis').innerHTML = kpis.map(k => '<div class="coll-kpi"><div class="coll-kpi-label">' + k[0] + '</div><div class="coll-kpi-value">' + k[1] + '</div><div class="coll-kpi-sub">' + k[2] + '</div></div>').join('');

  // ---- cash collected
  function payRows(P, D, isMonth) {
    const rows = [
      { cls: 'logi-total', label: 'Amount collected (GEL)', vals: P.amt, share: true },
      { cls: 'logi-white', label: 'Principal (excl. advance)', vals: P.prin, share: true },
      { cls: 'logi-light', label: 'Advance / downpayment', vals: P.adv, share: true },
      { cls: 'logi-white', label: 'Penalty', vals: P.pen, share: true },
      { sub: true, label: 'By bank / channel' },
      { cls: 'logi-white', label: 'BOG', vals: P.bog, share: true },
      { cls: 'logi-light', label: 'TBC', vals: P.tbc, share: true },
      { cls: 'logi-white', label: 'TBCPay', vals: P.tbcpay, share: true },
      { cls: 'logi-light', label: 'Other (cash desk, PayBox, TBC UFC, other)', vals: P.other, share: true },
      { sub: true, label: 'Counts and scheduled dues' },
      { cls: 'logi-plain', label: 'Payments (count)', vals: P.n, kind: 'cnt' },
      { cls: 'logi-plain', label: 'Paying loans (distinct)', vals: P.loans, kind: 'cnt' },
      { cls: 'logi-plain', label: 'Scheduled due (GEL, schedule rows due ' + (isMonth ? 'in the month' : 'that day') + ')', vals: D.amt },
      { cls: 'logi-plain', label: 'Scheduled due (rows)', vals: D.rows_due, kind: 'cnt' },
      { cls: 'logi-plain', label: 'Collected ÷ due (principal excl. advance)', vals: ratio(P.prin, D.amt), kind: 'pct' },
    ];
    if (isMonth) {
      rows.push({ cls: 'logi-plain', label: 'Of the amount due in the month: still unpaid today (GEL)', vals: D.open_amt });
      rows.push({ cls: 'logi-plain', label: 'Still unpaid today, % of due', vals: ratio(D.open_amt, D.amt), kind: 'pct' });
      rows.push({ cls: 'logi-plain', label: 'Payments reversed in the month (count)', vals: C.reversed.nMonth, kind: 'cnt' });
    } else {
      rows.push({ cls: 'logi-plain', label: 'Payments reversed that day (count)', vals: C.reversed.n, kind: 'cnt' });
    }
    return rows;
  }
  renderSeries('collPayDay', 'CASH COLLECTED &mdash; by day', 'Payment date', dayCols, payRows(C.payDay, C.dueDay, false), C.payDay.amt, 'Share ' + dayLabel(C.today));
  renderSeries('collPayMonth', 'CASH COLLECTED &mdash; by month', 'Payment month', monthCols, payRows(C.payMonth, C.dueMonth, true), C.payMonth.amt, 'Share ' + monthLabel(C.months.at(-1)));
  document.getElementById('collPayNote').innerHTML = 'Flow. Amount collected = <code>crm_payments.amount</code> by <code>payment_date</code>, excluding reversed payments (<code>reversed_at</code>) and the one 99,999,999.99 placeholder row the migration carried over (dated ' + C.cutover + '); Principal / Advance / Penalty = <code>principal_part &minus; advance</code>, <code>advance</code>, <code>penalty_part</code> (they add up to the amount; the advance is the downpayment taken when the deal is signed, so it is not a collection on an existing schedule). Bank = <code>crm_banks</code> via <code>bank_id</code>. Scheduled due = <code>SUM(schedule_amount)</code> of <code>crm_installment_schedules</code> rows whose <code>schedule_date</code> falls on that day / in that month (all loans, paid or not); Collected &divide; due compares principal collected with the amount that fell due in the same period (payments for older dues push it above 100%). "Still unpaid today" = the rows due in that month that are still open (<code>active = 1</code>) as of the last refresh, amount = <code>schedule_amount &minus; paid_amount</code> &mdash; a current-state view of how much of each month\\'s dues is still outstanding. The month series starts ' + monthLabel(C.mstart) + ' because the migrated schedule covers every loan from 2023 on (2022: 97%, earlier years only partly), so due vs collected is complete from there; payments themselves exist from Oct 2022. The share column is each row\\'s share of the last column\\'s total.';

  // ---- overdue portfolio
  function odRows(pick) {
    const P = arr => pick.map(i => arr[i]);
    const rows = [{ cls: 'logi-total', label: 'Overdue amount (GEL)', vals: P(O.amt), share: true }];
    O.buckets.forEach((b, i) => rows.push({ cls: alt(i), label: b + ' (amount)', vals: P(O.amtByBucket[i]), share: true }));
    rows.push({ cls: 'logi-plain', label: 'of which on CRM-active loans (crm_active = 1)', vals: P(O.amtActive), share: true });
    rows.push({ cls: 'logi-plain', label: 'of which on loans no longer active in the CRM (closed / legacy status)', vals: P(O.amtLegacy), share: true });
    rows.push({ sub: true, label: 'Overdue schedule rows (count)' });
    rows.push({ cls: 'logi-total', label: 'Overdue rows', vals: P(O.rows), kind: 'cnt', share: true });
    O.buckets.forEach((b, i) => rows.push({ cls: alt(i), label: b, vals: P(O.rowsByBucket[i]), kind: 'cnt', share: true }));
    rows.push({ sub: true, label: 'Overdue loans (distinct, by the loan\\'s oldest overdue row)' });
    rows.push({ cls: 'logi-total', label: 'Overdue loans', vals: P(O.loans), kind: 'cnt', share: true });
    O.buckets.forEach((b, i) => rows.push({ cls: alt(i), label: b, vals: P(O.loansByWb[i]), kind: 'cnt', share: true }));
    rows.push({ cls: 'logi-plain', label: 'of which CRM-active loans', vals: P(O.loansActive), kind: 'cnt', share: true });
    rows.push({ cls: 'logi-plain', label: 'of which no longer active in the CRM', vals: P(O.loansLegacy), kind: 'cnt', share: true });
    return rows;
  }
  // share for the row-count and loan-count sections must use their own totals: build per-section share by rendering with a base that switches on the row kind
  function renderOd(id, title, headLabel, cols, pick, shareLabel) {
    const rows = odRows(pick);
    const bases = { amt: pick.map(i => O.amt[i]), rows: pick.map(i => O.rows[i]), loans: pick.map(i => O.loans[i]) };
    let section = 'amt';
    const k = cols.length;
    let h = '<tr class="logi-title"><td colspan="' + (k + 2) + '">' + title + '</td></tr>';
    h += '<tr class="logi-head"><td>' + headLabel + '</td>' + cols.map(c => '<td>' + c + '</td>').join('') + exCell(shareLabel, 0) + '</tr>';
    rows.forEach(r => {
      if (r.sub) { section = r.label.startsWith('Overdue schedule rows') ? 'rows' : 'loans'; h += '<tr class="logi-sub"><td colspan="' + (k + 2) + '">' + r.label + '</td></tr>'; return; }
      const base = bases[section][k - 1];
      h += '<tr class="' + r.cls + '"><td>' + r.label + '</td>' + r.vals.map(v => cell(v, r.kind)).join('') + exCell(pct(base ? (r.vals[k - 1] || 0) / base : 0), 0) + '</tr>';
    });
    document.getElementById(id + 'Table').querySelector('tbody').innerHTML = h;
  }
  renderOd('collOdDay', 'OVERDUE PORTFOLIO &mdash; end of day', 'Days past due', dayCols, C.days.map((_, i) => i), 'Share ' + dayLabel(C.today));
  const me = O.monthEnds;
  renderOd('collOdMonth', 'OVERDUE PORTFOLIO &mdash; month end', 'Days past due', me.map((m, i) => monthLabel(m.m) + (i === me.length - 1 ? ' (MTD, ' + dayLabel(m.d) + ')' : ' (' + dayLabel(m.d) + ')')), me.map(m => m.i), 'Share ' + monthLabel(me.at(-1).m));
  document.getElementById('collOdNote').innerHTML = 'Stock at the end of each day. A schedule row (<code>crm_installment_schedules</code>) is overdue on day D when <code>schedule_date &lt; D</code> (days past due = D &minus; schedule_date, so a row due yesterday is 1 day overdue today) and it had not been settled by the end of D: rows still open at the last refresh (<code>active = 1</code>, i.e. <code>paid_amount &lt; schedule_amount</code>) were open on every earlier day of the window; rows settled since the cutover carry a <code>crm_payment_events.schedule_settled</code> event and count as open on the days before it; rows settled before the cutover (no event) are never overdue in this window. Amount = <code>schedule_amount &minus; paid_amount</code> as of D (partial payments posted after D are added back from the <code>schedule_partial</code> events). Today\\'s column is therefore exactly the live "<code>active = 1 AND schedule_date &lt; today</code>" figure (a settle event dated after the refresh day &mdash; the CRM holds one payment post-dated to 18 Sep 2026 &mdash; counts as settled on the refresh day, so the row leaves the stock here the same day it is gone live). Buckets are the CRM\\'s own DPD buckets (<code>dpd_bucket</code> 1&ndash;6 in <code>crm_payment_events</code>, boundaries verified against its <code>days_late</code>): 1&ndash;3 / 4&ndash;10 / 11&ndash;30 / 31&ndash;60 / 61&ndash;90 / 91+ days (labelled 90+ like the CRM); add them up for 1&ndash;30 / 31&ndash;60 / 61&ndash;90 / 90+. Overdue loans = distinct <code>installment_id</code> (= <code>orders.id</code>), each loan placed in the bucket of its oldest overdue row. "No longer active in the CRM" = the loan\\'s <code>orders.crm_active &ne; 1</code> (closed or legacy-status loans that still have unpaid schedule rows &mdash; mostly old 90+ cases); the CRM-active split is what the collectors work. The 22 payment reversals since the cutover are not unwound day by day (a reversed row simply counts as open on all days). Month view = the same stock on the last day of each month in the window (' + dayLabel(C.cutover) + ' = the cutover snapshot); no earlier month-end can be reconstructed, because the migrated schedule does not record when a row was paid.';

  // ---- overdue by portfolio manager (day series)
  {
    const pms = Object.values(O.pm).sort((a, b) => (a.name === 'Unassigned') - (b.name === 'Unassigned') || b.amt[t] - a.amt[t]);
    const rows = [{ cls: 'logi-total', label: 'Overdue amount (GEL)', vals: O.amt, share: true }];
    pms.forEach((p, i) => rows.push({ cls: alt(i), label: p.name, vals: p.amt, share: true }));
    rows.push({ sub: true, label: 'of which 90+ days (amount)' });
    pms.forEach((p, i) => rows.push({ cls: alt(i), label: p.name, vals: p.amt90, share: true }));
    rows.push({ sub: true, label: 'Overdue loans (count)' });
    rows.push({ cls: 'logi-total', label: 'Overdue loans', vals: O.loans, kind: 'cnt' });
    pms.forEach((p, i) => rows.push({ cls: alt(i), label: p.name, vals: p.loans, kind: 'cnt' }));
    renderSeries('collPmDay', 'OVERDUE BY PORTFOLIO MANAGER &mdash; end of day', 'Portfolio manager', dayCols, rows, O.amt, 'Share ' + dayLabel(C.today));
    document.getElementById('collPmNote').innerHTML = 'Same stock as the Overdue Portfolio table, split by the loan\\'s <b>current</b> portfolio manager (<code>orders.crm_portfolio_manager_id</code> &rarr; <code>crm_users</code>; 3 managers, auto-assigned by the CRM since 2 Sep 2026 by lowest workload &mdash; <code>crm_portfolio_assignments</code>). Assignment history is not replayed: a loan is shown under the manager it has today for every day. "Unassigned" = loans with no manager, mostly older loans; the share column is the manager\\'s share of today\\'s overdue amount. Manager names are staff, not customers.';
  }

  // ---- due-date performance
  function perfRows(D) {
    return [
      { cls: 'logi-total', label: 'Amount due (GEL)', vals: D.amt, share: true },
      { cls: 'logi-white', label: 'Paid on or before the due date', vals: D.ontime_amt, share: true },
      { cls: 'logi-light', label: 'Paid late (after the due date, by the last refresh)', vals: D.late_amt, share: true },
      { cls: 'logi-white', label: 'Still unpaid (as of the last refresh)', vals: D.open_amt, share: true },
      { sub: true, label: 'Schedule rows (count)' },
      { cls: 'logi-white', label: 'Rows due', vals: D.rows_due, kind: 'cnt' },
      { cls: 'logi-light', label: 'Loans with a due date', vals: D.loans, kind: 'cnt' },
      { cls: 'logi-white', label: 'Paid on time (rows)', vals: D.ontime_rows, kind: 'cnt' },
      { cls: 'logi-light', label: 'Paid late (rows)', vals: D.late_rows, kind: 'cnt' },
      { cls: 'logi-white', label: 'Still unpaid (rows)', vals: D.open_rows, kind: 'cnt' },
      { cls: 'logi-plain', label: 'On-time rate (rows paid by the due date &divide; rows due)', vals: ratio(D.ontime_rows, D.rows_due), kind: 'pct' },
      { cls: 'logi-plain', label: 'Collected so far (on time + late) &divide; amount due', vals: ratio(D.ontime_amt.map((v, i) => v + D.late_amt[i]), D.amt), kind: 'pct' },
    ];
  }
  renderSeries('collDueDay', 'DUE-DATE PERFORMANCE &mdash; by due date', 'Due date', dayCols, perfRows(C.dueDay), C.dueDay.amt, 'Share ' + dayLabel(C.today));
  const pmCols = C.perfMonths.map((m, i) => monthLabel(m) + (i === C.perfMonths.length - 1 ? ' (MTD, due dates to ' + dayLabel(C.today) + ')' : m === C.cutover.slice(0, 7) ? ' (' + dayLabel(C.cutover) + ' only)' : ''));
  renderSeries('collDueMonth', 'DUE-DATE PERFORMANCE &mdash; by due month', 'Due month', pmCols, perfRows(C.perfMonth), C.perfMonth.amt, 'Share ' + monthLabel(C.perfMonths.at(-1)));
  document.getElementById('collDueNote').innerHTML = 'Keyed to the due date (<code>schedule_date</code>), for due dates from the cutover on &mdash; the only period where the CRM records <i>when</i> a schedule row was paid. On time = the row is settled (<code>active = 0</code>) and its <code>schedule_settled</code> event is dated on or before the due date, or it has no event at all (settled before the cutover, i.e. prepaid). Late = settled by an event dated after the due date. Still unpaid = <code>active = 1</code> at the last refresh (amount = the unpaid remainder). The three amounts add up to the amount due. Recent due dates naturally show a low on-time rate until the late payments arrive; the "(today)" column is only the rows due today. The month view sums the day rows; ' + monthLabel(C.cutover.slice(0, 7)) + ' contains the cutover day only. Rows due before the cutover cannot be classified (their pre-cutover settlement dates were not migrated).';

  // ---- collection activity
  {
    const M = C.activity.metrics, get = k => M[k] || { day: C.days.map(() => 0), month: C.perfMonths.map(() => 0), amtDay: C.days.map(() => 0), amtMonth: C.perfMonths.map(() => 0) };
    const keysStarting = p => Object.keys(M).filter(k => k.startsWith(p)).sort();
    const SMS = { 'sms_მოვალეთა რეესტრი': 'მოვალეთა რეესტრი (debtors registry)', 'sms_შეუსაბამო მონაცემები': 'შეუსაბამო მონაცემები (inconsistent data)', 'sms_ვერ ვუკავშირდები': 'ვერ ვუკავშირდები (cannot reach)', 'sms_დასაფარია მიმდინარე': 'დასაფარია მიმდინარე (current instalment due)', 'sms_გადახდისუუნარო': 'გადახდისუუნარო (insolvent)' };
    function actRows(key) {
      const v = k => get(k)[key], rows = [];
      rows.push({ sub: true, label: 'Customer contacts (crm_case_calls, logged by collectors)' });
      const callKeys = keysStarting('call_').filter(k => k !== 'call_loans');
      rows.push({ cls: 'logi-total', label: 'Contacts logged', vals: C[key === 'day' ? 'days' : 'perfMonths'].map((_, i) => callKeys.reduce((s, k) => s + v(k)[i], 0)), kind: 'cnt' });
      callKeys.forEach((k, i) => rows.push({ cls: alt(i), label: 'Outcome: ' + k.slice(5).replace('_', ' '), vals: v(k), kind: 'cnt' }));
      keysStarting('chan_').forEach((k, i) => rows.push({ cls: alt(i), label: 'Channel: ' + k.slice(5), vals: v(k), kind: 'cnt' }));
      rows.push({ cls: 'logi-plain', label: 'Loans contacted (distinct)', vals: v('call_loans'), kind: 'cnt' });
      rows.push({ sub: true, label: 'Loan status changes by the collectors (crm_activity_log collections.contact_status &rarr; crm_installment_statuses)' });
      keysStarting('status_').forEach((k, i) => rows.push({ cls: alt(i), label: '&rarr; ' + k.slice(7), vals: v(k), kind: 'cnt' }));
      rows.push({ sub: true, label: 'SMS sent (crm_sms_history, by template)' });
      const smsKeys = keysStarting('sms_');
      rows.push({ cls: 'logi-total', label: 'SMS sent', vals: C[key === 'day' ? 'days' : 'perfMonths'].map((_, i) => smsKeys.reduce((s, k) => s + v(k)[i], 0)), kind: 'cnt' });
      smsKeys.forEach((k, i) => rows.push({ cls: alt(i), label: SMS[k] || k.slice(4), vals: v(k), kind: 'cnt' }));
      rows.push({ sub: true, label: 'Promises to pay (crm_promises)' });
      rows.push({ cls: 'logi-white', label: 'Promises made', vals: v('promise_made'), kind: 'cnt' });
      rows.push({ cls: 'logi-light', label: 'Promised amount (GEL)', vals: get('promise_made')[key === 'day' ? 'amtDay' : 'amtMonth'] });
      rows.push({ cls: 'logi-white', label: 'Promises kept (settled)', vals: v('promise_kept'), kind: 'cnt' });
      rows.push({ cls: 'logi-light', label: 'Promises broken', vals: v('promise_broken'), kind: 'cnt' });
      rows.push({ sub: true, label: 'Restructuring, reversals, assignments, reminders' });
      rows.push({ cls: 'logi-white', label: 'Restructure requests', vals: v('restructure_requested'), kind: 'cnt' });
      rows.push({ cls: 'logi-light', label: 'Restructure approved', vals: v('restructure_approved'), kind: 'cnt' });
      rows.push({ cls: 'logi-white', label: 'Payments reversed', vals: key === 'day' ? C.reversed.n : C.perfMonths.map(m => C.reversed.nMonth[C.months.indexOf(m)] || 0), kind: 'cnt' });
      rows.push({ cls: 'logi-light', label: 'Portfolio-manager assignments (auto)', vals: v('pm_assigned_pm'), kind: 'cnt' });
      rows.push({ cls: 'logi-white', label: 'Collector assignments', vals: v('pm_assigned_collector'), kind: 'cnt' });
      rows.push({ cls: 'logi-light', label: 'Reminders created', vals: v('reminder_created'), kind: 'cnt' });
      rows.push({ cls: 'logi-white', label: 'Reminders due that day', vals: v('reminder_due'), kind: 'cnt' });
      rows.push({ sub: true, label: 'CRM\\'s own daily overdue job (crm_payment_events)' });
      rows.push({ cls: 'logi-white', label: 'Rows entering overdue', vals: v('crm_overdue_entered'), kind: 'cnt' });
      rows.push({ cls: 'logi-light', label: 'Rows cured (overdue_cured)', vals: v('crm_overdue_cured'), kind: 'cnt' });
      rows.push({ cls: 'logi-white', label: 'Rows moving up a bucket', vals: v('crm_overdue_bucket_up'), kind: 'cnt' });
      rows.push({ cls: 'logi-light', label: 'Loans fully settled (case_settled)', vals: v('crm_case_settled'), kind: 'cnt' });
      return rows;
    }
    renderSeries('collActDay', 'COLLECTION ACTIVITY &mdash; by day', 'Activity', dayCols, actRows('day'), null, null);
    renderSeries('collActMonth', 'COLLECTION ACTIVITY &mdash; by month', 'Activity', C.perfMonths.map((m, i) => monthLabel(m) + (i === C.perfMonths.length - 1 ? ' (MTD)' : '')), actRows('month'), null, null);
    document.getElementById('collActNote').innerHTML = 'Flow, by the day the record was created (UTC date of the timestamp). Contacts = <code>crm_case_calls</code> (outcome reached / promised / no answer; channel call / sms / email / other) &mdash; the same rows the CRM logs as <code>collections.contact</code>; Loan status changes = <code>collections.contact_status</code> entries in <code>crm_activity_log</code>, labelled with the new status (<code>crm_installment_statuses</code>: Active, Promised, Negotiating, New schedule, No contact, Refused to pay, In default, Legal handoff, Closed); SMS = <code>crm_sms_history</code> rows by template name (<code>status</code> column, CRM labels kept as is); Promises = <code>crm_promises</code> by creation date, kept = <code>collections.promise_settled</code> log entries, broken = <code>promise_broken</code> events; Restructuring = <code>crm_restructure_requests</code> (requested by creation date, approved by decision date); Reversals = <code>crm_payments.reversed_at</code>; Assignments = <code>crm_portfolio_assignments</code> (role pm / collector); Reminders = <code>crm_reminders</code> (type "collections"). The last block is the CRM\\'s own nightly overdue job (<code>crm_payment_events</code> overdue_entered / overdue_cured / overdue_bucket_up / case_settled), shown for reference: it tracks a subset of the schedule (it started backfilling on 1 Sep), so it will not add up to the Overdue Portfolio table. All of these tables came alive with the CRM on ' + dayLabel(C.cutover) + '; there is no earlier history. Underwriting call interviews (<code>crm_call_interviews</code>) are application checks, not collections, and are not counted here.';
  }

  // ---- today's snapshots (mini tables)
  function mini(id, title, head, rows, total) {
    let h = '<tr class="logi-mini-title"><td colspan="' + head.length + '">' + title + '</td></tr><tr class="logi-mini-head">' + head.map(x => '<td>' + x + '</td>').join('') + '</tr>';
    rows.forEach((r, i) => { h += '<tr class="logi-mini-data' + (i % 2 ? ' logi-mini-alt' : '') + '">' + r.map(x => '<td>' + x + '</td>').join('') + '</tr>'; });
    if (total) h += '<tr class="logi-mini-total">' + total.map(x => '<td>' + x + '</td>').join('') + '</tr>';
    document.getElementById(id).querySelector('tbody').innerHTML = h;
  }
  {
    const P = C.pm.slice().sort((a, b) => (a.name === 'Unassigned') - (b.name === 'Unassigned') || b.odAmt - a.odAmt);
    const tot = k => P.reduce((s, p) => s + p[k], 0);
    mini('collPmTable', 'Portfolio managers &mdash; today', ['Manager', 'Active loans', 'Loans with open rows', 'Outstanding schedule (GEL)', 'Overdue loans', 'Overdue (GEL)', '90+ (GEL)', 'Share of overdue'],
      P.map(p => [p.name, fmt(p.activeLoans), fmt(p.openLoans), fmt(p.outstanding), fmt(p.odLoans), fmt(p.odAmt), fmt(p.od90Amt), pct(tot('odAmt') ? p.odAmt / tot('odAmt') : 0)]),
      ['Total', fmt(tot('activeLoans')), fmt(tot('openLoans')), fmt(tot('outstanding')), fmt(tot('odLoans')), fmt(tot('odAmt')), fmt(tot('od90Amt')), pct(1)]);
    const S = C.status, st = k => S.reduce((s, r) => s + r[k], 0);
    mini('collStatusTable', 'Loans by CRM status (crm_installment_statuses) &mdash; today, loans with open schedule rows', ['Status', 'Loans', 'Outstanding schedule (GEL)', 'Overdue loans', 'Overdue (GEL)', 'Share of overdue'],
      S.map(r => [r.name, fmt(r.loans), fmt(r.outstanding), fmt(r.odLoans), fmt(r.odAmt), pct(st('odAmt') ? r.odAmt / st('odAmt') : 0)]),
      ['Total', fmt(st('loans')), fmt(st('outstanding')), fmt(st('odLoans')), fmt(st('odAmt')), pct(1)]);
    const K = C.cps, kt = k => K.reduce((s, r) => s + r[k], 0);
    mini('collCpsTable', 'CRM customer payment-stats cache (crm_customer_payment_stats) &mdash; by worst DPD bucket', ['Worst bucket', 'Customers', 'Currently overdue', 'Avg on-time rate', 'Promises made', 'Kept', 'Broken', 'Total paid (GEL)'],
      K.map(r => [r.label, fmt(r.customers), fmt(r.curOd), r.onTimeRate === null ? DASH : r.onTimeRate.toFixed(1) + '%', fmt(r.promisesMade), fmt(r.promisesKept), fmt(r.promisesBroken), fmt(r.totalPaid)]),
      ['Total', fmt(kt('customers')), fmt(kt('curOd')), DASH, fmt(kt('promisesMade')), fmt(kt('promisesKept')), fmt(kt('promisesBroken')), fmt(kt('totalPaid'))]);
    const m = C.meta, mrow = (t, what) => [t, m[t] === undefined ? DASH : fmt(m[t]), m[t] === 0 ? 'empty' : what];
    mini('collMetaTable', 'Collections tables in the new DB &mdash; row counts at the last refresh', ['Table', 'Rows', 'Used here as'],
      [mrow('crm_payments', 'cash collected'), mrow('crm_installment_schedules', 'dues, overdue portfolio'), mrow('crm_payment_events', 'settlement dates, CRM overdue job'), mrow('crm_case_calls', 'customer contacts'), mrow('crm_sms_history', 'SMS'),
       mrow('crm_promises', 'promises to pay'), mrow('crm_restructure_requests', 'restructuring'), mrow('crm_reminders', 'reminders'), mrow('crm_portfolio_assignments', 'manager assignments'), mrow('crm_customer_payment_stats', 'customer cache (partial population)'),
       mrow('crm_collection_items', 'NOT collections: vendor pick-up lines of the logistics module'), mrow('crm_call_interviews', 'NOT collections: underwriting interviews'),
       mrow('crm_loans', 'not used'), mrow('crm_tasks', 'not used'), mrow('crm_loan_marks', 'not used'), mrow('crm_loan_comments', 'not used'), mrow('crm_charges', 'not used')], null);
    document.getElementById('collSnapNote').innerHTML = 'Portfolio managers: Active loans = <code>orders.crm_active = 1</code> per <code>crm_portfolio_manager_id</code>; Loans with open rows / Outstanding schedule = loans with at least one unpaid schedule row and the sum of their unpaid remainders (all future and past dues); Overdue = today\\'s column of the tables above. CRM status = <code>orders.crm_status_id</code> &rarr; <code>crm_installment_statuses</code> for loans that still have open schedule rows &mdash; "Promised" is the value most migrated loans carry, so treat it as the default rather than a real promise; the collectors\\' changes since the cutover are in the activity table. Customer payment-stats cache = the CRM\\'s own per-customer scoring (on-time rate, worst DPD bucket 1&ndash;6 as above, promises); it is computed only for customers the CRM has touched since the cutover (' + fmt(kt('customers')) + ' of ~29k customers), so it is a sample of the currently worked cases, not the whole book. Tables with 0 rows were empty at the last refresh (' + C.generatedAt + '); <code>crm_collection_items</code> / <code>crm_collection_statuses</code> belong to the logistics module (vendor collections), not to debt collection.';
  }

  const ids = ['collPayDay', 'collPayMonth', 'collOdDay', 'collOdMonth', 'collPmDay', 'collDueDay', 'collDueMonth', 'collActDay', 'collActMonth'];
  window.collScrollUpdaters = ids.map(id => setupTopScrollSync(id + 'ScrollTop', id + 'ScrollBody'));
});
${JS_END}
`;
must('// ---- top-level page nav (grows as more reports get added) ----', 'nav handler');
html = html.replace('// ---- top-level page nav (grows as more reports get added) ----', () => js.replace(/^\n/, '') + '// ---- top-level page nav (grows as more reports get added) ----');
must("  if (btn.dataset.page === 'subcategoryanalyze') subcategoryScrollUpdate();", 'nav updaters');
if (!html.includes("btn.dataset.page === 'collections'")) html = html.replace("  if (btn.dataset.page === 'subcategoryanalyze') subcategoryScrollUpdate();", "  if (btn.dataset.page === 'subcategoryanalyze') subcategoryScrollUpdate();" + NL + "  if (btn.dataset.page === 'collections' && window.collScrollUpdaters) window.collScrollUpdaters.forEach(f => f());");

if (__hadCRLF) html = html.replace(/\n/g, '\r\n');
fs.writeFileSync(htmlPath, html);
console.log('html:', htmlPath, 'bytes:', html.length);
})();

// ================ Portfolio — Portfolio Analyze ================
(function () {
// Portfolio -> Portfolio Analyze for Volta_Analytics_New DB: turns the pf_*.tsv extracts (pull_pf.sh) into pf_data.json and
// injects it into the dashboard HTML (env DASH_HTML, default deals_amount_migration.html) as `const PF_JSON = …;` together
// with the "Portfolio" nav group, the page markup, a small CSS block and the render code. Idempotent: every block it owns is
// removed and re-injected on each run.
const fs = require('fs');
const path = require('path');
const CUTOVER = '2026-08-31';

function parseTsv(file) {
  const p = path.join(__dirname, file);
  if (!fs.existsSync(p)) return [];
  let c = fs.readFileSync(p, 'utf8');
  if (c.charCodeAt(0) === 0xFEFF) c = c.slice(1);
  const lines = c.split(/\r?\n/).filter(l => l.length);
  if (!lines.length) return [];
  const header = lines[0].split('\t');
  return lines.slice(1).map(line => { const cols = line.split('\t'); const o = {}; header.forEach((h, i) => o[h] = cols[i]); return o; });
}
const num = v => (v === '' || v === 'NULL' || v === undefined) ? null : +v;
const r2 = v => Math.round(v * 100) / 100;

// ---- mapping-sheet classifier (categoryEn level) over the new-DB category tree, same as build_logistics.js / build_sales.js
const mappingPath = fs.existsSync(path.join(__dirname, 'product_mapping.json')) ? path.join(__dirname, 'product_mapping.json') : path.join(__dirname, '..', 'src', 'product_mapping.json');
const MAP = {};
for (const [k, v] of Object.entries(JSON.parse(fs.readFileSync(mappingPath, 'utf8')))) MAP[k.trim()] = v;
function lookup(raw) {
  const name = String(raw || '').trim();
  if (name === '' || name === 'none') return null;
  if (MAP[name]) return MAP[name];
  for (const part of name.split(',').map(s => s.trim()).filter(Boolean).reverse()) if (MAP[part]) return MAP[part];
  return null;
}
const cats = {};
for (const r of parseTsv('new_categories.tsv')) cats[r.id] = { parent: r.parent_id, name: (r.name || '').trim() };
function depth(id) { let d = 0, c = cats[id]; while (c && c.parent && cats[c.parent]) { d++; c = cats[c.parent]; } return d; }
const productCats = {};
for (const r of parseTsv('new_product_categories.tsv')) (productCats[r.product_id] ||= []).push(r.category_id);
function rawCategory(pid) {
  const ids = (productCats[pid] || []).slice().sort((a, b) => depth(a) - depth(b));
  return ids.map(id => cats[id] && cats[id].name).filter(n => n && n !== 'none').join(',');
}
const goodsType = pid => { const m = lookup(rawCategory(pid)); const s = m && String(m.categoryEn || '').trim(); return (s && s.toLowerCase() !== 'none') ? s : 'Uncategorized'; };

// ---- label rules shared by the structure tables and the current-book tables
const RISK = raw => { const s = String(raw || '').trim().toLowerCase(); if (s === 'დაბალი' || s === 'low') return 'Low (დაბალი)'; if (s === 'საშუალო' || s === 'medium') return 'Medium (საშუალო)'; if (s === 'მაღალი' || s === 'high') return 'High (მაღალი)'; return 'Not scored'; };
const RISK_ORDER = ['Low (დაბალი)', 'Medium (საშუალო)', 'High (მაღალი)', 'Not scored'];
const TERM_BANDS = ['1–3 months', '4–6 months', '7–9 months', '10 months', '11–12 months', '13+ months', 'No schedule'];
const termBand = t => { t = +t; if (!t) return 'No schedule'; if (t <= 3) return '1–3 months'; if (t <= 6) return '4–6 months'; if (t <= 9) return '7–9 months'; if (t === 10) return '10 months'; if (t <= 12) return '11–12 months'; return '13+ months'; };
const AMT_BANDS = { 1: '< 500 GEL', 2: '500 – 999 GEL', 3: '1,000 – 1,999 GEL', 4: '2,000 – 2,999 GEL', 5: '3,000 – 4,999 GEL', 6: '5,000+ GEL' };
const AMT_ORDER = Object.values(AMT_BANDS);
const AGE_BANDS = ['0–1 months', '2–3 months', '4–6 months', '7–9 months', '10–12 months', '13+ months'];
const ageBand = a => { a = +a; if (a <= 1) return '0–1 months'; if (a <= 3) return '2–3 months'; if (a <= 6) return '4–6 months'; if (a <= 9) return '7–9 months'; if (a <= 12) return '10–12 months'; return '13+ months'; };
const RM_ORDER = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11+', 'Unknown'];
const rmLabel = k => { k = +k; if (k < 0) return 'Unknown'; if (k >= 11) return '11+'; return String(k); };
const CITY_ORDER = ['Tbilisi', 'Other Cities', 'Without City'];
const SEG_LABEL = { A: 'Segment A (phone / TV or > 2,500 GEL)', B: 'Segment B (other)' };
const KIND_LABEL = { active: 'Still active', paid: 'Paid off (recorded close)', norec: 'Paid off (no close record)', wo: 'Written off' };

// ---- stocks
const stockDayRows = parseTsv('pf_stock_day.tsv'), stockMonthRows = parseTsv('pf_stock_month.tsv');
const stockOf = rows => ({
  dates: rows.map(r => r.d), active: rows.map(r => num(r.n)), balance: rows.map(r => r2(num(r.rem_now) + num(r.paid_after))),
  contract: rows.map(r => num(r.contract)), price: rows.map(r => num(r.price)),
});
const stockDay = stockOf(stockDayRows);
const stockMonth = Object.assign(stockOf(stockMonthRows), { months: stockMonthRows.map(r => r.d.slice(0, 7)) });
const END = stockDay.dates.at(-1);
const MSTART = stockMonth.months[0];
const dayDates = stockDay.dates, months = stockMonth.months;

// ---- flows: day extracts (MSTART..END) rolled up to the day series (cutover..END) and the month series
const disbRows = parseTsv('pf_disb_day.tsv'), closeRows = parseTsv('pf_close_day.tsv'), singleRows = parseTsv('pf_single_day.tsv');
function flowsFor(keys, keyOf) {
  const z = () => keys.map(() => 0), idx = {}; keys.forEach((k, i) => idx[k] = i);
  const f = { keys, disb: { n: z(), amount: z(), price: z(), adv: z(), termSum: z(), advN: z(), segA: z() }, single: { n: z(), price: z() },
    close: { paid: { n: z(), amount: z() }, norec: { n: z(), amount: z() }, wo: { n: z(), amount: z(), rem: z() } } };
  for (const r of disbRows) { const i = idx[keyOf(r.d)]; if (i === undefined) continue; f.disb.n[i] += num(r.n); f.disb.amount[i] += num(r.amount); f.disb.price[i] += num(r.price); f.disb.adv[i] += num(r.adv_sum); f.disb.termSum[i] += num(r.term_sum); f.disb.advN[i] += num(r.adv_n); f.disb.segA[i] += num(r.seg_a); }
  for (const r of singleRows) { const i = idx[keyOf(r.d)]; if (i === undefined) continue; f.single.n[i] += num(r.n); f.single.price[i] += num(r.price); }
  for (const r of closeRows) { const i = idx[keyOf(r.d)]; if (i === undefined) continue; const c = f.close[r.kind]; if (!c) continue; c.n[i] += num(r.n); c.amount[i] += num(r.amount); if (c.rem) c.rem[i] += num(r.rem); }
  for (const g of [f.disb, f.single, f.close.paid, f.close.norec, f.close.wo]) for (const k of Object.keys(g)) g[k] = g[k].map(r2);
  return f;
}
const flowDay = flowsFor(dayDates, d => d);
const flowMonth = flowsFor(months, d => d.slice(0, 7));

// ---- structure of the loans disbursed each month: one table per dimension, rows = bands, columns = months (+ totals)
const structRows = parseTsv('pf_struct.tsv');
function structTable(dim, labelOf, order, title, headLabel) {
  const rows = new Map();
  const get = l => { if (!rows.has(l)) rows.set(l, { label: l, n: months.map(() => 0), amount: months.map(() => 0), act: months.map(() => 0) }); return rows.get(l); };
  for (const l of order || []) get(l);
  for (const r of structRows) {
    if (r.dim !== dim) continue;
    const i = months.indexOf(r.mo); if (i < 0) continue;
    const g = get(labelOf(r.k)); g.n[i] += num(r.n); g.amount[i] += num(r.amount); g.act[i] += num(r.act);
  }
  let out = [...rows.values()].map(g => ({ label: g.label, n: g.n, amount: g.amount.map(r2), act: g.act, total: g.n.reduce((a, b) => a + b, 0) }));
  if (!order) out.sort((a, b) => (a.label === 'Uncategorized') - (b.label === 'Uncategorized') || b.total - a.total);
  out = out.filter(g => g.total > 0);
  const total = { n: months.map((_, i) => out.reduce((t, g) => t + g.n[i], 0)), amount: months.map((_, i) => r2(out.reduce((t, g) => t + g.amount[i], 0))) };
  return { title, headLabel, rows: out, total };
}
const struct = {
  term: structTable('term', termBand, TERM_BANDS, 'Loans by term (months in the payment schedule)', 'Term'),
  amt: structTable('amt', k => AMT_BANDS[k] || 'Unknown', AMT_ORDER, 'Loans by contract amount', 'Contract amount'),
  seg: structTable('seg', k => SEG_LABEL[k] || k, ['Segment A (phone / TV or > 2,500 GEL)', 'Segment B (other)'], 'Loans by segment', 'Segment'),
  goods: structTable('goods', goodsType, null, 'Loans by goods type (highest-value product line)', 'Goods Type'),
  city: structTable('city', k => k, CITY_ORDER, 'Loans by city (shipping address)', 'City'),
  risk: structTable('risk', RISK, RISK_ORDER, 'Loans by risk status (crm_risk_status)', 'Risk status'),
};
// vintage: per disbursement month, how the loans stand today
const vintage = (() => {
  const by = {}; for (const m of months) by[m] = { disb: 0, amount: 0, active: 0, paid: 0, norec: 0, wo: 0, rem: 0, remActive: 0 };
  for (const r of structRows) {
    if (r.dim !== 'kind' || !by[r.mo]) continue;
    const g = by[r.mo]; g.disb += num(r.n); g.amount += num(r.amount); g[r.k] += num(r.n); g.rem += num(r.rem); if (r.k === 'active') g.remActive += num(r.rem);
  }
  return months.map(m => Object.assign({ month: m }, by[m], { amount: r2(by[m].amount), rem: r2(by[m].rem), remActive: r2(by[m].remActive) }));
})();

// ---- the active book today (as of END)
const bookRows = parseTsv('pf_book.tsv');
function bookTable(dim, labelOf, order, title, headLabel) {
  const rows = new Map();
  const get = l => { if (!rows.has(l)) rows.set(l, { label: l, n: 0, amount: 0, rem: 0 }); return rows.get(l); };
  for (const l of order || []) get(l);
  for (const r of bookRows) { if (r.dim !== dim) continue; const g = get(labelOf(r.k)); g.n += num(r.n); g.amount += num(r.amount); g.rem += num(r.rem); }
  let out = [...rows.values()].map(g => ({ label: g.label, n: g.n, amount: r2(g.amount), rem: r2(g.rem) }));
  if (!order) out.sort((a, b) => (a.label === 'Uncategorized') - (b.label === 'Uncategorized') || b.n - a.n);
  out = out.filter(g => g.n > 0);
  const total = { n: out.reduce((t, g) => t + g.n, 0), amount: r2(out.reduce((t, g) => t + g.amount, 0)), rem: r2(out.reduce((t, g) => t + g.rem, 0)) };
  return { title, headLabel, rows: out, total };
}
const book = {
  rm: bookTable('rm', rmLabel, RM_ORDER, 'Active loans by months remaining (crm_remaining_months)', 'Months remaining'),
  age: bookTable('age', ageBand, AGE_BANDS, 'Active loans by age (months since disbursement)', 'Loan age'),
  risk: bookTable('risk', RISK, RISK_ORDER, 'Active loans by risk status', 'Risk status'),
  term: bookTable('term', termBand, TERM_BANDS, 'Active loans by term', 'Term'),
  amt: bookTable('amt', k => AMT_BANDS[k] || 'Unknown', AMT_ORDER, 'Active loans by contract amount', 'Contract amount'),
  seg: bookTable('seg', k => SEG_LABEL[k] || k, ['Segment A (phone / TV or > 2,500 GEL)', 'Segment B (other)'], 'Active loans by segment', 'Segment'),
  city: bookTable('city', k => k, CITY_ORDER, 'Active loans by city', 'City'),
  goods: bookTable('goods', goodsType, null, 'Active loans by goods type', 'Goods Type'),
};

// ---- data-quality memo
const quality = { kinds: parseTsv('pf_quality.tsv').map(r => ({ kind: r.kind, label: KIND_LABEL[r.kind] || r.kind, n: num(r.n), rem: num(r.rem), fullyPaid: num(r.fully_paid), firstDisb: r.first_disb, lastDisb: r.last_disb, firstClose: r.first_close === 'NULL' ? null : r.first_close, lastClose: r.last_close === 'NULL' ? null : r.last_close })) };
{ const q = parseTsv('pf_quality2.tsv')[0] || {}; Object.assign(quality, { badCloseDates: num(q.bad_close_dates), activeZeroBalance: num(q.active_zero_balance), singleAll: num(q.single_all), closesSinceCutover: num(q.closes_since_cutover) }); }

const payload = { cutover: CUTOVER, mstart: MSTART, end: END, stockDay, stockMonth, flowDay, flowMonth, struct, vintage, book, quality, generatedAt: new Date().toISOString().slice(0, 16).replace('T', ' ') + ' UTC' };
fs.writeFileSync(path.join(__dirname, 'pf_data.json'), JSON.stringify(payload));
// console reconciliation: stock(D) - stock(D-1) must equal disbursed(D) - closed(D) on every day after the first
{
  const c = flowDay.close, bad = [];
  for (let i = 1; i < dayDates.length; i++) { const net = flowDay.disb.n[i] - c.paid.n[i] - c.norec.n[i] - c.wo.n[i]; if (stockDay.active[i] - stockDay.active[i - 1] !== net) bad.push(dayDates[i] + ':' + (stockDay.active[i] - stockDay.active[i - 1]) + '!=' + net); }
  console.log('days:', dayDates.length, '| months:', months.length, MSTART, '->', END, '| active today:', stockDay.active.at(-1), 'balance:', stockDay.balance.at(-1), '| disbursed MTD:', flowMonth.disb.n.at(-1), flowMonth.disb.amount.at(-1), '| closes MTD paid/norec/wo:', c.paid.n.reduce((a, b) => a + b, 0), c.norec.n.reduce((a, b) => a + b, 0), c.wo.n.reduce((a, b) => a + b, 0), '| goods rows:', struct.goods.rows.length, '| day stock-vs-flow mismatches:', bad.length ? bad.join(' ') : 'none');
}

// ---------------- inject into the HTML ----------------
const htmlPath = process.env.DASH_HTML ? path.resolve(process.env.DASH_HTML) : path.join(__dirname, 'deals_amount_migration.html');
let html = fs.readFileSync(htmlPath, 'utf8');
const __hadCRLF = html.includes('\r\n'); if (__hadCRLF) html = html.replace(/\r\n/g, '\n'); // tolerate a git-checked-out CRLF file (Windows); every marker below assumes plain LF
const must = (n, what) => { if (!html.includes(n)) throw new Error('anchor not found: ' + what); };
const NL = String.fromCharCode(10);
const cutBetween = (startMark, endMark, what) => {
  const s = html.indexOf(startMark); if (s < 0) return;
  const e = html.indexOf(endMark, s); if (e < 0) throw new Error(what + ' end marker not found');
  html = html.slice(0, s) + html.slice(e + endMark.length);
};

// CSS — only what the shared logi-* classes do not already cover
const CSS_MARK = '/* --- pf --- */', CSS_END = '/* --- /pf --- */';
const css = `
${CSS_MARK}
.pf-title { color: var(--text-primary); font-weight: 700; font-size: 13px; margin: 14px 0 -6px; }
table.logi-table tr.pf-memo td { background: #fff; color: #5a5a75; font-style: italic; }
table.logi-table tr.pf-memo td:first-child { padding-left: 18px; }
table.logi-table tr.pf-vint td { background: #fff; color: #1a1a34; }
table.logi-table tr.pf-vint.pf-alt td { background: #f5ffd6; color: #1a1a34; }
table.logi-table tr.pf-vint td.pf-pct { font-style: italic; }
${CSS_END}`;
cutBetween(NL + CSS_MARK, NL + CSS_END, 'pf css');
must('.report-scroll-top > div { height: 1px; }', 'css anchor');
html = html.replace('.report-scroll-top > div { height: 1px; }', '.report-scroll-top > div { height: 1px; }' + css);

// nav group
if (!html.includes('data-page="portfolio"')) {
  const navAnchor = '  </div>' + NL + '</div>' + NL + NL + '<div class="page active" data-page="report">';
  must(navAnchor, 'nav anchor');
  html = html.replace(navAnchor, `    <div class="nav-group">
      <div class="nav-group-title">Portfolio</div>
      <div class="nav-group-items">
        <button data-page="portfolio">Portfolio Analyze</button>
      </div>
    </div>
` + navAnchor);
}

// page markup
const PAGE_START = '<!-- pf-page-start -->', PAGE_END = '<!-- pf-page-end -->';
cutBetween(PAGE_START, PAGE_END + NL, 'pf page');
{
  const card = id => `  <div class="report-card">
    <div class="report-scroll-top" id="${id}ScrollTop"><div></div></div>
    <div class="report-scroll" id="${id}ScrollBody"><table class="logi-table" id="${id}Table"><tbody></tbody></table></div>
  </div>`;
  const page = `${PAGE_START}
<div class="page" data-page="portfolio" id="page-portfolio">
<div class="wrap">
  <p class="section-title">Portfolio Analyze &mdash; the loan book by day and by month</p>
  <div class="banner" id="pfBanner"></div>

  <p class="pf-title">Portfolio size &mdash; end-of-day stock</p>
${card('pfStockDay')}
  <p class="note">Stock at the end of each day (last column = today as of the last refresh). Active loans = installment loans disbursed by the end of that day and not closed by then (<code>orders</code> with <code>crm_order_status</code> 5, or 1 on rows migrated from the old CRM; disbursement date = <code>crm_creator_date</code>; closure = <code>crm_close_date</code>, or the last payment date when the close was never recorded or its date is a placeholder). Outstanding balance = what those loans still owe: today it is exactly the schedule balance (<code>crm_installment_schedules</code>: schedule &minus; paid, which equals <code>orders.grand_total</code>); for earlier days it is today's balance plus the principal the loans paid after that day (<code>crm_payments</code>, reversed payments and one 99,999,999.99 placeholder excluded) &mdash; a reconstruction, exact to the extent the payment ledger is. Contract amount = the full installment amount (schedule total + advance, the Daily Mail "Amount Sold" rule); product price = <code>base_grand_total</code>.</p>

  <p class="pf-title">Portfolio size &mdash; month-end stock</p>
${card('pfStockMonth')}
  <p class="note">The same stock at each month-end from ${MSTART} (last column = today, i.e. the current month to date). Months before the cutover (${CUTOVER}) are reconstructed from rows migrated on the cutover day: their disbursement dates drift by hours to a few days from the old CRM's order dates and their closure dates come from the recorded close or the last payment, so the month-end counts are close but not to-the-loan exact; from the cutover on everything is real history. The old CRM stopped recording closes in June 2026 &mdash; loans that finished paying between then and the cutover have no close record and are treated as closed on their last payment day.</p>

  <p class="pf-title">Portfolio flows &mdash; by day</p>
${card('pfFlowDay')}
  <p class="note">Flows on each day. Disbursed = loans whose disbursement date (<code>crm_creator_date</code>) is that day &mdash; the same population as Daily Mail's Deals Closed / Amount Sold (active loans by sale date), extended with the loans that were disbursed and have since been closed; contract amount, product price and advance (<code>crm_advance_amount</code>) as in Daily Mail. Average term = schedule rows per loan (the advance row of a migrated 11-row schedule is not a month); average advance % = advance &divide; product price over loans that carry an advance (migrated loans carry none, so before the cutover this reads "&ndash;"). Closed = loans whose closure fell on that day: paid off with a recorded close (<code>crm_close_type</code> 1, keyed to <code>crm_close_date</code>), paid off without a close record (schedule fully paid, keyed to the last payment date), written off (<code>crm_close_type</code> 2; amount = balance written off, the schedule balance still open on those loans). Net change = disbursed &minus; closed, and equals the day-to-day change of the Active loans stock above. Single-payment sales (<code>crm_order_status</code> 99) are a memo line, not part of the book.</p>

  <p class="pf-title">Portfolio flows &mdash; by month</p>
${card('pfFlowMonth')}
  <p class="note">The same flows by calendar month from ${MSTART} (last column = current month to date). Before the cutover the disbursement month comes from migrated <code>crm_creator_date</code> values (month totals reconcile with the old dashboard within a few percent; days do not), closures from recorded close dates (garbage dates such as 1924/1970 or dates before disbursement are replaced by the last payment date) or, where none was recorded, the last payment date. Written-off loans were booked in bulk (Jan 2026), not month by month.</p>

  <p class="pf-title">Structure of the loans disbursed each month</p>
  <div class="logi-group">
    <div class="logi-group-title">Loans by term, contract amount, segment, goods type, city and risk &mdash; by month of disbursement</div>
${card('pfStructTerm')}
${card('pfStructAmt')}
${card('pfStructSeg')}
${card('pfStructGoods')}
${card('pfStructCity')}
${card('pfStructRisk')}
  </div>
  <p class="note">Each table counts the loans disbursed in each month (same population as the Disbursed row above) by one attribute; the trailing columns give each row's share of the current month, the total over the whole window and its share. Term = number of monthly schedule rows. Contract amount = the full installment amount. Segment A = the product name starts with ტელეფონი / ტელევიზორ or the order is over 2,500 GEL (the Daily Mail rule). Goods type = the mapping sheet's top-level category of the loan's highest-value product line (<code>src/product_mapping.json</code>; catalog categories the sheet does not know land in Uncategorized). City = the order's shipping address (<code>addresses</code>, <code>order_shipping</code>). Risk status = <code>crm_risk_status</code> as stored (Georgian and English spellings merged; "0" / empty = not scored).</p>

  <p class="pf-title">Vintage &mdash; how the loans of each disbursement month stand today</p>
${card('pfVintage')}
  <p class="note">One row per disbursement month: loans disbursed and their contract amount; how many are still active today, paid off (with or without a close record) or written off; the balance still outstanding on the active ones and its share of the month's contract amount. A young month is mostly active; a month older than the typical 10-month term is mostly paid off &mdash; what remains active there is late.</p>

  <p class="pf-title">The active book today (${END})</p>
  <div class="logi-mini-row">
    <div class="table-card"><table class="logi-mini" id="pfBookRm"><colgroup><col class="logi-mini-name"></colgroup><tbody></tbody></table></div>
    <div class="table-card"><table class="logi-mini" id="pfBookAge"><colgroup><col class="logi-mini-name"></colgroup><tbody></tbody></table></div>
  </div>
  <div class="logi-mini-row">
    <div class="table-card"><table class="logi-mini" id="pfBookRisk"><colgroup><col class="logi-mini-name"></colgroup><tbody></tbody></table></div>
    <div class="table-card"><table class="logi-mini" id="pfBookTerm"><colgroup><col class="logi-mini-name"></colgroup><tbody></tbody></table></div>
  </div>
  <div class="logi-mini-row">
    <div class="table-card"><table class="logi-mini" id="pfBookAmt"><colgroup><col class="logi-mini-name"></colgroup><tbody></tbody></table></div>
    <div class="table-card"><table class="logi-mini" id="pfBookSeg"><colgroup><col class="logi-mini-name"></colgroup><tbody></tbody></table></div>
  </div>
  <div class="logi-mini-row">
    <div class="table-card"><table class="logi-mini" id="pfBookCity"><colgroup><col class="logi-mini-name"></colgroup><tbody></tbody></table></div>
    <div class="table-card"><table class="logi-mini" id="pfBookGoods"><colgroup><col class="logi-mini-name"></colgroup><tbody></tbody></table></div>
  </div>
  <p class="note">Point-in-time snapshot of every active loan (<code>crm_active = 1</code>) as of the last refresh: count, contract amount, outstanding balance and the row's share of the balance. Months remaining = <code>crm_remaining_months</code> as stored by the CRM; loan age = full months since disbursement. Overdue buckets, collections and payments are on the Collections tab, not here.</p>

  <p class="pf-title">Data notes</p>
  <div class="table-card"><table class="logi-mini" id="pfQuality"><colgroup><col class="logi-mini-name"></colgroup><tbody></tbody></table></div>
  <p class="note" id="pfQualityNote"></p>
</div>
</div>
${PAGE_END}
`;
  const li = html.indexOf('id="page-logistics"'); if (li < 0) throw new Error('page-logistics not found');
  const si = html.indexOf('<script>', li); if (si < 0) throw new Error('script after page-logistics not found');
  const lineStart = html.lastIndexOf(NL, si) + 1;
  html = html.slice(0, lineStart) + page + html.slice(lineStart);
}

// data line
const dataLine = 'var PF_JSON = ' + JSON.stringify(payload) + ';';
if (/^(?:const|var) PF_JSON = .*;$/m.test(html)) html = html.replace(/^(?:const|var) PF_JSON = .*;$/m, () => dataLine);
else { must('const generatedAt = REPORT_JSON.generatedAt;', 'report consts'); html = html.replace('const generatedAt = REPORT_JSON.generatedAt;', 'const generatedAt = REPORT_JSON.generatedAt;' + NL + dataLine); }

// render code
const JS_MARK = '/* ---------- pf ---------- */', JS_END = '/* ---------- /pf ---------- */';
cutBetween(NL + JS_MARK, JS_END + NL, 'pf js');
{
  const js = `
${JS_MARK}
window.__registerPage(['portfolio'], function () {
  const P = PF_JSON;
  const dayLabel = d => { const [, m, day] = d.split('-').map(Number); return MONTH_NAMES[m - 1] + ' ' + day; };
  const monthLabel = m => MONTH_NAMES[Number(m.slice(5, 7)) - 1] + ' ' + m.slice(0, 4);
  const DASH = '&ndash;';
  const n0 = v => (v === null || v === undefined) ? DASH : fmt(v);
  const n1 = v => (v === null || v === undefined) ? DASH : Number(v).toLocaleString('en-US', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
  const p1 = v => (v === null || v === undefined) ? DASH : pct(v);
  const div = (a, b) => b ? a / b : null;
  document.getElementById('pfBanner').innerHTML = '<b>წყარო:</b> ახალი CRM-ის სესხების წიგნი (VoltaStoreDB: <code>orders</code> სტატუსით 5 = განვადება, <code>crm_installment_schedules</code>, <code>crm_payments</code>). დღეების ჭრილში ისტორია ' + P.cutover + '-დან ზუსტია (CRM-ის გადართვის დღე); თვეების ჭრილი ' + monthLabel(P.mstart) + '-დან ' + P.cutover + '-მდე ძველი CRM-იდან გადმოტანილი ჩანაწერებით არის აღდგენილი &mdash; გაცემის თარიღები რამდენიმე დღით ცდება, დახურვის თარიღი, სადაც არ ჩაწერილა (2026 წლის ივნისიდან), ბოლო გადახდის დღეა. ნაშთი დღეს ზუსტია (გრაფიკის დარჩენილი ჯამი), წინა დღეებზე გადახდების ლოგიდანაა აღდგენილი. &mdash; <b>Source:</b> the new CRM loan book (VoltaStoreDB). Day series exact from the cutover ' + P.cutover + '; month series from ' + monthLabel(P.mstart) + ' reconstructed from migrated rows before the cutover (disbursement dates drift by days; closes without a record are dated to the last payment). Balance is exact today, reconstructed from the payment ledger for earlier dates. Refreshed ' + P.generatedAt + '.';

  const cell = (v, cls) => '<td' + (cls ? ' class="' + cls + '"' : '') + '>' + v + '</td>';
  const exCell = (v, i) => cell(v, 'logi-extra' + (i === 0 ? ' logi-extra-first' : ''));
  const row = (cls, label, cells, extras) => '<tr class="' + cls + '"><td>' + label + '</td>' + cells.map(v => cell(v)).join('') + (extras || []).map(exCell).join('') + '</tr>';
  const headRow = (label, heads, extras) => '<tr class="logi-head"><td>' + label + '</td>' + heads.map(h => cell(h)).join('') + (extras || []).map(exCell).join('') + '</tr>';
  const titleRow = (title, span) => '<tr class="logi-title"><td colspan="' + span + '">' + title + '</td></tr>';
  const dayHeads = P.stockDay.dates.map((d, i, a) => dayLabel(d) + (i === a.length - 1 ? ' (today)' : ''));
  const monthHeads = P.stockMonth.months.map((m, i, a) => monthLabel(m) + (i === a.length - 1 ? ' (MTD)' : ''));
  const set = (id, inner) => { document.getElementById(id).querySelector('tbody').innerHTML = inner; };

  function renderStock(id, S, heads, title, headLabel) {
    const n = heads.length, alt = i => i % 2 ? 'logi-light' : 'logi-white';
    let h = titleRow(title, n + 1) + headRow(headLabel, heads);
    h += row('logi-total', 'Active loans (count)', S.active.map(n0));
    h += row(alt(0), 'Outstanding balance (GEL)', S.balance.map(n0));
    h += row(alt(1), 'Contract amount of active loans (GEL)', S.contract.map(n0));
    h += row(alt(0), 'Product price of active loans (GEL)', S.price.map(n0));
    h += row('logi-plain', 'Balance as % of contract amount', S.balance.map((b, i) => p1(div(b, S.contract[i]))));
    h += row('logi-plain', 'Average balance per loan (GEL)', S.balance.map((b, i) => n0(div(b, S.active[i]))));
    h += row('logi-plain', 'Average contract per loan (GEL)', S.contract.map((c, i) => n0(div(c, S.active[i]))));
    set(id, h);
  }
  function renderFlow(id, F, S, heads, title, headLabel) {
    const n = heads.length, alt = i => i % 2 ? 'logi-light' : 'logi-white', d = F.disb, c = F.close;
    const closedN = heads.map((_, i) => c.paid.n[i] + c.norec.n[i] + c.wo.n[i]);
    let h = titleRow(title, n + 1) + headRow(headLabel, heads);
    h += row('logi-sub', 'Disbursed', heads.map(() => ''));
    h += row('logi-total', 'Disbursed loans (count)', d.n.map(n0));
    h += row(alt(0), 'Disbursed amount &ndash; contract (GEL)', d.amount.map(n0));
    h += row(alt(1), 'Product price (GEL)', d.price.map(n0));
    h += row(alt(0), 'Advance collected (GEL)', d.adv.map((v, i) => d.advN[i] ? n0(v) : DASH));
    h += row('logi-plain', 'Average ticket &ndash; contract (GEL)', d.amount.map((v, i) => n0(div(v, d.n[i]))));
    h += row('logi-plain', 'Average term (months)', d.termSum.map((v, i) => n1(div(v, d.n[i]))));
    h += row('logi-plain', 'Average advance % of price', d.adv.map((v, i) => d.advN[i] ? p1(div(v, d.price[i])) : DASH));
    h += row('logi-plain', 'Segment A share of loans', d.segA.map((v, i) => p1(div(v, d.n[i]))));
    h += row('pf-memo', 'memo: single-payment sales (count / GEL) &ndash; not in the book', F.single.n.map((v, i) => v ? fmt(v) + ' / ' + fmt(F.single.price[i]) : DASH));
    h += row('logi-sub', 'Closed', heads.map(() => ''));
    h += row('logi-total', 'Closed loans (count)', closedN.map(n0));
    h += row(alt(0), 'Paid off &ndash; recorded close (count)', c.paid.n.map(n0));
    h += row(alt(1), 'Paid off &ndash; recorded close, contract amount (GEL)', c.paid.amount.map(n0));
    h += row(alt(0), 'Paid off &ndash; no close record (count)', c.norec.n.map(n0));
    h += row(alt(1), 'Paid off &ndash; no close record, contract amount (GEL)', c.norec.amount.map(n0));
    h += row(alt(0), 'Written off (count)', c.wo.n.map(n0));
    h += row(alt(1), 'Written off &ndash; balance written off (GEL)', c.wo.rem.map(n0));
    h += row('logi-sub', 'Net', heads.map(() => ''));
    h += row('logi-total', 'Net change in active loans (disbursed &minus; closed)', d.n.map((v, i) => { const x = v - closedN[i]; return (x > 0 ? '+' : '') + fmt(x); }));
    h += row('logi-plain', 'Active loans at period end (stock)', S.active.map(n0));
    set(id, h);
  }
  function renderStruct(id, T) {
    const n = monthHeads.length, last = n - 1, tot = T.total.n, grand = tot.reduce((a, b) => a + b, 0);
    const extraHead = ['Share ' + monthLabel(P.stockMonth.months[last]), 'Total ' + monthLabel(P.stockMonth.months[0]) + ' &ndash; ' + monthLabel(P.stockMonth.months[last]), 'Share of total'];
    let h = titleRow(T.title, n + 1 + extraHead.length) + headRow(T.headLabel, monthHeads, extraHead);
    T.rows.forEach((r, i) => { h += row(i % 2 ? 'logi-light' : 'logi-white', r.label, r.n.map(v => v ? fmt(v) : DASH), [p1(div(r.n[last], tot[last])), fmt(r.total), p1(div(r.total, grand))]); });
    h += row('logi-total', 'Total', tot.map(n0), [p1(tot[last] ? 1 : 0), fmt(grand), p1(grand ? 1 : 0)]);
    set(id, h);
  }
  function renderVintage() {
    const heads = ['Disbursed (count)', 'Contract amount (GEL)', 'Still active', '% still active', 'Paid off &ndash; recorded', 'Paid off &ndash; no record', 'Written off', 'Balance outstanding (GEL)', '% of contract outstanding'];
    let h = titleRow('Vintage &mdash; loans of each disbursement month as of ' + dayLabel(P.end) + ' ' + P.end.slice(0, 4), heads.length + 1) + headRow('Disbursement month', heads);
    const t = { disb: 0, amount: 0, active: 0, paid: 0, norec: 0, wo: 0, remActive: 0 };
    P.vintage.forEach((v, i) => {
      for (const k of Object.keys(t)) t[k] += v[k];
      const cells = [n0(v.disb), n0(v.amount), n0(v.active), cell(p1(div(v.active, v.disb)), 'pf-pct'), n0(v.paid), n0(v.norec), n0(v.wo), n0(v.remActive), cell(p1(div(v.remActive, v.amount)), 'pf-pct')];
      h += '<tr class="pf-vint' + (i % 2 ? ' pf-alt' : '') + '"><td>' + monthLabel(v.month) + (i === P.vintage.length - 1 ? ' (MTD)' : '') + '</td>' + cells.map(x => x.startsWith('<td') ? x : cell(x)).join('') + '</tr>';
    });
    h += row('logi-total', 'Total', [n0(t.disb), n0(t.amount), n0(t.active), p1(div(t.active, t.disb)), n0(t.paid), n0(t.norec), n0(t.wo), n0(t.remActive), p1(div(t.remActive, t.amount))]);
    set('pfVintageTable', h);
  }
  function renderBook(id, T) {
    let h = '<tr class="logi-mini-title"><td colspan="5">' + T.title + '</td></tr><tr class="logi-mini-head"><td>' + T.headLabel + '</td><td>Loans</td><td>Contract (GEL)</td><td>Balance (GEL)</td><td>Share of balance</td></tr>';
    T.rows.forEach((r, i) => { h += '<tr class="logi-mini-data' + (i % 2 ? ' logi-mini-alt' : '') + '"><td>' + r.label + '</td><td>' + fmt(r.n) + '</td><td>' + fmt(r.amount) + '</td><td>' + fmt(r.rem) + '</td><td>' + p1(div(r.rem, T.total.rem)) + '</td></tr>'; });
    h += '<tr class="logi-mini-total"><td>Total</td><td>' + fmt(T.total.n) + '</td><td>' + fmt(T.total.amount) + '</td><td>' + fmt(T.total.rem) + '</td><td>' + p1(T.total.rem ? 1 : 0) + '</td></tr>';
    set(id, h);
  }
  function renderQuality() {
    const q = P.quality;
    let h = '<tr class="logi-mini-title"><td colspan="6">How the book is recorded (all loans, all years)</td></tr><tr class="logi-mini-head"><td>Kind</td><td>Loans</td><td>Balance open (GEL)</td><td>Disbursed</td><td>Closed</td><td>Fully paid</td></tr>';
    q.kinds.forEach((k, i) => { h += '<tr class="logi-mini-data' + (i % 2 ? ' logi-mini-alt' : '') + '"><td>' + k.label + '</td><td>' + fmt(k.n) + '</td><td>' + fmt(k.rem) + '</td><td>' + k.firstDisb + ' &ndash; ' + k.lastDisb + '</td><td>' + (k.firstClose ? k.firstClose + ' &ndash; ' + k.lastClose : DASH) + '</td><td>' + fmt(k.fullyPaid) + '</td></tr>'; });
    set('pfQuality', h);
    document.getElementById('pfQualityNote').innerHTML = 'Recorded closes since the cutover: ' + fmt(q.closesSinceCutover) + ' (the new CRM posts them automatically as "paid_in_full", first one on 2026-09-04). ' + fmt(q.badCloseDates) + ' recorded closes carry an unusable date (before disbursement, or a 1924/1970 placeholder) and are dated to the last payment instead. ' + fmt(q.activeZeroBalance) + ' loans are still flagged active with a zero balance (not yet closed by the CRM). Single-payment sales in the whole database: ' + fmt(q.singleAll) + '. Migrated loans carry no advance amount and no risk score in the new spelling; both are complete for loans created in the new CRM.';
  }

  renderStock('pfStockDayTable', P.stockDay, dayHeads, 'PORTFOLIO &mdash; Size by day (end-of-day stock)', 'Stock');
  renderStock('pfStockMonthTable', P.stockMonth, monthHeads, 'PORTFOLIO &mdash; Size by month (month-end stock)', 'Stock');
  renderFlow('pfFlowDayTable', P.flowDay, P.stockDay, dayHeads, 'PORTFOLIO &mdash; Flows by day', 'Flow');
  renderFlow('pfFlowMonthTable', P.flowMonth, P.stockMonth, monthHeads, 'PORTFOLIO &mdash; Flows by month', 'Flow');
  renderStruct('pfStructTermTable', P.struct.term); renderStruct('pfStructAmtTable', P.struct.amt); renderStruct('pfStructSegTable', P.struct.seg);
  renderStruct('pfStructGoodsTable', P.struct.goods); renderStruct('pfStructCityTable', P.struct.city); renderStruct('pfStructRiskTable', P.struct.risk);
  renderVintage();
  renderBook('pfBookRm', P.book.rm); renderBook('pfBookAge', P.book.age); renderBook('pfBookRisk', P.book.risk); renderBook('pfBookTerm', P.book.term);
  renderBook('pfBookAmt', P.book.amt); renderBook('pfBookSeg', P.book.seg); renderBook('pfBookCity', P.book.city); renderBook('pfBookGoods', P.book.goods);
  renderQuality();
  window.pfScrollUpdaters = ['pfStockDay', 'pfStockMonth', 'pfFlowDay', 'pfFlowMonth', 'pfStructTerm', 'pfStructAmt', 'pfStructSeg', 'pfStructGoods', 'pfStructCity', 'pfStructRisk', 'pfVintage'].map(id => setupTopScrollSync(id + 'ScrollTop', id + 'ScrollBody'));
});
${JS_END}
`;
  must('// ---- top-level page nav (grows as more reports get added) ----', 'nav handler');
  html = html.replace('// ---- top-level page nav (grows as more reports get added) ----', js + '// ---- top-level page nav (grows as more reports get added) ----');
  const navLine = "  if (btn.dataset.page === 'subcategoryanalyze') subcategoryScrollUpdate();";
  must(navLine, 'nav updaters');
  if (!html.includes("btn.dataset.page === 'portfolio'")) html = html.replace(navLine, navLine + NL + "  if (btn.dataset.page === 'portfolio' && window.pfScrollUpdaters) window.pfScrollUpdaters.forEach(f => f());");
}

if (__hadCRLF) html = html.replace(/\n/g, '\r\n');
fs.writeFileSync(htmlPath, html);
console.log('html:', htmlPath, 'bytes:', html.length);
})();
