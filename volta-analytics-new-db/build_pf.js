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
const dataLine = 'const PF_JSON = ' + JSON.stringify(payload) + ';';
if (/^const PF_JSON = .*;$/m.test(html)) html = html.replace(/^const PF_JSON = .*;$/m, () => dataLine);
else { must('const generatedAt = REPORT_JSON.generatedAt;', 'report consts'); html = html.replace('const generatedAt = REPORT_JSON.generatedAt;', 'const generatedAt = REPORT_JSON.generatedAt;' + NL + dataLine); }

// render code
const JS_MARK = '/* ---------- pf ---------- */', JS_END = '/* ---------- /pf ---------- */';
cutBetween(NL + JS_MARK, JS_END + NL, 'pf js');
{
  const js = `
${JS_MARK}
(function () {
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
})();
${JS_END}
`;
  must('// ---- top-level page nav (grows as more reports get added) ----', 'nav handler');
  html = html.replace('// ---- top-level page nav (grows as more reports get added) ----', js + '// ---- top-level page nav (grows as more reports get added) ----');
  const navLine = "  if (btn.dataset.page === 'subcategoryanalyze') subcategoryScrollUpdate();";
  must(navLine, 'nav updaters');
  if (!html.includes("btn.dataset.page === 'portfolio'")) html = html.replace(navLine, navLine + NL + "  if (btn.dataset.page === 'portfolio' && window.pfScrollUpdaters) window.pfScrollUpdaters.forEach(f => f());");
}

fs.writeFileSync(htmlPath, html);
console.log('html:', htmlPath, 'bytes:', html.length);
