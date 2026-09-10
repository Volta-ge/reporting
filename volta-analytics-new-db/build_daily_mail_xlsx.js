// report_data.json -> daily_mail_xlsx.json: three sheets (Report, MTD Statistics, Daily Statistics) laid out exactly
// like the Volta_Analytics_New DB tabs. Source numbers are written as values; every derived cell (%, Section C
// totals, budget rows) is an Excel formula so the workbook recalculates if a number is edited.
const fs = require('fs');
const path = require('path');
const R = JSON.parse(fs.readFileSync(path.join(__dirname, 'report_data.json'), 'utf8'));
const targets = { applications: 2500, amount: 1900000 };
const MONTH = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

const col = n => { let s = ''; n++; while (n > 0) { const m = (n - 1) % 26; s = String.fromCharCode(65 + m) + s; n = Math.floor((n - 1) / 26); } return s; };
const cell = (c, r) => col(c) + r; // c 0-based, r 1-based
// cell descriptors: {v} value, {f} formula, t: 'n' number | 'p' percent | 's' text ; note cells get n:true
const S = v => ({ v, t: 's' });
const N = v => ({ v, t: 'n' });
const F = f => ({ f, t: 'n' });
const P = f => ({ f, t: 'p' });
const NOTE = v => ({ v, t: 's', note: true });
const DASH = () => ({ v: '–', t: 's', dash: true });

