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
 * @var array|null $salesMonthlyStats
 * @var array|null $brandStats
 * @var array|null $subcategoryStats
 * @var array|null $categoryBrandBreakdown
 * @var array|null $incomeDelinquencyByCategory
 * @var array|null $incomeDelinquencyBySubcategory
 * @var array|null $incomeDelinquencyByBrand
 * @var array|null $incomeDelinquencyByProduct
 * @var array<string, array{count: int, capturedAt: string}> $pendingStatusLog
 * @var array|null $customerAnalysis
 * @var array|null $customerAgeGenderAnalysis
 * @var array|null $customerWorkshopAnalysis
 * @var array|null $customerWorkposAnalysis
 * @var array|null $customerIncomeAnalysis
 * @var array|null $customerDistrictAnalysis
 * @var array|null $riskSegmentation
 * @var array|null $closedLoansMonthly
 * @var array|null $delinquencyAnalysis
 * @var array|null $rejectionReasonsMonthly
 * @var array|null $clientRefusedReasonsMonthly
 * @var array|null $expiredReasonsMonthly
 * @var array|null $notRespondingReasonsMonthly
 * @var array|null $approvedReasonsMonthly
 * @var array|null $applicationStatusesMonthly
 * @var array|null $leadStatusesMonthly
 * @var array|null $exCustomers
 * @var array|null $neverBorrowedByStatus
 * @var array|null $neverBorrowedDetail
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

/* plain table card — used by the Logistics Daily tab */
.table-card { background: var(--surface-1); border: 1px solid var(--border); border-radius: 12px; padding: 18px 20px; overflow-x: auto; }
.table-card table { width: 100%; border-collapse: collapse; font-size: 12.5px; min-width: 320px; }
.table-card th, .table-card td { text-align: right; padding: 7px 10px; border-bottom: 1px solid var(--grid); font-variant-numeric: tabular-nums; white-space: nowrap; }
.table-card th:first-child, .table-card td:first-child { text-align: left; font-variant-numeric: normal; }
.table-card th { color: var(--text-secondary); font-weight: 650; font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.03em; }
.table-card tbody tr:hover { background: color-mix(in srgb, var(--text-primary) 4%, transparent); }
.table-card tr.total td { font-weight: 650; border-top: 2px solid var(--baseline); border-bottom: none; }

/* page nav */
.page-nav { display: inline-flex; background: var(--surface-1); border: 1px solid var(--border); border-radius: 8px; padding: 3px; gap: 2px; }
.page-nav button {
  border: none; background: transparent; color: var(--text-secondary); font: inherit; font-size: 13px; font-weight: 600;
  padding: 7px 16px; border-radius: 6px; cursor: pointer;
}
.page-nav button.active { background: var(--text-primary); color: var(--surface-1); }
.page { display: none; flex-direction: column; gap: 22px; }
.page.active { display: flex; }

/* top-level nav groups: 3 group cards side by side, each with its own title and a vertically
   stacked list of its sub-tabs always visible (not a dropdown/accordion) — so which sub-tabs
   live inside a group is visible without ever clicking into it. Sized to fit one screen without
   scrolling even for the 8-item Sales Analyze group. */
.nav-groups { display: flex; flex-wrap: wrap; align-items: flex-start; gap: 10px; }
.nav-group { background: var(--surface-1); border: 2px solid var(--border); border-radius: 10px; padding: 7px; min-width: 180px; }
.nav-group.active-group { border-color: var(--text-primary); }
.nav-group-title { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); padding: 3px 7px 6px; }
.nav-group.active-group .nav-group-title { color: var(--text-primary); }
.nav-group-items { display: flex; flex-direction: column; gap: 1px; }
.nav-group-items button, .nav-group-items a {
  border: none; background: transparent; color: var(--text-secondary); font: inherit; font-size: 12.5px; font-weight: 600;
  padding: 6px 9px; border-radius: 6px; cursor: pointer; text-align: left; white-space: nowrap;
  text-decoration: none; display: block;
}
.nav-group-items button:hover, .nav-group-items a:hover { background: color-mix(in srgb, var(--text-primary) 6%, transparent); }
.nav-group-items button.active { background: var(--text-primary); color: var(--surface-1); }

/* Ex Customers: the one individual-row (PII) table in this project — a plain wide table instead
   of .table-card's label/value/share layout (most columns here are text, not numeric), plus a
   client-side search box since it lists 3,000+ people. */
.excust-search { margin: -8px 0 4px; display: flex; align-items: center; gap: 8px; }
.excust-search input {
  font: inherit; font-size: 13px; padding: 7px 12px; border: 1px solid var(--border); border-radius: 6px;
  width: 320px; background: var(--surface-1); color: var(--text-primary);
}
.excust-search-count { font-size: 12px; color: var(--text-muted); }
table.excust-table { width: 100%; border-collapse: collapse; font-size: 11.5px; }
table.excust-table th, table.excust-table td { padding: 5px 8px; border-bottom: 1px solid var(--grid); white-space: nowrap; text-align: left; }
table.excust-table th { color: var(--text-secondary); font-weight: 650; font-size: 10px; text-transform: uppercase; letter-spacing: 0.02em; background: var(--surface-1); position: sticky; top: 0; }
table.excust-table td.num { text-align: right; font-variant-numeric: tabular-nums; }
table.excust-table td.products-cell { white-space: normal; max-width: 260px; }
table.excust-table tbody tr:hover { background: color-mix(in srgb, var(--text-primary) 4%, transparent); }
table.excust-table td.grade-cell { font-weight: 700; text-align: center; }
table.excust-table tr[data-grade="A"] td.grade-cell { color: var(--good); }
table.excust-table tr[data-grade="B"] td.grade-cell, table.excust-table tr[data-grade="C"] td.grade-cell { color: var(--warning); }
table.excust-table tr[data-grade="D"] td.grade-cell, table.excust-table tr[data-grade="E"] td.grade-cell { color: var(--critical); }

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

/* Sales Monthly — product-category x month breakdown, theme-aware (reuses table.rpt-pivot's
   sticky-first-column/tabular-numbers base). One 4-column group (Sales/Cogs/Margin/Qty) per
   month plus a Total group; rows pre-sorted by total Sales descending on the PHP side. */
table.sm-table col.sm-name { width: 190px; }
table.sm-table tr.sm-grandtotal td { background: var(--rpt-section-strong-bg); color: var(--rpt-ink); font-weight: 700; }
table.sm-table tr.sm-row:nth-child(even) td { background: var(--rpt-plain-bg); color: var(--rpt-ink); }
table.sm-table tr.sm-uncategorized td { font-style: italic; color: var(--rpt-muted); background: var(--rpt-plain-bg); }
table.sm-table td.sm-total-col { border-left: 2px solid var(--rpt-border); font-weight: 600; }
table.sm-table tr.rpt-colhead td.sm-total-col { border-left: 2px solid var(--rpt-border); font-weight: 700; }

/* Logistics Daily — exact replica of the business's own "LOGISTICS — Delivery Status" sheet,
   fixed spreadsheet palette regardless of app theme (same convention as .rpt / the Report tab). */
