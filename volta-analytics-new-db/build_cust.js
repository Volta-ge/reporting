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
const dataLine = 'const CUST_JSON = ' + JSON.stringify(payload) + ';';
if (/^const CUST_JSON = .*;$/m.test(html)) html = html.replace(/^const CUST_JSON = .*;$/m, () => dataLine);
else { must('const generatedAt = REPORT_JSON.generatedAt;', 'report consts'); html = html.replace('const generatedAt = REPORT_JSON.generatedAt;', 'const generatedAt = REPORT_JSON.generatedAt;' + NL + dataLine); }

// render code (replaced on every run)
const JS_MARK = '/* ---------- cust ---------- */', JS_END = '/* ---------- /cust ---------- */';
removeBetween(NL + JS_MARK, JS_END, 'cust js');   // the newline after JS_END stays: it is the one before the nav-handler comment
const js = `
${JS_MARK}
(function () {
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
})();
${JS_END}`;
must('// ---- top-level page nav (grows as more reports get added) ----', 'nav handler');
html = html.replace('// ---- top-level page nav (grows as more reports get added) ----', js.replace(/^\n/, '') + NL + '// ---- top-level page nav (grows as more reports get added) ----');
must("  if (btn.dataset.page === 'subcategoryanalyze') subcategoryScrollUpdate();", 'nav updaters');
if (!html.includes("btn.dataset.page === 'customers'")) html = html.replace("  if (btn.dataset.page === 'subcategoryanalyze') subcategoryScrollUpdate();", "  if (btn.dataset.page === 'subcategoryanalyze') subcategoryScrollUpdate();" + NL + "  if (btn.dataset.page === 'customers' && window.custScrollUpdaters) window.custScrollUpdaters.forEach(f => f());");

fs.writeFileSync(htmlPath, html);
console.log('html:', htmlPath, 'bytes:', html.length);