// ---------------- Report sheet ----------------
function reportSheet() {
  const rows = []; // {cls, cells:[...]}
  const dayKeys = Object.keys(R.dailyStatsObj);
  const yestKey = dayKeys[dayKeys.length - 1];
  const mtdStart = dayKeys.find(k => k.slice(0, 7) === yestKey.slice(0, 7));
  const [yy, ym, yd] = yestKey.split('-').map(Number);
  const daysInMonth = new Date(yy, ym, 0).getDate();
  const workingDaysLeft = Math.max(daysInMonth - (yd + 1) + 1, 0);
  const longDate = k => { const [y, m, d] = k.split('-').map(Number); return `${MONTH[m-1]} ${d}, ${y}`; };
  const rangeLabel = (a, b) => { const [y, m, d1] = a.split('-').map(Number); return `${MONTH[m-1]} ${d1}–${b.split('-')[2] * 1}, ${y}`; };

  const Y = R.reportData.yest, M = R.reportData.mtd;
  rows.push({ cls: 'title', cells: [S('Volta Daily Report — Sales & Product-Terms Funnel')], span: 6 });
  rows.push({ cls: 'title', cells: [S(`Yesterday (${longDate(yestKey)})  |  MTD (${rangeLabel(mtdStart, yestKey)})`)], span: 6 });
  rows.push({ cls: 'colhead', cells: [S('Funnel Stage / Metric'), S('Yesterday'), S('Yest. %'), S('MTD'), S('MTD %'), S('Insight / Example Message')] });

  const idx = {}; // metric row numbers per section for formulas
  function section(key, title, seg, notes, strong) {
    rows.push({ cls: strong ? 'section-strong' : 'section', cells: [S(title)], span: 6 });
    const base = rows.length + 1; // 1-based row of the first metric row
    idx[key] = { apps: base, terms: base + 1, uw: base + 2, closed: base + 3, amount: base + 4, dp: base + 5, rate: base + 6 };
    const r = idx[key];
    const KEY = { apps: 'applications', terms: 'terms', uw: 'uw', closed: 'closed', amount: 'amount', dp: 'dp' };
    const val = (m, side) => seg ? N(seg[side][KEY[m]]) : F(`=B${idx.A[m]}+B${idx.B[m]}`.replace(/B/g, side === 'yest' ? 'B' : 'D'));
    const fill = strong ? 'peach' : 'plain';
    const fillHot = strong ? 'peach-strong' : 'peach';
    rows.push({ cls: fill, cells: [S('Applications'), val('apps', 'yest'), P(`=IF(B${r.apps}=0,0,B${r.apps}/B${r.apps})`), val('apps', 'mtd'), P(`=IF(D${r.apps}=0,0,D${r.apps}/D${r.apps})`), NOTE(notes.apps)] });
    rows.push({ cls: fill, cells: [S('Product Terms Approved by Customer'), val('terms', 'yest'), P(`=IF(B${r.apps}=0,0,B${r.terms}/B${r.apps})`), val('terms', 'mtd'), P(`=IF(D${r.apps}=0,0,D${r.terms}/D${r.apps})`), NOTE(notes.terms)] });
    rows.push({ cls: fill, cells: [S('Underwriting Approved'), val('uw', 'yest'), P(`=IF(B${r.apps}=0,0,B${r.uw}/B${r.apps})`), val('uw', 'mtd'), P(`=IF(D${r.apps}=0,0,D${r.uw}/D${r.apps})`), NOTE(notes.uw)] });
    rows.push({ cls: fillHot, cells: [S('Deals Closed (Clients)'), val('closed', 'yest'), P(`=IF(B${r.apps}=0,0,B${r.closed}/B${r.apps})`), val('closed', 'mtd'), P(`=IF(D${r.apps}=0,0,D${r.closed}/D${r.apps})`), NOTE(notes.closed)] });
    rows.push({ cls: fillHot, cells: [S('Amount Sold (GEL)'), val('amount', 'yest'), null, val('amount', 'mtd'), null, NOTE(notes.amount)] });
    rows.push({ cls: 'green', cells: [S('Downpayment Collected (GEL)'), val('dp', 'yest'), null, val('dp', 'mtd'), null, NOTE(notes.dp)] });
    rows.push({ cls: 'green', cells: [S('Downpayment Rate (% of amount)'), DASH(), P(`=IF(B${r.amount}=0,0,B${r.dp}/B${r.amount})`), DASH(), P(`=IF(D${r.amount}=0,0,D${r.dp}/D${r.amount})`), NOTE(notes.rate)] });
  }
  section('A', 'A. Requires Downpayment (TV / Phone / >2,500 GEL)', Y && { yest: Y.A, mtd: M.A }, {
    apps: 'TV, phone, or a single product priced over 2,500 GEL', terms: 'Customer accepted high-DP terms → enters underwriting', uw: 'Approved by underwriting',
    closed: 'Loans actually disbursed / active', amount: 'Revenue from high-DP segment', dp: 'Downpayment money collected (high, product-driven)', rate: 'Avg downpayment as % of amount sold' });
  section('B', 'B. Standard Terms (no product-driven downpayment)', { yest: Y.B, mtd: M.B }, {
    apps: 'Standard product terms', terms: 'Customer accepted standard terms → enters underwriting', uw: 'Approved by underwriting',
    closed: 'Loans actually disbursed / active', amount: 'Revenue from standard segment', dp: 'Downpayment money collected (standard, low)', rate: 'Avg downpayment as % of amount sold' });
  section('C', 'C. TOTAL FUNNEL (A + B — auto-calculated)', null, {
    apps: 'MTD total (A + B)', terms: 'Total who accepted terms & entered underwriting', uw: 'Approved by underwriting (total)',
    closed: 'Total loans disbursed', amount: "Yest % = yesterday's sales vs required daily budget · target attainment shown below", dp: 'Total downpayment collected (A + B)', rate: 'Blended downpayment as % of amount sold' }, true);
  // Amount / DP % cells: A & B = share of the A+B total; C amount yest% = vs required daily (filled after budget rows exist)
  for (const k of ['A', 'B']) {
    const r = idx[k], c = idx.C;
    rows[r.amount - 1].cells[2] = P(`=IF(B${c.amount}=0,0,B${r.amount}/B${c.amount})`); rows[r.amount - 1].cells[4] = P(`=IF(D${c.amount}=0,0,D${r.amount}/D${c.amount})`);
    rows[r.dp - 1].cells[2] = P(`=IF(B${c.dp}=0,0,B${r.dp}/B${c.dp})`); rows[r.dp - 1].cells[4] = P(`=IF(D${c.dp}=0,0,D${r.dp}/D${c.dp})`);
  }
  rows[idx.C.dp - 1].cells[2] = P('=1'); rows[idx.C.dp - 1].cells[4] = P('=1');

  rows.push({ cls: 'section', cells: [S('Budget & Pacing (MTD Actual vs Monthly Target)')], span: 6 });
  rows.push({ cls: 'colhead', cells: [S('Target Metric'), S('Actual MTD'), null, S('Monthly Target'), S('Attainment'), S('Notes')] });
  const bAppsRow = rows.length + 1;
  rows.push({ cls: 'peach-strong', cells: [S('Applications (MTD vs Target)'), F(`=D${idx.C.apps}`), null, N(targets.applications), P(`=IF(D${bAppsRow}=0,0,B${bAppsRow}/D${bAppsRow})`), NOTE('Actual MTD applications / monthly target')] });
  const bAmtRow = rows.length + 1;
  rows.push({ cls: 'peach-strong', cells: [S('Amount Sold (MTD vs Target, GEL)'), F(`=D${idx.C.amount}`), null, N(targets.amount), P(`=IF(D${bAmtRow}=0,0,B${bAmtRow}/D${bAmtRow})`), NOTE('Actual MTD amount sold / monthly target')] });
  const remRow = rows.length + 1;
  rows.push({ cls: 'peach', cells: [S('Remaining to Sales Target (GEL)'), F(`=D${bAmtRow}-B${bAmtRow}`), null, null, DASH(), NOTE('Monthly target − actual MTD sold')] });
  const daysRow = remRow + 2;
  const reqRow = rows.length + 1;
  rows.push({ cls: 'peach', cells: [S('Required Daily Sales (GEL)'), F(`=IF(B${daysRow}=0,0,B${remRow}/B${daysRow})`), null, null, DASH(), NOTE(`Over ${workingDaysLeft} remaining working days`)] });
  rows.push({ cls: 'peach', cells: [S('Remaining Working Days'), N(workingDaysLeft), null, null, DASH(), NOTE('Working days left in the month')] });
  rows[idx.C.amount - 1].cells[2] = P(`=IF(B${reqRow}=0,0,B${idx.C.amount}/B${reqRow})`); rows[idx.C.amount - 1].cells[4] = DASH();

  rows.push({ cls: 'footer', span: 6, cells: [S(`Segment A = TV, Phone, or any single product > 2,500 GEL. Segment B = everything else. Applications, Terms Approved, Underwriting Approved and Downpayment are keyed to Application Date; Deals Closed and Amount Sold to Order Date (active/disbursed only). Hybrid source: before ${R.cutover} myvolta.info (instalments), from ${R.cutover} on VoltaStoreDB (orders). Count % = share of the segment's own Applications; Amount/DP % in A & B = share of the A+B total. Budget targets are a fixed business goal, not from the database. Generated ${R.generatedAt}.`)] });
  return { name: 'Report', rows, widths: [38, 12, 10, 12, 10, 70], firstColWidth: 38 };
}

