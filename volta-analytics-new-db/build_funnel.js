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
// Per-application extract (pull_new.sh funnel_drill.tsv, gitignored: same as-of-END status / approval / committee /
// reason expressions as funnel_apps.tsv, one row per application plus its date, amount, items and sales manager).
// When present every figure is counted from it, one row = one application, so the drill-down list behind each
// cell (user, 2026-11-12: "რომელიმე ციფრზე რომ დავდგები ... იმ მათზე მონაცემები გამოვიდეს, რომლებიც ამ ციფრის
// უკან დგას") adds up to the cell exactly; the grouped funnel_apps.tsv stays the fallback (no drill-down then).
const drillRows = parseTsv(path.join(dir, 'funnel_drill.tsv'));
if (!appsRows && !drillRows) throw new Error('funnel_apps.tsv missing — run bash pull_new.sh first');
const srcRows = drillRows ? drillRows.map(r => ({ ...r, m: String(r.d).slice(0, 7), n: '1' })) : appsRows;
// the extract runs through YESTERDAY (pull_new.sh's END, recorded in funnel_meta.tsv); the current month is the
// month of that day, so on the 1st the report still ends with the finished month and no empty column appears
const metaRows = parseTsv(path.join(dir, 'funnel_meta.tsv'));
const appsThrough = (metaRows && metaRows[0] && /^\d{4}-\d{2}-\d{2}$/.test(metaRows[0].through || '')) ? metaRows[0].through
  : (() => { const d = new Date(); d.setDate(d.getDate() - 1); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); })();
