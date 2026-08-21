<?php
/**
 * @var array{mtd: array, yest: array}|null $data
 * @var string|null $connectionError
 * @var array{applications: int, amount: int, workingDaysLeft: int} $targets
 * @var string $headerYesterday
 * @var string $headerMtdRange
 * @var string $generatedAt
 * @var array<string, array{A: array, B: array}>|null $monthlyStats
 * @var array<string, array{A: array, B: array}>|null $dailyStats
 */

declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Volta Funnel Dashboard</title>
<style>
:root {
  color-scheme: light;
  --surface-1:      #fcfcfb;
  --page:           #f9f9f7;
  --text-primary:   #0b0b0b;
  --text-secondary: #52514e;
  --text-muted:     #898781;
  --grid:           #e1e0d9;
  --baseline:       #c3c2b7;
  --border:         rgba(11,11,11,0.10);
  --series-a:       #2a78d6;
  --series-a-soft:  #cde2fb;
  --series-b:       #eb6834;
  --series-b-soft:  #ffe0d1;
  --good:           #0ca30c;
  --warning:        #fab219;
  --critical:       #d03b3b;

  /* Report page: fixed spreadsheet palette (matches the source Excel exactly, same in both themes) */
  --rpt-title-bg:         #9dc3e6;
  --rpt-title-ink:        #10253a;
  --rpt-colhead-bg:       #ddebf7;
  --rpt-section-bg:       #ffd966;
  --rpt-section-strong-bg:#ffc000;
  --rpt-peach-bg:         #fce4d6;
  --rpt-peach-strong-bg:  #f4b183;
  --rpt-green-bg:         #e2efda;
  --rpt-note-bg:          #fff2cc;
  --rpt-plain-bg:         #ffffff;
  --rpt-ink:              #111111;
  --rpt-muted:            #6b6b6b;
  --rpt-border:           #c9c9c9;
}
@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) {
    color-scheme: dark;
    --surface-1:      #1a1a19;
    --page:           #0d0d0d;
    --text-primary:   #ffffff;
    --text-secondary: #c3c2b7;
    --text-muted:     #898781;
    --grid:           #2c2c2a;
    --baseline:       #383835;
    --border:         rgba(255,255,255,0.10);
    --series-a:       #3987e5;
    --series-a-soft:  #1c3a5c;
    --series-b:       #d95926;
    --series-b-soft:  #5c331f;
    --good:           #0ca30c;
    --warning:        #fab219;
    --critical:       #e66767;
  }
}
:root[data-theme="dark"] {
  color-scheme: dark;
  --surface-1:      #1a1a19;
  --page:           #0d0d0d;
  --text-primary:   #ffffff;
  --text-secondary: #c3c2b7;
  --text-muted:     #898781;
  --grid:           #2c2c2a;
  --baseline:       #383835;
  --border:         rgba(255,255,255,0.10);
  --series-a:       #3987e5;
  --series-a-soft:  #1c3a5c;
  --series-b:       #d95926;
  --series-b-soft:  #5c331f;
  --good:           #0ca30c;
  --warning:        #fab219;
  --critical:       #e66767;
}

* { box-sizing: border-box; }
body {
  background: var(--page);
  color: var(--text-primary);
  font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
  margin: 0;
}
.app { max-width: 1180px; margin: 0 auto; padding: 28px 20px 56px; display: flex; flex-direction: column; gap: 22px; }

/* header */
.hdr { display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 12px; border-bottom: 1px solid var(--border); padding-bottom: 16px; }
.hdr h1 { font-size: 21px; font-weight: 650; margin: 0 0 4px; text-wrap: balance; }
.hdr .sub { font-size: 13px; color: var(--text-secondary); }
.hdr .period { text-align: right; font-size: 12.5px; color: var(--text-muted); }
.hdr .period b { color: var(--text-primary); font-weight: 600; }

/* section titles */
.section-title { font-size: 13px; font-weight: 650; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-secondary); margin: 0; }

.note { font-size: 11.5px; color: var(--text-muted); line-height: 1.5; }

/* page nav */
.page-nav { display: inline-flex; background: var(--surface-1); border: 1px solid var(--border); border-radius: 8px; padding: 3px; gap: 2px; }
.page-nav button {
  border: none; background: transparent; color: var(--text-secondary); font: inherit; font-size: 13px; font-weight: 600;
  padding: 7px 16px; border-radius: 6px; cursor: pointer;
}
.page-nav button.active { background: var(--text-primary); color: var(--surface-1); }
.page { display: none; flex-direction: column; gap: 22px; }
.page.active { display: flex; }

