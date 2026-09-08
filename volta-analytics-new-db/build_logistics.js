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

// ---- snapshots
const cityOrder = ['Tbilisi', 'Other Cities', 'Without City'];
const cityRows = parseTsv('logi_city.tsv');
const byCityRows = cityOrder.map(label => { const r = cityRows.find(x => x.grp === label); return { label, notDelivered: r ? +r.notDelivered : 0, all: r ? +r.allOrders : 0 }; });
const totND = byCityRows.reduce((s, r) => s + r.notDelivered, 0), totAll = byCityRows.reduce((s, r) => s + r.all, 0);
byCityRows.forEach(r => r.share = totND ? r.notDelivered / totND : 0);
const byCity = { title: 'Orders by City', headLabel: 'City', rows: byCityRows, total: { label: 'Total', notDelivered: totND, all: totAll, share: totND ? 1 : 0 } };

const goods = {};
for (const l of parseTsv('logi_lines.tsv')) {
  const g = goods[goodsType(l.product_id)] ||= { notDelivered: 0, all: 0 };
  g.all++;
  if (l.not_delivered === '1') g.notDelivered++;
}
const goodsRows = Object.entries(goods).map(([label, g]) => ({ label, ...g })).sort((a, b) => (a.label === 'Uncategorized') - (b.label === 'Uncategorized') || b.all - a.all);
const gND = goodsRows.reduce((s, r) => s + r.notDelivered, 0), gAll = goodsRows.reduce((s, r) => s + r.all, 0);
goodsRows.forEach(r => r.share = gND ? r.notDelivered / gND : 0);
const byGoods = { title: 'Orders by Goods Type', headLabel: 'Goods Type', rows: goodsRows, total: { label: 'Total', notDelivered: gND, all: gAll, share: gND ? 1 : 0 } };

const STATUS_LABEL = { 20: 'დაწყებული / Started', 25: 'მოძიება / Procuring', 30: 'მზადაა მომწოდებელთან / Ready at vendor', 35: 'აღებულია მომწოდებლისგან / Collected', 40: 'საწყობისკენ / To warehouse', 45: 'საწყობშია / Warehouse', 48: 'სტატუსი 48', 50: 'გასაგზავნად მზადაა / Ready to ship', 60: 'გზაშია / Out for delivery', 80: 'მიტანილი / Delivered', 81: 'გატანილი / Picked up' };
const openCases = parseTsv('logi_open.tsv').map(r => ({
  customer: r.customer, waitingFrom: r.waiting_from,
  status: (r.logistics_status === '' || r.logistics_status === 'NULL') ? 'ლოგისტიკა არ დაწყებულა / Not started' : (STATUS_LABEL[r.logistics_status] || ('სტატუსი ' + r.logistics_status)),
  city: r.city || '–', orderNum: +r.order_id,
}));

const payload = { pending, delivery, byCity, byGoods, openCases, cutover: CUTOVER, generatedAt: new Date().toISOString().slice(0, 16).replace('T', ' ') + ' UTC' };
fs.writeFileSync(path.join(__dirname, 'logistics_data.json'), JSON.stringify(payload));
console.log('pending days:', pending.dates.length, '| delivery days:', delivery.dates.length, '| last pending:', pending.upTo1.at(-1), pending.oneTo5.at(-1), pending.over5.at(-1), '| city ND/all:', totND, totAll, '| goods rows:', goodsRows.length, '| open cases:', openCases.length);

// ---------------- inject into the HTML ----------------
const htmlPath = path.join(__dirname, 'deals_amount_migration.html');
let html = fs.readFileSync(htmlPath, 'utf8');
const must = (n, what) => { if (!html.includes(n)) throw new Error('anchor not found: ' + what); };

