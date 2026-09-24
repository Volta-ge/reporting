#!/usr/bin/env node
// Daily Mail > Full Sales Funnel: website sessions (GA4) -> applications -> Volta's screening by status (with the
// rejection reasons) -> the customer's own declines (with their reasons) -> signed cases, one column per month of
// 2026. Reads funnel_apps.tsv (pull_new.sh) + the GA4 daily extract, writes funnel_data.json (gitignored) and
// funnel_ga4_month.tsv (committed: the live PHP page has no GA4 access, it reads this file), and injects the nav
// button / page / render JS / `const FUNNEL_JSON = …;` into deals_amount_migration.html between its own markers.
// Idempotent: nav button added once; page, JS and data line replaced on every run. Same injection idiom as
// build_logistics.js (CRLF-tolerant, replacer functions only — a plain replacement string would let a `$'` inside
// the generated JS be read as a regex back-reference and truncate the block).
//   node build_funnel.js            (DASH_HTML=<path> to patch another copy of the dashboard)
const fs = require('fs');
const path = require('path');

const MONTH_START = '2026-01';

function parseTsv(file) {
  if (!fs.existsSync(file)) return null;
  let c = fs.readFileSync(file, 'utf8');
  if (c.charCodeAt(0) === 0xFEFF) c = c.slice(1);
  const lines = c.split(/\r?\n/).filter(l => l.length);
  if (!lines.length) return [];
  const header = lines[0].split('\t');
  return lines.slice(1).map(line => { const cols = line.split('\t'); const o = {}; header.forEach((h, i) => o[h] = cols[i]); return o; });
}
function monthRange(from, to) {
  const out = [];
  let [y, m] = from.split('-').map(Number);
  const [ty, tm] = to.split('-').map(Number);
  while (y < ty || (y === ty && m <= tm)) { out.push(y + '-' + String(m).padStart(2, '0')); m++; if (m > 12) { m = 1; y++; } }
  return out;
}

// ---- reason vocabulary (Georgian as typed in the CRM -> canonical Georgian, English gloss). Kept in sync with
// NewDbReport::fullFunnel() (PHP twin) — same aliases, same glosses.
const REASON_ALIAS = {
  'პროდუქტის არ ქონა': 'პროდუქციის არ ქონა', 'დუბლი': 'დუბლირებული', 'დუბლირებული განაცხადი': 'დუბლირებული',
  'არ პასუხოსბ': 'არ პასუხობს', 'არ მპასუხობს': 'არ პასუხობს',
};
const REASON_EN = {
  'შეუსაბამო მონაცემები': 'Inconsistent data', 'მოვალეთა რეესტრი': "Debtors' registry", 'ვერ ვუკავშირდები': 'Cannot reach the customer',
  'დასაფარია მიმდინარე': 'Existing loan must be repaid first', 'გადახდისუუნარო': 'Insolvent', 'დუბლირებული': 'Duplicate application',
  'ხიშნიკი (NO SMS)': 'Suspected fraud (no SMS)', 'ხიშნიკი': 'Suspected fraud', 'კლიენტის უარი': 'Customer refused',
  'პროდუქციის არ ქონა': 'Product unavailable', 'უარი განვადებაზე': 'Installment refused', 'არ პასუხობს': 'Not responding',
  'აღარ არის დაინტერესებული': 'No longer interested', 'სხვა': 'Other', 'ავანსი': 'Down payment', 'მაღალი ფასი': 'Price too high',
  'საკონტაქტო პირებთან დაკავშირება': 'Contact-person check', 'მიტანის ვადა/პირობები': 'Delivery time / terms', 'მიტანის საფასური': 'Delivery fee',
  'ხანდაზმული განაცხადი': 'Expired application',
};
function reasonKey(raw) {
  let r = String(raw || '').trim().replace(/\.$/, '').trim();
  const dash = r.indexOf(' — ');           // "მოვალეთა რეესტრი — აქტიური ჩანაწერი" -> the base reason
  if (dash > 0) r = r.slice(0, dash).trim();
  return REASON_ALIAS[r] || r;
}
const MIN_REASON_TOTAL = 5;   // smaller free-text one-offs fold into Other

// ---- applications by month x status (state today)
const dir = __dirname;
const appsRows = parseTsv(path.join(dir, 'funnel_apps.tsv'));
if (!appsRows) throw new Error('funnel_apps.tsv missing — run bash pull_new.sh first');
// the extract runs through YESTERDAY (pull_new.sh's END, recorded in funnel_meta.tsv); the current month is the
// month of that day, so on the 1st the report still ends with the finished month and no empty column appears
const metaRows = parseTsv(path.join(dir, 'funnel_meta.tsv'));
const appsThrough = (metaRows && metaRows[0] && /^\d{4}-\d{2}-\d{2}$/.test(metaRows[0].through || '')) ? metaRows[0].through
  : (() => { const d = new Date(); d.setDate(d.getDate() - 1); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); })();