// ---------------- Pivot sheets (MTD / Daily) ----------------
function pivotSheet(name, statsObj, labelFn, subtitle, footer) {
  const periods = Object.keys(statsObj);
  const nCols = 1 + periods.length * 2;
  const rows = [];
  rows.push({ cls: 'title', cells: [S('Volta Daily Report — Sales & Product-Terms Funnel')], span: nCols });
  rows.push({ cls: 'title', cells: [S(subtitle)], span: nCols });
  const head1 = [S('Funnel Stage / Metric')]; const head2 = [S('')];
  periods.forEach(p => { head1.push(S(labelFn(p)), null); head2.push(S('Qty'), S('%')); });
  rows.push({ cls: 'colhead', cells: head1, pairSpans: true });
  rows.push({ cls: 'colhead', cells: head2 });

  const idx = {};
  const qtyCol = i => col(1 + i * 2), pctCol = i => col(2 + i * 2);
  function section(key, title, strong) {
    rows.push({ cls: strong ? 'section-strong' : 'section', cells: [S(title)], span: nCols });
    const base = rows.length + 1;
    idx[key] = { apps: base, terms: base + 1, uw: base + 2, closed: base + 3, amount: base + 4, dp: base + 5, rate: base + 6 };
    const r = idx[key];
    const fill = strong ? 'peach' : 'plain', fillHot = strong ? 'peach-strong' : 'peach';
    const metricRow = (label, m, cls, pctFn) => {
      const cells = [S(label)];
      periods.forEach((p, i) => {
        const q = qtyCol(i);
        const v = m === 'rate' ? DASH() : (key === 'C' ? F(`=${q}${idx.A[m]}+${q}${idx.B[m]}`) : N(statsObj[p][key][m]));
        cells.push(v, pctFn ? P(pctFn(q, i)) : null);
      });
      rows.push({ cls, cells });
    };
    metricRow('Applications', 'apps', fill, q => `=IF(${q}${r.apps}=0,0,${q}${r.apps}/${q}${r.apps})`);
    metricRow('Product Terms Approved by Customer', 'terms', fill, q => `=IF(${q}${r.apps}=0,0,${q}${r.terms}/${q}${r.apps})`);
    metricRow('Underwriting Approved', 'uw', fill, q => `=IF(${q}${r.apps}=0,0,${q}${r.uw}/${q}${r.apps})`);
    metricRow('Deals Closed (Clients)', 'closed', fillHot, q => `=IF(${q}${r.apps}=0,0,${q}${r.closed}/${q}${r.apps})`);
    metricRow('Amount Sold (GEL)', 'amount', fillHot, null);
    metricRow('Downpayment Collected (GEL)', 'dp', 'green', null);
    metricRow('Downpayment Rate (% of amount)', 'rate', 'green', q => `=IF(${q}${r.amount}=0,0,${q}${r.dp}/${q}${r.amount})`);
  }
  // statsObj entries: {A:{applications,...}, B:{...}} -> normalize metric names
  const norm = {};
  for (const p of periods) norm[p] = { A: mapSeg(statsObj[p].A), B: mapSeg(statsObj[p].B) };
  function mapSeg(s) { return { apps: s.applications, terms: s.terms, uw: s.uw, closed: s.closed, amount: s.amount, dp: s.dp }; }
  const statsNorm = norm;
  // rebind: section() reads statsObj[p][key][m] -> use normalized
  const sectionN = (key, title, strong) => { const saved = statsObj; statsObj = statsNorm; section(key, title, strong); statsObj = saved; };
  sectionN('A', 'A. Requires Downpayment (TV / Phone / >2,500 GEL)', false);
  sectionN('B', 'B. Standard Terms (no product-driven downpayment)', false);
  sectionN('C', 'C. TOTAL FUNNEL (A + B — auto-calculated)', true);
  for (const k of ['A', 'B']) periods.forEach((p, i) => {
    const q = qtyCol(i), r = idx[k], c = idx.C;
    rows[r.amount - 1].cells[2 + i * 2] = P(`=IF(${q}${c.amount}=0,0,${q}${r.amount}/${q}${c.amount})`);
    rows[r.dp - 1].cells[2 + i * 2] = P(`=IF(${q}${c.dp}=0,0,${q}${r.dp}/${q}${c.dp})`);
  });
  periods.forEach((p, i) => { rows[idx.C.amount - 1].cells[2 + i * 2] = P('=1'); rows[idx.C.dp - 1].cells[2 + i * 2] = P('=1'); });
  rows.push({ cls: 'footer', span: nCols, cells: [S(footer)] });
  const widths = [36]; periods.forEach(() => widths.push(9, 7));
  return { name, rows, widths, freezeCol: 1 };
}

