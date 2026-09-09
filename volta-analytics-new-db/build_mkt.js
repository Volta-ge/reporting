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
const dataLine = 'const MKT_JSON = ' + JSON.stringify(payload) + ';';
if (/^const MKT_JSON = .*;$/m.test(html)) html = html.replace(/^const MKT_JSON = .*;$/m, () => dataLine);
else {
  must('const generatedAt = REPORT_JSON.generatedAt;', 'report consts');
  html = html.replace('const generatedAt = REPORT_JSON.generatedAt;', 'const generatedAt = REPORT_JSON.generatedAt;' + NL + dataLine);
}

// render code (re-injected on every run)
const JS_MARK = '/* ---------- mkt ---------- */', JS_END = '/* ---------- /mkt ---------- */';
removeBetween(JS_MARK, JS_END + NL, 'mkt js');
{
  const js = NL + JS_MARK + `
(function () {
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
})();
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

fs.writeFileSync(htmlPath, html);
console.log('html:', htmlPath, 'bytes:', html.length);