const curMonth = appsThrough.slice(0, 7);
let lastMonth = MONTH_START;
for (const r of appsRows) if (r.m > lastMonth) lastMonth = r.m;
const months = monthRange(MONTH_START, lastMonth > curMonth ? lastMonth : curMonth);
const mi = Object.fromEntries(months.map((m, i) => [m, i]));
const zeros = () => months.map(() => 0);

// Two gates, so the arithmetic closes at each one (verified against the DB 2026-09-23):
//   gate 1 — underwriting approval (crm_underwriter_status_id = 16): every signed loan from Feb 2026 on carries it;
//            the only signed loans without it are January's legacy loans migrated from the old CRM (128), counted
//            as approved too — they obviously were;
//   gate 2 — the customer: status 12 ("customer declined") is mostly set BEFORE any underwriting decision (the
//            customer drops out during the sales call: Pending/In processing), only a minority after approval.
// Section 3 (of the month's applications): rejected / customer withdrew / expired / open — all before approval —
// plus single-payment sales (no underwriting) and APPROVED. Section 4 (of the approved): signed / declined after
// approval / rejected or expired after approval / still open. Each section's rows sum to its base exactly.
// THREE gates since 2026-09-24 (user: "ცალკე გამოყე ეტაპებად, რაც სეილებმა დააუარეს და რაც კომიტეტმა"):
//   sales stage  — no underwriting decision recorded (cmt = 0): rejected by sales / withdrew / expired / awaiting /
//                  unreachable, plus single-payment sales (no underwriting at all);
//   committee    — an underwriting decision is recorded (cmt = 1), or the case is at the committee (8) or signed
//                  (January's legacy loans carry no underwriter status): rejected by the committee / withdrew there /
//                  expired / awaiting / unreachable, and APPROVED;
//   approved     — signed / declined after approval / rejected or expired after approval / still open.
// Each gate's rows sum to its base exactly: sales + committee = applications, committee rows = reached, approved rows = approved.
const S = { apps: zeros(), committee: zeros(),
  rejSales: zeros(), declSales: zeros(), expiredSales: zeros(), openSales: zeros(), otherSales: zeros(), singlePay: zeros(),
  rejCmt: zeros(), declCmt: zeros(), expiredCmt: zeros(), openCmt: zeros(), otherCmt: zeros(), approved: zeros(),
  signedActive: zeros(), declPost: zeros(), rejPost: zeros(), expiredPost: zeros(), openPost: zeros(), otherPost: zeros() };
const IN_PROCESS = new Set([4, 7, 8, 15, 16, 17, 9, 10]);
const SIGNED = new Set([11, 5, 1]);
const reasonAcc = { rejSales: {}, declSales: {}, rejCmt: {}, declCmt: {}, declPost: {} };
const addReason = (bucket, raw, i, n) => { const k = reasonKey(raw); (reasonAcc[bucket][k] ||= zeros())[i] += n; };
for (const r of appsRows) {
  const i = mi[r.m]; if (i === undefined) continue;
  const st = +r.st, n = +r.n, uw16 = r.uw16 === '1';
  S.apps[i] += n;
  if (st === 99) { S.singlePay[i] += n; continue; }
  const reached = r.cmt === '1' || SIGNED.has(st) || st === 8;
  if (!reached) {
    if (st === 6) { S.rejSales[i] += n; addReason('rejSales', r.reason, i, n); }
    else if (st === 12) { S.declSales[i] += n; addReason('declSales', r.reason, i, n); }
    else if (st === 13) S.expiredSales[i] += n;
    else if (IN_PROCESS.has(st)) S.openSales[i] += n;
    else S.otherSales[i] += n;
    continue;
  }
  S.committee[i] += n;
  if (SIGNED.has(st)) { S.approved[i] += n; S.signedActive[i] += n; continue; }
  if (uw16) {
    S.approved[i] += n;
    if (st === 12) { S.declPost[i] += n; addReason('declPost', r.reason, i, n); }
    else if (st === 6) S.rejPost[i] += n;
    else if (st === 13) S.expiredPost[i] += n;
    else if (IN_PROCESS.has(st)) S.openPost[i] += n;
    else S.otherPost[i] += n;
  } else {
    if (st === 6) { S.rejCmt[i] += n; addReason('rejCmt', r.reason, i, n); }
    else if (st === 12) { S.declCmt[i] += n; addReason('declCmt', r.reason, i, n); }
    else if (st === 13) S.expiredCmt[i] += n;
    else if (IN_PROCESS.has(st)) S.openCmt[i] += n;
    else S.otherCmt[i] += n;
  }
}
// self-check: the three gates must close for every month
months.forEach((m, i) => {
  const sales = S.rejSales[i] + S.declSales[i] + S.expiredSales[i] + S.openSales[i] + S.otherSales[i] + S.singlePay[i];
  const cmt = S.rejCmt[i] + S.declCmt[i] + S.expiredCmt[i] + S.openCmt[i] + S.otherCmt[i] + S.approved[i];
  const post = S.signedActive[i] + S.declPost[i] + S.rejPost[i] + S.expiredPost[i] + S.openPost[i] + S.otherPost[i];
  if (sales + cmt !== S.apps[i] || cmt !== S.committee[i] || post !== S.approved[i]) throw new Error(`funnel does not close for ${m}: apps ${S.apps[i]} vs ${sales + cmt}, committee ${S.committee[i]} vs ${cmt}, approved ${S.approved[i]} vs ${post}`);
});
function reasonRows(acc) {
  const rows = [];
  let other = null, unspecified = null;
  for (const [ka, vals] of Object.entries(acc)) {
    const total = vals.reduce((a, b) => a + b, 0);
    if (ka === '') { unspecified = (unspecified || zeros()).map((v, i) => v + vals[i]); continue; }
    if (total < MIN_REASON_TOTAL) { other = (other || zeros()).map((v, i) => v + vals[i]); continue; }
    rows.push({ ka, en: REASON_EN[ka] || '', vals, total });
  }
  // sorted by the LAST month's count (the most recent, most decision-relevant figure), not the Jan-Sep total —
  // a reason that was rare all year but just spiked (e.g. "ავანსი") should sort near the top, not buried by its
  // stale total; ties fall back to the total, then name, for stability
  const lastIdx = (months.length - 1);
  rows.sort((a, b) => (b.vals[lastIdx] - a.vals[lastIdx]) || (b.total - a.total) || (a.ka < b.ka ? -1 : a.ka > b.ka ? 1 : 0));
  if (other) rows.push({ ka: 'სხვა (იშვიათი)', en: 'Other (rare, < ' + MIN_REASON_TOTAL + ' in total)', vals: other, total: other.reduce((a, b) => a + b, 0), other: true });
  if (unspecified) rows.push({ ka: 'მიზეზი არ არის მითითებული', en: 'Unspecified', vals: unspecified, total: unspecified.reduce((a, b) => a + b, 0), unspecified: true });
  return rows.map(({ total, ...rest }) => rest);
}