const monthLabel = ym => { const [y, m] = ym.split('-').map(Number); return `${MONTH[m-1]} ${y}` + (ym === '2026-09' ? ' (MTD)' : ''); };
const dayLabel = ymd => { const [, m, d] = ymd.split('-').map(Number); return `${MONTH[m-1]} ${d}`; };
const PIVOT_FOOTER = `Segment A = TV, Phone, or any single product > 2,500 GEL. Segment B = everything else. Deals Closed / Amount Sold keyed to Order Date (active only); Applications / Terms / Underwriting / Downpayment keyed to Application Date. Hybrid source: before ${R.cutover} myvolta.info, from ${R.cutover} on VoltaStoreDB. Generated ${R.generatedAt}.`;

const sheets = [
  reportSheet(),
  pivotSheet('MTD Statistics', R.monthlyStatsObj, monthLabel, 'One column pair per calendar month', PIVOT_FOOTER + ' A completed month shows its final total; the current month shows MTD-to-date.'),
  pivotSheet('Daily Statistics', R.dailyStatsObj, dayLabel, 'One column pair per calendar day, from June 1', PIVOT_FOOTER + ' Today is excluded. Recent days are undercounted and keep rising on refresh, because Order Date is set days after Application Date.'),
];
fs.writeFileSync(path.join(__dirname, 'daily_mail_xlsx.json'), JSON.stringify({ sheets }));
sheets.forEach(s => console.log(s.name, ': rows', s.rows.length, ', max cols', Math.max(...s.rows.map(r => r.cells.length))));
