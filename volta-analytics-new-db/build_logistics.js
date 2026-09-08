// Logistics Daily for Volta_Analytics_New DB: turns the logi_*.tsv extracts (pull_new.sh) into logistics_data.json
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
const htmlPath = path.join(__dirname, 'deals_amount_migration.html');
let html = fs.readFileSync(htmlPath, 'utf8');
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
{
  const NL = String.fromCharCode(10);
  const PAGE_START = NL + '<div class="page" data-page="logistics" id="page-logistics">', PAGE_END = NL + '</div>' + NL + '</div>' + NL;
  const ps = html.indexOf(PAGE_START);
  if (ps >= 0) { const pe = html.indexOf(PAGE_END, ps); if (pe < 0) throw new Error('page-logistics end not found'); html = html.slice(0, ps) + html.slice(pe + PAGE_END.length); }
  const JS_END = NL + '})();' + NL;
  const js0 = html.indexOf(NL + JS_MARK);
  if (js0 >= 0) { const je = html.indexOf(JS_END, js0); if (je < 0) throw new Error('logistics JS end not found'); html = html.slice(0, js0) + html.slice(je + JS_END.length); }
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
(function () {
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
})();
`;
  must('// ---- top-level page nav (grows as more reports get added) ----', 'nav handler');
  html = html.replace('// ---- top-level page nav (grows as more reports get added) ----', js + '\n// ---- top-level page nav (grows as more reports get added) ----');
  must("  if (btn.dataset.page === 'subcategoryanalyze') subcategoryScrollUpdate();", 'nav updaters');
  if (!html.includes("btn.dataset.page === 'logistics'")) html = html.replace("  if (btn.dataset.page === 'subcategoryanalyze') subcategoryScrollUpdate();", "  if (btn.dataset.page === 'subcategoryanalyze') subcategoryScrollUpdate();\n  if (btn.dataset.page === 'logistics' && window.logisticsScrollUpdaters) window.logisticsScrollUpdaters.forEach(f => f());");
}

fs.writeFileSync(htmlPath, html);
fs.writeFileSync(path.join(__dirname, 'extracted.js'), html.match(/<script>([\s\S]*)<\/script>/)[1]);
console.log('html bytes:', html.length);