// ---- GA4 sessions by month: from the daily extract when it is on this machine (aggregate -> committed monthly
//      file), else from the last committed monthly file
const ga4Daily = parseTsv(path.join(dir, '..', 'volta-ad-channels', 'channels_ga4_daily.tsv'));
const ga4MonthFile = path.join(dir, 'funnel_ga4_month.tsv');
let ga4Month;
if (ga4Daily && ga4Daily.length) {
  const acc = {};
  let through = '';
  for (const r of ga4Daily) {
    const m = r.d.slice(0, 7);
    const a = (acc[m] ||= { sessions: 0, engaged: 0, users: 0 });
    a.sessions += +r.sessions; a.engaged += +r.engaged_sessions; a.users += +r.users;
    if (r.d > through) through = r.d;
  }
  ga4Month = Object.keys(acc).sort().map(m => ({ m, ...acc[m], through }));
  fs.writeFileSync(ga4MonthFile, 'm\tsessions\tengaged_sessions\tusers\tthrough\n' + ga4Month.map(r => [r.m, r.sessions, r.engaged, r.users, r.through].join('\t')).join('\n') + '\n');
  console.log('funnel_ga4_month.tsv rewritten from the GA4 daily extract (through ' + through + ')');
} else {
  const rows = parseTsv(ga4MonthFile) || [];
  ga4Month = rows.map(r => ({ m: r.m, sessions: +r.sessions, engaged: +r.engaged_sessions, users: +r.users, through: r.through }));
  console.log('GA4 daily extract not present — using the committed funnel_ga4_month.tsv (' + rows.length + ' months)');
}
const ga4 = { sessions: months.map(() => null), engaged: months.map(() => null), users: months.map(() => null), through: null };
for (const r of ga4Month) {
  const i = mi[r.m]; if (i === undefined) continue;
  ga4.sessions[i] = r.sessions; ga4.engaged[i] = r.engaged; ga4.users[i] = r.users;
  if (r.through && (!ga4.through || r.through > ga4.through)) ga4.through = r.through;
}

const payload = {
  months, curMonth, monthStart: MONTH_START, appsThrough, ga4,
  ...S,
  rejectSalesReasons: reasonRows(reasonAcc.rejSales),
  declineSalesReasons: reasonRows(reasonAcc.declSales),
  rejectCommitteeReasons: reasonRows(reasonAcc.rejCmt),
  declineCommitteeReasons: reasonRows(reasonAcc.declCmt),
  declineAfterReasons: reasonRows(reasonAcc.declPost),
  generatedAt: new Date().toISOString().slice(0, 16).replace('T', ' ') + ' UTC',
};
fs.writeFileSync(path.join(dir, 'funnel_data.json'), JSON.stringify(payload));
const tot = a => a.reduce((x, y) => x + y, 0);
console.log('months:', months[0], '..', months[months.length - 1], '| applications:', tot(S.apps), '| rejected by sales:', tot(S.rejSales), '| withdrew (sales):', tot(S.declSales), '| reached committee:', tot(S.committee), '| rejected by committee:', tot(S.rejCmt), '| withdrew (committee):', tot(S.declCmt), '| approved:', tot(S.approved), '| signed/active:', tot(S.signedActive), '| declined after approval:', tot(S.declPost), '| single:', tot(S.singlePay));