const CSS_MARK = '/* --- logistics daily (ported) --- */';
if (!html.includes(CSS_MARK)) {
  const css = `
${CSS_MARK}
table.logi-table { border-collapse: collapse; font-size: 11.5px; table-layout: auto; }
table.logi-table td { padding: 5px 8px; border: 1px solid #c9c9c9; text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; color: #111; }
table.logi-table td:first-child { text-align: left; font-variant-numeric: normal; position: sticky; left: 0; z-index: 2; box-shadow: 1px 0 0 #c9c9c9; }
table.logi-table tr.logi-title td { background: #2e5496; color: #fff; font-weight: 700; text-align: left; }
table.logi-table tr.logi-title td:first-child { z-index: 3; }
table.logi-table tr.logi-head td { background: #4472c4; color: #fff; font-weight: 700; }
table.logi-table tr.logi-total td { background: #d9e1f2; font-weight: 700; }
table.logi-table tr.logi-light td { background: #eaf0fa; }
table.logi-table tr.logi-white td { background: #fff; }
table.logi-table tr.logi-plain td { background: #fff; }
table.logi-table tr.logi-today td:first-child { font-style: italic; }
.logi-mini-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media (max-width: 760px) { .logi-mini-row { grid-template-columns: 1fr; } }
table.logi-mini { border-collapse: collapse; font-size: 12px; width: 100%; }
table.logi-mini td { padding: 7px 10px; border: none; border-bottom: 1px solid #e5e7eb; text-align: right; font-variant-numeric: tabular-nums; }
table.logi-mini td:first-child { text-align: left; font-variant-numeric: normal; }
table.logi-mini col.logi-mini-name { width: 46%; }
table.logi-mini tr.logi-mini-title td { background: #0d6e7c; color: #fff; font-weight: 700; text-align: left; }
table.logi-mini tr.logi-mini-head td { background: #1b2a4a; color: #fff; font-weight: 700; }
table.logi-mini tr.logi-mini-data td { color: #555; background: #fff; }
table.logi-mini tr.logi-mini-data td:first-child { color: #333; }
table.logi-mini tr.logi-mini-data.logi-mini-alt td { background: #f4f6f9; }
table.logi-mini tr.logi-mini-total td { color: #0d6e7c; font-weight: 700; background: #fff; border-top: 2px solid #0d6e7c; border-bottom: none; }
table.logi-mini tr.logi-mini-total td:first-child { color: #333; }
.logi-open-title { color: var(--text-primary); font-weight: 700; font-size: 13px; margin: 4px 0 -6px; }
table.logi-open { border-collapse: collapse; font-size: 12px; width: 100%; }
table.logi-open td { padding: 7px 10px; border-bottom: 1px solid #e5e7eb; text-align: left; color: #1f1f1f; background: #fff; }
table.logi-open tr.logi-open-head td { background: #4472c4; color: #fff; font-weight: 700; }
.table-card { background: var(--surface-1); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
`;
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
  <p class="note">Sales &ndash; Pending Status = loan applications still in the "Pending" status (<code>crm_order_status = 4</code>) at the end of each day, by how long the application has been open (days since it was submitted). Reconstructed exactly from the CRM's status-change log, so every day since the cutover is real history, not a snapshot; the last column is today as of the last refresh.</p>

  <div class="report-card">
    <div class="report-scroll-top" id="logisticsScrollTop"><div></div></div>
    <div class="report-scroll" id="logisticsScrollBody">
      <table class="logi-table" id="logisticsTable"><tbody></tbody></table>
    </div>
  </div>
  <p class="note">Not Delivered = active orders (sale date from the cutover) with no delivered / picked-up event yet at the end of that day; age buckets (&le;1 day / 1&ndash;5 days / &gt;5 days) are measured from the sale date. Delivered = orders whose first delivered or picked-up event (CRM logistics status 80/81) fell on that day; Average Delivery Time = average days from sale to that event. "On Hold" has no equivalent in the new CRM yet and stays blank. All rows are reconstructed from the CRM's shipment-status history.</p>

  <p class="logi-open-title">Open Cases &mdash; Still Waiting for Delivery</p>
  <div class="table-card">
    <table class="logi-open" id="logisticsOpenCasesTable"><tbody></tbody></table>
  </div>
  <p class="note">The 10 oldest active orders (by sale date) with no delivered / picked-up event &mdash; the customers who have been waiting longest. Status = the CRM logistics stage of the order.</p>

  <div class="logi-mini-row">
    <div>
      <p class="section-title">Orders by City</p>
      <table class="logi-mini" id="logisticsCityTable"><tbody></tbody></table>
    </div>
    <div>
      <p class="section-title">Orders by Goods Type</p>
      <table class="logi-mini" id="logisticsGoodsTable"><tbody></tbody></table>
    </div>
  </div>
  <p class="note">City = the order's shipping address (Tbilisi / other cities / no city recorded). Goods Type = the product's top-level category from the mapping sheet (the old sheet's Soft / Medium / Heavy weight classes do not exist in the new catalog). ALL Orders = active orders since the cutover, counted per product line; Share = share of the not-delivered lines.</p>
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

const JS_MARK = '/* ---------- Logistics Daily (ported from Volta_Analytics) ---------- */';
if (!html.includes(JS_MARK)) {
  const js = `
${JS_MARK}
(function () {
  const L = LOGI_JSON;
  const dayLabelL = d => { const [, m, day] = d.split('-').map(Number); return MONTH_NAMES[m - 1] + ' ' + day; };
  document.getElementById('logiBanner').innerHTML = '<b>წყარო:</b> ახალი CRM-ის ლოგისტიკის მოდული (VoltaStoreDB: <code>crm_order_logistics</code>, <code>crm_shipment_status_history</code>, სტატუსების ლოგი). ისტორია ' + L.cutover + '-დან ლოგებიდან ზუსტადაა აღდგენილი; ძველი ცხრილების (Google Sheet) წინა ისტორია ძველ დაშბორდზე რჩება. ლოგისტიკის მოდული 2 სექტემბრიდან მუშაობს, ამიტომ მიწოდების რიცხვები ჯერ მცირეა და ყოველდღე იზრდება.';

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
  function renderMini(tableId, data) {
    let html = '<tr class="logi-mini-title"><td colspan="4">' + data.title + '</td></tr>';
    html += '<tr class="logi-mini-head"><td>' + data.headLabel + '</td><td>Not Delivered Orders</td><td>ALL Orders</td><td>Share</td></tr>';
    data.rows.forEach((r, i) => { html += '<tr class="logi-mini-data' + (i % 2 === 0 ? ' logi-mini-alt' : '') + '"><td>' + r.label + '</td><td>' + fmt(r.notDelivered) + '</td><td>' + fmt(r.all) + '</td><td>' + pct(r.share) + '</td></tr>'; });
    const t = data.total;
    html += '<tr class="logi-mini-total"><td>' + t.label + '</td><td>' + fmt(t.notDelivered) + '</td><td>' + fmt(t.all) + '</td><td>' + pct(t.share) + '</td></tr>';
    document.getElementById(tableId).querySelector('tbody').innerHTML = html;
  }
  function renderOpen() {
    let html = '<tr class="logi-open-head"><td>Customer</td><td>Waiting from</td><td>Status</td><td>City</td><td>Order #</td></tr>';
    if (!L.openCases.length) html += '<tr><td colspan="5">No open cases.</td></tr>';
    L.openCases.forEach(c => { html += '<tr><td>' + c.customer + '</td><td>' + dayLabelL(c.waitingFrom) + '</td><td>' + c.status + '</td><td>' + c.city + '</td><td>' + c.orderNum + '</td></tr>'; });
    document.getElementById('logisticsOpenCasesTable').querySelector('tbody').innerHTML = html;
  }
  renderPending(); renderDelivery(); renderMini('logisticsCityTable', L.byCity); renderMini('logisticsGoodsTable', L.byGoods); renderOpen();
  window.logisticsScrollUpdaters = [setupTopScrollSync('logisticsSalesScrollTop', 'logisticsSalesScrollBody'), setupTopScrollSync('logisticsScrollTop', 'logisticsScrollBody')];
})();
`;
  must('// ---- top-level page nav (grows as more reports get added) ----', 'nav handler');
  html = html.replace('// ---- top-level page nav (grows as more reports get added) ----', js + '\n// ---- top-level page nav (grows as more reports get added) ----');
  must("  if (btn.dataset.page === 'subcategoryanalyze') subcategoryScrollUpdate();", 'nav updaters');
  html = html.replace("  if (btn.dataset.page === 'subcategoryanalyze') subcategoryScrollUpdate();", "  if (btn.dataset.page === 'subcategoryanalyze') subcategoryScrollUpdate();\n  if (btn.dataset.page === 'logistics' && window.logisticsScrollUpdaters) window.logisticsScrollUpdaters.forEach(f => f());");
}

fs.writeFileSync(htmlPath, html);
fs.writeFileSync(path.join(__dirname, 'extracted.js'), html.match(/<script>([\s\S]*)<\/script>/)[1]);
console.log('html bytes:', html.length);
