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
const dataLine = 'const OPS_JSON = ' + JSON.stringify(payload) + ';';
if (/^const OPS_JSON = .*;$/m.test(html)) html = html.replace(/^const OPS_JSON = .*;$/m, () => dataLine);
else { must('const generatedAt = REPORT_JSON.generatedAt;', 'report consts'); html = html.replace('const generatedAt = REPORT_JSON.generatedAt;', 'const generatedAt = REPORT_JSON.generatedAt;' + NL + dataLine); }

// render IIFE (no backticks / ${ inside: it lives in a template literal)
const JS_MARK = '/* ---------- ops ---------- */', JS_END = '/* ---------- /ops ---------- */';
removeBlock(JS_MARK, JS_END, true);
const js = `
${JS_MARK}
(function () {
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
})();
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

fs.writeFileSync(htmlPath, html);
console.log('html:', htmlPath, 'bytes:', html.length);