// ---------------- inject into the HTML ----------------
const htmlPath = process.env.DASH_HTML ? path.resolve(process.env.DASH_HTML) : path.join(dir, 'deals_amount_migration.html');
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

// CSS (replaced on every run) — scoped to this page/table only: the shared rpt-pivot rules keep a pivot at its
// natural (narrow) width with a 150px label column, which squeezes a 10-column funnel into a third of the page
const CSS_MARK = '/* --- funnel --- */', CSS_END = '/* --- /funnel --- */';
removeBetween(NL + CSS_MARK, CSS_END, 'funnel css');
{
  const css = `
${CSS_MARK}
#page-fullfunnel .wrap { max-width: 1500px; }
#funnelTable { width: 100%; font-size: 12.5px; }
#funnelTable td { padding: 7px 10px; }
#funnelTable td:first-child { min-width: 300px; max-width: none; white-space: normal; line-height: 1.35; }
#funnelTable td:not(:first-child) { min-width: 84px; }
#funnelTable tr.rpt-colhead td { font-size: 11px; }
#funnelTable tr.rpt-title td { font-size: 13px; }
#funnelTable tr.rpt-footer td { white-space: normal; font-size: 11.5px; }
#funnelTable td.pct-cell { color: var(--rpt-muted); font-style: italic; }
#funnelTable tr.rpt-peach td.pct-cell, #funnelTable tr.rpt-peach-strong td.pct-cell, #funnelTable tr.rpt-green td.pct-cell { color: var(--rpt-ink); font-style: normal; }
#funnelTable .pct-inline { color: var(--rpt-muted); font-style: italic; font-weight: 400; }
${CSS_END}`;
  const cssAnchor = '.report-scroll-top > div { height: 1px; }';
  must(cssAnchor, 'css anchor');
  html = html.replace(cssAnchor, () => cssAnchor + css);
}

// nav button (added once, last item of the Daily Mail group)
if (!html.includes('data-page="fullfunnel"')) {
  const navAnchor = '<button data-page="dailystats">Daily Statistics</button>';
  must(navAnchor, 'Daily Mail nav');
  html = html.replace(navAnchor, () => navAnchor + NL + '        <button data-page="fullfunnel">Full Sales Funnel</button>');
}

// page markup (replaced on every run), right after the Daily Statistics page
const PAGE_START = '<!-- funnel-page-start -->', PAGE_END = '<!-- funnel-page-end -->';
// the block is inserted as "\n<start>…<end>" right after the anchor line, so remove exactly that span — the
// anchor's own trailing newline stays, and a rebuild never eats or accumulates blank lines
removeBetween(NL + PAGE_START, PAGE_END, 'funnel page');
{
  const page = `
${PAGE_START}
<div class="page" data-page="fullfunnel" id="page-fullfunnel">
<div class="wrap">
  <p class="section-title">Full Sales Funnel &mdash; from website sessions to signed cases</p>
  <p class="note">One column per calendar month of 2026 (the current month is month-to-date, through yesterday). Website sessions are the month's traffic; every other row is the applications <b>submitted in that month</b> and the status each of them was in <b>at the end of yesterday</b> (today's changes are not in yet) &mdash; a recent month keeps moving on refresh as its applications get decided. Three gates, in the order a case travels: <b>sales</b> screen the applications first (3 rejected by sales, 3.1 still with sales / unreachable / expired, 4 the customer withdrew there) and pass the rest on to the <b>committee</b> (5); the committee rejects (6), leaves some waiting (6.1), loses some to the customer (7) and <b>approves</b> the rest (8); of the approved, 8.1 fall out on Volta's side, 9 are declined by the customer and the rest are signed (10, together with the single-payment sales that need no underwriting). Each percentage row names its base. Tap a section header to collapse it.</p>
  <div class="banner" id="funnelBanner"></div>
  <div class="report-card">
    <div class="report-scroll-top" id="funnelScrollTop"><div></div></div>
    <div class="report-scroll" id="funnelScrollBody">
      <table class="rpt rpt-pivot rpt-pivot-compact" id="funnelTable"><tbody></tbody></table>
    </div>
  </div>
</div>
</div>
${PAGE_END}`;
  const pageAnchor = '</div><!-- /page dailystats -->';
  must(pageAnchor, 'dailystats page end');
  html = html.replace(pageAnchor, () => pageAnchor + page);
}

