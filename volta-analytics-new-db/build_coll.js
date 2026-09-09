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
const dataLine = 'const COLL_JSON = ' + JSON.stringify(payload) + ';';
if (/^const COLL_JSON = .*;$/m.test(html)) html = html.replace(/^const COLL_JSON = .*;$/m, () => dataLine);
else { must('const generatedAt = REPORT_JSON.generatedAt;', 'report consts'); html = html.replace('const generatedAt = REPORT_JSON.generatedAt;', () => 'const generatedAt = REPORT_JSON.generatedAt;' + NL + dataLine); }

// render IIFE
const JS_MARK = '/* ---------- coll ---------- */', JS_END = '/* ---------- /coll ---------- */';
removeBetween(JS_MARK, JS_END, 'coll js');
const js = `
${JS_MARK}
(function () {
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
})();
${JS_END}
`;
must('// ---- top-level page nav (grows as more reports get added) ----', 'nav handler');
html = html.replace('// ---- top-level page nav (grows as more reports get added) ----', () => js.replace(/^\n/, '') + '// ---- top-level page nav (grows as more reports get added) ----');
must("  if (btn.dataset.page === 'subcategoryanalyze') subcategoryScrollUpdate();", 'nav updaters');
if (!html.includes("btn.dataset.page === 'collections'")) html = html.replace("  if (btn.dataset.page === 'subcategoryanalyze') subcategoryScrollUpdate();", "  if (btn.dataset.page === 'subcategoryanalyze') subcategoryScrollUpdate();" + NL + "  if (btn.dataset.page === 'collections' && window.collScrollUpdaters) window.collScrollUpdaters.forEach(f => f());");

fs.writeFileSync(htmlPath, html);
console.log('html:', htmlPath, 'bytes:', html.length);
