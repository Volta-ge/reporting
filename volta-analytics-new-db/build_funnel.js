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
const today = new Date();
const curMonth = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0');
let lastMonth = MONTH_START;
for (const r of appsRows) if (r.m > lastMonth) lastMonth = r.m;
const months = monthRange(MONTH_START, lastMonth > curMonth ? lastMonth : curMonth);
const mi = Object.fromEntries(months.map((m, i) => [m, i]));
const zeros = () => months.map(() => 0);

const S = { apps: zeros(), rejected: zeros(), expired: zeros(), inProcess: zeros(), uwApproved: zeros(), declined: zeros(), signedActive: zeros(), singlePay: zeros(), other: zeros() };
const IN_PROCESS = new Set([4, 7, 8, 15, 16, 17, 9, 10]);
const reasonAcc = { 6: {}, 12: {} };
for (const r of appsRows) {
  const i = mi[r.m]; if (i === undefined) continue;
  const st = +r.st, n = +r.n;
  S.apps[i] += n;
  if (r.uw16 === '1') S.uwApproved[i] += n;
  if (st === 6) S.rejected[i] += n;
  else if (st === 13) S.expired[i] += n;
  else if (st === 12) S.declined[i] += n;
  else if (st === 11 || st === 5 || st === 1) S.signedActive[i] += n;
  else if (st === 99) S.singlePay[i] += n;
  else if (IN_PROCESS.has(st)) S.inProcess[i] += n;
  else S.other[i] += n;
  if (st === 6 || st === 12) {
    const k = reasonKey(r.reason);
    (reasonAcc[st][k] ||= zeros())[i] += n;
  }
}
function reasonRows(acc) {
  const rows = [];
  let other = null, unspecified = null;
  for (const [ka, vals] of Object.entries(acc)) {
    const total = vals.reduce((a, b) => a + b, 0);
    if (ka === '') { unspecified = (unspecified || zeros()).map((v, i) => v + vals[i]); continue; }
    if (total < MIN_REASON_TOTAL) { other = (other || zeros()).map((v, i) => v + vals[i]); continue; }
    rows.push({ ka, en: REASON_EN[ka] || '', vals, total });
  }
  rows.sort((a, b) => (b.total - a.total) || (a.ka < b.ka ? -1 : a.ka > b.ka ? 1 : 0));
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
  months, curMonth, monthStart: MONTH_START, ga4,
  ...S,
  rejectReasons: reasonRows(reasonAcc[6]),
  declineReasons: reasonRows(reasonAcc[12]),
  generatedAt: new Date().toISOString().slice(0, 16).replace('T', ' ') + ' UTC',
};
fs.writeFileSync(path.join(dir, 'funnel_data.json'), JSON.stringify(payload));
const tot = a => a.reduce((x, y) => x + y, 0);
console.log('months:', months[0], '..', months[months.length - 1], '| applications:', tot(S.apps), '| rejected:', tot(S.rejected), '| declined:', tot(S.declined), '| signed/active:', tot(S.signedActive), '| single:', tot(S.singlePay), '| reasons:', payload.rejectReasons.length, '/', payload.declineReasons.length);

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
  <p class="note">One column per calendar month of 2026 (the current month is month-to-date). Website sessions are the month's traffic; every other row is the applications <b>submitted in that month</b> and the status each of them is in <b>today</b> &mdash; a recent month keeps moving on refresh as its applications get decided. Percentages are shares of that month's applications (the two rates under the stage totals say so explicitly). Tap a section header to collapse it.</p>
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
  const anyGa4 = F.ga4.sessions.some(v => v !== null);
  const ga4Total = arr => anyGa4 ? sum(arr) : null;

  let h = '';
  h += plain('rpt-title', 'Full Sales Funnel &mdash; website sessions &rarr; applications &rarr; Volta\\'s decision &rarr; customer\\'s decision &rarr; signed cases');
  h += plain('rpt-title', 'One column per calendar month of 2026 &middot; application rows = applications submitted that month, by their status today');
  h += '<tr class="rpt-colhead"><td>Funnel stage / metric</td>' + F.months.map(m => '<td>' + mLabel(m) + '</td>').join('') + '<td>Total</td></tr>';

  const s1 = secRow('rpt-section', '1. Website traffic (GA4)'); h += s1.row;
  h += row(s1.id, 'rpt-peach', 'Website sessions', F.ga4.sessions, ga4Total(F.ga4.sessions), false);
  h += row(s1.id, 'rpt-plain', 'of which engaged sessions (10 s+, 2+ pages or a conversion)', F.ga4.engaged, ga4Total(F.ga4.engaged), false);
  h += row(s1.id, 'rpt-plain', 'Active users', F.ga4.users, ga4Total(F.ga4.users), false);

  const s2 = secRow('rpt-section', '2. Applications'); h += s2.row;
  h += cnt(s2.id, 'rpt-peach-strong', 'Applications submitted', F.apps);
  h += row(s2.id, 'rpt-plain', '<span style="color:var(--text-muted)">Applications as % of website sessions</span>', F.apps, sum(F.apps), true, F.ga4.sessions);

  const s3 = secRow('rpt-section', '3. Volta\\'s screening &mdash; status of the month\\'s applications today'); h += s3.row;
  h += cnt(s3.id, 'rpt-peach', 'Rejected by Volta (status 6)', F.rejected);
  h += rate(s3.id, '% of applications', F.rejected, F.apps);
  for (const r of F.rejectReasons) h += cnt(s3.id, 'rpt-plain', '&nbsp;&nbsp;&middot; ' + r.ka + (r.en ? ' <span style="color:var(--text-muted)">(' + r.en + ')</span>' : ''), r.vals);
  h += cnt(s3.id, 'rpt-plain', 'Expired (status 13)', F.expired);
  h += cnt(s3.id, 'rpt-plain', 'Still in process (4 / 7 / 8 / 15 / 16 / 17 / 9 / 10)', F.inProcess);
  if (sum(F.other)) h += cnt(s3.id, 'rpt-plain', 'Other / unverified statuses (3, 14)', F.other);
  h += cnt(s3.id, 'rpt-plain', 'Memo: approved by underwriting (underwriting status 16), whatever happened next', F.uwApproved);
  h += rate(s3.id, '% of applications', F.uwApproved, F.apps);

  const s4 = secRow('rpt-section', '4. Customer\\'s side &mdash; declined after Volta said yes'); h += s4.row;
  h += cnt(s4.id, 'rpt-peach', 'Customer declined (status 12)', F.declined);
  h += rate(s4.id, '% of applications', F.declined, F.apps);
  for (const r of F.declineReasons) h += cnt(s4.id, 'rpt-plain', '&nbsp;&nbsp;&middot; ' + r.ka + (r.en ? ' <span style="color:var(--text-muted)">(' + r.en + ')</span>' : ''), r.vals);

  const s5 = secRow('rpt-section-strong', '5. Final agreement between the customer and Volta'); h += s5.row;
  h += cnt(s5.id, 'rpt-green', 'Signed installment cases (status 11 Signed / 5 Active / 1)', F.signedActive);
  h += cnt(s5.id, 'rpt-plain', 'Single-payment sales (status 99)', F.singlePay);
  const finalAll = F.months.map((m, i) => F.signedActive[i] + F.singlePay[i]);
  h += cnt(s5.id, 'rpt-green', 'Total final agreements (signed + single payment)', finalAll);
  h += rate(s5.id, '% of applications', finalAll, F.apps);
  h += row(s5.id, 'rpt-plain', '<span style="color:var(--text-muted)">Final agreements as % of website sessions</span>', finalAll, sum(finalAll), true, F.ga4.sessions);

  h += '<tr class="rpt-footer"><td colspan="' + colspan + '">Source: VoltaStoreDB only (no old-DB history in this tab). <b>Applications</b> = <code>orders</code> rows by application month (<code>created_at</code>), including the applications migrated from the old CRM for January&ndash;August, so the counts differ slightly from the Daily Mail Report tab (which reads those months from myvolta.info). <b>Statuses</b> = <code>crm_order_status</code> as of today, the same code list the Operations tab verified against the CRM: 6 = Volta rejected the application, 12 = the customer declined after approval, 13 = expired, 11 / 5 / 1 = contract signed / loan active / legacy active, 99 = single-payment sale; everything else is still in process. <b>Reasons</b> = <code>orders.crm_reason</code> as recorded on the application (present on ~99% of rejected and declined applications in every month of 2026); spelling variants are merged (e.g. \\u201Cპროდუქტის არ ქონა\\u201D into \\u201Cპროდუქციის არ ქონა\\u201D, an em-dash suffix such as \\u201C&mdash; აქტიური ჩანაწერი\\u201D dropped), reasons with fewer than ' + ${MIN_REASON_TOTAL} + ' applications in total are grouped as Other (rare). <b>Website sessions</b> = Google Analytics 4 (property 369140604, all traffic, not only paid), summed per month' + (F.ga4.through ? ', data through ' + F.ga4.through : '') + '; the two \\u201Cas % of website sessions\\u201D rows divide the month\\'s applications / final agreements by that month\\'s sessions. Generated ' + F.generatedAt + '.</td></tr>';

  function render() {
    const table = document.getElementById('funnelTable');
    if (!table) return;
    table.querySelector('tbody').innerHTML = h;
    table.querySelectorAll('tr[data-toggle]').forEach(header => {
      header.addEventListener('click', () => {
        const id = header.getAttribute('data-toggle');
        const chevron = header.querySelector('.rpt-chevron');
        const rows = table.querySelectorAll('tr[data-sec="' + id + '"]');
        const collapsing = rows.length && !rows[0].classList.contains('is-collapsed');
        rows.forEach(r => r.classList.toggle('is-collapsed', collapsing));
        if (chevron) chevron.style.transform = collapsing ? 'rotate(-90deg)' : 'rotate(0deg)';
      });
    });
    const banner = document.getElementById('funnelBanner');
    if (banner) banner.innerHTML = '<b>How to read it:</b> each month starts with the website sessions Google Analytics counted, then the applications submitted that month, then how Volta decided them (rejections with the recorded reason, expiries, still open), then how many approved applicants said no themselves (with their reason), and finally how many ended in a signed installment contract or a single-payment sale. Month totals of the stage rows add up to the month\\'s applications' + (anyGa4 ? '' : ' &mdash; GA4 sessions are not available in this build (funnel_ga4_month.tsv missing)') + '.';
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
