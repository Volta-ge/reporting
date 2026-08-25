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
 * @var array<string, array{count: int, capturedAt: string}> $pendingStatusLog
 * @var array|null $customerAnalysis
 * @var array|null $riskSegmentation
 * @var array|null $closedLoansMonthly
 * @var array|null $delinquencyAnalysis
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
    <div class="period">Yesterday: <b><?= htmlspecialchars($headerYesterday, ENT_QUOTES, 'UTF-8') ?></b><br>MTD: <b><?= htmlspecialchars($headerMtdRange, ENT_QUOTES, 'UTF-8') ?></b></div>
  </div>

  <div class="page-nav" id="pageNav">
    <button data-page="report" class="active">Daily Report</button>
    <button data-page="mtdstats">MTD Statistics</button>
    <button data-page="dailystats">Daily Statistics</button>
    <button data-page="salesmonthly">Sales Monthly</button>
    <button data-page="brandanalyze">Brand Analyze</button>
    <button data-page="subcategoryanalyze">Subcategory Analyze</button>
    <button data-page="logistics">Logistics Daily</button>
    <button data-page="customers">Customers</button>
    <button data-page="risksegmentation">Risk Segmentation</button>
    <button data-page="closedloans">Closed Loans</button>
    <button data-page="delinquency">Overdue Analysis</button>
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
    <p class="note" style="margin:-8px 0 0;">One column-group (Sales / Cogs / Margin / Qty) per calendar month of <?= htmlspecialchars($now->format('Y'), ENT_QUOTES, 'UTF-8') ?>, plus Q1 and Q2 quarterly summary groups (each with that row's share of the quarter's total Sales) and an overall Total group (share of the whole window). Live from the database on every page load (not a manual snapshot) &mdash; Order_Status = 5 is used as the "real sale" filter (matches the business's own report exactly for every category+month checked). Rows are sorted by total Sales, highest first. Sales = SUM(Final_Price), Cogs = SUM(Start_Price), both raw and unmodified &mdash; same convention the business's own report uses.</p>
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
    <div class="report-card">
      <div class="report-scroll-top" id="subcategoryScrollTop"><div></div></div>
      <div class="report-scroll" id="subcategoryScrollBody">
        <table class="rpt rpt-pivot sm-table" id="subcategoryTable"><tbody></tbody></table>
      </div>
    </div>
    <p class="note" id="subcategoryFooterNote"></p>
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

<script>
const data = <?= json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const targets = <?= json_encode($targets, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const generatedAt = <?= json_encode($generatedAt, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const monthlyStats = <?= json_encode($monthlyStats, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const dailyStats = <?= json_encode($dailyStats, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const salesMonthlyStats = <?= json_encode($salesMonthlyStats, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const brandStats = <?= json_encode($brandStats, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const subcategoryStats = <?= json_encode($subcategoryStats, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const pendingStatusLog = <?= json_encode($pendingStatusLog, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const customerAnalysis = <?= json_encode($customerAnalysis, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const riskSegmentation = <?= json_encode($riskSegmentation, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const closedLoansMonthly = <?= json_encode($closedLoansMonthly, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const delinquencyAnalysis = <?= json_encode($delinquencyAnalysis, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;

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

renderBucketedTable('salesMonthlyTable', 'salesMonthlyFooterNote', salesMonthlyStats,
  'Sales Monthly &mdash; by Product Category', 'Product Category', 'Uncategorized',
  `<strong>Uncategorized:</strong> {count} line items / {sales} GEL ({pct}% of total sales) have no usable product category in the database (recently-added products often sit under a category literally named "none" until someone categorizes them) &mdash; grouped into one row at the bottom rather than guessed at.`,
  GARBAGE_NOTE);
const salesMonthlyScrollUpdate = setupTopScrollSync('salesMonthlyScrollTop', 'salesMonthlyScrollBody');

renderBucketedTable('brandTable', 'brandFooterNote', brandStats,
  'Brand Analyze &mdash; by Brand', 'Brand', 'No Brand',
  `<strong>No Brand:</strong> {count} line items / {sales} GEL ({pct}% of total sales) had no real brand recorded (combines "none" / "N/A" / "ბრენდის გარეშე" into one row) &mdash; grouped at the bottom rather than guessed at.`,
  GARBAGE_NOTE);
const brandScrollUpdate = setupTopScrollSync('brandScrollTop', 'brandScrollBody');

renderBucketedTable('subcategoryTable', 'subcategoryFooterNote', subcategoryStats,
  'Subcategory Analyze &mdash; by Broader Product Group', 'Subcategory', 'Uncategorized',
  `<strong>Uncategorized:</strong> {count} line items / {sales} GEL ({pct}% of total sales) have no usable product category in the database &mdash; same gap as Sales Monthly, grouped into one row at the bottom rather than guessed at.`,
  GARBAGE_NOTE);
const subcategoryScrollUpdate = setupTopScrollSync('subcategoryScrollTop', 'subcategoryScrollBody');

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
    if (page === 'salesmonthly') salesMonthlyScrollUpdate();
    if (page === 'brandanalyze') brandScrollUpdate();
    if (page === 'subcategoryanalyze') subcategoryScrollUpdate();
    if (page === 'logistics') { logisticsSalesScrollUpdate(); logisticsScrollUpdate(); }
    if (page === 'closedloans') closedLoansScrollUpdate();
  });
});
</script>
</body>
</html>