table.logi-table { border-collapse: collapse; font-size: 11.5px; table-layout: auto; }
table.logi-table td { padding: 5px 8px; border: 1px solid #c9c9c9; text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; color: #111; }
table.logi-table td:first-child { text-align: left; font-variant-numeric: normal; position: sticky; left: 0; z-index: 2; box-shadow: 1px 0 0 #c9c9c9; }
table.logi-table tr.logi-title td { background: #2e5496; color: #fff; font-weight: 700; text-align: left; }
table.logi-table tr.logi-title td:first-child { z-index: 3; }
table.logi-table tr.logi-head td { background: #4472c4; color: #fff; font-weight: 700; }
table.logi-table tr.logi-total td { background: #d9e1f2; font-weight: 700; }
table.logi-table tr.logi-light td { background: #eaf0fa; }
table.logi-table tr.logi-white td { background: #fff; }
table.logi-table tr.logi-plain td { background: #fff; }
table.logi-table tr.logi-today td:first-child { font-style: italic; }

/* Orders by City / Orders by Goods Type — small breakdown tables, same fixed-palette
   convention, colored per column to match the source sheet's own styling. */
.logi-mini-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media (max-width: 760px) { .logi-mini-row { grid-template-columns: 1fr; } }
table.logi-mini { border-collapse: collapse; font-size: 12px; width: 100%; }
table.logi-mini td { padding: 7px 10px; border: none; border-bottom: 1px solid #e5e7eb; text-align: right; font-variant-numeric: tabular-nums; }
table.logi-mini td:first-child { text-align: left; font-variant-numeric: normal; }
table.logi-mini col.logi-mini-name { width: 46%; }
table.logi-mini tr.logi-mini-title td { background: #0d6e7c; color: #fff; font-weight: 700; text-align: left; }
table.logi-mini tr.logi-mini-head td { background: #1b2a4a; color: #fff; font-weight: 700; }
table.logi-mini tr.logi-mini-data td { color: #555; background: #fff; }
table.logi-mini tr.logi-mini-data td:first-child { color: #333; }
table.logi-mini tr.logi-mini-data.logi-mini-alt td { background: #f4f6f9; }
table.logi-mini tr.logi-mini-total td { color: #0d6e7c; font-weight: 700; background: #fff; border-top: 2px solid #0d6e7c; border-bottom: none; }
table.logi-mini tr.logi-mini-total td:first-child { color: #333; }

/* Open Cases — Still Waiting for Delivery: top 10 oldest not-yet-delivered orders.
   Title sits directly on the page (not inside a filled table band like the other titles
   on this tab), so it uses the app's own theme-aware text color instead of a fixed navy —
   a fixed dark color here goes low-contrast the moment the app is in dark mode. */
.logi-open-title { color: var(--text-primary); font-weight: 700; font-size: 13px; margin: 4px 0 -6px; }
table.logi-open { border-collapse: collapse; font-size: 12px; width: 100%; }
table.logi-open td { padding: 7px 10px; border-bottom: 1px solid #e5e7eb; text-align: left; color: #1f1f1f; background: #fff; }
table.logi-open tr.logi-open-head td { background: #4472c4; color: #fff; font-weight: 700; }
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
    <div class="period" id="periodBadge">Yesterday: <b><?= htmlspecialchars($headerYesterday, ENT_QUOTES, 'UTF-8') ?></b><br>MTD: <b><?= htmlspecialchars($headerMtdRange, ENT_QUOTES, 'UTF-8') ?></b></div>
  </div>

  <div class="nav-groups" id="pageNav">
    <div class="nav-group" data-group="dailymail">
      <div class="nav-group-title">Daily Mail</div>
      <div class="nav-group-items">
        <button data-page="report" class="active">Daily Report</button>
        <button data-page="mtdstats">MTD Statistics</button>
        <button data-page="dailystats">Daily Statistics</button>
      </div>
    </div>
    <div class="nav-group" data-group="salesanalyze">
      <div class="nav-group-title">Sales Analyze</div>
      <div class="nav-group-items">
        <button data-page="salesmonthly">Sales Monthly</button>
        <button data-page="brandanalyze">Brand Analyze</button>
        <button data-page="subcategoryanalyze">Subcategory Analyze</button>
        <button data-page="categorybrand">Category/Brand</button>
        <button data-page="incomecategory">Income/Delinq: Category</button>
        <button data-page="incomesubcategory">Income/Delinq: Subcategory</button>
        <button data-page="incomebrand">Income/Delinq: Brand</button>
        <button data-page="incomeproduct">Income/Delinq: Product</button>
      </div>
    </div>
    <div class="nav-group" data-group="logistics">
      <div class="nav-group-title">Logistics</div>
      <div class="nav-group-items">
        <button data-page="logistics">Logistics Daily</button>
        <a href="https://volta-ge.github.io/reporting/waybills.html" target="_blank" rel="noopener">CRM Sales &harr; RS Waybills &#8599;</a>
      </div>
    </div>
    <div class="nav-group" data-group="customers">
      <div class="nav-group-title">Customers</div>
      <div class="nav-group-items">
        <button data-page="customers">Customers Analyze</button>
        <button data-page="excustomers">Ex Customers</button>
      </div>
    </div>
    <div class="nav-group" data-group="marketing">
      <div class="nav-group-title">Marketing</div>
      <div class="nav-group-items">
        <button data-page="leads">Leads</button>
      </div>
    </div>
    <div class="nav-group" data-group="application">
      <div class="nav-group-title">Application</div>
      <div class="nav-group-items">
        <button data-page="applicationstatuses">Application Statuses</button>
      </div>
    </div>
    <div class="nav-group" data-group="committee">
      <div class="nav-group-title">Committee</div>
      <div class="nav-group-items">
        <button data-page="rejectionreasons">Committee Statuses</button>
      </div>
    </div>
    <div class="nav-group" data-group="other">
      <div class="nav-group-title">Other</div>
      <div class="nav-group-items">
        <button data-page="risksegmentation">Risk Segmentation</button>
        <button data-page="closedloans">Closed Loans</button>
        <button data-page="delinquency">Overdue Analysis</button>
      </div>
    </div>
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

  <div class="page" id="page-salesmonthly">
    <p class="section-title">Sales Monthly &mdash; by product category</p>
    <p class="note" style="margin:-8px 0 0;">One column-group (Sales / Cogs / Margin / Qty) per calendar month of <?= htmlspecialchars($now->format('Y'), ENT_QUOTES, 'UTF-8') ?>, plus Q1 and Q2 quarterly summary groups (each with that row's share of the quarter's total Sales) and an overall Total group (share of the whole window). Live from the database on every page load (not a manual snapshot) &mdash; installment sales use Order_Status = 5 as the "real sale" filter (matches the business's own report exactly for every category+month checked); single-payment sales use <code>Type_Of_Sales = 99</code> instead, keyed to <code>Aplication_Date</code> since they have no <code>Order_Date</code>. Rows are sorted by total Sales, highest first. Sales = SUM(Final_Price), Cogs = SUM(Start_Price), both raw and unmodified &mdash; same convention the business's own report uses.</p>
    <div class="page-nav" id="salesMonthlyDealTypeNav" style="margin-bottom:-8px;">
      <button data-deal="all" class="active">ყველა</button>
      <button data-deal="installment">განვადება</button>
      <button data-deal="single">ერთიანი გადახდა</button>
    </div>
    <div class="report-card">
      <div class="report-scroll-top" id="salesMonthlyScrollTop"><div></div></div>
      <div class="report-scroll" id="salesMonthlyScrollBody">
        <table class="rpt rpt-pivot sm-table" id="salesMonthlyTable"><tbody></tbody></table>
      </div>
    </div>
    <p class="note" id="salesMonthlyFooterNote"></p>
  </div>

  <div class="page" id="page-brandanalyze">
    <p class="section-title">Brand Analyze &mdash; by brand</p>
    <p class="note" style="margin:-8px 0 0;">Same layout as Sales Monthly, grouped by <code>product_brands.Brand_Name</code> instead of product category &mdash; the Brand_ID link is fully populated in the database (unlike category), so this needed no mapping-sheet translation. Three different "no real brand" spellings found in the data (<code>none</code>, <code>N/A</code>, <code>ბრენდის გარეშე</code>) are combined into one "No Brand" row at the bottom.</p>
    <div class="page-nav" id="brandDealTypeNav" style="margin-bottom:-8px;">
      <button data-deal="all" class="active">ყველა</button>
      <button data-deal="installment">განვადება</button>
      <button data-deal="single">ერთიანი გადახდა</button>
    </div>
    <div class="report-card">
      <div class="report-scroll-top" id="brandScrollTop"><div></div></div>
      <div class="report-scroll" id="brandScrollBody">
        <table class="rpt rpt-pivot sm-table" id="brandTable"><tbody></tbody></table>
      </div>
    </div>
    <p class="note" id="brandFooterNote"></p>
  </div>

  <div class="page" id="page-subcategoryanalyze">
    <p class="section-title">Subcategory Analyze &mdash; by broader product group</p>
    <p class="note" style="margin:-8px 0 0;">Same layout as Sales Monthly, but one level broader &mdash; e.g. Air Fryer / Blender / Toaster (separate rows on Sales Monthly) all roll up into one "Small Kitchen Appliances" row here. Same mapping-sheet lookup as Sales Monthly, just reading its Subcategory(EN) field instead of Product(EN).</p>
    <div class="page-nav" id="subcategoryDealTypeNav" style="margin-bottom:-8px;">
      <button data-deal="all" class="active">ყველა</button>
      <button data-deal="installment">განვადება</button>
      <button data-deal="single">ერთიანი გადახდა</button>
    </div>
    <div class="report-card">
      <div class="report-scroll-top" id="subcategoryScrollTop"><div></div></div>
      <div class="report-scroll" id="subcategoryScrollBody">
        <table class="rpt rpt-pivot sm-table" id="subcategoryTable"><tbody></tbody></table>
      </div>
    </div>
    <p class="note" id="subcategoryFooterNote"></p>
  </div>

  <div class="page" id="page-categorybrand">
    <p class="section-title">Category / Brand &mdash; brand breakdown within each category</p>
    <p class="note" style="margin:-8px 0 0;">One block per product category, brands broken down within it &mdash; same layout as the business's own reference report's "Top 4 &mdash; Brands" sheet, built here for every category found in the window instead of a hand-picked top 4. Q1/Q2 Sales are date-bounded quarterly columns; Total Sales/COGS/Margin/Qty are for the whole window (Jan 1&ndash;yesterday), matching the reference sheet's own formulas. Categories and brands within them are sorted by Total Sales, highest first (Uncategorized/No Brand always last). Live from the database on every page load.</p>
    <div class="page-nav" id="categoryBrandDealTypeNav" style="margin-bottom:-8px;">
      <button data-deal="all" class="active">ყველა</button>
      <button data-deal="installment">განვადება</button>
      <button data-deal="single">ერთიანი გადახდა</button>
    </div>
    <div class="report-card">
      <div class="report-scroll">
        <table class="rpt rpt-pivot sm-table" id="categoryBrandTable"><tbody></tbody></table>
      </div>
    </div>
  </div>

  <div class="page" id="page-incomecategory">
    <p class="section-title">Income &amp; Delinquency &mdash; by Product Category</p>
    <p class="note" style="margin:-8px 0 0;">Closed loans only (<code>Close_Type IN (1, 2)</code>, keyed to <code>Close_Date</code>), same Jan 1&ndash;yesterday window as Sales Monthly. <strong>Income</strong> = realized (actually collected) margin, not invoiced/sale-time margin &mdash; a loan Paid Off (<code>Close_Type=1</code>) collected its full sale amount, one Written Off (<code>Close_Type=2</code>) only collected <code>Full_Cost &minus; Debt</code>; that per-loan collection ratio is applied proportionally across the loan's product lines, since Cogs lives per line item but collection status lives per loan (a documented modeling simplification, not exact accounting &mdash; see <code>IncomeDelinquencyRepository</code>). <strong>Delinquency</strong> = write-off rate: the share of closed loans, by quantity and by GEL, that ended up Written Off rather than Paid Off, broken down the same way (written-off GEL uses the same proportional allocation of the loan's remaining Debt). Rows sorted by Total Revenue, highest first (Uncategorized always last).</p>
    <div class="report-card">
      <div class="report-scroll-top" id="incomeCategoryScrollTop"><div></div></div>
      <div class="report-scroll" id="incomeCategoryScrollBody">
        <table class="rpt rpt-pivot sm-table" id="incomeCategoryTable"><tbody></tbody></table>
      </div>
    </div>
    <div class="report-card" style="margin-top:16px;">
      <div class="report-scroll-top" id="delinquencyCategoryScrollTop"><div></div></div>
      <div class="report-scroll" id="delinquencyCategoryScrollBody">
        <table class="rpt rpt-pivot sm-table" id="delinquencyCategoryTable"><tbody></tbody></table>
      </div>
    </div>
  </div>

  <div class="page" id="page-incomesubcategory">
    <p class="section-title">Income &amp; Delinquency &mdash; by Subcategory</p>
    <p class="note" style="margin:-8px 0 0;">Same methodology and window as Income/Delinquency: Category (see its note for the full Income/Delinquency definitions), grouped one level finer &mdash; e.g. "Small Kitchen Appliances" (one row on the Category tab) splits into "Air Fryer" / "Blender" / "Toaster" here.</p>
    <div class="report-card">
      <div class="report-scroll-top" id="incomeSubcategoryScrollTop"><div></div></div>
      <div class="report-scroll" id="incomeSubcategoryScrollBody">
        <table class="rpt rpt-pivot sm-table" id="incomeSubcategoryTable"><tbody></tbody></table>
      </div>
    </div>
    <div class="report-card" style="margin-top:16px;">
      <div class="report-scroll-top" id="delinquencySubcategoryScrollTop"><div></div></div>
      <div class="report-scroll" id="delinquencySubcategoryScrollBody">
        <table class="rpt rpt-pivot sm-table" id="delinquencySubcategoryTable"><tbody></tbody></table>
      </div>
    </div>
  </div>

  <div class="page" id="page-incomebrand">
    <p class="section-title">Income &amp; Delinquency &mdash; by Brand</p>
    <p class="note" style="margin:-8px 0 0;">Same methodology and window as Income/Delinquency: Category (see its note for the full Income/Delinquency definitions), grouped by <code>product_brands.Brand_Name</code> instead of product category. The three "no real brand" spellings (<code>none</code>, <code>N/A</code>, <code>ბრენდის გარეშე</code>) are combined into one "No Brand" row at the bottom.</p>
    <div class="report-card">
      <div class="report-scroll-top" id="incomeBrandScrollTop"><div></div></div>
      <div class="report-scroll" id="incomeBrandScrollBody">
        <table class="rpt rpt-pivot sm-table" id="incomeBrandTable"><tbody></tbody></table>
      </div>
    </div>
    <div class="report-card" style="margin-top:16px;">
      <div class="report-scroll-top" id="delinquencyBrandScrollTop"><div></div></div>
      <div class="report-scroll" id="delinquencyBrandScrollBody">
        <table class="rpt rpt-pivot sm-table" id="delinquencyBrandTable"><tbody></tbody></table>
      </div>
    </div>
  </div>

  <div class="page" id="page-incomeproduct">
    <p class="section-title">Income &amp; Delinquency &mdash; by Product</p>
    <p class="note" style="margin:-8px 0 0;">Same methodology and window as Income/Delinquency: Category (see its note for the full Income/Delinquency definitions), grouped at the finest level &mdash; the same Product(EN) buckets as the Sales Monthly tab (e.g. "Air Fryer", "Smartphones").</p>
    <div class="report-card">
      <div class="report-scroll-top" id="incomeProductScrollTop"><div></div></div>
      <div class="report-scroll" id="incomeProductScrollBody">
        <table class="rpt rpt-pivot sm-table" id="incomeProductTable"><tbody></tbody></table>
      </div>
    </div>
    <div class="report-card" style="margin-top:16px;">
      <div class="report-scroll-top" id="delinquencyProductScrollTop"><div></div></div>
      <div class="report-scroll" id="delinquencyProductScrollBody">
        <table class="rpt rpt-pivot sm-table" id="delinquencyProductTable"><tbody></tbody></table>
      </div>
    </div>
  </div>

  <div class="page" id="page-logistics">
    <p class="section-title">Logistics Daily &mdash; PO &amp; Order Fulfillment</p>

    <p class="note" style="margin:-8px 0 0;">Source: Google Sheet "Volta Order Managment". History through 20 Aug is carried over unchanged from the business's own tracking sheet; the 21 Aug column is freshly computed from the same raw data.</p>
    <div class="report-card">
      <div class="report-scroll-top" id="logisticsSalesScrollTop"><div></div></div>
      <div class="report-scroll" id="logisticsSalesScrollBody">
        <table class="logi-table" id="logisticsSalesTable"><tbody></tbody></table>
      </div>
    </div>
    <p class="note">Sales &ndash; Pending Status = customers not yet contacted, by how long the case has been open (measured from the same Status/date basis as the sheet itself). Only "Up to 1 day" has ever had data in the source; "1 to 5 days" / "&gt;5 days" are blank for every date shown, transferred unchanged. History through 21 Aug is from the Google Sheet as before; from 22 Aug onward, "Up to 1 day" switches source to a live daily count of <code>instalments.Order_Status = 4</code> ("Pending" loan applications, a different underlying process that happens to have landed on similar numbers) captured once a day by a server cron job &mdash; not backfillable for any date that wasn't actually captured that day, same as everywhere else on this tab.</p>

    <p class="note" style="margin:-8px 0 0;">Source: Google Sheet "Volta Order Managment" (&#x10E8;&#x10D4;&#x10D9;&#x10D5;&#x10D4;&#x10D7;&#x10D4;&#x10D1;&#x10D8; / "orders" sheet). History through 20 Aug is carried over unchanged from the business's own tracking sheet; the 21 Aug column is freshly computed from the same raw order data. "Not Delivered" / age-bucket / "On Hold" figures are a snapshot valid only for the date in that column; "Delivered" and "Average Delivery Time" are reconstructed from each order's actual Delivery/Pickup Date, so those two are reliable history, not snapshots.</p>
    <div class="report-card">
      <div class="report-scroll-top" id="logisticsScrollTop"><div></div></div>
      <div class="report-scroll" id="logisticsScrollBody">
        <table class="logi-table" id="logisticsTable"><tbody></tbody></table>
      </div>
    </div>
    <p class="note">Not Delivered = orders not yet in "Delivered" or "Cancelled" status. Age buckets (&le;1 day / 1&ndash;5 days / &gt;5 days) are measured from each order's Sale Date, for not-yet-delivered, not-on-hold orders. Average Delivery Time = average(Delivery Date &minus; Pickup Date) in days, only over orders where both dates are recorded &mdash; Pickup Date is sparsely filled in the source sheet, so several columns show a dash. This tab is a manual snapshot, not live; automatic daily refresh (planned for 20:00 every day) is not yet set up &mdash; it needs a Google Sheets API service account and a scheduled job on the server.</p>

    <p class="logi-open-title">Open Cases &mdash; Still Waiting for Delivery</p>
    <div class="table-card">
      <table class="logi-open" id="logisticsOpenCasesTable"><tbody></tbody></table>
    </div>
    <p class="note">The 10 oldest orders (by Sale Date) not yet in "Delivered" or "Cancelled" status &mdash; the customers who have been waiting longest.</p>

    <div class="logi-mini-row">
      <div>
        <p class="section-title">Orders by City</p>
        <table class="logi-mini" id="logisticsCityTable"><tbody></tbody></table>
      </div>
      <div>
        <p class="section-title">Orders by Goods Type</p>
        <table class="logi-mini" id="logisticsGoodsTable"><tbody></tbody></table>
      </div>
    </div>
    <p class="note">Snapshot, pulled the same manual way as the rest of this tab &mdash; not live.</p>
  </div>
</div>

<div class="page" id="page-customers">
  <p class="section-title">Customers &mdash; portfolio customer analysis</p>
  <p class="note" style="margin:-8px 0 0;">Aggregate counts only, scoped to customers with at least one real application (Product_ID &gt; 1) &mdash; no individual customer data shown. "New" = exactly one loan ever; "Repeat" = two or more. Live from the database on every page load.</p>
  <div class="table-card">
    <table id="customersSummaryTable"><tbody></tbody></table>
  </div>
  <p class="section-title" style="margin-top:20px;">Loans per Customer</p>
  <div class="table-card">
    <table id="customersLoansDistTable"><tbody></tbody></table>
  </div>
  <div class="logi-mini-row" style="margin-top:20px;">
    <div>
      <p class="section-title">By City</p>
      <div class="table-card">
        <table id="customersByCityTable"><tbody></tbody></table>
      </div>
    </div>
    <div>
      <p class="section-title">By Gender</p>
      <div class="table-card">
        <table id="customersByGenderTable"><tbody></tbody></table>
      </div>
    </div>
  </div>

  <p class="section-title" style="margin-top:20px;">Age &times; Gender</p>
  <p class="note" style="margin:-8px 0 0;">From <code>customers.BirthDay</code>. Share % is computed within each gender column (that age bucket's count &divide; that gender's own total) &mdash; shows how each gender's ages are distributed, not each bucket's share of everyone. "Unspecified" = missing/blank BirthDay.</p>
  <div class="table-card">
    <table id="customersAgeGenderTable"><tbody></tbody></table>
  </div>

  <div class="logi-mini-row" style="margin-top:20px;">
    <div>
      <p class="section-title">Workshop (Employer / Field)</p>
      <div class="table-card">
        <table id="customersWorkshopTable"><tbody></tbody></table>
      </div>
      <p class="note" id="customersWorkshopNote"></p>
    </div>
    <div>
      <p class="section-title">Workpos (Job Title)</p>
      <div class="table-card">
        <table id="customersWorkposTable"><tbody></tbody></table>
      </div>
      <p class="note" id="customersWorkposNote"></p>
    </div>
  </div>

  <div class="logi-mini-row" style="margin-top:20px;">
    <div>
      <p class="section-title">Reported Income (from Comment)</p>
      <div class="table-card">
        <table id="customersIncomeTable"><tbody></tbody></table>
      </div>
      <p class="note">From <code>customers.Comment</code> &mdash; not a free-text note field despite the name, this is where staff record reported income (e.g. "1500", "1200 ლარი თიბისი ბანკში", "დღეში 100 ლ"). Bucketed by the first number found in the text; daily-wage phrasing ("დღეში" = "per day") is not converted to a monthly figure, so a handful of daily amounts sit in the same buckets as monthly ones.</p>
    </div>
    <div>
      <p class="section-title">Tbilisi District (decoded from address)</p>
      <div class="table-card">
        <table id="customersDistrictTable"><tbody></tbody></table>
      </div>
      <p class="note" id="customersDistrictNote">From <code>customers.FactAddress</code>, scoped to addresses mentioning "თბილისი". Real addresses don't follow one consistent format, so this is a best-effort substring match against a list of known Tbilisi districts/microrayons; the rest are "ვერ დადგინდა" (could not be determined) rather than guessed at.</p>
    </div>
  </div>
</div>

<div class="page" id="page-excustomers">
  <p class="section-title">Ex Customers &mdash; payment-quality grade &amp; contact list</p>
  <p class="note" style="margin:-8px 0 0;">An "ex customer" = a <code>Customer_ID</code> with at least one genuinely closed loan (<code>Close_Type IN (1, 2)</code>) and no currently active one &mdash; 3,210 as of this build. <strong>Grade (A&ndash;E)</strong> = how much of what they bought actually got collected, weighted across all their closed loans (<code>SUM(Full_Cost &minus; Debt) / SUM(Full_Cost)</code>): <b>A</b> &ge;98% collected (paid in full) &middot; <b>B</b> 80&ndash;98% &middot; <b>C</b> 50&ndash;80% &middot; <b>D</b> 1&ndash;50% &middot; <b>E</b> &lt;1% (collected essentially nothing). Bands were chosen by looking at the real distribution before picking round numbers &mdash; not arbitrary. Contains real customer PII (name, national ID, phone, email) &mdash; this is the one individual-row report in this project, built for collections/win-back outreach specifically because it was asked for; every other tab stays aggregate-only on purpose. Live from the database on every page load.</p>
  <p class="note" style="margin:4px 0 0;"><strong>Reconciling against Customer Analysis</strong>: Total Customers &minus; Active Customers = ~29,768 "not currently a customer," not 3,210. The gap (~26,558) never actually received a loan in the first place &mdash; every application they ever submitted was rejected, refused, or expired before disbursement, so there's no payment history to grade. Shown below as an aggregate-only breakdown by their most recent application status (no PII), not folded into the graded list.</p>
  <div class="table-card">
    <table id="exCustomersSummaryTable"><tbody></tbody></table>
  </div>
  <p class="section-title" style="margin-top:20px;">Never Became a Customer &mdash; by last application status (no PII)</p>
  <p class="note" style="margin:-8px 0 0;">The other ~26,500 inactive people, bucketed by whichever <code>Order_Status</code> their most recent application ended on. Reconciles: 3,210 (graded) + this table's total = ~29,768 = Customer Analysis's Total &minus; Active.</p>
  <div class="table-card">
    <table id="neverBorrowedTable"><tbody></tbody></table>
  </div>
  <p class="section-title" style="margin-top:20px;">Full List &mdash; sorted worst payer first</p>
  <div class="excust-search">
    <input type="text" id="exCustomersSearch" placeholder="Search name, PID, phone, email, product…">
    <span class="excust-search-count" id="exCustomersSearchCount"></span>
  </div>
  <div class="report-card">
    <div class="report-scroll">
      <table class="excust-table" id="exCustomersTable">
        <thead>
          <tr>
            <th>Name</th><th>PID</th><th>Phone</th><th>Email</th><th>City</th><th>Grade</th>
            <th class="num">Collected %</th><th class="num">Loans</th><th class="num">Purchased</th>
            <th class="num">Written Off</th><th>Last Close</th><th>Products</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>

  <p class="section-title" style="margin-top:20px;">Never Became a Customer &mdash; full list</p>
  <p class="note" style="margin:-8px 0 0;">Same ~26,500 people as the status breakdown above, now with contact detail &mdash; every application they ever submitted was rejected, refused, or expired before disbursement, so there's no closed-loan history to grade (no Grade/Collected %/Purchased/Written Off columns here, unlike the graded list above). <b>Products</b> = what they applied for/expressed interest in, not what they bought. Sorted by most recent application first.</p>
  <div class="excust-search">
    <input type="text" id="neverBorrowedSearch" placeholder="Search name, PID, phone, email, product…">
    <span class="excust-search-count" id="neverBorrowedSearchCount"></span>
  </div>
  <div class="report-card">
    <div class="report-scroll">
      <table class="excust-table" id="neverBorrowedDetailTable">
        <thead>
          <tr>
            <th>Name</th><th>PID</th><th>Phone</th><th>Email</th><th>City</th>
            <th>Last Status</th><th>Last Application</th><th class="num">Applications</th><th>Products</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
</div>

<div class="page" id="page-risksegmentation">
  <p class="section-title">Risk Segmentation &mdash; active portfolio</p>
  <p class="note" style="margin:-8px 0 0;">Active loans (<code>Active = 1</code>) grouped by <code>Risk_Status</code>. Live from the database on every page load.</p>
  <div class="table-card">
    <table id="riskSegmentationTable"><tbody></tbody></table>
  </div>
</div>

<div class="page" id="page-closedloans">
  <p class="section-title">Closed Loans Analysis &mdash; paid off vs. written off</p>
  <p class="note" style="margin:-8px 0 0;">One row per month, keyed to <code>Close_Date</code>. Only loans that were genuinely active and then closed (<code>Close_Type</code> 1 = paid off, 2 = written off) &mdash; excludes rejected/never-activated applications, which sit at <code>Close_Type = 0</code> along with currently-active loans and aren't a real "closure." Paid Off Amount = <code>Full_Cost</code> (the loan's full sale price); Written Off Debt = remaining <code>Debt</code> at closure. Live from the database on every page load.</p>
  <div class="report-card">
    <div class="report-scroll-top" id="closedLoansScrollTop"><div></div></div>
    <div class="report-scroll" id="closedLoansScrollBody">
      <table class="rpt rpt-pivot" id="closedLoansTable"><tbody></tbody></table>
    </div>
  </div>
</div>

<div class="page" id="page-delinquency">
  <p class="section-title">Overdue Analysis &mdash; Portfolio at Risk</p>
  <p class="note" style="margin:-8px 0 0;">Active loans (<code>Active = 1</code>) grouped by <code>Days_Age</code> &mdash; the reliably-populated aging field (<code>OverDay</code> is 0 for every active loan in this database and isn't usable). PAR30/60/90 = share of total outstanding debt that is 30/60/90+ days overdue, the standard lending "Portfolio at Risk" metric. Point-in-time snapshot, live on every page load, but not reconstructable for a past date &mdash; same as the Logistics Daily "Not Delivered" table.</p>
  <div class="table-card">
    <table id="delinquencyTable"><tbody></tbody></table>
  </div>
  <p class="note" id="delinquencyParNote"></p>
</div>

<div class="page" id="page-leads">
  <p class="section-title">Leads &mdash; status by month</p>
  <p class="note" style="margin:-8px 0 0;">Every lead status by month, keyed to <code>instalments.Lead_Create_Date</code> &mdash; the same admin-panel Status filter shown in the "Leads" panel. Uses <code>instalments.Lead_Status_ID</code> rather than the standalone <code>leads</code> table: the standalone table is just the untriaged intake log (92% blank/Pending in this window), while a lead's status only actually gets updated once it's pulled into <code>instalments</code> (as a <code>Lead = 1</code> row) and worked. "Unspecified" covers a small number of rows with no status set. Live from the database on every page load.</p>
  <div class="report-card">
    <div class="report-scroll-top" id="leadsScrollTop"><div></div></div>
    <div class="report-scroll" id="leadsScrollBody">
      <table class="rpt rpt-pivot sm-table" id="leadsTable"><tbody></tbody></table>
    </div>
  </div>
</div>

<div class="page" id="page-applicationstatuses">
  <p class="section-title">Application Statuses &mdash; all statuses by month</p>
  <p class="note" style="margin:-8px 0 0;">Every <code>instalments.Order_Status</code> an application has ever carried, one row per month, keyed to <code>Aplication_Date</code> &mdash; the same admin-panel Status filter shown in the "Applications" panel, but covering the full status lifecycle rather than just the 5 committee outcomes on the Committee tab. Two raw codes that share the literal label "ინვოისის გაგზავნა" (IDs 5 and 9 &mdash; 5 is the one actually used at volume) are merged into one row; "Unspecified" covers a small number of rows whose status code has no label in <code>order_statuses</code> at all. Live from the database on every page load.</p>
  <div class="report-card">
    <div class="report-scroll-top" id="applicationStatusesScrollTop"><div></div></div>
    <div class="report-scroll" id="applicationStatusesScrollBody">
      <table class="rpt rpt-pivot sm-table" id="applicationStatusesTable"><tbody></tbody></table>
    </div>
  </div>
</div>

<div class="page" id="page-rejectionreasons">
  <p class="section-title">Committee &mdash; decision reasons by status</p>
  <p class="note" style="margin:-8px 0 0;">There's no separate committee/reasons table &mdash; every status below is <code>instalments.Order_Status</code>, and every reason is <code>instalments.Reason</code>, the same admin-panel Status filter shown in the loan-management panel (Approved/Rejected/"Client Refused"/Expired/Not Responded). Each table is one row per month, keyed to <code>Aplication_Date</code>, "Unspecified" = Reason left blank, shown as its own row rather than dropped. English column is a hand-translated label for the free-text Georgian Reason (no English field exists in the database). Live from the database on every page load.</p>

  <p class="section-title" style="margin-top:20px;">Rejected &mdash; <code>Order_Status = 6</code> ("უარი განაცხადზე")</p>
  <div class="report-card">
    <div class="report-scroll-top" id="rejectionReasonsScrollTop"><div></div></div>
    <div class="report-scroll" id="rejectionReasonsScrollBody">
      <table class="rpt rpt-pivot sm-table" id="rejectionReasonsTable"><tbody></tbody></table>
    </div>
  </div>

  <p class="section-title" style="margin-top:20px;">Client Refused &mdash; <code>Order_Status = 12</code> ("კლიენტის უარი განაცხადზე")</p>
  <div class="report-card">
    <div class="report-scroll-top" id="clientRefusedReasonsScrollTop"><div></div></div>
    <div class="report-scroll" id="clientRefusedReasonsScrollBody">
      <table class="rpt rpt-pivot sm-table" id="clientRefusedReasonsTable"><tbody></tbody></table>
    </div>
  </div>

  <p class="section-title" style="margin-top:20px;">Expired &mdash; <code>Order_Status = 13</code></p>
  <div class="report-card">
    <div class="report-scroll-top" id="expiredReasonsScrollTop"><div></div></div>
    <div class="report-scroll" id="expiredReasonsScrollBody">
      <table class="rpt rpt-pivot sm-table" id="expiredReasonsTable"><tbody></tbody></table>
    </div>
  </div>

  <p class="section-title" style="margin-top:20px;">Not Responding &mdash; <code>Order_Status = 14</code> ("არ პასუხობს")</p>
  <div class="report-card">
    <div class="report-scroll-top" id="notRespondingReasonsScrollTop"><div></div></div>
    <div class="report-scroll" id="notRespondingReasonsScrollBody">
      <table class="rpt rpt-pivot sm-table" id="notRespondingReasonsTable"><tbody></tbody></table>
    </div>
  </div>

  <p class="section-title" style="margin-top:20px;">Approved &mdash; <code>Order_Status = 5</code> ("ინვოისის გაგზავნა", the same status used elsewhere in this project as the "real sale" filter)</p>
  <p class="note" style="margin:-8px 0 0;">Unlike the four outcomes above, an approved/sold application doesn't need a reason recorded &mdash; 11,360 of 11,361 <code>Order_Status = 5</code> rows have a blank Reason (shown below almost entirely as "Unspecified"). Kept for completeness/transparency rather than hidden.</p>
  <div class="report-card">
    <div class="report-scroll-top" id="approvedReasonsScrollTop"><div></div></div>
    <div class="report-scroll" id="approvedReasonsScrollBody">
      <table class="rpt rpt-pivot sm-table" id="approvedReasonsTable"><tbody></tbody></table>
    </div>
  </div>
</div>

<script>
const data = <?= json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const targets = <?= json_encode($targets, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const generatedAt = <?= json_encode($generatedAt, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const headerYesterday = <?= json_encode($headerYesterday, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const headerMtdRange = <?= json_encode($headerMtdRange, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const monthlyStats = <?= json_encode($monthlyStats, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const dailyStats = <?= json_encode($dailyStats, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const salesMonthlyStats = <?= json_encode($salesMonthlyStats, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const brandStats = <?= json_encode($brandStats, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const subcategoryStats = <?= json_encode($subcategoryStats, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const categoryBrandBreakdown = <?= json_encode($categoryBrandBreakdown, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const incomeDelinquencyByCategory = <?= json_encode($incomeDelinquencyByCategory, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const incomeDelinquencyBySubcategory = <?= json_encode($incomeDelinquencyBySubcategory, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const incomeDelinquencyByBrand = <?= json_encode($incomeDelinquencyByBrand, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const incomeDelinquencyByProduct = <?= json_encode($incomeDelinquencyByProduct, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const pendingStatusLog = <?= json_encode($pendingStatusLog, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const customerAnalysis = <?= json_encode($customerAnalysis, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const customerAgeGenderAnalysis = <?= json_encode($customerAgeGenderAnalysis, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const customerWorkshopAnalysis = <?= json_encode($customerWorkshopAnalysis, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const customerWorkposAnalysis = <?= json_encode($customerWorkposAnalysis, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const customerIncomeAnalysis = <?= json_encode($customerIncomeAnalysis, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const customerDistrictAnalysis = <?= json_encode($customerDistrictAnalysis, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const riskSegmentation = <?= json_encode($riskSegmentation, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const exCustomers = <?= json_encode($exCustomers, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const neverBorrowedByStatus = <?= json_encode($neverBorrowedByStatus, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const neverBorrowedDetail = <?= json_encode($neverBorrowedDetail, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const closedLoansMonthly = <?= json_encode($closedLoansMonthly, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const delinquencyAnalysis = <?= json_encode($delinquencyAnalysis, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const rejectionReasonsMonthly = <?= json_encode($rejectionReasonsMonthly, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const clientRefusedReasonsMonthly = <?= json_encode($clientRefusedReasonsMonthly, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const expiredReasonsMonthly = <?= json_encode($expiredReasonsMonthly, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const notRespondingReasonsMonthly = <?= json_encode($notRespondingReasonsMonthly, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const approvedReasonsMonthly = <?= json_encode($approvedReasonsMonthly, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const applicationStatusesMonthly = <?= json_encode($applicationStatusesMonthly, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const leadStatusesMonthly = <?= json_encode($leadStatusesMonthly, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;

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

/* ---------- Sales Monthly / Brand Analyze / Subcategory Analyze: bucket x month breakdown,
   live from the DB (FunnelRepository::salesMonthlyStats / brandMonthlyStats /
   subcategoryMonthlyStats — see volta_sales_monthly memory for the full investigation behind
   the Order_Status=5 filter and the mapping-sheet-based category/subcategory/brand
   translation). One shared render function — the three tabs differ only in their data,
   row-label column header, and the wording of the "fallback bucket" footer note. ---------- */

function bucketedColHead(rowLabel, periods, q1Periods, q2Periods) {
  const cells = [];
  const subCells = [];
  const summaryHead = label => `<td colspan="5" class="sm-total-col">${label}</td>`;
  const summarySub = () => `<td class="sm-total-col">Sales</td><td>Cogs</td><td>Mrg</td><td>Qty</td><td>Share</td>`;
  periods.forEach(p => {
    cells.push(`<td colspan="4">${monthLabel(p)}</td>`);
    subCells.push(`<td>Sales</td><td>Cogs</td><td>Mrg</td><td>Qty</td>`);
    if (q1Periods.length && p === q1Periods[q1Periods.length - 1]) { cells.push(summaryHead('Q1 Total')); subCells.push(summarySub()); }
    if (q2Periods.length && p === q2Periods[q2Periods.length - 1]) { cells.push(summaryHead('Q2 Total')); subCells.push(summarySub()); }
  });
  cells.push(summaryHead('Total'));
  subCells.push(summarySub());
  return `<tr class="rpt-colhead"><td>${rowLabel}</td>${cells.join('')}</tr><tr class="rpt-colhead"><td></td>${subCells.join('')}</tr>`;
}
function bucketedCells(cell, isSummary) {
  const margin = cell.sales > 0 ? (cell.sales - cell.cogs) / cell.sales : null;
  const cls = isSummary ? ' sm-total-col' : '';
  let html = `<td class="${cls}">${fmt(cell.sales)}</td><td>${fmt(cell.cogs)}</td><td>${margin === null ? '&ndash;' : pct(margin)}</td><td>${fmt(cell.qty)}</td>`;
  if (isSummary) html += `<td>${pct(cell.share || 0)}</td>`;
  return html;
}
function bucketedRowCells(row, periods, q1Periods, q2Periods) {
  let html = '';
  periods.forEach(p => {
    html += bucketedCells(row.byPeriod[p], false);
    if (q1Periods.length && p === q1Periods[q1Periods.length - 1]) html += bucketedCells(row.q1, true);
    if (q2Periods.length && p === q2Periods[q2Periods.length - 1]) html += bucketedCells(row.q2, true);
  });
  html += bucketedCells(row.total, true);
  return html;
}
function renderBucketedTable(tableId, footerId, stats, titleText, rowLabel, fallbackBucket, fallbackNoteHtml, garbageNoteHtml) {
  const table = document.getElementById(tableId);
  if (!stats || !stats.rows) {
    table.querySelector('tbody').innerHTML = '<tr><td>No data.</td></tr>';
    return;
  }
  const { periods, q1Periods, q2Periods, rows, grandTotal, grandQ1, grandQ2, uncategorized, garbage } = stats;
  const summaryGroups = 1 + (q1Periods.length ? 1 : 0) + (q2Periods.length ? 1 : 0);
  const colspan = 1 + periods.length * 4 + summaryGroups * 5;

  let html = rptPivotPlainSpan('rpt-title', titleText, colspan);
  html += bucketedColHead(rowLabel, periods, q1Periods, q2Periods);

  html += `<tr class="sm-grandtotal"><td>TOTAL (all)</td>`;
  html += bucketedRowCells({
    byPeriod: Object.fromEntries(periods.map(p => [p, rows.reduce((acc, r) => {
      const c = r.byPeriod[p]; acc.sales += c.sales; acc.cogs += c.cogs; acc.qty += c.qty; return acc;
    }, { sales: 0, cogs: 0, qty: 0 })])),
    q1: { ...grandQ1, share: 1 }, q2: { ...grandQ2, share: 1 }, total: { ...grandTotal, share: 1 },
  }, periods, q1Periods, q2Periods);
  html += `</tr>`;

  rows.forEach(row => {
    const rowCls = row.bucket === fallbackBucket ? 'sm-row sm-uncategorized' : 'sm-row';
    html += `<tr class="${rowCls}"><td>${row.bucket}</td>${bucketedRowCells(row, periods, q1Periods, q2Periods)}</tr>`;
  });

  table.querySelector('tbody').innerHTML = html;

  const note = document.getElementById(footerId);
  const uncatPct = grandTotal.sales > 0 ? (100 * uncategorized.sales / grandTotal.sales).toFixed(1) : '0';
  const garbagePct = garbage.total > 0 ? (100 * garbage.count / garbage.total).toFixed(1) : '0';
  const garbageSalesPct = grandTotal.sales > 0 ? (100 * garbage.salesAffected / grandTotal.sales).toFixed(1) : '0';
  note.innerHTML = fallbackNoteHtml
    .replace('{count}', fmt(uncategorized.count)).replace('{sales}', fmt(uncategorized.sales)).replace('{pct}', uncatPct)
    + ' ' + garbageNoteHtml
      .replace('{gcount}', fmt(garbage.count)).replace('{gtotal}', fmt(garbage.total)).replace('{gpct}', garbagePct)
      .replace('{gsales}', fmt(garbage.salesAffected)).replace('{gsalespct}', garbageSalesPct);
}

const GARBAGE_NOTE = `<strong>Cogs data quality:</strong> {gcount} of {gtotal} line items ({gpct}%, {gsales} GEL / {gsalespct}% of sales) have a placeholder or clearly-wrong Cogs value (staff enters "1" because the system requires some value, then returns later with the real cost) &mdash; the Cogs column above is left raw/unmodified to match the business's own report exactly, so months with more unfilled Cogs will show an inflated Margin until those get backfilled.`;

/* ---------- Deal-type filter (All / Installment / Single Payment): shared by Sales Monthly,
   Brand Analyze, Subcategory Analyze. Each *Stats object from the PHP app is now
   {all, installment, single} — see FunnelRepository::salesMonthlyStats() docblock for how
   "single payment" (Type_Of_Sales=99, Order_Status IN (1,3), no dedicated Order_Date) was
   identified and folded in. One small reusable wiring function per tab rather than three
   near-identical blocks. */
function wireDealTypeFilter(navId, dealTypeSetter) {
  document.querySelectorAll('#' + navId + ' button').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('#' + navId + ' button').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      dealTypeSetter(btn.getAttribute('data-deal'));
    });
  });
}

const salesMonthlyScrollUpdate = setupTopScrollSync('salesMonthlyScrollTop', 'salesMonthlyScrollBody');
let salesMonthlyDealType = 'all';
function renderSalesMonthlyForDealType() {
  renderBucketedTable('salesMonthlyTable', 'salesMonthlyFooterNote', salesMonthlyStats ? salesMonthlyStats[salesMonthlyDealType] : null,
    'Sales Monthly &mdash; by Product Category', 'Product Category', 'Uncategorized',
    `<strong>Uncategorized:</strong> {count} line items / {sales} GEL ({pct}% of total sales) have no usable product category in the database (recently-added products often sit under a category literally named "none" until someone categorizes them) &mdash; grouped into one row at the bottom rather than guessed at.`,
    GARBAGE_NOTE);
  salesMonthlyScrollUpdate();
}
renderSalesMonthlyForDealType();
wireDealTypeFilter('salesMonthlyDealTypeNav', dt => { salesMonthlyDealType = dt; renderSalesMonthlyForDealType(); });

const brandScrollUpdate = setupTopScrollSync('brandScrollTop', 'brandScrollBody');
let brandDealType = 'all';
function renderBrandForDealType() {
  renderBucketedTable('brandTable', 'brandFooterNote', brandStats ? brandStats[brandDealType] : null,
    'Brand Analyze &mdash; by Brand', 'Brand', 'No Brand',
    `<strong>No Brand:</strong> {count} line items / {sales} GEL ({pct}% of total sales) had no real brand recorded (combines "none" / "N/A" / "ბრენდის გარეშე" into one row) &mdash; grouped at the bottom rather than guessed at.`,
    GARBAGE_NOTE);
  brandScrollUpdate();
}
renderBrandForDealType();
wireDealTypeFilter('brandDealTypeNav', dt => { brandDealType = dt; renderBrandForDealType(); });

const subcategoryScrollUpdate = setupTopScrollSync('subcategoryScrollTop', 'subcategoryScrollBody');
let subcategoryDealType = 'all';
function renderSubcategoryForDealType() {
  renderBucketedTable('subcategoryTable', 'subcategoryFooterNote', subcategoryStats ? subcategoryStats[subcategoryDealType] : null,
    'Subcategory Analyze &mdash; by Broader Product Group', 'Subcategory', 'Uncategorized',
    `<strong>Uncategorized:</strong> {count} line items / {sales} GEL ({pct}% of total sales) have no usable product category in the database &mdash; same gap as Sales Monthly, grouped into one row at the bottom rather than guessed at.`,
    GARBAGE_NOTE);
  subcategoryScrollUpdate();
}
renderSubcategoryForDealType();
wireDealTypeFilter('subcategoryDealTypeNav', dt => { subcategoryDealType = dt; renderSubcategoryForDealType(); });

/* ---------- Category/Brand: one block per category (title bar + header + brand rows + total
   row), mirroring the business's own reference report's "Top 4 — Brands" sheet layout for every
   category instead of a hand-picked top 4. Same All/Installment/Single-Payment deal-type filter
   as Sales Monthly/Brand Analyze/Subcategory Analyze. */
function renderCategoryBrandTable(d) {
  const table = document.getElementById('categoryBrandTable');
  if (!d || !d.categories) {
    table.querySelector('tbody').innerHTML = '<tr><td>No data.</td></tr>';
    return;
  }

  const marginCell = m => m === null ? '&ndash;' : pct(m);
  let html = '';

  d.categories.forEach(cat => {
    const isFallback = cat.category === 'Uncategorized';
    html += `<tr class="rpt-title"><td colspan="8">${cat.category}</td></tr>`;
    html += `<tr class="rpt-colhead"><td>Brand</td><td>Q1 Sales</td><td>Q2 Sales</td><td>Total Sales</td><td>Share %</td><td>COGS</td><td>PR Mrg %</td><td>Q-ty</td></tr>`;
    cat.brands.forEach(b => {
      const rowCls = (isFallback || b.brand === 'No Brand') ? 'sm-row sm-uncategorized' : 'sm-row';
      html += `<tr class="${rowCls}"><td>${b.brand}</td><td>${fmt(b.q1.sales)}</td><td>${fmt(b.q2.sales)}</td><td>${fmt(b.total.sales)}</td><td>${pct(b.share)}</td><td>${fmt(b.total.cogs)}</td><td>${marginCell(b.margin)}</td><td>${fmt(b.total.qty)}</td></tr>`;
    });
    html += `<tr class="sm-grandtotal"><td>Total</td><td></td><td></td><td>${fmt(cat.total.sales)}</td><td>100.0%</td><td>${fmt(cat.total.cogs)}</td><td>${marginCell(cat.margin)}</td><td>${fmt(cat.total.qty)}</td></tr>`;
  });

  table.querySelector('tbody').innerHTML = html;
}
let categoryBrandDealType = 'all';
function renderCategoryBrandForDealType() {
  renderCategoryBrandTable(categoryBrandBreakdown ? categoryBrandBreakdown[categoryBrandDealType] : null);
}
renderCategoryBrandForDealType();
wireDealTypeFilter('categoryBrandDealTypeNav', dt => { categoryBrandDealType = dt; renderCategoryBrandForDealType(); });

/* ---------- Income & Delinquency by Product (Category/Subcategory/Brand/Product): two pivot
   tables per dimension, sharing the same period/Q1/Q2/Total column structure as Sales Monthly
   but reading IncomeDelinquencyRepository's cell shape — revenue/cogs/qty for Income,
   paidQty/writtenQty/paidAmt/writtenAmt for Delinquency — instead of Sales Monthly's
   sales/cogs/qty. Closed-loans-only, no deal-type filter (Close_Type is a closed-loan concept,
   not a deal-type one). Percentages (margin, write-off rate) are always computed client-side
   from the raw counts/amounts rather than trusted from a precomputed field, same convention as
   bucketedCells() above — works whether the cell came straight from the server or was summed
   here in JS for a synthetic "TOTAL (all)" row. */
function incomeColHead(rowLabel, periods, q1Periods, q2Periods) {
  const cells = [];
  const subCells = [];
  const summaryHead = label => `<td colspan="5" class="sm-total-col">${label}</td>`;
  const summarySub = () => `<td class="sm-total-col">Revenue</td><td>Cogs</td><td>Mrg</td><td>Qty</td><td>Share</td>`;
  periods.forEach(p => {
    cells.push(`<td colspan="4">${monthLabel(p)}</td>`);
    subCells.push(`<td>Revenue</td><td>Cogs</td><td>Mrg</td><td>Qty</td>`);
    if (q1Periods.length && p === q1Periods[q1Periods.length - 1]) { cells.push(summaryHead('Q1 Total')); subCells.push(summarySub()); }
    if (q2Periods.length && p === q2Periods[q2Periods.length - 1]) { cells.push(summaryHead('Q2 Total')); subCells.push(summarySub()); }
  });
  cells.push(summaryHead('Total'));
  subCells.push(summarySub());
  return `<tr class="rpt-colhead"><td>${rowLabel}</td>${cells.join('')}</tr><tr class="rpt-colhead"><td></td>${subCells.join('')}</tr>`;
}
function incomeCells(cell, isSummary) {
  const margin = cell.revenue > 0 ? (cell.revenue - cell.cogs) / cell.revenue : null;
  const cls = isSummary ? ' sm-total-col' : '';
  let html = `<td class="${cls}">${fmt(cell.revenue)}</td><td>${fmt(cell.cogs)}</td><td>${margin === null ? '&ndash;' : pct(margin)}</td><td>${fmt(cell.qty)}</td>`;
  if (isSummary) html += `<td>${pct(cell.share || 0)}</td>`;
  return html;
}
function incomeRowCells(row, periods, q1Periods, q2Periods) {
  let html = '';
  periods.forEach(p => {
    html += incomeCells(row.byPeriod[p], false);
    if (q1Periods.length && p === q1Periods[q1Periods.length - 1]) html += incomeCells(row.q1, true);
    if (q2Periods.length && p === q2Periods[q2Periods.length - 1]) html += incomeCells(row.q2, true);
  });
  html += incomeCells(row.total, true);
  return html;
}
function renderIncomeTable(tableId, stats, titleText, rowLabel, fallbackBucket) {
  const table = document.getElementById(tableId);
  if (!stats || !stats.rows) {
    table.querySelector('tbody').innerHTML = '<tr><td>No data.</td></tr>';
    return;
  }
  const { periods, q1Periods, q2Periods, rows, grandTotal, grandQ1, grandQ2 } = stats;
  const summaryGroups = 1 + (q1Periods.length ? 1 : 0) + (q2Periods.length ? 1 : 0);
  const colspan = 1 + periods.length * 4 + summaryGroups * 5;

  let html = rptPivotPlainSpan('rpt-title', titleText, colspan);
  html += incomeColHead(rowLabel, periods, q1Periods, q2Periods);

  html += `<tr class="sm-grandtotal"><td>TOTAL (all)</td>`;
  html += incomeRowCells({
    byPeriod: Object.fromEntries(periods.map(p => [p, rows.reduce((acc, r) => {
      const c = r.byPeriod[p]; acc.revenue += c.revenue; acc.cogs += c.cogs; acc.qty += c.qty; return acc;
    }, { revenue: 0, cogs: 0, qty: 0 })])),
    q1: { ...grandQ1, share: 1 }, q2: { ...grandQ2, share: 1 }, total: { ...grandTotal, share: 1 },
  }, periods, q1Periods, q2Periods);
  html += `</tr>`;

  rows.forEach(row => {
    const rowCls = row.bucket === fallbackBucket ? 'sm-row sm-uncategorized' : 'sm-row';
    html += `<tr class="${rowCls}"><td>${row.bucket}</td>${incomeRowCells(row, periods, q1Periods, q2Periods)}</tr>`;
  });

  table.querySelector('tbody').innerHTML = html;
}

function delinquencyColHead(rowLabel, periods, q1Periods, q2Periods) {
  const cells = [];
  const subCells = [];
  const summaryHead = label => `<td colspan="6" class="sm-total-col">${label}</td>`;
  const summarySub = () => `<td class="sm-total-col">Paid Qty</td><td>WO Qty</td><td>WO Qty %</td><td>Paid GEL</td><td>WO GEL</td><td>WO GEL %</td>`;
  periods.forEach(p => {
    cells.push(`<td colspan="6">${monthLabel(p)}</td>`);
    subCells.push(summarySub());
    if (q1Periods.length && p === q1Periods[q1Periods.length - 1]) { cells.push(summaryHead('Q1 Total')); subCells.push(summarySub()); }
    if (q2Periods.length && p === q2Periods[q2Periods.length - 1]) { cells.push(summaryHead('Q2 Total')); subCells.push(summarySub()); }
  });
  cells.push(summaryHead('Total'));
  subCells.push(summarySub());
  return `<tr class="rpt-colhead"><td>${rowLabel}</td>${cells.join('')}</tr><tr class="rpt-colhead"><td></td>${subCells.join('')}</tr>`;
}
function delinquencyCells(cell, isSummary) {
  const closedQty = cell.paidQty + cell.writtenQty;
  const woQtyRate = closedQty > 0 ? cell.writtenQty / closedQty : 0;
  const closedAmt = cell.paidAmt + cell.writtenAmt;
  const woAmtRate = closedAmt > 0 ? cell.writtenAmt / closedAmt : 0;
  const cls = isSummary ? ' sm-total-col' : '';
  return `<td class="${cls}">${fmt(cell.paidQty)}</td><td>${fmt(cell.writtenQty)}</td><td>${pct(woQtyRate)}</td><td>${fmt(cell.paidAmt)}</td><td>${fmt(cell.writtenAmt)}</td><td>${pct(woAmtRate)}</td>`;
}
function delinquencyRowCells(row, periods, q1Periods, q2Periods) {
  let html = '';
  periods.forEach(p => {
    html += delinquencyCells(row.byPeriod[p], false);
    if (q1Periods.length && p === q1Periods[q1Periods.length - 1]) html += delinquencyCells(row.q1, true);
    if (q2Periods.length && p === q2Periods[q2Periods.length - 1]) html += delinquencyCells(row.q2, true);
  });
  html += delinquencyCells(row.total, true);
  return html;
}
function renderDelinquencyTable(tableId, stats, titleText, rowLabel, fallbackBucket) {
  const table = document.getElementById(tableId);
  if (!stats || !stats.rows) {
    table.querySelector('tbody').innerHTML = '<tr><td>No data.</td></tr>';
    return;
  }
  const { periods, q1Periods, q2Periods, rows, grandTotal, grandQ1, grandQ2 } = stats;
  const summaryGroups = 1 + (q1Periods.length ? 1 : 0) + (q2Periods.length ? 1 : 0);
  const colspan = 1 + periods.length * 6 + summaryGroups * 6;

  let html = rptPivotPlainSpan('rpt-title', titleText, colspan);
  html += delinquencyColHead(rowLabel, periods, q1Periods, q2Periods);

  html += `<tr class="sm-grandtotal"><td>TOTAL (all)</td>`;
  html += delinquencyRowCells({
    byPeriod: Object.fromEntries(periods.map(p => [p, rows.reduce((acc, r) => {
      const c = r.byPeriod[p];
      acc.paidQty += c.paidQty; acc.writtenQty += c.writtenQty; acc.paidAmt += c.paidAmt; acc.writtenAmt += c.writtenAmt;
      return acc;
    }, { paidQty: 0, writtenQty: 0, paidAmt: 0, writtenAmt: 0 })])),
    q1: grandQ1, q2: grandQ2, total: grandTotal,
  }, periods, q1Periods, q2Periods);
  html += `</tr>`;

  rows.forEach(row => {
    const rowCls = row.bucket === fallbackBucket ? 'sm-row sm-uncategorized' : 'sm-row';
    html += `<tr class="${rowCls}"><td>${row.bucket}</td>${delinquencyRowCells(row, periods, q1Periods, q2Periods)}</tr>`;
  });

  table.querySelector('tbody').innerHTML = html;
}

// Keyed by data-page value (not dimKey) so the #pageNav click handler below can re-trigger the
// scroll-spacer measurement for whichever tab was just switched into — a hidden table reports
// scrollWidth 0, so this has to run again once the tab is actually visible, same as every other
// scroll-synced tab on this page.
const incomeDelinquencyScrollUpdaters = {};
function renderIncomeDelinquencyDimension(pageKey, dimKey, data, incomeTitleText, delinquencyTitleText, rowLabel, fallbackBucket) {
  const incomeScrollUpdate = setupTopScrollSync(`income${dimKey}ScrollTop`, `income${dimKey}ScrollBody`);
  const delinquencyScrollUpdate = setupTopScrollSync(`delinquency${dimKey}ScrollTop`, `delinquency${dimKey}ScrollBody`);
  renderIncomeTable(`income${dimKey}Table`, data, incomeTitleText, rowLabel, fallbackBucket);
  renderDelinquencyTable(`delinquency${dimKey}Table`, data, delinquencyTitleText, rowLabel, fallbackBucket);
  incomeScrollUpdate();
  delinquencyScrollUpdate();
  incomeDelinquencyScrollUpdaters[pageKey] = [incomeScrollUpdate, delinquencyScrollUpdate];
}
renderIncomeDelinquencyDimension('incomecategory', 'Category', incomeDelinquencyByCategory,
  'Income &mdash; by Product Category (Realized Margin, Closed Loans)', 'Delinquency &mdash; Write-off Rate by Product Category',
  'Product Category', 'Uncategorized');
renderIncomeDelinquencyDimension('incomesubcategory', 'Subcategory', incomeDelinquencyBySubcategory,
  'Income &mdash; by Subcategory (Realized Margin, Closed Loans)', 'Delinquency &mdash; Write-off Rate by Subcategory',
  'Subcategory', 'Uncategorized');
renderIncomeDelinquencyDimension('incomebrand', 'Brand', incomeDelinquencyByBrand,
  'Income &mdash; by Brand (Realized Margin, Closed Loans)', 'Delinquency &mdash; Write-off Rate by Brand',
  'Brand', 'No Brand');
renderIncomeDelinquencyDimension('incomeproduct', 'Product', incomeDelinquencyByProduct,
  'Income &mdash; by Product (Realized Margin, Closed Loans)', 'Delinquency &mdash; Write-off Rate by Product',
  'Product', 'Uncategorized');

/* ---------- Sales — Pending Status: same "one column per date, transferred unchanged"
   pattern as the Logistics Delivery Status table below it, from the same source sheet.
   Reuses the identical .logi-table CSS classes (title/head/total/white/light) since the
   business's own coloring for this table happens to match exactly. */
const salesPendingHistory = {
  dates: ['2026-06-26','2026-06-29','2026-06-30','2026-07-01','2026-07-09','2026-07-10','2026-07-14','2026-07-15','2026-07-16','2026-07-20','2026-07-21','2026-07-22','2026-07-24','2026-07-27','2026-07-28','2026-07-29','2026-07-30','2026-07-31','2026-08-17','2026-08-18','2026-08-19','2026-08-20','2026-08-21'],
  upTo1:  [8, 22, 35, 103, 14, 22, 26, 25, 23, 20, 21, 21, 14, 36, 7, 54, 25, 29, 29, 33, 19, 19, 31],
  oneTo5: new Array(23).fill(null),
  over5:  new Array(23).fill(null),
};

// Extends the Google-Sheet-sourced history with any date captured by
// bin/capture_pending_status.php (Order_Status=4 "Pending" loan applications) that isn't
// already in the frozen history — 22 Aug onward grows automatically as the daily cron
// captures more days, with no code change needed here. See the note under this table on
// the page for the full disclosure that this is a different underlying metric.
function extendedSalesPendingHistory() {
  const h = salesPendingHistory;
  const known = new Set(h.dates);
  const newDates = Object.keys(pendingStatusLog).filter(d => !known.has(d)).sort();
  return {
    dates: [...h.dates, ...newDates],
    upTo1: [...h.upTo1, ...newDates.map(d => pendingStatusLog[d].count)],
    oneTo5: [...h.oneTo5, ...newDates.map(() => null)],
    over5: [...h.over5, ...newDates.map(() => null)],
  };
}
function renderSalesPendingTable() {
  const h = extendedSalesPendingHistory();
  const n = h.dates.length;
  function cell(v) { return (v === null || v === undefined) ? '<td>&ndash;</td>' : `<td>${fmt(v)}</td>`; }
  function rowHtml(cls, label, vals) {
    return `<tr class="${cls}"><td>${label}</td>${vals.map(cell).join('')}</tr>`;
  }
  const pending = h.dates.map((_, i) => (h.upTo1[i] || 0) + (h.oneTo5[i] || 0) + (h.over5[i] || 0));
  const dateLabels = h.dates.map(d => {
    const [, m, day] = d.split('-').map(Number);
    return `<td>${MONTH_NAMES[m - 1]} ${day}</td>`;
  }).join('');

  let html = `<tr class="logi-title"><td colspan="${n + 1}">SALES &mdash; Pending Status</td></tr>`;
  html += `<tr class="logi-head"><td>Status</td>${dateLabels}</tr>`;
  html += rowHtml('logi-total', 'Sales &ndash; Pending Status (Not Contacted Customers)', pending);
  html += rowHtml('logi-white', 'Up to 1 day', h.upTo1);
  html += rowHtml('logi-light', '1 to 5 days', h.oneTo5);
  html += rowHtml('logi-white', '&gt;5 days', h.over5);

  document.getElementById('logisticsSalesTable').querySelector('tbody').innerHTML = html;
}
renderSalesPendingTable();
const logisticsSalesScrollUpdate = setupTopScrollSync('logisticsSalesScrollTop', 'logisticsSalesScrollBody');

/* ---------- Logistics Daily: exact replica of the business's own "LOGISTICS — Delivery
   Status" tracking sheet. History (26 Jun – 20 Aug) is copied unchanged from that sheet —
   those figures are a point-in-time snapshot per column and cannot be recomputed later.
   21 Aug and 24 Aug are freshly computed from the same raw order data (22/23 Aug were never
   captured and can't be reconstructed after the fact — same rule as everywhere else on this
   tab, so those two dates are simply absent, not zero); each new day's column should keep
   being appended the same way (or, once the daily 20:00 automation exists, captured
   automatically). Delivered / Average Delivery Time are the exception — reconstructed from
   real Delivery/Pickup Date facts, so those two rows are reliable history rather than
   snapshots even for days that were never captured live.
   Average Delivery Time, for 21 Aug onward, = average(Delivery Date − Sale Date), NOT
   Pickup Date — Pickup Date is populated for only ~20% of orders (too sparse to reliably
   produce a number every day). Historical columns keep whatever basis the business's own
   sheet used (unknown, not our formula) — only new columns going forward use this
   Sale→Delivery definition. */
const logisticsHistory = {
  dates: ['2026-06-26','2026-06-29','2026-06-30','2026-07-01','2026-07-09','2026-07-10','2026-07-14','2026-07-15','2026-07-16','2026-07-20','2026-07-20','2026-07-21','2026-07-24','2026-07-27','2026-07-28','2026-07-29','2026-07-30','2026-07-31','2026-08-17','2026-08-18','2026-08-19','2026-08-20','2026-08-21','2026-08-24'],
  upTo1:   [103,109,135,87,55,65,68,51,51,57,38,68,67,36,36,43,36,27,23,34,30,22, 42, 40],
  oneTo5:  [204,228,169,169,202,214,177,183,190,121,148,135,75,121,101,73,69,60,75,40,37,40, 36, 35],
  over5:   [382,301,316,344,245,186,341,381,328,247,216,154,36,71,45,62,44,25,87,17,19,11, 7, 7],
  onHold:  [null,null,73,60,9,13,12,9,9,15,20,17,10,25,21,11,10,2,2,3,2,2, 2, 3],
  delivered:       [65,16,49,14,23,41,23,1,4,6,11,3,0,33,6,72,61,45,29,43,50,37, 9, 63],
  avgDeliveryTime: [10,11.2,7,8.4,8.6,9.8,7.3,7,13.8,8.8,10.5,9,null,10.4,7.2,6,5.2,3.4,5.9,4.4,3.1,3.3, 3.2, 4.0],
};

function renderLogisticsTable() {
  const h = logisticsHistory;
  const n = h.dates.length;
  const todayIdx = n - 1;

  function cell(v, isAvg) {
    if (v === null || v === undefined) return '<td>&ndash;</td>';
    return `<td>${isAvg ? v.toFixed(1) : fmt(v)}</td>`;
  }
  function rowHtml(cls, label, vals, isAvg) {
    const tds = vals.map(v => cell(v, isAvg)).join('');
    return `<tr class="${cls}"><td>${label}</td>${tds}</tr>`;
  }

  const notDelivered = h.dates.map((_, i) => (h.upTo1[i] || 0) + (h.oneTo5[i] || 0) + (h.over5[i] || 0) + (h.onHold[i] || 0));

  const dateLabels = h.dates.map((d, i) => {
    const [, m, day] = d.split('-').map(Number);
    const label = `${MONTH_NAMES[m - 1]} ${day}`;
    return i === todayIdx ? `<td>${label} (today)</td>` : `<td>${label}</td>`;
  }).join('');

  let html = `<tr class="logi-title"><td colspan="${n + 1}">LOGISTICS &mdash; Delivery Status</td></tr>`;
  html += `<tr class="logi-head"><td>Status</td>${dateLabels}</tr>`;
  html += rowHtml('logi-total', 'Logistics &ndash; Number of Not Delivered Orders', notDelivered);
  html += rowHtml('logi-white', 'Up to 1 day', h.upTo1);
  html += rowHtml('logi-light', '1 to 5 days', h.oneTo5);
  html += rowHtml('logi-white', '&gt;5 days', h.over5);
  html += rowHtml('logi-white', 'On Hold', h.onHold);
  html += rowHtml('logi-plain', 'Delivered', h.delivered);
  html += rowHtml('logi-plain', 'Average Delivery Time', h.avgDeliveryTime, true);

  document.getElementById('logisticsTable').querySelector('tbody').innerHTML = html;
}
renderLogisticsTable();
const logisticsScrollUpdate = setupTopScrollSync('logisticsScrollTop', 'logisticsScrollBody');

/* Orders by City / Orders by Goods Type — snapshot from the same Google Sheet, pulled the
   same manual way as the rest of this tab. */
const logisticsByCity = {
  title: 'Orders by City',
  headLabel: 'City',
  rows: [
    { label: 'Tbilisi', notDelivered: 68, all: 1973, share: 0.8 },
    { label: 'Other Cities', notDelivered: 17, all: 1188, share: 0.2 },
    { label: 'Without City', notDelivered: 0, all: 185, share: 0 },
  ],
  total: { label: 'ToTal', notDelivered: 85, all: 3346, share: 1 },
};
const logisticsByGoods = {
  title: 'Orders by Goods Type',
  headLabel: 'Goods Type',
  rows: [
    { label: 'Soft', notDelivered: 39, all: 1056, share: 0.4588235294117647 },
    { label: 'Medium', notDelivered: 13, all: 674, share: 0.15294117647058825 },
    { label: 'Heavy', notDelivered: 16, all: 1026, share: 0.18823529411764706 },
    { label: '#N/A', notDelivered: 17, all: 590, share: 0.2 },
  ],
  total: { label: 'Total', notDelivered: 85, all: 3346, share: 1 },
};

function renderLogisticsMiniTable(tableId, data) {
  let html = `<tr class="logi-mini-title"><td colspan="4">${data.title}</td></tr>`;
  html += `<tr class="logi-mini-head"><td>${data.headLabel}</td><td>Not Delivered Orders</td><td>ALL Orders</td><td>Share</td></tr>`;
  data.rows.forEach((r, i) => {
    const alt = i % 2 === 0 ? ' logi-mini-alt' : '';
    html += `<tr class="logi-mini-data${alt}"><td>${r.label}</td><td>${fmt(r.notDelivered)}</td><td>${fmt(r.all)}</td><td>${pct(r.share)}</td></tr>`;
  });
  const t = data.total;
  html += `<tr class="logi-mini-total"><td>${t.label}</td><td>${fmt(t.notDelivered)}</td><td>${fmt(t.all)}</td><td>${pct(t.share)}</td></tr>`;
  document.getElementById(tableId).querySelector('tbody').innerHTML = html;
}
renderLogisticsMiniTable('logisticsCityTable', logisticsByCity);
renderLogisticsMiniTable('logisticsGoodsTable', logisticsByGoods);

/* Open Cases — Still Waiting for Delivery: the 10 oldest not-yet-delivered orders, by Sale
   Date, transferred unchanged from the same Google Sheet. */
const logisticsOpenCases = [
  { customer: 'ნაია ჯანეზაშვილი', waitingFrom: '2026-07-10', status: 'გასარკვევი', city: 'თბილისი', orderNum: 155957 },
  { customer: 'იზა ღუბელაძე', waitingFrom: '2026-07-11', status: 'გასარკვევი', city: 'თბილისი', orderNum: 156561 },
  { customer: 'ნინო აბელიშვილი', waitingFrom: '2026-07-21', status: 'შეჩერებული / On Hold', city: 'თბილისი', orderNum: 158057 },
  { customer: 'ნინო ქადაგიშვილი', waitingFrom: '2026-08-13', status: 'შეჩერებული / On Hold', city: 'ბათუმი', orderNum: 162153 },
  { customer: 'ანა ხურციძე', waitingFrom: '2026-08-13', status: 'აღებული / Warehouse', city: 'თბილისი', orderNum: 161999 },
  { customer: 'ანდრეი ოვსიანიკოვი (საჩუქ)', waitingFrom: '2026-08-17', status: 'აღებული / Warehouse', city: 'თბილისი', orderNum: 157453 },
  { customer: 'ნათია გოგობერიშვილი', waitingFrom: '2026-08-18', status: 'აღებული / Warehouse', city: 'თბილისი', orderNum: 162811 },
  { customer: 'ლაშა ჟღენტი', waitingFrom: '2026-08-18', status: 'ადგილზეა/From Warehouse', city: 'თბილისი', orderNum: 162755 },
  { customer: 'გენადი გოგუაძე', waitingFrom: '2026-08-19', status: 'აღებული / Warehouse', city: 'თბილისი', orderNum: 162100 },
  { customer: 'ლელა კეკელია', waitingFrom: '2026-08-19', status: 'აღებული / Warehouse', city: 'თბილისი', orderNum: 162950 },
];

function renderLogisticsOpenCases() {
  let html = '<tr class="logi-open-head"><td>Customer</td><td>Waiting from</td><td>Status</td><td>City</td><td>Order #</td></tr>';
  logisticsOpenCases.forEach(c => {
    const [, m, d] = c.waitingFrom.split('-').map(Number);
    html += `<tr><td>${c.customer}</td><td>${MONTH_NAMES[m - 1]} ${d}</td><td>${c.status}</td><td>${c.city}</td><td>${c.orderNum}</td></tr>`;
  });
  document.getElementById('logisticsOpenCasesTable').querySelector('tbody').innerHTML = html;
}
renderLogisticsOpenCases();

/* ---------- Customers / Risk Segmentation / Closed Loans / Overdue Analysis: loan-portfolio
   tabs, a different domain from the sales/application funnel above — live from the DB on every
   page load, computed server-side in PortfolioRepository. See its class docblock for the data
   quality checks behind these definitions (OverDay unusable, Close_Type meanings, etc.) and
   volta_portfolio_analysis memory for the full investigation. ---------- */

function renderCustomerAnalysis() {
  const d = customerAnalysis;
  if (!d) return;

  const summaryHtml = `
    <tr><td>Total Customers (all-time, real applications)</td><td>${fmt(d.totalCustomers)}</td></tr>
    <tr><td>Active Customers (currently have &ge;1 active loan)</td><td>${fmt(d.activeCustomers)}</td></tr>
    <tr><td>New (exactly 1 loan ever)</td><td>${fmt(d.newCustomers)} (${pct(d.newCustomers / d.totalCustomers)})</td></tr>
    <tr class="total"><td>Repeat (2+ loans ever)</td><td>${fmt(d.repeatCustomers)} (${pct(d.repeatCustomers / d.totalCustomers)})</td></tr>
  `;
  document.getElementById('customersSummaryTable').querySelector('tbody').innerHTML =
    `<tr><th>Metric</th><th>Value</th></tr>${summaryHtml}`;

  const loansDistTotal = Object.values(d.loansPerCustomer).reduce((s, n) => s + n, 0);
  let loansDistHtml = '';
  Object.entries(d.loansPerCustomer).forEach(([loans, n]) => {
    loansDistHtml += `<tr><td>${loans} loan${loans === '1' ? '' : 's'}</td><td>${fmt(n)}</td><td>${pct(n / loansDistTotal)}</td></tr>`;
  });
  document.getElementById('customersLoansDistTable').querySelector('tbody').innerHTML =
    `<tr><th>Loans per Customer</th><th>Customers</th><th>Share</th></tr>${loansDistHtml}`;

  const cityTotal = d.byCity.reduce((s, r) => s + r.n, 0);
  const topCities = d.byCity.slice(0, 12);
  const otherCitiesN = d.byCity.slice(12).reduce((s, r) => s + r.n, 0);
  let cityHtml = topCities.map(r => `<tr><td>${r.label}</td><td>${fmt(r.n)}</td><td>${pct(r.n / cityTotal)}</td></tr>`).join('');
  if (otherCitiesN > 0) cityHtml += `<tr><td>Other</td><td>${fmt(otherCitiesN)}</td><td>${pct(otherCitiesN / cityTotal)}</td></tr>`;
  document.getElementById('customersByCityTable').querySelector('tbody').innerHTML =
    `<tr><th>City</th><th>Customers</th><th>Share</th></tr>${cityHtml}<tr class="total"><td>Total</td><td>${fmt(cityTotal)}</td><td>100.0%</td></tr>`;

  const genderTotal = d.byGender.reduce((s, r) => s + r.n, 0);
  const genderLabel = { 'მდედრ.': 'Female', 'მამრ.': 'Male', 'N/A': 'N/A' };
  const genderHtml = d.byGender.map(r => `<tr><td>${genderLabel[r.label] || r.label}</td><td>${fmt(r.n)}</td><td>${pct(r.n / genderTotal)}</td></tr>`).join('');
  document.getElementById('customersByGenderTable').querySelector('tbody').innerHTML =
    `<tr><th>Gender</th><th>Customers</th><th>Share</th></tr>${genderHtml}<tr class="total"><td>Total</td><td>${fmt(genderTotal)}</td><td>100.0%</td></tr>`;
}
renderCustomerAnalysis();

function renderCustomerAgeGender() {
  const d = customerAgeGenderAnalysis;
  if (!d) return;
  const genderLabel = { 'მდედრ.': 'Female', 'მამრ.': 'Male', 'N/A': 'N/A' };
  const headCells = d.genders.map(g => `<th colspan="2">${genderLabel[g] || g}</th>`).join('');
  const subCells = d.genders.map(() => `<th>Qty</th><th>%</th>`).join('');
  let html = `<tr><th rowspan="2">Age</th>${headCells}<th colspan="2">Total</th></tr>`;
  html += `<tr>${subCells}<th>Qty</th><th>%</th></tr>`;

  d.rows.forEach(row => {
    const cells = d.genders.map(g => `<td>${fmt(row[g].n)}</td><td>${pct(row[g].share)}</td>`).join('');
    html += `<tr><td>${row.bucket}</td>${cells}<td>${fmt(row.total.n)}</td><td>${pct(row.total.share)}</td></tr>`;
  });

  const totalRowCells = d.genders.map(g => `<td>${fmt(d.genderTotals[g])}</td><td>100.0%</td>`).join('');
  html += `<tr class="total"><td>Total</td>${totalRowCells}<td>${fmt(d.grandTotal)}</td><td>100.0%</td></tr>`;

  document.getElementById('customersAgeGenderTable').querySelector('tbody').innerHTML = html;
}
renderCustomerAgeGender();

function renderCustomerGroupedField(stats, tableId, headLabel, noteId, noteTemplate) {
  if (!stats) return;
  const rowsHtml = stats.rows.map(r =>
    `<tr><td>${r.label}</td><td>${fmt(r.n)}</td><td>${pct(r.share)}</td></tr>`
  ).join('');
  document.getElementById(tableId).querySelector('tbody').innerHTML =
    `<tr><th>${headLabel}</th><th>Customers</th><th>Share</th></tr>${rowsHtml}<tr class="total"><td>Total</td><td>${fmt(stats.total)}</td><td>100.0%</td></tr>`;
  if (noteId && noteTemplate) {
    document.getElementById(noteId).innerHTML = noteTemplate.replace('{distinct}', fmt(stats.distinctValues));
  }
}
renderCustomerGroupedField(customerWorkshopAnalysis, 'customersWorkshopTable', 'Workshop', 'customersWorkshopNote',
  `From <code>customers.Workshop</code> &mdash; free text (employer/field), top 20 exact values shown + "Other" for the long tail ({distinct} distinct raw values total). Spelling/typo variants (e.g. "თვითდასაქმებული" vs "თვით დასაქმებული") are shown separately, not merged &mdash; merging them would mean guessing which strings mean the same thing.`);
renderCustomerGroupedField(customerWorkposAnalysis, 'customersWorkposTable', 'Workpos',  'customersWorkposNote',
  `From <code>customers.Workpos</code> &mdash; job title/position, top 20 exact values shown + "Other" for the long tail ({distinct} distinct raw values total). Same convention as Workshop: no spelling-variant merging.`);
renderCustomerGroupedField(customerIncomeAnalysis, 'customersIncomeTable', 'Income (GEL)', null, null);

function renderCustomerDistrict() {
  const d = customerDistrictAnalysis;
  if (!d) return;
  const topRows = d.rows.filter(r => r.label !== 'ვერ დადგინდა');
  const undetermined = d.rows.find(r => r.label === 'ვერ დადგინდა');
  const rowsHtml = topRows.map(r => `<tr><td>${r.label}</td><td>${fmt(r.n)}</td><td>${pct(r.share)}</td></tr>`).join('');
  document.getElementById('customersDistrictTable').querySelector('tbody').innerHTML =
    `<tr><th>District</th><th>Addresses</th><th>Share</th></tr>${rowsHtml}<tr><td>${undetermined.label}</td><td>${fmt(undetermined.n)}</td><td>${pct(undetermined.share)}</td></tr><tr class="total"><td>Total (Tbilisi)</td><td>${fmt(d.total)}</td><td>100.0%</td></tr>`;
  const matchedShare = 1 - undetermined.share;
  document.getElementById('customersDistrictNote').innerHTML += ` Matched on ${pct(matchedShare)} of Tbilisi addresses.`;
}
renderCustomerDistrict();

function renderRiskSegmentation() {
  const d = riskSegmentation;
  if (!d) return;
  const rowsHtml = d.rows.map(r =>
    `<tr><td>${r.label}</td><td>${fmt(r.n)}</td><td>${fmt(r.debt)}</td><td>${fmt(r.penalty)}</td><td>${pct(r.share)}</td></tr>`
  ).join('');
  const html = `<tr><th>Risk Tier</th><th>Loans</th><th>Debt (GEL)</th><th>Penalty (GEL)</th><th>Share</th></tr>`
    + rowsHtml
    + `<tr class="total"><td>Total</td><td>${fmt(d.total.n)}</td><td>${fmt(d.total.debt)}</td><td></td><td>100.0%</td></tr>`;
  document.getElementById('riskSegmentationTable').querySelector('tbody').innerHTML = html;
}
renderRiskSegmentation();

/* ---------- Ex Customers: individual-row PII report (name/PID/phone/email + payment grade),
   the one report in this project that isn't aggregate-only — see ExCustomerRepository's docblock
   for why. Summary table reuses the plain .table-card th/td idiom already used above; the full
   list is a new wide table (.excust-table, see CSS) with a client-side text-search filter, since
   it lists 3,000+ people. */
function renderExCustomersSummary() {
  const d = exCustomers;
  if (!d) return;
  const rowsHtml = d.summary.byGrade.map(g =>
    `<tr><td>Grade ${g.grade}</td><td>${fmt(g.count)}</td><td>${pct(g.share)}</td><td>${fmt(g.totalPurchased)}</td><td>${fmt(g.totalWrittenOff)}</td></tr>`
  ).join('');
  const html = `<tr><th>Grade</th><th>Customers</th><th>Share</th><th>Total Purchased (GEL)</th><th>Total Written Off (GEL)</th></tr>`
    + rowsHtml
    + `<tr class="total"><td>Total</td><td>${fmt(d.summary.grandTotal.count)}</td><td>100.0%</td><td>${fmt(d.summary.grandTotal.totalPurchased)}</td><td>${fmt(d.summary.grandTotal.totalWrittenOff)}</td></tr>`;
  document.getElementById('exCustomersSummaryTable').querySelector('tbody').innerHTML = html;
}
renderExCustomersSummary();

function renderNeverBorrowedByStatus() {
  const d = neverBorrowedByStatus;
  if (!d) return;
  const rowsHtml = d.rows.map(r =>
    `<tr><td>${r.status}</td><td>${fmt(r.count)}</td><td>${pct(r.share)}</td></tr>`
  ).join('');
  const html = `<tr><th>Last Application Status</th><th>Customers</th><th>Share</th></tr>`
    + rowsHtml
    + `<tr class="total"><td>Total</td><td>${fmt(d.total)}</td><td>100.0%</td></tr>`;
  document.getElementById('neverBorrowedTable').querySelector('tbody').innerHTML = html;
}
renderNeverBorrowedByStatus();

function renderExCustomersTable(filterText) {
  const d = exCustomers;
  const table = document.getElementById('exCustomersTable');
  if (!d || !d.rows) {
    table.querySelector('tbody').innerHTML = '<tr><td>No data.</td></tr>';
    return;
  }
  const needle = (filterText || '').trim().toLowerCase();
  const rows = needle
    ? d.rows.filter(r => `${r.name} ${r.pid} ${r.phone} ${r.email} ${r.products}`.toLowerCase().includes(needle))
    : d.rows;

  const html = rows.map(r => `<tr data-grade="${r.grade}">
    <td>${r.name}</td><td>${r.pid}</td><td>${r.phone}</td><td>${r.email}</td><td>${r.city}</td>
    <td class="grade-cell">${r.grade}</td>
    <td class="num">${pct(r.collectionRate)}</td><td class="num">${fmt(r.loanCount)}</td>
    <td class="num">${fmt(r.totalPurchased)}</td><td class="num">${fmt(r.totalWrittenOff)}</td>
    <td>${r.lastCloseDate || '&ndash;'}</td><td class="products-cell">${r.products}</td>
  </tr>`).join('');
  table.querySelector('tbody').innerHTML = html || '<tr><td colspan="12">No matches.</td></tr>';

  const countEl = document.getElementById('exCustomersSearchCount');
  if (countEl) countEl.textContent = needle ? `${rows.length} of ${d.rows.length}` : `${d.rows.length} customers`;
}
renderExCustomersTable('');
document.getElementById('exCustomersSearch').addEventListener('input', e => renderExCustomersTable(e.target.value));

function renderNeverBorrowedDetailTable(filterText) {
  const d = neverBorrowedDetail;
  const table = document.getElementById('neverBorrowedDetailTable');
  if (!d || !d.rows) {
    table.querySelector('tbody').innerHTML = '<tr><td>No data.</td></tr>';
    return;
  }
  const needle = (filterText || '').trim().toLowerCase();
  const rows = needle
    ? d.rows.filter(r => `${r.name} ${r.pid} ${r.phone} ${r.email} ${r.products}`.toLowerCase().includes(needle))
    : d.rows;

  const html = rows.map(r => `<tr>
    <td>${r.name}</td><td>${r.pid}</td><td>${r.phone}</td><td>${r.email}</td><td>${r.city}</td>
    <td>${r.lastStatus}</td><td>${r.lastAppDate || '&ndash;'}</td>
    <td class="num">${fmt(r.appCount)}</td><td class="products-cell">${r.products}</td>
  </tr>`).join('');
  table.querySelector('tbody').innerHTML = html || '<tr><td colspan="9">No matches.</td></tr>';

  const countEl = document.getElementById('neverBorrowedSearchCount');
  if (countEl) countEl.textContent = needle ? `${rows.length} of ${d.rows.length}` : `${d.rows.length} customers`;
}
renderNeverBorrowedDetailTable('');
document.getElementById('neverBorrowedSearch').addEventListener('input', e => renderNeverBorrowedDetailTable(e.target.value));

function renderClosedLoans() {
  const d = closedLoansMonthly;
  if (!d) return;
  const periods = Object.keys(d.periods).sort();
  const table = document.getElementById('closedLoansTable');

  const periodCells = periods.map(p => `<td colspan="2">${monthLabel(p)}</td>`).join('');
  const subCells = periods.map(() => `<td>Qty</td><td>Amount</td>`).join('');
  let html = `<tr class="rpt-title"><td colspan="${1 + periods.length * 2 + 2}">Closed Loans &mdash; Paid Off vs. Written Off</td></tr>`;
  html += `<tr class="rpt-colhead"><td>Status</td>${periodCells}<td colspan="2" class="sm-total-col">Total</td></tr>`;
  html += `<tr class="rpt-colhead"><td></td>${subCells}<td class="sm-total-col">Qty</td><td>Amount</td></tr>`;

  const paidCells = periods.map(p => `<td>${fmt(d.periods[p].paidOffN)}</td><td>${fmt(d.periods[p].paidOffAmount)}</td>`).join('');
  html += `<tr class="sm-row"><td>Paid Off</td>${paidCells}<td class="sm-total-col">${fmt(d.total.paidOffN)}</td><td>${fmt(d.total.paidOffAmount)}</td></tr>`;

  const writtenCells = periods.map(p => `<td>${fmt(d.periods[p].writtenOffN)}</td><td>${fmt(d.periods[p].writtenOffDebt)}</td>`).join('');
  html += `<tr class="sm-row"><td>Written Off</td>${writtenCells}<td class="sm-total-col">${fmt(d.total.writtenOffN)}</td><td>${fmt(d.total.writtenOffDebt)}</td></tr>`;

  table.querySelector('tbody').innerHTML = html;
}
renderClosedLoans();
const closedLoansScrollUpdate = setupTopScrollSync('closedLoansScrollTop', 'closedLoansScrollBody');

function renderDelinquency() {
  const d = delinquencyAnalysis;
  if (!d) return;
  const order = ['Current', '1-30', '31-60', '61-90', '90+'];
  const rowsHtml = order.map(bucket => {
    const b = d.buckets[bucket];
    const share = d.total.debt > 0 ? b.debt / d.total.debt : 0;
    return `<tr><td>${bucket === 'Current' ? 'Current (not overdue)' : bucket + ' days'}</td><td>${fmt(b.n)}</td><td>${fmt(b.debt)}</td><td>${fmt(b.penalty)}</td><td>${pct(share)}</td></tr>`;
  }).join('');
  const html = `<tr><th>Days Overdue</th><th>Loans</th><th>Debt (GEL)</th><th>Penalty (GEL)</th><th>Share of Debt</th></tr>`
    + rowsHtml
    + `<tr class="total"><td>Total</td><td>${fmt(d.total.n)}</td><td>${fmt(d.total.debt)}</td><td></td><td>100.0%</td></tr>`;
  document.getElementById('delinquencyTable').querySelector('tbody').innerHTML = html;

  document.getElementById('delinquencyParNote').innerHTML =
    `<strong>Portfolio at Risk:</strong> PAR30 = ${pct(d.par30)} &middot; PAR60 = ${pct(d.par60)} &middot; PAR90 = ${pct(d.par90)} of total outstanding debt.`;
}
renderDelinquency();

// Hand-translated English labels for the free-text Georgian Reason values (no English field
// exists in the database) — shown as a second column alongside the original. Falls back to the
// Georgian text itself for any value not in this list, rather than showing a blank cell.
const reasonLabelEn = {
  'შეუსაბამო მონაცემები': 'Inconsistent Data',
  'მოვალეთა რეესტრი': "Debtors' Registry",
  'კლიენტის უარი': 'Client Refused',
  'დასაფარია მიმდინარე': 'Existing Debt to Settle',
  'გადახდისუუნარო': 'Insolvent',
  'დუბლირებული განაცხადი': 'Duplicate Application',
  'ხიშნიკი': 'Fraud/Scam',
  'ხიშნიკი (NO SMS)': 'Fraud/Scam (No SMS)',
  'ვერ ვუკავშირდები': 'Unable to Reach',
  'პროდუქციის არ ქონა': 'Product Unavailable',
  'სხვა': 'Other',
  'აღარ არის დაინტერესებული': 'No Longer Interested',
  'მაღალი ფასი': 'Price Too High',
  'ავანსი': 'Down Payment Issue',
  'მიტანის საფასური': 'Delivery Fee',
  'ხანდაზმული განაცხადი': 'Expired Application',
  'არ პასუხობს': 'Not Responding',
  'Unspecified': 'Unspecified',
};

function renderReasonsPivotTable(d, tableId, title, emptyMessage) {
  if (!d) return;
  const table = document.getElementById(tableId);
  if (d.rows.length === 0) {
    table.querySelector('tbody').innerHTML =
      `<tr><th>${title}</th></tr><tr><td>${emptyMessage || 'No data for this period.'}</td></tr>`;
    return;
  }
  const periods = d.periods;

  const totalsByPeriod = {};
  periods.forEach(p => { totalsByPeriod[p] = d.rows.reduce((s, r) => s + r.byPeriod[p], 0); });

  const periodHeadCells = periods.map(p => `<td colspan="2">${monthLabel(p)}</td>`).join('');
  const periodSubCells = periods.map(() => `<td>Qty</td><td>%</td>`).join('');
  let html = `<tr class="rpt-title"><td colspan="${periods.length * 2 + 4}">${title} &mdash; by Month</td></tr>`;
  html += `<tr class="rpt-colhead"><td>Reason</td><td>English</td>${periodHeadCells}<td colspan="2" class="sm-total-col">Total</td></tr>`;
  html += `<tr class="rpt-colhead"><td></td><td></td>${periodSubCells}<td class="sm-total-col">Qty</td><td>Share</td></tr>`;

  d.rows.forEach(row => {
    const rowCls = row.reason === 'Unspecified' ? 'sm-row sm-uncategorized' : 'sm-row';
    const cells = periods.map(p => {
      const n = row.byPeriod[p];
      const monthShare = totalsByPeriod[p] > 0 ? n / totalsByPeriod[p] : 0;
      return `<td>${fmt(n)}</td><td>${pct(monthShare)}</td>`;
    }).join('');
    const english = reasonLabelEn[row.reason] || row.reason;
    html += `<tr class="${rowCls}"><td>${row.reason}</td><td>${english}</td>${cells}<td class="sm-total-col">${fmt(row.total)}</td><td>${pct(row.share)}</td></tr>`;
  });

  const grandCells = periods.map(p => `<td>${fmt(totalsByPeriod[p])}</td><td>100.0%</td>`).join('');
  html += `<tr class="sm-grandtotal"><td>TOTAL (all reasons)</td><td></td>${grandCells}<td class="sm-total-col">${fmt(d.grandTotal)}</td><td>100.0%</td></tr>`;

  table.querySelector('tbody').innerHTML = html;
}
renderReasonsPivotTable(rejectionReasonsMonthly, 'rejectionReasonsTable', 'Rejected');
renderReasonsPivotTable(clientRefusedReasonsMonthly, 'clientRefusedReasonsTable', 'Client Refused');
renderReasonsPivotTable(expiredReasonsMonthly, 'expiredReasonsTable', 'Expired');
renderReasonsPivotTable(notRespondingReasonsMonthly, 'notRespondingReasonsTable', 'Not Responding');
renderReasonsPivotTable(approvedReasonsMonthly, 'approvedReasonsTable', 'Approved');
const rejectionReasonsScrollUpdate = setupTopScrollSync('rejectionReasonsScrollTop', 'rejectionReasonsScrollBody');
const clientRefusedReasonsScrollUpdate = setupTopScrollSync('clientRefusedReasonsScrollTop', 'clientRefusedReasonsScrollBody');
const expiredReasonsScrollUpdate = setupTopScrollSync('expiredReasonsScrollTop', 'expiredReasonsScrollBody');
const notRespondingReasonsScrollUpdate = setupTopScrollSync('notRespondingReasonsScrollTop', 'notRespondingReasonsScrollBody');
const approvedReasonsScrollUpdate = setupTopScrollSync('approvedReasonsScrollTop', 'approvedReasonsScrollBody');

/* ---------- Application Statuses: same pivot idiom as renderReasonsPivotTable() above (one
   row per bucket, Qty+% per month, Total Qty+Share), minus the "English" translation column —
   most status labels here are already the production label shown in the admin panel, and no
   per-status English mapping was asked for. */
function renderStatusPivotTable(d, tableId, title) {
  if (!d) return;
  const table = document.getElementById(tableId);
  if (d.rows.length === 0) {
    table.querySelector('tbody').innerHTML = `<tr><th>${title}</th></tr><tr><td>No data for this period.</td></tr>`;
    return;
  }
  const periods = d.periods;
  const totalsByPeriod = {};
  periods.forEach(p => { totalsByPeriod[p] = d.rows.reduce((s, r) => s + r.byPeriod[p], 0); });

  const periodHeadCells = periods.map(p => `<td colspan="2">${monthLabel(p)}</td>`).join('');
  const periodSubCells = periods.map(() => `<td>Qty</td><td>%</td>`).join('');
  let html = `<tr class="rpt-title"><td colspan="${periods.length * 2 + 3}">${title} &mdash; by Month</td></tr>`;
  html += `<tr class="rpt-colhead"><td>Status</td>${periodHeadCells}<td colspan="2" class="sm-total-col">Total</td></tr>`;
  html += `<tr class="rpt-colhead"><td></td>${periodSubCells}<td class="sm-total-col">Qty</td><td>Share</td></tr>`;

  d.rows.forEach(row => {
    const rowCls = row.status === 'Unspecified' ? 'sm-row sm-uncategorized' : 'sm-row';
    const cells = periods.map(p => {
      const n = row.byPeriod[p];
      const monthShare = totalsByPeriod[p] > 0 ? n / totalsByPeriod[p] : 0;
      return `<td>${fmt(n)}</td><td>${pct(monthShare)}</td>`;
    }).join('');
    html += `<tr class="${rowCls}"><td>${row.status}</td>${cells}<td class="sm-total-col">${fmt(row.total)}</td><td>${pct(row.share)}</td></tr>`;
  });

  const grandCells = periods.map(p => `<td>${fmt(totalsByPeriod[p])}</td><td>100.0%</td>`).join('');
  html += `<tr class="sm-grandtotal"><td>TOTAL (all statuses)</td>${grandCells}<td class="sm-total-col">${fmt(d.grandTotal)}</td><td>100.0%</td></tr>`;

  table.querySelector('tbody').innerHTML = html;
}
renderStatusPivotTable(applicationStatusesMonthly, 'applicationStatusesTable', 'Application Statuses');
const applicationStatusesScrollUpdate = setupTopScrollSync('applicationStatusesScrollTop', 'applicationStatusesScrollBody');
renderStatusPivotTable(leadStatusesMonthly, 'leadsTable', 'Leads');
const leadsScrollUpdate = setupTopScrollSync('leadsScrollTop', 'leadsScrollBody');

/* ---------- Per-tab "as of" badge (top-right corner) — replaces one dashboard-wide Yesterday/MTD
   date with a label specific to whichever tab is currently open, since different tabs query
   genuinely different windows (per user request 2026-08-26: each tab should show its own dates,
   not one shared pair). Grounded in each tab's actual query window, not guessed:
   - report/mtdstats: Yesterday + MTD, exactly as before (these two tabs ARE that window).
   - dailystats: Jun 1 through yesterday (DailyStatistics' real window, see index.php $dailyFrom).
   - salesmonthly/brandanalyze/subcategoryanalyze/categorybrand/incomecategory/
     incomesubcategory/incomebrand/incomeproduct/closedloans/rejectionreasons: all share the same
     Jan 1 through yesterday window ($monthlyFrom in index.php).
   - logistics: manual/mixed-source snapshot with its own per-table dates already disclosed in
     each table's note text — no single accurate date to put in the corner badge.
   - customers/risksegmentation/delinquency (Overdue Analysis): point-in-time snapshots of the
     current active-loan book, not date-ranged at all (PortfolioRepository takes no from/to for
     these three). */
const windowedPages = new Set(['salesmonthly', 'brandanalyze', 'subcategoryanalyze', 'categorybrand',
  'incomecategory', 'incomesubcategory', 'incomebrand', 'incomeproduct', 'closedloans', 'rejectionreasons',
  'applicationstatuses', 'leads']);
function periodBadgeHtml(page) {
  if (page === 'report' || page === 'mtdstats') {
    return `Yesterday: <b>${headerYesterday}</b><br>MTD: <b>${headerMtdRange}</b>`;
  }
  if (page === 'dailystats') {
    return `Window: <b>Jun 1 &ndash; ${headerYesterday}</b>`;
  }
  if (windowedPages.has(page)) {
    return `Window: <b>Jan 1 &ndash; ${headerYesterday}</b>`;
  }
  if (page === 'logistics') {
    return `Manual snapshot &mdash; <b>see notes below</b>`;
  }
  if (page === 'excustomers') {
    return `<b>Live snapshot</b> &mdash; closed-loan history, all-time`;
  }
  return `<b>Live snapshot</b> &mdash; current loan book`;
}
function updatePeriodBadge(page) {
  document.getElementById('periodBadge').innerHTML = periodBadgeHtml(page);
}

document.querySelectorAll('#pageNav button').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('#pageNav button').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('.nav-group').forEach(g => g.classList.remove('active-group'));
    btn.closest('.nav-group').classList.add('active-group');
    const page = btn.getAttribute('data-page');
    document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
    document.getElementById('page-' + page).classList.add('active');
    updatePeriodBadge(page);
    // A hidden (display:none) table reports scrollWidth 0, so the top-scroll spacer only
    // gets its real width once the tab holding it is actually visible.
    if (page === 'mtdstats') mtdScrollUpdate();
    if (page === 'dailystats') dailyScrollUpdate();
    if (page === 'salesmonthly') salesMonthlyScrollUpdate();
    if (page === 'brandanalyze') brandScrollUpdate();
    if (page === 'subcategoryanalyze') subcategoryScrollUpdate();
    if (incomeDelinquencyScrollUpdaters[page]) incomeDelinquencyScrollUpdaters[page].forEach(fn => fn());
    if (page === 'logistics') { logisticsSalesScrollUpdate(); logisticsScrollUpdate(); }
    if (page === 'closedloans') closedLoansScrollUpdate();
    if (page === 'applicationstatuses') applicationStatusesScrollUpdate();
    if (page === 'leads') leadsScrollUpdate();
    if (page === 'rejectionreasons') {
      rejectionReasonsScrollUpdate();
      clientRefusedReasonsScrollUpdate();
      expiredReasonsScrollUpdate();
      notRespondingReasonsScrollUpdate();
      approvedReasonsScrollUpdate();
    }
  });
});
document.querySelector('.nav-group[data-group="dailymail"]').classList.add('active-group');
</script>
</body>
</html>