// render JS + data line (replaced on every run), just before the Sales Analyze script section
const JS_START = '/* ---------- Full Sales Funnel ---------- */', JS_END = '/* ---------- /Full Sales Funnel ---------- */';
// inserted as "<start>…<end>\n" right before the anchor line, so remove exactly that span (see the page note above)
removeBetween(JS_START, JS_END + NL, 'funnel js');
{
  const dataLine = 'const FUNNEL_JSON = ' + JSON.stringify(payload) + ';';
  const js = `
${JS_START}
${dataLine}
(function () {
  const F = FUNNEL_JSON;
  const DASH = '&ndash;';
  const fmtN = n => (n === null || n === undefined) ? null : Math.round(n).toLocaleString('en-US');
  const fmtP = (n, d) => (n === null || n === undefined || d === null || d === undefined) ? null : (d ? (n / d * 100).toLocaleString('en-US', { maximumFractionDigits: 1 }) + '%' : '0%');
  const sum = a => a.reduce((x, y) => x + (y || 0), 0);
  const MN = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  const mLabel = m => { const [y, mo] = m.split('-').map(Number); return MN[mo - 1] + ' ' + y + (m === F.curMonth ? ' (MTD)' : ''); };
  const n = F.months.length, colspan = n + 2;
  const cell = (v, cls) => (v === null || v === undefined) ? '<td class="dash ' + (cls || '') + '">' + DASH + '</td>' : '<td class="' + (cls || '') + '">' + v + '</td>';
  let secN = 0;
  const secRow = (cls, text) => { const id = 'fsec' + (++secN); return { id, row: '<tr class="' + cls + '" data-toggle="' + id + '"><td colspan="' + colspan + '"><span class="rpt-chevron">&#9662;</span>' + text + '</td></tr>' }; };
  const plain = (cls, text) => '<tr class="' + cls + '"><td colspan="' + colspan + '">' + text + '</td></tr>';
  // one row: label + one value per month + a total column; vals = numbers (or nulls), tot = number|null|'skip'
  const row = (sec, cls, label, vals, tot, isPct, denom) => {
    let tds = '';
    for (let i = 0; i < n; i++) tds += isPct ? cell(fmtP(vals[i], denom ? denom[i] : null), 'pct-cell') : cell(fmtN(vals[i]));
    const t = tot === 'skip' ? null : (isPct ? fmtP(tot, denom ? sum(denom) : null) : fmtN(tot));
    return '<tr class="' + cls + '" data-sec="' + sec + '"><td>' + label + '</td>' + tds + cell(t, isPct ? 'pct-cell' : '') + '</tr>';
  };
  const cnt = (sec, cls, label, vals) => row(sec, cls, label, vals, sum(vals), false);
  const rate = (sec, label, num, den) => row(sec, 'rpt-plain', '<span style="color:var(--text-muted)">' + label + '</span>', num, sum(num), true, den);
  // a breakdown row: the count, then in the same cell its share of the stage it belongs to (denom = that stage's row)
  const cntPct = (sec, cls, label, vals, denom) => {
    const c = (v, d) => { if (v === null || v === undefined) return cell(null); const p = fmtP(v, d); return '<td>' + fmtN(v) + (p === null ? '' : ' <span class="pct-inline">(' + p + ')</span>') + '</td>'; };
    let tds = '';
    for (let i = 0; i < n; i++) tds += c(vals[i], denom ? denom[i] : null);
    return '<tr class="' + cls + '" data-sec="' + sec + '"><td>' + label + '</td>' + tds + c(sum(vals), denom ? sum(denom) : null) + '</tr>';
  };
  // every breakdown list is ordered by the LAST month's count (the current picture), ties by the total
  const byLastMonth = (a, b) => (b.vals[n - 1] - a.vals[n - 1]) || (sum(b.vals) - sum(a.vals));
  const anyGa4 = F.ga4.sessions.some(v => v !== null);
  const ga4Total = arr => anyGa4 ? sum(arr) : null;

  let h = '';
  h += plain('rpt-title', 'Full Sales Funnel &mdash; website sessions &rarr; applications &rarr; Volta\\'s decision &rarr; customer\\'s decision &rarr; signed cases');
  const longDate = d => { if (!d) return ''; const [y, mo, da] = d.split('-').map(Number); return da + ' ' + MN[mo - 1] + ' ' + y; };
  h += plain('rpt-title', 'One column per calendar month of 2026 &middot; applications submitted 1 Jan &ndash; ' + longDate(F.appsThrough) + ' (through yesterday), by the status each was in at the end of that day');
  h += '<tr class="rpt-colhead"><td>Funnel stage / metric</td>' + F.months.map(m => '<td>' + mLabel(m) + '</td>').join('') + '<td>Total</td></tr>';

  const s1 = secRow('rpt-section', '1. Website traffic (GA4)'); h += s1.row;
  h += row(s1.id, 'rpt-peach', 'Website sessions', F.ga4.sessions, ga4Total(F.ga4.sessions), false);

  const s2 = secRow('rpt-section', '2. Applications'); h += s2.row;
  h += cnt(s2.id, 'rpt-peach-strong', 'Applications submitted', F.apps);
  h += row(s2.id, 'rpt-plain', '<span style="color:var(--text-muted)">Applications as % of website sessions</span>', F.apps, sum(F.apps), true, F.ga4.sessions);

  // reason rows: sorted by the last month, each count with its share of the stage's own total (denom)
  const reasonRowsHtml = (sec, rows, denom) => { let s = ''; for (const r of rows.slice().sort(byLastMonth)) s += cntPct(sec, 'rpt-plain', '&nbsp;&nbsp;&middot; ' + r.ka + (r.en ? ' <span style="color:var(--text-muted)">(' + r.en + ')</span>' : ''), r.vals, denom); return s; };
  // a sub-stage (3.1 / 6.1 / 8.1): its header belongs to the parent section (hidden when it collapses) and toggles its
  // own rows; a total + share row like every other stage, then its items sorted by the last month
  const subStage = (parent, title, totalLabel, totals, shareLabel, denom, items) => {
    const id = 'fsec' + (++secN);
    let s = '<tr class="rpt-section" data-sec="' + parent + '" data-toggle="' + id + '"><td colspan="' + colspan + '"><span class="rpt-chevron">&#9662;</span>' + title + '</td></tr>';
    s += cnt(id, 'rpt-peach', totalLabel, totals);
    s += rate(id, shareLabel, totals, denom);
    for (const it of items.filter(it => sum(it.vals) || it.always).sort(byLastMonth)) s += cntPct(id, 'rpt-plain', '&nbsp;&nbsp;&middot; ' + it.label, it.vals, totals);
    return s;
  };
  // the sub-stage totals, needed both for their own rows and for the check at the end
  const st31 = F.months.map((m, i) => F.expiredSales[i] + F.openSales[i] + F.otherSales[i]);
  const st61 = F.months.map((m, i) => F.expiredCmt[i] + F.openCmt[i] + F.otherCmt[i]);
  const st81 = F.months.map((m, i) => F.rejPost[i] + F.expiredPost[i] + F.openPost[i] + F.otherPost[i]);

  // ---- gate 1: the sales screening (3 + 3.1 + 4 + 5 + single-payment sales in 10 = Applications)
  const s3 = secRow('rpt-section', '3. Rejected by sales &mdash; before the committee, no underwriting decision (3 + 3.1 + 4 + 5 + single-payment sales in 10 = Applications)'); h += s3.row;
  h += cnt(s3.id, 'rpt-peach', 'Rejected by sales (status 6, no underwriting decision)', F.rejSales);
  h += rate(s3.id, '% of applications', F.rejSales, F.apps);
  h += reasonRowsHtml(s3.id, F.rejectSalesReasons, F.rejSales);
  h += subStage(s3.id, '3.1 Other statuses at the sales stage', 'Other statuses at the sales stage (awaiting, unreachable, expired)', st31, '% of applications', F.apps, [
    { label: 'Still with sales, awaiting (status 4 / 7)', vals: F.openSales, always: true },
    { label: 'Customer unreachable &mdash; &ldquo;უკონტაქტო&rdquo; (status 14)', vals: F.otherSales },
    { label: 'Expired at the sales stage (status 13)', vals: F.expiredSales },
  ]);

  const s4 = secRow('rpt-section', '4. Customer withdrew at the sales stage &mdash; before the committee'); h += s4.row;
  h += cnt(s4.id, 'rpt-peach', 'Customer withdrew at the sales stage (status 12, no underwriting decision)', F.declSales);
  h += rate(s4.id, '% of applications', F.declSales, F.apps);
  h += reasonRowsHtml(s4.id, F.declineSalesReasons, F.declSales);

  const s5 = secRow('rpt-section', '5. Reached the committee = 2 Applications &minus; 3 &minus; 3.1 &minus; 4 &minus; single-payment sales (6 + 6.1 + 7 + 8 = 5)'); h += s5.row;
  h += cnt(s5.id, 'rpt-peach-strong', 'Passed on to the committee (an underwriting decision recorded, at the committee, or signed)', F.committee);
  h += rate(s5.id, '% of applications', F.committee, F.apps);

  // ---- gate 2: the committee (6 + 6.1 + 7 + 8 = Reached the committee)
  const s6 = secRow('rpt-section', '6. Rejected by the committee &mdash; underwriting said no'); h += s6.row;
  h += cnt(s6.id, 'rpt-peach', 'Rejected by the committee (status 6, underwriting decision recorded)', F.rejCmt);
  h += rate(s6.id, '% of reached the committee', F.rejCmt, F.committee);
  h += reasonRowsHtml(s6.id, F.rejectCommitteeReasons, F.rejCmt);
  h += subStage(s6.id, '6.1 Other committee statuses', 'Other committee statuses (awaiting a decision, unreachable, expired)', st61, '% of reached the committee', F.committee, [
    { label: 'At the committee / clarification needed, awaiting a decision (status 8 / 15)', vals: F.openCmt, always: true },
    { label: 'Customer unreachable &mdash; &ldquo;უკონტაქტო&rdquo; (status 14)', vals: F.otherCmt },
    { label: 'Expired at the committee (status 13)', vals: F.expiredCmt },
  ]);

  const s7 = secRow('rpt-section', '7. Customer withdrew at the committee stage &mdash; before approval'); h += s7.row;
  h += cnt(s7.id, 'rpt-peach', 'Customer withdrew at the committee stage (status 12, before approval)', F.declCmt);
  h += rate(s7.id, '% of reached the committee', F.declCmt, F.committee);
  h += reasonRowsHtml(s7.id, F.declineCommitteeReasons, F.declCmt);

  // ---- gate 3: approval (8.1 + 9 + Signed in 10 = Approved)
  const s8 = secRow('rpt-section', '8. Approved by underwriting (= 8.1 + 9 + Signed in 10)'); h += s8.row;
  h += cnt(s8.id, 'rpt-peach', 'Approved by underwriting (underwriting status 16)', F.approved);
  h += rate(s8.id, '% of reached the committee', F.approved, F.committee);
  h += rate(s8.id, '% of applications', F.approved, F.apps);
  h += subStage(s8.id, '8.1 Other underwriting statuses', 'Other underwriting statuses (rejected / expired after approval, in process, cancelled)', st81, '% of approved', F.approved, [
    { label: 'Rejected after approval (status 6)', vals: F.rejPost, always: true },
    { label: 'Expired after approval (status 13)', vals: F.expiredPost, always: true },
    { label: 'Approved, still in process (16 / 17 / 9 / 10)', vals: F.openPost, always: true },
    { label: 'Signed, then cancelled (status 18)', vals: F.otherPost },
  ]);

  const s9 = secRow('rpt-section', '9. Customer declined after approval &mdash; although underwriting approved'); h += s9.row;
  h += cnt(s9.id, 'rpt-peach', 'Customer declined after approval (status 12)', F.declPost);
  h += rate(s9.id, '% of approved', F.declPost, F.approved);
  h += reasonRowsHtml(s9.id, F.declineAfterReasons, F.declPost);

  const s10 = secRow('rpt-section-strong', '10. Final agreement between the customer and Volta'); h += s10.row;
  h += cnt(s10.id, 'rpt-green', 'Signed / active installment (status 11 / 5 / 1)', F.signedActive);
  h += cnt(s10.id, 'rpt-plain', 'Single-payment sales (status 99, no underwriting)', F.singlePay);
  const finalAll = F.months.map((m, i) => F.signedActive[i] + F.singlePay[i]);
  h += cnt(s10.id, 'rpt-green', 'Total final agreements (signed + single payment)', finalAll);
  h += rate(s10.id, '% of applications', finalAll, F.apps);
  h += row(s10.id, 'rpt-plain', '<span style="color:var(--text-muted)">Final agreements as % of website sessions</span>', finalAll, sum(finalAll), true, F.ga4.sessions);

  // Check — not a stage, a comparison: the stages subtracted from the applications must leave 0 every month.
  // One chain: 2 − 3 − 3.1 − 4 − 6 − 6.1 − 7 − 8.1 − 9 − 10. The two subtotals are not in it: 5 (reached the
  // committee) is fully split into 6 + 6.1 + 7 + 8, and 8 (approved) into 8.1 + 9 + the signed cases inside 10
  const check = F.months.map((m, i) => F.apps[i] - F.rejSales[i] - st31[i] - F.declSales[i] - F.rejCmt[i] - st61[i] - F.declCmt[i] - st81[i] - F.declPost[i] - finalAll[i]);
  const checkRow = (sec, label, vals) => {
    const c = v => '<td style="color:' + (v === 0 ? 'var(--good)' : 'var(--critical)') + ';font-weight:700">' + (v === 0 ? '0 &#10003;' : fmtN(v)) + '</td>';
    return '<tr class="rpt-plain" data-sec="' + sec + '"><td>' + label + '</td>' + vals.map(c).join('') + c(sum(vals)) + '</tr>';
  };
  const sChk = secRow('rpt-section', 'Check &mdash; stages subtracted from each other (must be 0 in every month)'); h += sChk.row;
  h += checkRow(sChk.id, '2 Applications &minus; 3 Rejected by sales &minus; 3.1 Other at the sales stage &minus; 4 Withdrew at the sales stage &minus; 6 Rejected by the committee &minus; 6.1 Other committee statuses &minus; 7 Withdrew at the committee stage &minus; 8.1 Other underwriting statuses &minus; 9 Declined after approval &minus; 10 Final agreements', check);

  h += '<tr class="rpt-footer"><td colspan="' + colspan + '">Source: VoltaStoreDB only (no old-DB history in this tab). <b>Applications</b> = <code>orders</code> rows by application month (<code>created_at</code>), 1 January through ' + longDate(F.appsThrough) + ' &mdash; yesterday, like every other Daily Mail figure; today\\'s applications are never included &mdash; including the applications migrated from the old CRM for January&ndash;August, so the counts differ slightly from the Daily Mail Report tab (which reads those months from myvolta.info). <b>Three gates.</b> Reached the committee (5) = an underwriting decision is recorded on the case (<code>crm_underwriter_status_id</code> set &mdash; the same concept for the January&ndash;August months migrated from the old CRM, which have no status log), or the case is at the committee (status 8), or it is signed; a committee entry logged after yesterday does not count yet. Everything else was screened out by sales (3, 3.1, 4) or is a single-payment sale (in 10), so 3 + 3.1 + 4 + 5 + single-payment = Applications. Approved (8) = <code>crm_underwriter_status_id = 16</code> (every signed loan from February 2026 on carries it; January\\'s 128 signed loans without it are legacy loans migrated from the old CRM and are counted as approved), so 6 + 6.1 + 7 + 8 = 5; and 8.1 + 9 + Signed = 8. <b>Statuses</b> = <code>crm_order_status</code> as of the end of yesterday (' + longDate(F.appsThrough) + '): where a status changed after that, the earlier status is taken back from <code>crm_activity_log</code>, and an approval logged after that day does not count yet &mdash; so the whole tab is one consistent end-of-day picture. The same code list the Operations tab verified against the CRM: 6 = rejected (by sales when no underwriting decision is recorded, by the committee when one is), 12 = the customer declined (split the same way: at the sales stage, at the committee stage, or after approval), 13 = expired, 14 = &ldquo;უკონტაქტო&rdquo; (the customer could not be reached; the CRM log\\'s own wording), 11 / 5 / 1 = contract signed / loan active / legacy active, 18 = signed and activated, then cancelled (Bagisto status canceled, close type 4), 99 = single-payment sale (paid in full, no underwriting); everything else is still in process (4 Pending, 7 In processing, 8 At committee, 15 Clarification needed, 16 Approved, 17 Disbursement in process, 9 Invoice &amp; contract draft sent, 10 Contract sent for signing). <b>Reasons</b> = <code>orders.crm_reason</code> as recorded on the application (present on ~99% of rejected and declined applications in every month of 2026); spelling variants are merged (e.g. \\u201Cპროდუქტის არ ქონა\\u201D into \\u201Cპროდუქციის არ ქონა\\u201D, an em-dash suffix such as \\u201C&mdash; აქტიური ჩანაწერი\\u201D dropped), reasons with fewer than ' + ${MIN_REASON_TOTAL} + ' applications in total are grouped as Other (rare). <b>Website sessions</b> = Google Analytics 4 (property 369140604, all traffic, not only paid), summed per month' + (F.ga4.through ? ', data through ' + F.ga4.through : '') + '; the two \\u201Cas % of website sessions\\u201D rows divide the month\\'s applications / final agreements by that month\\'s sessions. Generated ' + F.generatedAt + '.</td></tr>';

  function render() {
    const table = document.getElementById('funnelTable');
    if (!table) return;
    table.querySelector('tbody').innerHTML = h;
    table.querySelectorAll('tr[data-toggle]').forEach(header => {
      header.addEventListener('click', () => {
        const id = header.getAttribute('data-toggle');
        const chevron = header.querySelector('.rpt-chevron');
        const rows = Array.from(table.querySelectorAll('tr[data-sec="' + id + '"]'));
        // one level of nesting (3 > 3.1): a sub-stage header inside this section takes its own rows along
        rows.slice().forEach(r => { const sub = r.getAttribute('data-toggle'); if (sub) rows.push(...table.querySelectorAll('tr[data-sec="' + sub + '"]')); });
        const collapsing = rows.length && !rows[0].classList.contains('is-collapsed');
        rows.forEach(r => r.classList.toggle('is-collapsed', collapsing));
        if (chevron) chevron.style.transform = collapsing ? 'rotate(-90deg)' : 'rotate(0deg)';
      });
    });
    const banner = document.getElementById('funnelBanner');
    if (banner) banner.innerHTML = '<b>How to read it:</b> each month starts with the website sessions Google Analytics counted, then the applications submitted that month, then the sales screening (rejected by sales with the recorded reason, still with sales, the customer withdrew there with their reason) and how many were passed on to the committee, then the committee\\'s decision on those (rejected with the reason, still waiting, the customer withdrew there, approved), then the approved ones that did not end in a contract (rejected or expired after approval, still in process, declined by the customer with their reason), and finally the signed installment contracts and single-payment sales together. 3 + 3.1 + 4 + 5 + single-payment sales = applications; 6 + 6.1 + 7 + 8 = reached the committee; 8.1 + 9 + signed = approved' + (anyGa4 ? '' : ' &mdash; GA4 sessions are not available in this build (funnel_ga4_month.tsv missing)') + '.';
    if (window.__funnelScrollUpdate) window.__funnelScrollUpdate();
  }
  if (document.getElementById('funnelScrollTop') && !window.__funnelScrollUpdate) window.__funnelScrollUpdate = setupTopScrollSync('funnelScrollTop', 'funnelScrollBody');
  if (window.__registerPage) window.__registerPage(['fullfunnel'], render); else render();
})();
${JS_END}`;
  const jsAnchor = '/* ---------- Sales Analyze (ported from Volta_Analytics) ---------- */';
  must(jsAnchor, 'Sales Analyze JS section');
  html = html.replace(jsAnchor, () => js.slice(1) + NL + jsAnchor);
}

if (__hadCRLF) html = html.replace(/\n/g, '\r\n');
fs.writeFileSync(htmlPath, html);
console.log('injected Full Sales Funnel into ' + htmlPath);