const curMonth = appsThrough.slice(0, 7);
let lastMonth = MONTH_START;
for (const r of srcRows) if (r.m > lastMonth) lastMonth = r.m;
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
for (const r of srcRows) {
  const i = mi[r.m]; if (i === undefined) continue;
  const st = +r.st, n = +r.n, uw16 = r.uw16 === '1';
  S.apps[i] += n;
  if (st === 99) { S.singlePay[i] += n; r.b = 'singlePay'; continue; }
  const reached = r.cmt === '1' || SIGNED.has(st) || st === 8;
  if (!reached) {
    if (st === 6) { S.rejSales[i] += n; r.b = 'rejSales'; addReason('rejSales', r.reason, i, n); }
    else if (st === 12) { S.declSales[i] += n; r.b = 'declSales'; addReason('declSales', r.reason, i, n); }
    else if (st === 13) { S.expiredSales[i] += n; r.b = 'expiredSales'; }
    else if (IN_PROCESS.has(st)) { S.openSales[i] += n; r.b = 'openSales'; }
    else { S.otherSales[i] += n; r.b = 'otherSales'; }
    continue;
  }
  S.committee[i] += n;
  if (SIGNED.has(st)) { S.approved[i] += n; { S.signedActive[i] += n; r.b = 'signedActive'; } continue; }
  if (uw16) {
    S.approved[i] += n;
    if (st === 12) { S.declPost[i] += n; r.b = 'declPost'; addReason('declPost', r.reason, i, n); }
    else if (st === 6) { S.rejPost[i] += n; r.b = 'rejPost'; }
    else if (st === 13) { S.expiredPost[i] += n; r.b = 'expiredPost'; }
    else if (IN_PROCESS.has(st)) { S.openPost[i] += n; r.b = 'openPost'; }
    else { S.otherPost[i] += n; r.b = 'otherPost'; }
  } else {
    if (st === 6) { S.rejCmt[i] += n; r.b = 'rejCmt'; addReason('rejCmt', r.reason, i, n); }
    else if (st === 12) { S.declCmt[i] += n; r.b = 'declCmt'; addReason('declCmt', r.reason, i, n); }
    else if (st === 13) { S.expiredCmt[i] += n; r.b = 'expiredCmt'; }
    else if (IN_PROCESS.has(st)) { S.openCmt[i] += n; r.b = 'openCmt'; }
    else { S.otherCmt[i] += n; r.b = 'otherCmt'; }
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

// ---- drill-down: every counted application with its leaf bucket (the 17 mutually exclusive outcomes above; the
// table's other rows are unions of them), compact column arrays. Kept identical to NewDbReport::funnelDrill() (PHP
// twin). No customer data: the page is also published on the public GitHub Pages — case number, application day,
// items, amount, status, sales manager only, and the reason only as the table's own reason rows (a free-text reason
// used < MIN_REASON_TOTAL times is folded into "Other (rare)" here too, its text can describe the customer's situation).
function funnelDrill() {
  if (!drillRows) return null;
  const BUCKETS = ["rejSales", "declSales", "expiredSales", "openSales", "otherSales", "singlePay", "rejCmt", "declCmt", "expiredCmt", "openCmt", "otherCmt", "signedActive", "declPost", "rejPost", "expiredPost", "openPost", "otherPost"];
  const bi = Object.fromEntries(BUCKETS.map((x, i) => [x, i]));
  // the reason rows the table shows for each bucket (same rule as reasonRows: named once used >= MIN_REASON_TOTAL times)
  const named = {};
  for (const [bk, acc] of Object.entries(reasonAcc)) named[bk] = new Set(Object.entries(acc).filter(([k, v]) => k !== '' && v.reduce((x, y) => x + y, 0) >= MIN_REASON_TOTAL).map(([k]) => k));
  const reasons = [], items = [], mgrs = [];
  const ri = {}, ii = {}, gi = {};
  const intern = (list, idx, v) => (idx[v] !== undefined ? idx[v] : (idx[v] = list.push(v) - 1));
  const D = { id: [], day: [], b: [], st: [], r: [], it: [], amt: [], mg: [] };
  const day0 = Date.UTC(2026, 0, 1);
  let prev = 0;
  for (const r of srcRows) {
    if (r.b === undefined) continue;
    const id = +r.id;
    const [y, mo, da] = String(r.d).split('-').map(Number);
    let rv = 0;   // 0 = no reason row for this outcome, 1 = unspecified, 2 = other (rare), 3 + k = reasons[k]
    if (named[r.b]) { const k = reasonKey(r.reason); rv = k === '' ? 1 : named[r.b].has(k) ? 3 + intern(reasons, ri, k) : 2; }
    const itRaw = (r.items === undefined || r.items === 'NULL') ? '' : String(r.items).replace(/\\\\/g, '\\');
    const mgRaw = (r.mgr === undefined || r.mgr === 'NULL') ? '' : String(r.mgr).trim();
    D.id.push(id - prev); prev = id;
    D.day.push(Math.round((Date.UTC(y, mo - 1, da) - day0) / 864e5));
    D.b.push(bi[r.b]);
    D.st.push(+r.st);
    D.r.push(rv);
    const cps = Array.from(itRaw);   // code points, like the PHP twin (no mbstring on the server)
    D.it.push(intern(items, ii, cps.length > 140 ? cps.slice(0, 139).join('') + '…' : itRaw));
    D.amt.push(Math.round(+r.amt || 0));
    D.mg.push(intern(mgrs, gi, mgRaw));
  }
  return { buckets: BUCKETS, reasons, items, mgrs, ...D };
}

// ---- PRIVATE customer data for the drill-down (user 2026-11-12: name, phone, personal number, advance … in the list
// behind each figure). Read from the gitignored funnel_pii*.tsv extracts and written ONLY into
// deals_amount_migration_private.html (gitignored, published to the private claude.ai Artifact); the committed page —
// also served on the public GitHub Pages — never carries it. Arrays aligned 1:1 with FUNNEL_JSON.drill's rows.
// Same rules as the hand-built refusal lists: name = the order's own name, else the customer account's; phone = the
// first valid Georgian mobile (5XXXXXXXX) of contact / billing / account phone; personal number = the application
// form's, else the account's; GDPR = the form's marketing consent, or a site consent by customer or phone; active loan =
// an open issued loan (1/5/11, crm_active) by the same personal number or customer account (the case's own included).
function funnelPii(drill) {
  const pii = parseTsv(path.join(dir, 'funnel_pii.tsv'));
  if (!pii || !drill) return null;
  const gdpr = parseTsv(path.join(dir, 'funnel_pii_gdpr.tsv')) || [];
  const active = parseTsv(path.join(dir, 'funnel_pii_active.tsv')) || [];
  const nn = v => (v === undefined || v === null || v === 'NULL') ? '' : String(v).replace(/\\\\/g, '\\').trim();
  const digits = v => nn(v).replace(/\D/g, '');
  const p9 = v => { let d = digits(v); if (d.startsWith('995')) d = d.slice(3); return d.length === 9 && d[0] === '5' ? d : ''; };
  const gC = new Set(), gP = new Set();
  for (const r of gdpr) { if (nn(r.cid)) gC.add(nn(r.cid)); const p = p9(r.phone); if (p) gP.add(p); }
  const aC = new Set(), aP = new Set();
  for (const r of active) { if (nn(r.cid)) aC.add(nn(r.cid)); const p = digits(r.pid); if (p.length === 11) aP.add(p); }
  const byId = new Map(pii.map(r => [+r.id, r]));
  const out = { nm: [], ph: [], pid: [], adv: [], gd: [], act: [], rt: [] };
  let id = 0, miss = 0;
  for (let j = 0; j < drill.id.length; j++) {
    id += drill.id[j];
    const r = byId.get(id);
    if (!r) { miss++; out.nm.push(''); out.ph.push(''); out.pid.push(''); out.adv.push(null); out.gd.push(0); out.act.push(0); out.rt.push(''); continue; }
    const cid = nn(r.cid);
    const phone = p9(r.p1) || p9(r.bphone) || p9(r.cphone);
    const pid = digits(r.fpid) || digits(r.cpid);
    const adv = nn(r.adv) === '' ? null : Math.round(+r.adv);
    out.nm.push(nn(r.oname) || nn(r.cname));
    out.ph.push(phone);
    out.pid.push(pid);
    out.adv.push(Number.isFinite(adv) ? adv : null);
    out.gd.push((r.mc === '1' || (cid && gC.has(cid)) || (phone && gP.has(phone))) ? 1 : 0);
    out.act.push(((pid.length === 11 && aP.has(pid)) || (cid && aC.has(cid))) ? 1 : 0);
    out.rt.push(drill.r[j] ? nn(r.rtext) : '');   // the CRM reason text, only where the table shows a reason for the case
  }
  if (miss) console.log('funnel_pii.tsv: ' + miss + ' drill rows without a PII row (extracts pulled at different times?)');
  return out;
}

const payload = {
  months, curMonth, monthStart: MONTH_START, appsThrough, ga4,
  ...S,
  rejectSalesReasons: reasonRows(reasonAcc.rejSales),
  declineSalesReasons: reasonRows(reasonAcc.declSales),
  rejectCommitteeReasons: reasonRows(reasonAcc.rejCmt),
  declineCommitteeReasons: reasonRows(reasonAcc.declCmt),
  declineAfterReasons: reasonRows(reasonAcc.declPost),
  drill: funnelDrill(),
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
#funnelTable.is-summary tr.fn-detail { display: none; }
/* drill-down: a count cell opens the list of applications behind it */
#funnelTable td[data-mi] { cursor: pointer; }
#funnelTable td[data-mi]:hover { text-decoration: underline dotted; text-underline-offset: 3px; box-shadow: inset 0 0 0 2px var(--series-a-soft); }
.fn-drill-ov { position: fixed; inset: 0; z-index: 3000; background: rgba(0,0,0,.45); display: flex; align-items: flex-start; justify-content: center; padding: 40px 16px; overflow: auto; }
.fn-drill { background: var(--surface-1); color: var(--text-primary); border: 1px solid var(--border); border-radius: 12px; width: 100%; max-width: 1200px; box-shadow: 0 12px 40px rgba(0,0,0,.3); }
.fn-drill.has-pii { max-width: 1600px; }
.fn-drill-tbl .rt { display: block; color: var(--text-muted); font-size: 11px; margin-top: 2px; }
.fn-drill-tbl td.it { min-width: 220px; }
.fn-drill-tbl td.nm { min-width: 140px; }
.fn-drill-head { display: flex; gap: 12px; justify-content: space-between; align-items: flex-start; padding: 16px 18px 6px; }
.fn-drill-title { font-weight: 700; font-size: 15px; color: var(--text-primary); }
.fn-drill-sub { color: var(--text-secondary); font-size: 12px; margin-top: 4px; }
.fn-drill-x { border: 1px solid var(--border); background: var(--page); color: var(--text-secondary); border-radius: 50%; width: 30px; height: 30px; cursor: pointer; font-size: 18px; line-height: 1; flex: none; }
.fn-drill-mix { font-size: 11.5px; color: var(--text-secondary); padding: 2px 18px 8px; line-height: 1.6; }
.fn-drill-mix b { color: var(--text-primary); font-weight: 600; }
.fn-drill-tools { padding: 2px 18px 10px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
.fn-drill-tools input { flex: 1 1 260px; padding: 7px 10px; border: 1px solid var(--border); border-radius: 8px; background: var(--page); color: var(--text-primary); font-size: 12.5px; }
.fn-drill-tools span { font-size: 11.5px; color: var(--text-muted); }
.fn-drill-xlsx { display: inline-flex; align-items: center; gap: 6px; border: 1px solid var(--border); background: var(--page); color: var(--text-primary); border-radius: 8px; padding: 6px 12px; cursor: pointer; font-size: 12px; white-space: nowrap; }
.fn-drill-xlsx svg { width: 14px; height: 14px; }
.fn-drill-xlsx:disabled { opacity: .6; cursor: progress; }
.fn-drill-body { padding: 0 18px 16px; overflow-x: auto; }
.fn-drill-tbl { width: 100%; border-collapse: collapse; font-size: 12px; }
.fn-drill-tbl th { text-align: left; font-weight: 600; color: var(--text-secondary); background: var(--surface-1); border-bottom: 1px solid var(--border); padding: 6px 8px; white-space: nowrap; }
.fn-drill-tbl td { border-bottom: 1px solid var(--grid); padding: 6px 8px; vertical-align: top; color: var(--text-primary); background: transparent; }
.fn-drill-tbl td.num, .fn-drill-tbl th.num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
.fn-drill-tbl td.it { min-width: 260px; }
.fn-drill-tbl td.nw { white-space: nowrap; }
.fn-drill-more { text-align: center; padding-top: 12px; }
.fn-drill-more button { border: 1px solid var(--border); background: var(--page); color: var(--text-primary); border-radius: 8px; padding: 6px 14px; cursor: pointer; font-size: 12px; }
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
  <p class="note">One column per calendar month of 2026 (the current month is month-to-date, through yesterday). Website sessions are the month's traffic; every other row is the applications <b>submitted in that month</b> and the status each of them was in <b>at the end of yesterday</b> (today's changes are not in yet) &mdash; a recent month keeps moving on refresh as its applications get decided. Three gates, in the order a case travels: <b>sales</b> screen the applications first (3 rejected by sales, 3.1 still with sales / unreachable / expired, 4 the customer withdrew there) and pass the rest on to the <b>committee</b> (5); the committee rejects (6), leaves some waiting (6.1), loses some to the customer (7) and <b>approves</b> the rest (8); of the approved, 8.1 fall out on Volta's side, 9 are declined by the customer and the rest are signed (10, together with the single-payment sales that need no underwriting). Each percentage row names its base. <b>Main stages</b> shows only the numbered stages; <b>Detailed breakdown</b> adds the reasons and the sub-stage items. Tap a section header to collapse it.</p>
  <div class="banner" id="funnelBanner"></div>
  <div class="page-nav" id="funnelViewNav" style="margin-bottom:-8px;">
    <button data-view="summary" type="button">Main stages</button>
    <button data-view="detail" type="button" class="active">Detailed breakdown</button>
  </div>
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
// a function of the data, not a one-shot IIFE: the live page (public/index.php) streams the funnel in as its own section
// after the page is already open (it is the slowest of the core queries since it went per application, 2026-11-12) and
// calls it again with the live numbers; the static page / Artifact runs it once with the embedded FUNNEL_JSON
window.__funnelBuild = function (F) {
  const DASH = '&ndash;';
  const fmtN = n => (n === null || n === undefined) ? null : Math.round(n).toLocaleString('en-US');
  const fmtP = (n, d) => (n === null || n === undefined || d === null || d === undefined) ? null : (d ? (n / d * 100).toLocaleString('en-US', { maximumFractionDigits: 1 }) + '%' : '0%');
  const sum = a => a.reduce((x, y) => x + (y || 0), 0);
  const MN = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  const mLabel = m => { const [y, mo] = m.split('-').map(Number); return MN[mo - 1] + ' ' + y + (m === F.curMonth ? ' (MTD)' : ''); };
  const n = F.months.length, colspan = n + 2;
  // mi = the month index a drill-down cell lists (-1 = the Total column); a 0 or a dash is never clickable
  const cell = (v, cls, mi) => (v === null || v === undefined) ? '<td class="dash ' + (cls || '') + '">' + DASH + '</td>' : '<td class="' + (cls || '') + '"' + ((mi === undefined || v === '0') ? '' : ' data-mi="' + mi + '"') + '>' + v + '</td>';
  // ---- drill-down registry: each count row gets a key into DR = { set: leaf buckets it is the union of, rf: reason filter }
  const DD = F.drill || null;
  const DR = [];
  const BI = {}; if (DD) DD.buckets.forEach((b, i) => { BI[b] = i; });
  const RI = {}; if (DD) DD.reasons.forEach((k, i) => { RI[k] = i; });
  const dk = (buckets, rf) => { if (!DD) return undefined; const set = {}; buckets.forEach(b => { set[BI[b]] = 1; }); return DR.push({ set, rf: rf || null }) - 1; };
  const G = {
    all: DD ? DD.buckets.slice() : [],
    committee: ['rejCmt', 'declCmt', 'expiredCmt', 'openCmt', 'otherCmt', 'signedActive', 'declPost', 'rejPost', 'expiredPost', 'openPost', 'otherPost'],
    approved: ['signedActive', 'declPost', 'rejPost', 'expiredPost', 'openPost', 'otherPost'],
    st31: ['expiredSales', 'openSales', 'otherSales'], st61: ['expiredCmt', 'openCmt', 'otherCmt'], st81: ['rejPost', 'expiredPost', 'openPost', 'otherPost'],
    finalAll: ['signedActive', 'singlePay'],
  };
  let secN = 0;
  const secRow = (cls, text) => { const id = 'fsec' + (++secN); return { id, row: '<tr class="' + cls + '" data-toggle="' + id + '"><td colspan="' + colspan + '"><span class="rpt-chevron">&#9662;</span>' + text + '</td></tr>' }; };
  const plain = (cls, text) => '<tr class="' + cls + '"><td colspan="' + colspan + '">' + text + '</td></tr>';
  // one row: label + one value per month + a total column; vals = numbers (or nulls), tot = number|null|'skip'
  const row = (sec, cls, label, vals, tot, isPct, denom, key) => {
    const dr = !isPct && key !== undefined;
    let tds = '';
    for (let i = 0; i < n; i++) tds += isPct ? cell(fmtP(vals[i], denom ? denom[i] : null), 'pct-cell') : cell(fmtN(vals[i]), '', dr ? i : undefined);
    const t = tot === 'skip' ? null : (isPct ? fmtP(tot, denom ? sum(denom) : null) : fmtN(tot));
    return '<tr class="' + cls + '" data-sec="' + sec + '"' + (dr ? ' data-drill="' + key + '"' : '') + '><td>' + label + '</td>' + tds + cell(t, isPct ? 'pct-cell' : '', dr ? -1 : undefined) + '</tr>';
  };
  const cnt = (sec, cls, label, vals, key) => row(sec, cls, label, vals, sum(vals), false, null, key);
  const rate = (sec, label, num, den) => row(sec, 'rpt-plain', '<span style="color:var(--text-muted)">' + label + '</span>', num, sum(num), true, den);
  // a breakdown row: the count, then in the same cell its share of the stage it belongs to (denom = that stage's row)
  const cntPct = (sec, cls, label, vals, denom, key) => {
    const dr = key !== undefined;
    const c = (v, d, mi) => { if (v === null || v === undefined) return cell(null); const p = fmtP(v, d); return '<td' + (dr && v ? ' data-mi="' + mi + '"' : '') + '>' + fmtN(v) + (p === null ? '' : ' <span class="pct-inline">(' + p + ')</span>') + '</td>'; };
    let tds = '';
    for (let i = 0; i < n; i++) tds += c(vals[i], denom ? denom[i] : null, i);
    return '<tr class="' + cls + '" data-sec="' + sec + '"' + (dr ? ' data-drill="' + key + '"' : '') + '><td>' + label + '</td>' + tds + c(sum(vals), denom ? sum(denom) : null, -1) + '</tr>';
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
  h += cnt(s2.id, 'rpt-peach-strong', 'Applications submitted', F.apps, dk(G.all));
  h += row(s2.id, 'rpt-plain', '<span style="color:var(--text-muted)">Applications as % of website sessions</span>', F.apps, sum(F.apps), true, F.ga4.sessions);

  // reason rows: sorted by the last month, each count with its share of the stage's own total (denom)
  // breakdown rows carry fn-detail: the "Main stages" view (#funnelViewNav) hides them and leaves every numbered stage.
  // Their inline share is of the month's APPLICATIONS (user, 2026-09-24: "აპლიკაციების რა პროცენტს შეადგენს
  // თითოეული სტატუსი"), not of the stage — the stage's own share row above them already gives that base.
  const reasonRowsHtml = (sec, rows, bucket) => {
    let s = '';
    for (const r of rows.slice().sort(byLastMonth)) {
      const rf = r.other ? (x => x === 2) : r.unspecified ? (x => x === 1) : (RI[r.ka] !== undefined ? (k => x => x === 3 + k)(RI[r.ka]) : null);
      s += cntPct(sec, 'rpt-plain fn-detail', '&nbsp;&nbsp;&middot; ' + r.ka + (r.en ? ' <span style="color:var(--text-muted)">(' + r.en + ')</span>' : ''), r.vals, F.apps, (DD && rf) ? dk([bucket], rf) : undefined);
    }
    return s;
  };
  // a sub-stage (3.1 / 6.1 / 8.1): its header belongs to the parent section (hidden when it collapses) and toggles its
  // own rows; a total + share row like every other stage, then its items sorted by the last month
  const subStage = (parent, title, totalLabel, totals, shareLabel, denom, items, totalBuckets) => {
    const id = 'fsec' + (++secN);
    let s = '<tr class="rpt-section" data-sec="' + parent + '" data-toggle="' + id + '"><td colspan="' + colspan + '"><span class="rpt-chevron">&#9662;</span>' + title + '</td></tr>';
    s += cnt(id, 'rpt-peach', totalLabel, totals, dk(totalBuckets));
    s += rate(id, shareLabel, totals, denom);
    for (const it of items.filter(it => sum(it.vals) || it.always).sort(byLastMonth)) s += cntPct(id, 'rpt-plain fn-detail', '&nbsp;&nbsp;&middot; ' + it.label, it.vals, F.apps, dk([it.b]));
    return s;
  };
  // the sub-stage totals, needed both for their own rows and for the check at the end
  const st31 = F.months.map((m, i) => F.expiredSales[i] + F.openSales[i] + F.otherSales[i]);
  const st61 = F.months.map((m, i) => F.expiredCmt[i] + F.openCmt[i] + F.otherCmt[i]);
  const st81 = F.months.map((m, i) => F.rejPost[i] + F.expiredPost[i] + F.openPost[i] + F.otherPost[i]);

  // ---- gate 1: the sales screening (3 + 3.1 + 4 + 5 + single-payment sales in 10 = Applications)
  const s3 = secRow('rpt-section', '3. Rejected by sales &mdash; before the committee, no underwriting decision (3 + 3.1 + 4 + 5 + single-payment sales in 10 = Applications)'); h += s3.row;
  h += cnt(s3.id, 'rpt-peach', 'Rejected by sales (status 6, no underwriting decision)', F.rejSales, dk(['rejSales']));
  h += rate(s3.id, '% of applications', F.rejSales, F.apps);
  h += reasonRowsHtml(s3.id, F.rejectSalesReasons, 'rejSales');
  h += subStage(s3.id, '3.1 Other statuses at the sales stage', 'Other statuses at the sales stage (awaiting, unreachable, expired)', st31, '% of applications', F.apps, [
    { label: 'Still with sales, awaiting (status 4 / 7)', vals: F.openSales, always: true, b: 'openSales' },
    { label: 'Customer unreachable &mdash; &ldquo;უკონტაქტო&rdquo; (status 14)', vals: F.otherSales, b: 'otherSales' },
    { label: 'Expired at the sales stage (status 13)', vals: F.expiredSales, b: 'expiredSales' },
  ], G.st31);

  const s4 = secRow('rpt-section', '4. Customer withdrew at the sales stage &mdash; before the committee'); h += s4.row;
  h += cnt(s4.id, 'rpt-peach', 'Customer withdrew at the sales stage (status 12, no underwriting decision)', F.declSales, dk(['declSales']));
  h += rate(s4.id, '% of applications', F.declSales, F.apps);
  h += reasonRowsHtml(s4.id, F.declineSalesReasons, 'declSales');

  const s5 = secRow('rpt-section', '5. Reached the committee = 2 Applications &minus; 3 &minus; 3.1 &minus; 4 &minus; single-payment sales (6 + 6.1 + 7 + 8 = 5)'); h += s5.row;
  h += cnt(s5.id, 'rpt-peach-strong', 'Passed on to the committee (an underwriting decision recorded, at the committee, or signed)', F.committee, dk(G.committee));
  h += rate(s5.id, '% of applications', F.committee, F.apps);

  // ---- gate 2: the committee (6 + 6.1 + 7 + 8 = Reached the committee)
  const s6 = secRow('rpt-section', '6. Rejected by the committee &mdash; underwriting said no'); h += s6.row;
  h += cnt(s6.id, 'rpt-peach', 'Rejected by the committee (status 6, underwriting decision recorded)', F.rejCmt, dk(['rejCmt']));
  h += rate(s6.id, '% of reached the committee', F.rejCmt, F.committee);
  h += reasonRowsHtml(s6.id, F.rejectCommitteeReasons, 'rejCmt');
  h += subStage(s6.id, '6.1 Other committee statuses', 'Other committee statuses (awaiting a decision, unreachable, expired)', st61, '% of reached the committee', F.committee, [
    { label: 'At the committee / clarification needed, awaiting a decision (status 8 / 15)', vals: F.openCmt, always: true, b: 'openCmt' },
    { label: 'Customer unreachable &mdash; &ldquo;უკონტაქტო&rdquo; (status 14)', vals: F.otherCmt, b: 'otherCmt' },
    { label: 'Expired at the committee (status 13)', vals: F.expiredCmt, b: 'expiredCmt' },
  ], G.st61);

  const s7 = secRow('rpt-section', '7. Customer withdrew at the committee stage &mdash; before approval'); h += s7.row;
  h += cnt(s7.id, 'rpt-peach', 'Customer withdrew at the committee stage (status 12, before approval)', F.declCmt, dk(['declCmt']));
  h += rate(s7.id, '% of reached the committee', F.declCmt, F.committee);
  h += reasonRowsHtml(s7.id, F.declineCommitteeReasons, 'declCmt');

  // ---- gate 3: approval (8.1 + 9 + Signed in 10 = Approved)
  const s8 = secRow('rpt-section', '8. Approved by underwriting (= 8.1 + 9 + Signed in 10)'); h += s8.row;
  h += cnt(s8.id, 'rpt-peach', 'Approved by underwriting (underwriting status 16)', F.approved, dk(G.approved));
  h += rate(s8.id, '% of reached the committee', F.approved, F.committee);
  h += rate(s8.id, '% of applications', F.approved, F.apps);
  h += subStage(s8.id, '8.1 Other underwriting statuses', 'Other underwriting statuses (rejected / expired after approval, in process, cancelled)', st81, '% of approved', F.approved, [
    { label: 'Rejected after approval (status 6)', vals: F.rejPost, always: true, b: 'rejPost' },
    { label: 'Expired after approval (status 13)', vals: F.expiredPost, always: true, b: 'expiredPost' },
    { label: 'Approved, still in process (16 / 17 / 9 / 10)', vals: F.openPost, always: true, b: 'openPost' },
    { label: 'Signed, then cancelled (status 18)', vals: F.otherPost, b: 'otherPost' },
  ], G.st81);

  const s9 = secRow('rpt-section', '9. Customer declined after approval &mdash; although underwriting approved'); h += s9.row;
  h += cnt(s9.id, 'rpt-peach', 'Customer declined after approval (status 12)', F.declPost, dk(['declPost']));
  h += rate(s9.id, '% of approved', F.declPost, F.approved);
  h += reasonRowsHtml(s9.id, F.declineAfterReasons, 'declPost');

  const s10 = secRow('rpt-section-strong', '10. Final agreement between the customer and Volta'); h += s10.row;
  h += cnt(s10.id, 'rpt-green', 'Signed / active installment (status 11 / 5 / 1)', F.signedActive, dk(['signedActive']));
  h += cnt(s10.id, 'rpt-plain', 'Single-payment sales (status 99, no underwriting)', F.singlePay, dk(['singlePay']));
  const finalAll = F.months.map((m, i) => F.signedActive[i] + F.singlePay[i]);
  h += cnt(s10.id, 'rpt-green', 'Total final agreements (signed + single payment)', finalAll, dk(G.finalAll));
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

  // ---- drill-down panel: the applications behind a count (decoded once from the compact column arrays)
  const SN = { 1: 'Active', 4: 'New', 5: 'Active', 6: 'Rejected', 7: 'In processing', 8: 'At the committee', 9: 'Approved, invoice sent', 10: 'Contract sent for signing', 11: 'Signed', 12: 'Customer declined', 13: 'Expired', 14: 'Unreachable', 15: 'Clarification needed', 16: 'Approved', 17: 'Disbursement in process', 18: 'Cancelled', 99: 'Single payment' };
  const REN = {};
  [F.rejectSalesReasons, F.declineSalesReasons, F.rejectCommitteeReasons, F.declineCommitteeReasons, F.declineAfterReasons].forEach(list => (list || []).forEach(r => { if (r.en && !r.other && !r.unspecified) REN[r.ka] = r.en; }));
  const esc = v => String(v).replace(/[&<>"]/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[ch]);
  const reasonText = v => v === 0 ? '' : v === 1 ? 'Unspecified' : v === 2 ? 'Other (rare)' : DD.reasons[v - 3] + (REN[DD.reasons[v - 3]] ? ' (' + REN[DD.reasons[v - 3]] + ')' : '');
  // the private Artifact copy carries const FUNNEL_PII (name, phone, personal number, advance, GDPR, active loan, CRM
  // reason text), aligned with DD's rows; the committed / GitHub Pages page has none and shows the case data only
  const PII = (typeof FUNNEL_PII !== 'undefined' && FUNNEL_PII && DD && FUNNEL_PII.nm.length === DD.id.length) ? FUNNEL_PII : null;
  const dashC = '<span style="color:var(--text-muted)">&ndash;</span>';
  let DEC = null;
  const decode = () => {
    if (DEC) return DEC;
    const N = DD.id.length, ids = new Array(N), dates = new Array(N), t0 = Date.UTC(2026, 0, 1);
    let id = 0;
    for (let j = 0; j < N; j++) { id += DD.id[j]; ids[j] = id; dates[j] = new Date(t0 + DD.day[j] * 864e5).toISOString().slice(0, 10); }
    DEC = { ids, dates };
    return DEC;
  };
  const drillList = (key, mi) => {
    const spec = DR[key], D = decode(), out = [];
    const m = mi >= 0 ? F.months[mi] : null;
    for (let j = 0; j < D.ids.length; j++) {
      if (!spec.set[DD.b[j]]) continue;
      if (spec.rf && !spec.rf(DD.r[j])) continue;
      if (m && D.dates[j].slice(0, 7) !== m) continue;
      out.push(j);
    }
    out.sort((a, b) => (D.dates[b] < D.dates[a] ? -1 : D.dates[b] > D.dates[a] ? 1 : D.ids[b] - D.ids[a]));
    return out;
  };
  let ov = null, cur = [], shown = 0, filtered = [];
  const PAGE = 300;
  const reasonCell = j => {
    const canon = reasonText(DD.r[j]), raw = PII ? PII.rt[j] : '';
    // the CRM's own text under the reason row's name when it says more (always for "Other (rare)", whose text is private)
    const same = raw && raw.replace(/[ .]+$/, '') === (DD.r[j] >= 3 ? DD.reasons[DD.r[j] - 3] : '');
    return esc(canon) + (raw && !same ? '<span class="rt">' + esc(raw) + '</span>' : '');
  };
  const rowHtml = j => {
    const D = decode();
    const amt = DD.amt[j];
    const p = PII ? ('<td class="nm">' + (esc(PII.nm[j]) || dashC) + '</td><td class="nw">' + (PII.ph[j] || dashC) + '</td><td class="nw">' + (PII.pid[j] || dashC) + '</td>') : '';
    const adv = PII ? ('<td class="num">' + (PII.adv[j] === null ? dashC : PII.adv[j].toLocaleString('en-US')) + '</td>') : '';
    const tail = PII ? ('<td class="nw">' + (PII.gd[j] ? 'Yes' : 'No') + '</td><td class="nw">' + (PII.act[j] ? 'Yes' : 'No') + '</td>') : '';
    return '<tr><td class="nw">' + D.ids[j] + '</td><td class="nw">' + D.dates[j] + '</td>' + p + '<td class="it">' + (esc(DD.items[DD.it[j]]) || dashC) + '</td><td class="num">' + (amt ? amt.toLocaleString('en-US') : '&ndash;') + '</td>' + adv + '<td class="nw">' + esc(SN[DD.st[j]] || ('Status ' + DD.st[j])) + ' <span style="color:var(--text-muted)">(' + DD.st[j] + ')</span></td><td>' + reasonCell(j) + '</td><td class="nw">' + (esc(DD.mgrs[DD.mg[j]]) || dashC) + '</td>' + tail + '</tr>';
  };
  const paint = () => {
    const tb = ov.querySelector('tbody');
    tb.innerHTML = filtered.slice(0, shown).map(rowHtml).join('');
    const more = ov.querySelector('.fn-drill-more');
    const left = filtered.length - shown;
    more.innerHTML = left > 0 ? '<button type="button">Show ' + Math.min(left, PAGE * 3).toLocaleString('en-US') + ' more (' + left.toLocaleString('en-US') + ' not shown)</button>' : '';
    ov.querySelector('.fn-drill-count').textContent = filtered.length === cur.length ? '' : filtered.length.toLocaleString('en-US') + ' of ' + cur.length.toLocaleString('en-US') + ' match';
  };
  // ---- Excel download of the list on screen: every row of it (not only the ones painted so far), the search applied,
  // the same columns as the panel. ExcelJS loads from the CDN on the first click only (same library and version as the
  // dashboard's per-group Excel buttons); the file is saved through the Artifact's downloads capability when there is one.
  let ctx = { label: '', stage: '', period: '' };
  let xlP = null;
  const loadXl = () => window.ExcelJS ? Promise.resolve(window.ExcelJS) : (xlP || (xlP = new Promise((res, rej) => {
    const sc = document.createElement('script');
    sc.src = 'https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.4.0/exceljs.min.js';
    sc.onload = () => res(window.ExcelJS);
    sc.onerror = () => { xlP = null; rej(new Error('ExcelJS failed to load')); };
    document.head.appendChild(sc);
  })));
  const saveFile = (blob, name) => {
    const plain = () => { const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = name; document.body.appendChild(a); a.click(); setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1500); };
    if (window.claude && window.claude.use) window.claude.use('downloads').then(d => { if (!d) plain(); else d.save({ filename: name, data: blob }).catch(() => {}); }).catch(plain);
    else plain();
  };
  const exportXlsx = btn => {
    const rows = filtered.slice();
    const q = ov.querySelector('input').value.trim();
    btn.disabled = true;
    loadXl().then(ExcelJS => {
      const D = decode();
      const wb = new ExcelJS.Workbook();
      const ws = wb.addWorksheet('Applications');
      const day = j => { const p = D.dates[j].split('-').map(Number); return new Date(Date.UTC(p[0], p[1] - 1, p[2])); };
      const cols = [['Case #', 9, j => D.ids[j]], ['Applied', 11, day, 'yyyy-mm-dd']];
      if (PII) cols.push(['Name', 24, j => PII.nm[j]], ['Phone', 12, j => PII.ph[j]], ['Personal number', 14, j => PII.pid[j]]);
      cols.push(['Items', 55, j => DD.items[DD.it[j]]], ['Amount (GEL)', 12, j => DD.amt[j] || null, '#,##0']);
      if (PII) cols.push(['Advance asked (GEL)', 12, j => PII.adv[j], '#,##0']);
      cols.push(['Status at the end of ' + longDate(F.appsThrough), 24, j => (SN[DD.st[j]] || 'Status') + ' (' + DD.st[j] + ')'], ['Reason', 30, j => reasonText(DD.r[j])]);
      if (PII) cols.push(['Reason text (CRM)', 45, j => PII.rt[j]]);
      cols.push(['Sales manager', 20, j => DD.mgrs[DD.mg[j]]]);
      if (PII) cols.push(['GDPR consent', 10, j => PII.gd[j] ? 'Yes' : 'No'], ['Active loan now', 10, j => PII.act[j] ? 'Yes' : 'No']);
      const font = { name: 'Arial', size: 10 };
      ws.getCell('A1').value = ctx.label; ws.getCell('A1').font = { name: 'Arial', size: 12, bold: true };
      ws.getCell('A2').value = 'Full Sales Funnel: ' + ctx.stage; ws.getCell('A2').font = font;
      const tot = rows.reduce((a, j) => a + DD.amt[j], 0);
      ws.getCell('A3').value = ctx.period + ' | ' + rows.length.toLocaleString('en-US') + ' applications | GEL ' + tot.toLocaleString('en-US') + ' | statuses at the end of ' + longDate(F.appsThrough) + (q ? ' | search: ' + q : '') + ' | downloaded ' + new Date().toISOString().slice(0, 10);
      ws.getCell('A3').font = { name: 'Arial', size: 9, italic: true, color: { argb: 'FF666666' } };
      const H = 5;
      const hr = ws.getRow(H);
      cols.forEach((c, i) => {
        const cell = hr.getCell(i + 1);
        cell.value = c[0];
        cell.font = { name: 'Arial', size: 10, bold: true, color: { argb: 'FF1A1A34' } };
        cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFE8EEF5' } };
        cell.alignment = { vertical: 'middle', wrapText: true };
        cell.border = { bottom: { style: 'thin', color: { argb: 'FF9AA5B1' } } };
        ws.getColumn(i + 1).width = c[1];
      });
      rows.forEach((j, r) => {
        const xr = ws.getRow(H + 1 + r);
        cols.forEach((c, i) => {
          const v = c[2](j);
          const cell = xr.getCell(i + 1);
          cell.value = (v === '' || v === undefined) ? null : v;
          cell.font = font;
          cell.alignment = { vertical: 'top', wrapText: i > 1 && c[1] >= 24 };
          if (c[3]) cell.numFmt = c[3];
        });
      });
      ws.views = [{ state: 'frozen', ySplit: H }];
      ws.autoFilter = { from: { row: H, column: 1 }, to: { row: H + Math.max(rows.length, 1), column: cols.length } };
      return wb.xlsx.writeBuffer().then(buf => {
        const bad = '/:*?"<>|' + String.fromCharCode(92);
        const clean = t => String(t).split('').map(ch => bad.indexOf(ch) >= 0 ? ' ' : ch).join('').replace(/ +/g, ' ').trim();
        const lab = clean(ctx.label).slice(0, 70);
        saveFile(new Blob([buf], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }), 'Volta Funnel - ' + lab + ' - ' + clean(ctx.period.replace(' (MTD)', ' MTD')) + '.xlsx');
      });
    }).catch(() => {
      // library failed to load (offline / CDN blocked): no file, nothing else breaks
    }).finally(() => { btn.disabled = false; });
  };
  const closeDrill = () => { if (ov) { ov.style.display = 'none'; document.body.style.overflow = ''; } };
  const openDrill = (key, mi, label, stage) => {
    if (!ov) {
      ov = document.createElement('div');
      ov.className = 'fn-drill-ov';
      ov.innerHTML = '<div class="fn-drill' + (PII ? ' has-pii' : '') + '" role="dialog" aria-modal="true"><div class="fn-drill-head"><div><div class="fn-drill-title"></div><div class="fn-drill-sub"></div></div><button type="button" class="fn-drill-x" aria-label="Close">&times;</button></div>'
        + '<div class="fn-drill-mix"></div><div class="fn-drill-tools"><input type="search" placeholder="Search case number, ' + (PII ? 'name, phone, personal number, ' : '') + 'item, status, reason, sales manager"><button type="button" class="fn-drill-xlsx" title="Download this list as Excel (all rows, the search applied)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg><span>Excel</span></button><span class="fn-drill-count"></span></div>'
        + '<div class="fn-drill-body"><table class="fn-drill-tbl"><thead><tr><th>Case #</th><th>Applied</th>' + (PII ? '<th>Name</th><th>Phone</th><th>Personal number</th>' : '') + '<th>Items</th><th class="num">Amount (&#8382;)</th>' + (PII ? '<th class="num">Advance asked (&#8382;)</th>' : '') + '<th>Status at the end of ' + longDate(F.appsThrough) + '</th><th>Reason</th><th>Sales manager</th>' + (PII ? '<th>GDPR consent</th><th>Active loan now</th>' : '') + '</tr></thead><tbody></tbody></table><div class="fn-drill-more"></div></div></div>';
      document.body.appendChild(ov);
      ov.addEventListener('click', e => { if (e.target === ov || e.target.closest('.fn-drill-x')) closeDrill(); else if (e.target.closest('.fn-drill-more button')) { shown += PAGE * 3; paint(); } else if (e.target.closest('.fn-drill-xlsx')) exportXlsx(e.target.closest('.fn-drill-xlsx')); });
      document.addEventListener('keydown', e => { if (e.key === 'Escape' && ov.style.display !== 'none') closeDrill(); });
      ov.querySelector('input').addEventListener('input', e => {
        const q = e.target.value.trim().toLowerCase();
        const D = decode();
        filtered = !q ? cur : cur.filter(j => (D.ids[j] + ' ' + D.dates[j] + ' ' + DD.items[DD.it[j]] + ' ' + (SN[DD.st[j]] || '') + ' ' + reasonText(DD.r[j]) + ' ' + DD.mgrs[DD.mg[j]] + (PII ? ' ' + PII.nm[j] + ' ' + PII.ph[j] + ' ' + PII.pid[j] + ' ' + PII.rt[j] : '')).toLowerCase().indexOf(q) >= 0);
        shown = PAGE; paint();
      });
    }
    cur = drillList(key, mi); filtered = cur; shown = PAGE;
    ctx = { label, stage, period: mi >= 0 ? mLabel(F.months[mi]) : 'All months, ' + mLabel(F.months[0]).replace(' (MTD)', '') + ' - ' + mLabel(F.months[n - 1]) };
    const tot = cur.reduce((a, j) => a + DD.amt[j], 0);
    ov.querySelector('.fn-drill-title').textContent = label;
    ov.querySelector('.fn-drill-sub').innerHTML = (stage ? esc(stage) + '<br>' : '') + (mi >= 0 ? mLabel(F.months[mi]) : 'All months, ' + mLabel(F.months[0]).replace(' (MTD)', '') + ' &ndash; ' + mLabel(F.months[n - 1])) + ' &middot; <b>' + cur.length.toLocaleString('en-US') + '</b> application' + (cur.length === 1 ? '' : 's') + ' &middot; &#8382;' + tot.toLocaleString('en-US') + ' in total &middot; newest first';
    // the mix behind a combined row: by status, then the busiest sales managers
    const byS = {}, byM = {};
    cur.forEach(j => { const k = SN[DD.st[j]] || ('Status ' + DD.st[j]); byS[k] = (byS[k] || 0) + 1; const g = DD.mgrs[DD.mg[j]] || 'No sales manager'; byM[g] = (byM[g] || 0) + 1; });
    const top = (o, k) => Object.entries(o).sort((a, b) => b[1] - a[1]).slice(0, k).map(e => esc(e[0]) + ' <b>' + e[1].toLocaleString('en-US') + '</b>').join(' &middot; ');
    ov.querySelector('.fn-drill-mix').innerHTML = cur.length ? 'By status: ' + top(byS, 8) + '<br>By sales manager: ' + top(byM, 6) + (Object.keys(byM).length > 6 ? ' &middot; &hellip;' : '') : '';
    ov.querySelector('input').value = '';
    paint();
    ov.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    ov.scrollTop = 0;
  };

  function render() {
    const table = document.getElementById('funnelTable');
    if (!table) return;
    table.querySelector('tbody').innerHTML = h;
    table.__drillOpen = DD ? openDrill : null;   // this build's panel (a live rebuild swaps it)
    if (!table.__drillWired) {
      table.__drillWired = true;
      table.addEventListener('click', e => {
        if (!table.__drillOpen) return;
        const td = e.target.closest('td[data-mi]');
        if (!td) return;
        const tr = td.parentElement;
        const key = tr.getAttribute('data-drill');
        if (key === null) return;
        const lab = tr.firstElementChild.textContent.replace(/^[^A-Za-z0-9ა-ჰ]+/, '').trim();
        // the stage it belongs to: the section (or 3.1 / 6.1 / 8.1 sub-stage) header that toggles this row
        const hdr = table.querySelector('tr[data-toggle="' + tr.getAttribute('data-sec') + '"]');
        table.__drillOpen(+key, +td.getAttribute('data-mi'), lab, hdr ? hdr.textContent.replace(/^[^A-Za-z0-9ა-ჰ]+/, '').trim() : '');
      });
    }
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
    // Main stages / Detailed breakdown toggle — remembered per browser
    const nav = document.getElementById('funnelViewNav');
    if (nav && !nav.__wired) {
      nav.__wired = true;
      const apply = v => {
        table.classList.toggle('is-summary', v === 'summary');
        nav.querySelectorAll('button[data-view]').forEach(b => b.classList.toggle('active', b.getAttribute('data-view') === v));
        try { localStorage.setItem('funnelView', v); } catch (e) {}
        if (window.__funnelScrollUpdate) window.__funnelScrollUpdate();
      };
      nav.querySelectorAll('button[data-view]').forEach(b => b.addEventListener('click', () => apply(b.getAttribute('data-view'))));
      let saved = 'detail'; try { saved = localStorage.getItem('funnelView') || 'detail'; } catch (e) {}
      apply(saved === 'summary' ? 'summary' : 'detail');
    }
    const banner = document.getElementById('funnelBanner');
    if (banner) banner.innerHTML = (DD ? '<b>Click any count</b> to list the applications behind it (case number, date, items, amount, status, reason, sales manager). ' : '') + '<b>How to read it:</b> each month starts with the website sessions Google Analytics counted, then the applications submitted that month, then the sales screening (rejected by sales with the recorded reason, still with sales, the customer withdrew there with their reason) and how many were passed on to the committee, then the committee\\'s decision on those (rejected with the reason, still waiting, the customer withdrew there, approved), then the approved ones that did not end in a contract (rejected or expired after approval, still in process, declined by the customer with their reason), and finally the signed installment contracts and single-payment sales together. 3 + 3.1 + 4 + 5 + single-payment sales = applications; 6 + 6.1 + 7 + 8 = reached the committee; 8.1 + 9 + signed = approved' + (anyGa4 ? '' : ' &mdash; GA4 sessions are not available in this build (funnel_ga4_month.tsv missing)') + '.';
    if (window.__funnelScrollUpdate) window.__funnelScrollUpdate();
  }
  if (document.getElementById('funnelScrollTop') && !window.__funnelScrollUpdate) window.__funnelScrollUpdate = setupTopScrollSync('funnelScrollTop', 'funnelScrollBody');
  if (window.__registerPage) window.__registerPage(['fullfunnel'], render); else render();
};
window.__funnelBuild(FUNNEL_JSON);
// live page: index.php sends window.FUNNEL_LIVE then __onLiveSection('funnel') — rebuild with it and repaint the tab
(function () {
  const prev = window.__onLiveSection;
  if (typeof prev !== 'function' || prev.__funnel) return;
  const f = function (key) {
    if (key === 'funnel' && window.FUNNEL_LIVE) { window.__funnelBuild(window.FUNNEL_LIVE); if (window.__forceRenderPage) window.__forceRenderPage('fullfunnel'); }
    prev(key);
  };
  f.__funnel = true;
  window.__onLiveSection = f;
})();
${JS_END}`;
  const jsAnchor = '/* ---------- Sales Analyze (ported from Volta_Analytics) ---------- */';
  must(jsAnchor, 'Sales Analyze JS section');
  html = html.replace(jsAnchor, () => js.slice(1) + NL + jsAnchor);
}

if (__hadCRLF) html = html.replace(/\n/g, '\r\n');
fs.writeFileSync(htmlPath, html);
console.log('injected Full Sales Funnel into ' + htmlPath);

// the PRIVATE copy for the claude.ai Artifact: the same page plus `const FUNNEL_PII` (see funnelPii above) right after
// the FUNNEL_JSON line. Gitignored — never commit it, never copy it to docs/ (GitHub Pages is public).
{
  const pii = funnelPii(payload.drill);
  const privPath = htmlPath.replace(/\.html$/i, '') + '_private.html';
  if (pii) {
    const a = html.indexOf('const FUNNEL_JSON = ');
    if (a < 0) throw new Error('FUNNEL_JSON line not found');
    const e = html.indexOf('\n', a);
    fs.writeFileSync(privPath, html.slice(0, e + 1) + 'const FUNNEL_PII = ' + JSON.stringify(pii) + ';' + (__hadCRLF ? '\r\n' : '\n') + html.slice(e + 1));
    console.log('wrote the PRIVATE copy with customer data (never commit, never Pages): ' + privPath);
  } else if (fs.existsSync(privPath)) {
    fs.unlinkSync(privPath);   // no PII extract this run: drop a stale private copy rather than publish old customer data
    console.log('no funnel_pii.tsv — removed the stale private copy ' + privPath);
  }
}