/* report page (spreadsheet replica) */
.report-card { background: var(--surface-1); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
.report-scroll { overflow-x: auto; }
table.rpt { width: 100%; border-collapse: collapse; font-size: 11.5px; table-layout: fixed; }
table.rpt th, table.rpt td { padding: 5px 7px; border: 1px solid var(--rpt-border); font-variant-numeric: tabular-nums; overflow-wrap: break-word; white-space: normal; }
table.rpt col.c-metric { width: 25%; }
table.rpt col.c-val { width: 10%; }
table.rpt col.c-pct { width: 9%; }
table.rpt col.c-note { width: 37%; }
table.rpt td:first-child, table.rpt th:first-child { text-align: left; font-variant-numeric: normal; }
table.rpt td:not(:first-child), table.rpt th:not(:first-child) { text-align: right; }
table.rpt td.note-cell, table.rpt th.note-cell { text-align: left; white-space: normal; font-variant-numeric: normal; color: var(--rpt-ink); font-size: 11px; }
table.rpt tr.rpt-title td { background: var(--rpt-title-bg); color: var(--rpt-title-ink); font-weight: 700; text-align: left; }
table.rpt tr.rpt-colhead td { background: var(--rpt-colhead-bg); color: var(--rpt-ink); font-weight: 700; font-size: 10px; text-transform: uppercase; letter-spacing: 0.02em; }
table.rpt tr.rpt-section td, table.rpt tr.rpt-section-strong td { cursor: pointer; user-select: none; }
table.rpt .rpt-chevron { display: inline-block; width: 1em; }
table.rpt tr[data-sec].is-collapsed { display: none; }
/* laptop: drop the Notes column, keep both periods + both % columns */
@media (max-width: 980px) {
  table.rpt col.c-note { width: 0; }
  table.rpt td.note-cell, table.rpt th.note-cell { display: none; }
  table.rpt col.c-metric { width: 40%; }
  table.rpt col.c-val { width: 15%; }
  table.rpt col.c-pct { width: 15%; }
}
/* mobile: also drop the two % columns, keep Metric / Yesterday / MTD */
@media (max-width: 560px) {
  table.rpt col.c-pct { width: 0; }
  table.rpt td.pct-cell, table.rpt th.pct-cell { display: none; }
  table.rpt col.c-metric { width: 46%; }
  table.rpt col.c-val { width: 27%; }
}
table.rpt tr.rpt-section td { background: var(--rpt-section-bg); color: var(--rpt-ink); font-weight: 700; }
table.rpt tr.rpt-section-strong td { background: var(--rpt-section-strong-bg); color: var(--rpt-ink); font-weight: 700; }
table.rpt tr.rpt-peach td { background: var(--rpt-peach-bg); color: var(--rpt-ink); }
table.rpt tr.rpt-peach-strong td { background: var(--rpt-peach-strong-bg); color: var(--rpt-ink); }
table.rpt tr.rpt-green td { background: var(--rpt-green-bg); color: var(--rpt-ink); }
table.rpt tr.rpt-plain td { background: var(--rpt-plain-bg); color: var(--rpt-ink); }
table.rpt tr.rpt-plain td:first-child, table.rpt tr.rpt-peach td:first-child, table.rpt tr.rpt-peach-strong td:first-child, table.rpt tr.rpt-green td:first-child { font-weight: 600; }
table.rpt td.dash { color: var(--rpt-muted); text-align: right; }
table.rpt .rpt-note { background: var(--rpt-note-bg); }
table.rpt tr.rpt-footer td { background: var(--rpt-plain-bg); color: var(--rpt-muted); font-size: 11px; line-height: 1.5; font-variant-numeric: normal; }

/* pivot variant: dynamic column count (one pair per month/day) instead of a fixed 2-period
   layout, so columns size to content and the metric column stays pinned while scrolling */
table.rpt-pivot { table-layout: auto; font-size: 10.5px; width: auto; }
table.rpt-pivot td, table.rpt-pivot th { padding: 4px 5px; white-space: nowrap; overflow-wrap: normal; }
table.rpt-pivot td:first-child, table.rpt-pivot th:first-child { position: sticky; left: 0; z-index: 2; box-shadow: 1px 0 0 var(--rpt-border); }
table.rpt-pivot tr.rpt-title td:first-child { z-index: 3; }
/* MTD Statistics only: few enough columns that the whole table should fit without scrolling,
   so its metric column wraps onto 2 lines instead of forcing one long unbroken line. Daily
   Statistics keeps the plain nowrap label column (it always needs to scroll — 80+ days — so
   there's no width to save, and wrapping there just made rows unnecessarily tall). */
table.rpt-pivot-compact td:first-child, table.rpt-pivot-compact th:first-child { white-space: normal; max-width: 150px; }
table.rpt-pivot tr.rpt-colhead td:not(:first-child) { text-align: center; }
table.rpt-pivot td.col-collapsed { display: none; }
table.rpt-pivot td.rpt-group-toggle { cursor: pointer; user-select: none; text-align: center; }

/* duplicate top scrollbar for the two pivot tables (many columns — the bottom scrollbar can be
   far below the viewport), kept in sync with the real horizontal scroll via JS */
.report-scroll-top { overflow-x: auto; overflow-y: hidden; height: 14px; margin-bottom: -1px; }
.report-scroll-top > div { height: 1px; }

.db-error { background: color-mix(in srgb, var(--critical) 10%, var(--surface-1)); border: 1px solid var(--critical); border-radius: 12px; padding: 16px 20px; color: var(--text-primary); }
.db-error strong { color: var(--critical); }
</style>
</head>
<body>

<?php if ($connectionError !== null): ?>
<div class="app">
  <div class="hdr">
    <div><h1>Volta &mdash; Sales &amp; Product-Terms Funnel</h1></div>
  </div>
  <div class="db-error">
    <strong>Could not load data.</strong>
    <p><?= htmlspecialchars($connectionError, ENT_QUOTES, 'UTF-8') ?></p>
    <p class="note">Check config.php and confirm this server can reach the database host. No data was fabricated — this page shows nothing rather than stale or made-up numbers.</p>
  </div>
</div>
<?php return; endif; ?>

<div class="app">
  <div class="hdr">
    <div>
      <h1>Volta &mdash; Sales &amp; Product-Terms Funnel</h1>
      <div class="sub">myvolta8_voltadb &middot; instalments table &middot; Product Terms approved by customer = moved to Underwriting</div>
    </div>
    <div class="period">Yesterday: <b><?= htmlspecialchars($headerYesterday, ENT_QUOTES, 'UTF-8') ?></b><br>MTD: <b><?= htmlspecialchars($headerMtdRange, ENT_QUOTES, 'UTF-8') ?></b></div>
  </div>

  <div class="page-nav" id="pageNav">
    <button data-page="report" class="active">Daily Report</button>
    <button data-page="mtdstats">MTD Statistics</button>
    <button data-page="dailystats">Daily Statistics</button>
  </div>

  <div class="page active" id="page-report">
    <p class="section-title">Volta Daily Report &mdash; original spreadsheet layout</p>
    <p class="note" style="margin:-8px 0 0;">Yesterday and MTD side by side, like the Excel file. Tap a yellow/orange section header to collapse it.</p>
    <div class="report-card">
      <div class="report-scroll">
        <table class="rpt" id="reportTable">
          <colgroup><col class="c-metric"><col class="c-val"><col class="c-pct"><col class="c-val"><col class="c-pct"><col class="c-note"></colgroup>
          <tbody id="reportBody"></tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="page" id="page-mtdstats">
    <p class="section-title">MTD Statistics &mdash; same report, one column per month</p>
    <p class="note" style="margin:-8px 0 0;">Same Section A/B/C layout as the Report tab, but pivoted: each column pair is one calendar month of <?= htmlspecialchars($now->format('Y'), ENT_QUOTES, 'UTF-8') ?> instead of Yesterday/MTD. A completed month shows its final total; the current month shows MTD-to-date. Scroll right for more months. Tap a section header to collapse it.</p>
    <div class="report-card">
      <div class="report-scroll-top" id="mtdStatsScrollTop"><div></div></div>
      <div class="report-scroll" id="mtdStatsScrollBody">
        <table class="rpt rpt-pivot rpt-pivot-compact" id="mtdStatsTable"><tbody></tbody></table>
      </div>
    </div>
  </div>

  <div class="page" id="page-dailystats">
    <p class="section-title">Daily Statistics &mdash; same report, one column per day</p>
    <p class="note" style="margin:-8px 0 0;">Same Section A/B/C layout as the Report tab, but pivoted: each column pair is one calendar day from June 1, <?= htmlspecialchars($now->format('Y'), ENT_QUOTES, 'UTF-8') ?> through yesterday, instead of Yesterday/MTD. Today is excluded (not yet finished). Recent days are undercounted and will keep rising on refresh, because Active/Order Date can be set several days after Application Date. Scroll right for more days. Tap a section header to collapse it.</p>
    <div class="report-card">
      <div class="report-scroll-top" id="dailyStatsScrollTop"><div></div></div>
      <div class="report-scroll" id="dailyStatsScrollBody">
        <table class="rpt rpt-pivot" id="dailyStatsTable"><tbody></tbody></table>
      </div>
    </div>
  </div>
</div>

<script>
const data = <?= json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const targets = <?= json_encode($targets, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const generatedAt = <?= json_encode($generatedAt, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const monthlyStats = <?= json_encode($monthlyStats, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const dailyStats = <?= json_encode($dailyStats, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

const fmt = n => Math.round(n).toLocaleString('en-US');
const fmt1 = n => n.toLocaleString('en-US', { maximumFractionDigits: 1 });
const pct = n => (n*100).toLocaleString('en-US', { maximumFractionDigits: 1 }) + '%';

/* ---------- Report page: compact replica of the original "Volta Daily Report - Sales & Product-Terms Funnel" spreadsheet ---------- */

function pctSafe(n, d) { return d ? n / d : 0; }
function cellOrDash(v, cls) { return (v === null || v === undefined) ? `<td class="dash ${cls||''}">&ndash;</td>` : `<td class="${cls||''}">${v}</td>`; }

let secCounter = 0;
function rptRow(sec, cls, label, y, yp, m, mp, note) {
  return `<tr class="${cls}" data-sec="${sec}"><td>${label}</td>${cellOrDash(y)}${cellOrDash(yp, 'pct-cell')}${cellOrDash(m)}${cellOrDash(mp, 'pct-cell')}<td class="note-cell rpt-note">${note || ''}</td></tr>`;
}
function rptSpan(cls, text) {
  const id = 'sec' + (++secCounter);
  return { id, row: `<tr class="${cls}" data-toggle="${id}"><td colspan="6"><span class="rpt-chevron">&#9662;</span>${text}</td></tr>` };
}
function rptPlainSpan(cls, text) {
  return `<tr class="${cls}"><td colspan="6">${text}</td></tr>`;
}
function rptColHead(c1, c2, c3, c4, c5, c6) {
  return `<tr class="rpt-colhead"><td>${c1}</td><td>${c2}</td><td class="pct-cell">${c3}</td><td>${c4}</td><td class="pct-cell">${c5}</td><td class="note-cell">${c6}</td></tr>`;
}

function renderReport() {
  const Y = data.yest, M = data.mtd;
  const totAmountY = Y.A.amount + Y.B.amount, totDpY = Y.A.dp + Y.B.dp;
  const totAmountM = M.A.amount + M.B.amount, totDpM = M.A.dp + M.B.dp;

  function segBlock(title, seg, sectionNote) {
    const appsY = seg.yest.applications, appsM = seg.mtd.applications;
    const { id, row } = rptSpan('rpt-section', title);
    let html = row;
    html += rptRow(id, 'rpt-plain', 'Applications', fmt(appsY), pct(pctSafe(appsY, appsY)), fmt(appsM), pct(pctSafe(appsM, appsM)), sectionNote.apps);
    html += rptRow(id, 'rpt-plain', 'Product Terms Approved by Customer', fmt(seg.yest.terms), pct(pctSafe(seg.yest.terms, appsY)), fmt(seg.mtd.terms), pct(pctSafe(seg.mtd.terms, appsM)), sectionNote.terms);
    html += rptRow(id, 'rpt-plain', 'Underwriting Approved', fmt(seg.yest.uw), pct(pctSafe(seg.yest.uw, appsY)), fmt(seg.mtd.uw), pct(pctSafe(seg.mtd.uw, appsM)), sectionNote.uw);
    html += rptRow(id, 'rpt-peach', 'Deals Closed (Clients)', fmt(seg.yest.closed), pct(pctSafe(seg.yest.closed, appsY)), fmt(seg.mtd.closed), pct(pctSafe(seg.mtd.closed, appsM)), sectionNote.closed);
    html += rptRow(id, 'rpt-peach', 'Amount Sold (GEL)', fmt(seg.yest.amount), pct(pctSafe(seg.yest.amount, totAmountY)), fmt(seg.mtd.amount), pct(pctSafe(seg.mtd.amount, totAmountM)), sectionNote.amount);
    html += rptRow(id, 'rpt-green', 'Downpayment Collected (GEL)', fmt(seg.yest.dp), pct(pctSafe(seg.yest.dp, totDpY)), fmt(seg.mtd.dp), pct(pctSafe(seg.mtd.dp, totDpM)), sectionNote.dp);
    html += rptRow(id, 'rpt-green', 'Downpayment Rate (% of amount)', null, pct(pctSafe(seg.yest.dp, seg.yest.amount)), null, pct(pctSafe(seg.mtd.dp, seg.mtd.amount)), sectionNote.rate);
    return html;
  }

  const segA = { yest: Y.A, mtd: M.A };
  const segB = { yest: Y.B, mtd: M.B };

  let html = '';
  html += rptPlainSpan('rpt-title', 'Volta Daily Report &mdash; Sales &amp; Product-Terms Funnel');
  html += rptPlainSpan('rpt-title', `${Y.label} &nbsp;|&nbsp; ${M.label}`);
  html += rptColHead('Funnel Stage / Metric', 'Yesterday', 'Yest. %', 'MTD', 'MTD %', 'Insight / Example Message');

  html += segBlock('A. Requires Downpayment (TV / Phone / &gt;2,500 GEL)', segA, {
    apps: 'TV, phone, or a single product priced over 2,500 GEL',
    terms: 'Customer accepted high-DP terms &rarr; enters underwriting',
    uw: 'Approved by underwriting',
    closed: 'Loans actually disbursed / active',
    amount: 'Revenue from high-DP segment',
    dp: 'Downpayment money collected (high, product-driven)',
    rate: 'Avg downpayment as % of amount sold',
  });

  html += segBlock('B. Standard Terms (no product-driven downpayment)', segB, {
    apps: 'Standard product terms',
    terms: 'Customer accepted standard terms &rarr; enters underwriting',
    uw: 'Approved by underwriting',
    closed: 'Loans actually disbursed / active',
    amount: 'Revenue from standard segment',
    dp: 'Downpayment money collected (standard, low)',
    rate: 'Avg downpayment as % of amount sold',
  });

  // Section C — Total funnel.
  const appsY_C = Y.A.applications + Y.B.applications, appsM_C = M.A.applications + M.B.applications;
  const termsY_C = Y.A.terms + Y.B.terms, termsM_C = M.A.terms + M.B.terms;
  const uwY_C = Y.A.uw + Y.B.uw, uwM_C = M.A.uw + M.B.uw;
  const closedY_C = Y.A.closed + Y.B.closed, closedM_C = M.A.closed + M.B.closed;

  const remaining = targets.amount - totAmountM;
  const requiredDaily = targets.workingDaysLeft ? remaining / targets.workingDaysLeft : 0;

  const secC = rptSpan('rpt-section-strong', 'C. TOTAL FUNNEL (A + B &mdash; auto-calculated)');
  html += secC.row;
  html += rptRow(secC.id, 'rpt-peach', 'Applications', fmt(appsY_C), pct(pctSafe(appsY_C, appsY_C)), fmt(appsM_C), pct(pctSafe(appsM_C, appsM_C)),
    `MTD total = ${fmt(appsM_C)} (A ${fmt(M.A.applications)} + B ${fmt(M.B.applications)})`);
  html += rptRow(secC.id, 'rpt-peach', 'Product Terms Approved by Customer', fmt(termsY_C), pct(pctSafe(termsY_C, appsY_C)), fmt(termsM_C), pct(pctSafe(termsM_C, appsM_C)), 'Total who accepted terms &amp; entered underwriting');
  html += rptRow(secC.id, 'rpt-peach', 'Underwriting Approved', fmt(uwY_C), pct(pctSafe(uwY_C, appsY_C)), fmt(uwM_C), pct(pctSafe(uwM_C, appsM_C)), 'Approved by underwriting (total)');
  html += rptRow(secC.id, 'rpt-peach-strong', 'Deals Closed (Clients)', fmt(closedY_C), pct(pctSafe(closedY_C, appsY_C)), fmt(closedM_C), pct(pctSafe(closedM_C, appsM_C)),
    `Total loans disbursed &mdash; MTD ${fmt(closedM_C)} / Yest ${fmt(closedY_C)}`);
  html += rptRow(secC.id, 'rpt-peach-strong', 'Amount Sold (GEL)', fmt(totAmountY), pct(pctSafe(totAmountY, requiredDaily)), fmt(totAmountM), null, "Yest % = yesterday's sales vs required daily budget &middot; target attainment shown below");
  html += rptRow(secC.id, 'rpt-green', 'Downpayment Collected (GEL)', fmt(totDpY), '100.0%', fmt(totDpM), '100.0%', 'Total downpayment collected (A + B)');
  html += rptRow(secC.id, 'rpt-green', 'Downpayment Rate (% of amount)', null, pct(pctSafe(totDpY, totAmountY)), null, pct(pctSafe(totDpM, totAmountM)), 'Blended downpayment as % of amount sold');

  const secBudget = rptSpan('rpt-section', 'Budget &amp; Pacing (MTD Actual vs Monthly Target)');
  html += secBudget.row;
  html += `<tr class="rpt-colhead" data-sec="${secBudget.id}"><td>Target Metric</td><td colspan="2">Actual MTD</td><td>Monthly Target</td><td class="pct-cell">Attainment</td><td class="note-cell">Notes</td></tr>`;
  html += `<tr class="rpt-peach-strong" data-sec="${secBudget.id}"><td>Applications (MTD vs Target)</td><td colspan="2">${fmt(appsM_C)}</td><td>${fmt(targets.applications)}</td><td class="pct-cell">${pct(pctSafe(appsM_C, targets.applications))}</td><td class="note-cell rpt-note">Actual MTD applications / monthly target</td></tr>`;
  html += `<tr class="rpt-peach-strong" data-sec="${secBudget.id}"><td>Amount Sold (MTD vs Target, GEL)</td><td colspan="2">${fmt(totAmountM)}</td><td>${fmt(targets.amount)}</td><td class="pct-cell">${pct(pctSafe(totAmountM, targets.amount))}</td><td class="note-cell rpt-note">Actual MTD amount sold / monthly target</td></tr>`;
  html += `<tr class="rpt-peach" data-sec="${secBudget.id}"><td>Remaining to Sales Target (GEL)</td><td colspan="3">${fmt(remaining)}</td><td class="pct-cell dash">&ndash;</td><td class="note-cell rpt-note">Monthly target &minus; actual MTD sold</td></tr>`;
  html += `<tr class="rpt-peach" data-sec="${secBudget.id}"><td>Required Daily Sales (GEL)</td><td colspan="3">${fmt(requiredDaily)}</td><td class="pct-cell dash">&ndash;</td><td class="note-cell rpt-note">Over ${targets.workingDaysLeft} remaining working days</td></tr>`;
  html += `<tr class="rpt-peach" data-sec="${secBudget.id}"><td>Remaining Working Days</td><td colspan="3">${targets.workingDaysLeft}</td><td class="pct-cell dash">&ndash;</td><td class="note-cell rpt-note">Working days left in the month</td></tr>`;

  html += `<tr class="rpt-footer"><td colspan="6">Segment A = TV, Phone, or any single product &gt; 2,500 GEL. Segment B = everything else. Excludes unassigned "lead" placeholder rows. Applications, Terms Approved, and Underwriting Approved are keyed to Application Date. Deals Closed is keyed to Application Date and counts active/disbursed instalments. Amount Sold is keyed to Order Date (the disbursement/issue date, not the application date) for active/disbursed instalments only &mdash; so a loan applied for on one day but issued the next counts toward the day it was actually issued. Downpayment Collected = sum of the first payment on every application in the period, regardless of underwriting/active status &mdash; once collected it is not refunded, so it counts even if the deal was later rejected or has not gone active yet. Count % = share of the segment's own Applications; Amount/DP % in A &amp; B = share of the A+B total. Budget targets are a fixed business goal, not derived from the database (config.php). Source: myvolta8_voltadb, table instalments. Generated ${generatedAt}.</td></tr>`;

  document.getElementById('reportBody').innerHTML = html;

  document.querySelectorAll('#reportBody tr[data-toggle]').forEach(header => {
    header.addEventListener('click', () => {
      const id = header.getAttribute('data-toggle');
      const chevron = header.querySelector('.rpt-chevron');
      const rows = document.querySelectorAll(`#reportBody tr[data-sec="${id}"]`);
      const collapsing = rows.length && !rows[0].classList.contains('is-collapsed');
      rows.forEach(r => r.classList.toggle('is-collapsed', collapsing));
      if (chevron) chevron.style.transform = collapsing ? 'rotate(-90deg)' : 'rotate(0deg)';
    });
  });
}

renderReport();

/* ---------- MTD/Daily Statistics: same Section A/B/C report layout as the Report tab,
   pivoted so each column pair is one period (a month or a day) instead of Yesterday/MTD ---------- */

function rptPivotColHead(labels) {
  // Two stacked header rows rather than a rowspan cell — a rowspan on the metric column
  // would make the second row's real first <td> (a "Qty" cell) become the CSS `:first-child`
  // sticky column, misaligning it with the metric column below.
  const periodCells = labels.map((l, i) => `<td colspan="2" class="pc-${i}">${l}</td>`).join('');
  const subCells = labels.map((l, i) => `<td class="pc-${i}">Qty</td><td class="pct-cell pc-${i}">%</td>`).join('');
  return `<tr class="rpt-colhead"><td>Funnel Stage / Metric</td>${periodCells}</tr><tr class="rpt-colhead"><td></td>${subCells}</tr>`;
}
function rptPivotGroupRow(periods, groupKeyFn, groupLabelFn) {
  // Groups adjacent periods (e.g. all days in one month) under one clickable header cell,
  // so whole groups of columns can be hidden at once. Only meaningful when periods share a
  // coarser grouping (days -> months); the caller omits this row when it wouldn't help
  // (e.g. MTD Statistics, where each period already IS a month).
  const groups = [];
  periods.forEach((p, idx) => {
    const key = groupKeyFn(p.key);
    const last = groups[groups.length - 1];
    if (last && last.key === key) last.count++;
    else groups.push({ key, startIdx: idx, count: 1 });
  });
  let cells = '<td></td>';
  groups.forEach(g => {
    cells += `<td colspan="${g.count * 2}" class="rpt-group-toggle" data-group-start="${g.startIdx}" data-group-count="${g.count}"><span class="rpt-chevron">&#9662;</span>${groupLabelFn(g.key)}</td>`;
  });
  return `<tr class="rpt-colhead rpt-group-row">${cells}</tr>`;
}
function rptPivotRow(sec, cls, label, periods, valueFn) {
  const tds = periods.map((p, i) => {
    const [v, pv] = valueFn(p, i);
    return cellOrDash(v, `pc-${i}`) + cellOrDash(pv, `pct-cell pc-${i}`);
  }).join('');
  return `<tr class="${cls}" data-sec="${sec}"><td>${label}</td>${tds}</tr>`;
}
function rptPivotSpan(cls, text, colspan) {
  const id = 'sec' + (++secCounter);
  return { id, row: `<tr class="${cls}" data-toggle="${id}"><td colspan="${colspan}"><span class="rpt-chevron">&#9662;</span>${text}</td></tr>` };
}
function rptPivotPlainSpan(cls, text, colspan) {
  return `<tr class="${cls}"><td colspan="${colspan}">${text}</td></tr>`;
}

function pivotSegBlock(colspan, title, periods, segKey) {
  const { id, row } = rptPivotSpan('rpt-section', title, colspan);
  let html = row;
  html += rptPivotRow(id, 'rpt-plain', 'Applications', periods, p => [fmt(p[segKey].applications), pct(pctSafe(p[segKey].applications, p[segKey].applications))]);
  html += rptPivotRow(id, 'rpt-plain', 'Product Terms Approved by Customer', periods, p => [fmt(p[segKey].terms), pct(pctSafe(p[segKey].terms, p[segKey].applications))]);
  html += rptPivotRow(id, 'rpt-plain', 'Underwriting Approved', periods, p => [fmt(p[segKey].uw), pct(pctSafe(p[segKey].uw, p[segKey].applications))]);
  html += rptPivotRow(id, 'rpt-peach', 'Deals Closed (Clients)', periods, p => [fmt(p[segKey].closed), pct(pctSafe(p[segKey].closed, p[segKey].applications))]);
  html += rptPivotRow(id, 'rpt-peach', 'Amount Sold (GEL)', periods, p => [fmt(p[segKey].amount), pct(pctSafe(p[segKey].amount, p.A.amount + p.B.amount))]);
  html += rptPivotRow(id, 'rpt-green', 'Downpayment Collected (GEL)', periods, p => [fmt(p[segKey].dp), pct(pctSafe(p[segKey].dp, p.A.dp + p.B.dp))]);
  html += rptPivotRow(id, 'rpt-green', 'Downpayment Rate (% of amount)', periods, p => [null, pct(pctSafe(p[segKey].dp, p[segKey].amount))]);
  return html;
}

function pivotSectionC(colspan, periods) {
  const tot = (p, key) => p.A[key] + p.B[key];
  const { id, row } = rptPivotSpan('rpt-section-strong', 'C. TOTAL FUNNEL (A + B &mdash; auto-calculated)', colspan);
  let html = row;
  html += rptPivotRow(id, 'rpt-peach', 'Applications', periods, p => [fmt(tot(p, 'applications')), pct(pctSafe(tot(p, 'applications'), tot(p, 'applications')))]);
  html += rptPivotRow(id, 'rpt-peach', 'Product Terms Approved by Customer', periods, p => [fmt(tot(p, 'terms')), pct(pctSafe(tot(p, 'terms'), tot(p, 'applications')))]);
  html += rptPivotRow(id, 'rpt-peach', 'Underwriting Approved', periods, p => [fmt(tot(p, 'uw')), pct(pctSafe(tot(p, 'uw'), tot(p, 'applications')))]);
  html += rptPivotRow(id, 'rpt-peach-strong', 'Deals Closed (Clients)', periods, p => [fmt(tot(p, 'closed')), pct(pctSafe(tot(p, 'closed'), tot(p, 'applications')))]);
  html += rptPivotRow(id, 'rpt-peach-strong', 'Amount Sold (GEL)', periods, p => [fmt(tot(p, 'amount')), '100.0%']);
  html += rptPivotRow(id, 'rpt-green', 'Downpayment Collected (GEL)', periods, p => [fmt(tot(p, 'dp')), '100.0%']);
  html += rptPivotRow(id, 'rpt-green', 'Downpayment Rate (% of amount)', periods, p => [null, pct(pctSafe(tot(p, 'dp'), tot(p, 'amount')))]);
  return html;
}

// Keeps a table's own bottom scrollbar (native, inside .report-scroll) and a duplicate strip
// above the header (.report-scroll-top) moving together, so users don't have to scroll all
// the way down to the bottom of a tall table just to move it sideways.
function setupTopScrollSync(topId, bodyId) {
  const top = document.getElementById(topId);
  const body = document.getElementById(bodyId);
  const spacer = top.firstElementChild;
  function updateWidth() {
    const table = body.querySelector('table');
    spacer.style.width = table.scrollWidth + 'px';
  }
  let fromTop = false, fromBody = false;
  top.addEventListener('scroll', () => {
    if (fromBody) return;
    fromTop = true; body.scrollLeft = top.scrollLeft; fromTop = false;
  });
  body.addEventListener('scroll', () => {
    if (fromTop) return;
    fromBody = true; top.scrollLeft = body.scrollLeft; fromBody = false;
  });
  window.addEventListener('resize', updateWidth);
  updateWidth();
  return updateWidth;
}

function renderPivotReport(tableId, statsObj, labelFn, subtitle, footerText, groupBy, onRendered) {
  const table = document.getElementById(tableId);
  if (!statsObj || !Object.keys(statsObj).length) {
    table.querySelector('tbody').innerHTML = `<tr><td>No data.</td></tr>`;
    return;
  }
  const periods = Object.keys(statsObj).map(key => ({ key, label: labelFn(key), A: statsObj[key].A, B: statsObj[key].B }));
  const colspan = 1 + periods.length * 2;

  let html = '';
  html += rptPivotPlainSpan('rpt-title', 'Volta Daily Report &mdash; Sales &amp; Product-Terms Funnel', colspan);
  html += rptPivotPlainSpan('rpt-title', subtitle, colspan);
  if (groupBy) html += rptPivotGroupRow(periods, groupBy.keyFn, groupBy.labelFn);
  html += rptPivotColHead(periods.map(p => p.label));
  html += pivotSegBlock(colspan, 'A. Requires Downpayment (TV / Phone / &gt;2,500 GEL)', periods, 'A');
  html += pivotSegBlock(colspan, 'B. Standard Terms (no product-driven downpayment)', periods, 'B');
  html += pivotSectionC(colspan, periods);
  html += `<tr class="rpt-footer"><td colspan="${colspan}">${footerText}</td></tr>`;

  table.querySelector('tbody').innerHTML = html;

  table.querySelectorAll('tr[data-toggle]').forEach(header => {
    header.addEventListener('click', () => {
      const id = header.getAttribute('data-toggle');
      const chevron = header.querySelector('.rpt-chevron');
      const rows = table.querySelectorAll(`tr[data-sec="${id}"]`);
      const collapsing = rows.length && !rows[0].classList.contains('is-collapsed');
      rows.forEach(r => r.classList.toggle('is-collapsed', collapsing));
      if (chevron) chevron.style.transform = collapsing ? 'rotate(-90deg)' : 'rotate(0deg)';
    });
  });

  table.querySelectorAll('td.rpt-group-toggle').forEach(cell => {
    cell.addEventListener('click', () => {
      const start = parseInt(cell.dataset.groupStart, 10);
      const count = parseInt(cell.dataset.groupCount, 10);
      const chevron = cell.querySelector('.rpt-chevron');
      const firstCell = table.querySelector(`.pc-${start}`);
      const collapsing = firstCell && !firstCell.classList.contains('col-collapsed');
      for (let i = start; i < start + count; i++) {
        table.querySelectorAll(`.pc-${i}`).forEach(el => el.classList.toggle('col-collapsed', collapsing));
      }
      if (chevron) chevron.style.transform = collapsing ? 'rotate(-90deg)' : 'rotate(0deg)';
      if (onRendered) onRendered();
    });
  });

  if (onRendered) onRendered();
}

const MONTH_NAMES = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
function monthLabel(ym) {
  const [y, m] = ym.split('-').map(Number);
  const now = new Date();
  const isCurrent = y === now.getFullYear() && m === (now.getMonth() + 1);
  return `${MONTH_NAMES[m - 1]} ${y}` + (isCurrent ? ' (MTD)' : '');
}
function dayLabel(ymd) {
  const [, m, d] = ymd.split('-').map(Number);
  return `${MONTH_NAMES[m - 1]} ${d}`;
}

const PIVOT_FOOTER = 'Segment A = TV, Phone, or any single product &gt; 2,500 GEL. Segment B = everything else. Excludes unassigned "lead" placeholder rows. Applications, Terms Approved, and Underwriting Approved are keyed to Application Date. Deals Closed is keyed to Application Date and counts active/disbursed instalments. Amount Sold is keyed to Order Date (the disbursement/issue date) and equals Full Cost (Initial Amount + First Payment), the full sale price &mdash; not just the financed principal. Downpayment Collected = sum of the first payment on every application in the period, regardless of underwriting/active status; it is already included inside Amount Sold, not additive to it. Source: myvolta8_voltadb, table instalments. Generated ' + generatedAt + '.';

const mtdScrollUpdate = setupTopScrollSync('mtdStatsScrollTop', 'mtdStatsScrollBody');
renderPivotReport(
  'mtdStatsTable', monthlyStats, monthLabel,
  'One column pair per calendar month',
  PIVOT_FOOTER + ' A completed month shows its final total; the current month shows MTD-to-date.',
  null,
  mtdScrollUpdate
);

const dailyScrollUpdate = setupTopScrollSync('dailyStatsScrollTop', 'dailyStatsScrollBody');
renderPivotReport(
  'dailyStatsTable', dailyStats, dayLabel,
  'One column pair per calendar day, from June 1. Click a month header below to collapse its days.',
  PIVOT_FOOTER + ' Today is excluded (not yet finished). Recent days are undercounted and will keep rising on refresh, because Active/Order Date can be set several days after Application Date.',
  { keyFn: ymd => ymd.slice(0, 7), labelFn: ym => monthLabel(ym).replace(' (MTD)', '') },
  dailyScrollUpdate
);

document.querySelectorAll('#pageNav button').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('#pageNav button').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const page = btn.getAttribute('data-page');
    document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
    document.getElementById('page-' + page).classList.add('active');
    // A hidden (display:none) table reports scrollWidth 0, so the top-scroll spacer only
    // gets its real width once the tab holding it is actually visible.
    if (page === 'mtdstats') mtdScrollUpdate();
    if (page === 'dailystats') dailyScrollUpdate();
  });
});
</script>
</body>
</html>
