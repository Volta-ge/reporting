// Adds the "Sales Analyze" nav group + 4 pages (Sales Monthly / Brand Analyze / Subcategory Analyze /
// Category-Brand) to deals_amount_migration.html, in the original Volta_Analytics markup, CSS and render
// code (ported from the original artifact verbatim, plus the "cogs unknown" handling the new DB needs).
// Idempotent: re-running replaces the injected data const; markup/CSS/JS are inserted only once.
const fs = require('fs');
const path = require('path');
const htmlPath = path.join(__dirname, 'deals_amount_migration.html');
let html = fs.readFileSync(htmlPath, 'utf8');
const salesJson = fs.readFileSync(path.join(__dirname, 'sales_data.json'), 'utf8').trim();

const must = (needle, what) => { if (!html.includes(needle)) throw new Error('anchor not found: ' + what); };

// ---------- 1. CSS (once) ----------
const CSS_MARK = '/* --- sales analyze (ported) --- */';
if (!html.includes(CSS_MARK)) {
  const css = `
${CSS_MARK}
.page-nav { display: inline-flex; background: var(--surface-1); border: 1px solid var(--border); border-radius: 8px; padding: 3px; gap: 2px; }
.page-nav button {
  border: none; background: transparent; color: var(--text-secondary); font: inherit; font-size: 13px; font-weight: 600;
  padding: 7px 16px; border-radius: 6px; cursor: pointer;
}
.page-nav button.active { background: var(--text-primary); color: var(--surface-1); }
table.sm-table col.sm-name { width: 190px; }
table.sm-table tr.sm-grandtotal td { background: var(--rpt-section-strong-bg); color: var(--rpt-ink); font-weight: 700; }
table.sm-table tr.sm-row:nth-child(even) td { background: var(--rpt-plain-bg); color: var(--rpt-ink); }
table.sm-table tr.sm-uncategorized td { font-style: italic; color: var(--rpt-muted); background: var(--rpt-plain-bg); }
table.sm-table td.sm-total-col { border-left: 2px solid var(--rpt-border); font-weight: 600; }
table.sm-table tr.rpt-colhead td.sm-total-col { border-left: 2px solid var(--rpt-border); font-weight: 700; }
`;
  must('.report-scroll-top > div { height: 1px; }', 'css end');
  html = html.replace('.report-scroll-top > div { height: 1px; }', '.report-scroll-top > div { height: 1px; }' + css);
}

// ---------- 2. Nav group (once) ----------
if (!html.includes('data-page="salesmonthly"')) {
  const navAnchor = `        <button data-page="dailystats">Daily Statistics</button>
      </div>
    </div>`;
  must(navAnchor, 'nav anchor');
  html = html.replace(navAnchor, navAnchor + `
    <div class="nav-group">
      <div class="nav-group-title">Sales Analyze</div>
      <div class="nav-group-items">
        <button data-page="salesmonthly">Sales Monthly</button>
        <button data-page="brandanalyze">Brand Analyze</button>
        <button data-page="subcategoryanalyze">Subcategory Analyze</button>
        <button data-page="categorybrand">Category / Brand</button>
        <button class="soon">Income/Delinq: Category, Subcategory, Brand, Product (მალე)</button>
      </div>
    </div>`);
}

// ---------- 3. Pages (once) ----------
if (!html.includes('id="page-salesmonthly"')) {
  const dealNav = id => `    <div class="page-nav" id="${id}" style="margin-bottom:-8px;">
      <button data-deal="all" class="active">ყველა</button>
      <button data-deal="installment">განვადება</button>
      <button data-deal="single">ერთიანი გადახდა</button>
    </div>`;
  const pages = `
<div class="page" data-page="salesmonthly" id="page-salesmonthly">
<div class="wrap">
  <p class="section-title">Sales Monthly &mdash; by product category</p>
  <p class="note">One column-group (Sales / Cogs / Margin / Qty) per calendar month of 2026, plus Q1 and Q2 quarterly summary groups (each with that row's share of the quarter's total Sales) and an overall Total group (share of the whole window). Installment sales use the "real sale" status (<code>Order_Status = 5</code> / <code>crm_order_status = 5</code>); single-payment sales use <code>Type_Of_Sales = 99</code> / <code>crm_order_status = 99</code>, keyed to the application date since they have no order date. Rows are sorted by total Sales, highest first. Sales = SUM(Final_Price), Cogs = SUM(Start_Price), both raw and unmodified &mdash; same convention the business's own report uses.</p>
  <div class="banner" id="salesHybridBanner"></div>
${dealNav('salesMonthlyDealTypeNav')}
  <div class="report-card">
    <div class="report-scroll-top" id="salesMonthlyScrollTop"><div></div></div>
    <div class="report-scroll" id="salesMonthlyScrollBody">
      <table class="rpt rpt-pivot sm-table" id="salesMonthlyTable"><tbody></tbody></table>
    </div>
  </div>
  <p class="note" id="salesMonthlyFooterNote"></p>
</div>
</div>

<div class="page" data-page="brandanalyze" id="page-brandanalyze">
<div class="wrap">
  <p class="section-title">Brand Analyze &mdash; by brand</p>
  <p class="note">Same layout as Sales Monthly, grouped by brand instead of product category. On the old DB the brand link is fully populated; on the new DB brand is a product attribute that is missing on part of the catalog, so the "No Brand" row grows from the cutover on (see the note below the table). Three "no real brand" spellings (<code>none</code>, <code>N/A</code>, <code>ბრენდის გარეშე</code>) are combined into one "No Brand" row at the bottom.</p>
${dealNav('brandDealTypeNav')}
  <div class="report-card">
    <div class="report-scroll-top" id="brandScrollTop"><div></div></div>
    <div class="report-scroll" id="brandScrollBody">
      <table class="rpt rpt-pivot sm-table" id="brandTable"><tbody></tbody></table>
    </div>
  </div>
  <p class="note" id="brandFooterNote"></p>
</div>
</div>

<div class="page" data-page="subcategoryanalyze" id="page-subcategoryanalyze">
<div class="wrap">
  <p class="section-title">Subcategory Analyze &mdash; by broader product group</p>
  <p class="note">Same layout as Sales Monthly, but one level broader &mdash; e.g. Air Fryer / Blender / Toaster (separate rows on Sales Monthly) all roll up into one "Small Kitchen Appliances" row here. Same mapping-sheet lookup as Sales Monthly, just reading its Subcategory(EN) field instead of Product(EN).</p>
${dealNav('subcategoryDealTypeNav')}
  <div class="report-card">
    <div class="report-scroll-top" id="subcategoryScrollTop"><div></div></div>
    <div class="report-scroll" id="subcategoryScrollBody">
      <table class="rpt rpt-pivot sm-table" id="subcategoryTable"><tbody></tbody></table>
    </div>
  </div>
  <p class="note" id="subcategoryFooterNote"></p>
</div>
</div>

<div class="page" data-page="categorybrand" id="page-categorybrand">
<div class="wrap">
  <p class="section-title">Category / Brand &mdash; brand breakdown within each category</p>
  <p class="note">One block per product category, brands broken down within it &mdash; same layout as the business's own reference report's "Top 4 &mdash; Brands" sheet, built here for every category found in the window instead of a hand-picked top 4. Q1/Q2 Sales are date-bounded quarterly columns; Total Sales/COGS/Margin/Qty are for the whole window (Jan 1&ndash;yesterday), matching the reference sheet's own formulas. Categories and brands within them are sorted by Total Sales, highest first (Uncategorized/No Brand always last).</p>
${dealNav('categoryBrandDealTypeNav')}
  <div class="report-card">
    <div class="report-scroll">
      <table class="rpt rpt-pivot sm-table" id="categoryBrandTable"><tbody></tbody></table>
    </div>
  </div>
</div>
</div>
`;
  const pagesAnchor = `</div><!-- /page dailystats -->`;
  if (!html.includes(pagesAnchor)) {
    // the dailystats page ends right before the <script>; mark it so the anchor is stable
    must(`      <table class="rpt rpt-pivot" id="dailyStatsTable"><tbody></tbody></table>
    </div>
  </div>
</div>
</div>

<script>`, 'dailystats page end');
    html = html.replace(`      <table class="rpt rpt-pivot" id="dailyStatsTable"><tbody></tbody></table>
    </div>
  </div>
</div>
</div>

<script>`, `      <table class="rpt rpt-pivot" id="dailyStatsTable"><tbody></tbody></table>
    </div>
  </div>
</div>
</div><!-- /page dailystats -->

<script>`);
  }
  html = html.replace(pagesAnchor, pagesAnchor + pages);
}

// ---------- 4. Data const (idempotent) ----------
const dataLine = 'const SALES_JSON = ' + salesJson + ';';
if (/^const SALES_JSON = .*;$/m.test(html)) html = html.replace(/^const SALES_JSON = .*;$/m, dataLine);
else {
  must('const generatedAt = REPORT_JSON.generatedAt;', 'report json consts');
  html = html.replace('const generatedAt = REPORT_JSON.generatedAt;', 'const generatedAt = REPORT_JSON.generatedAt;\n' + dataLine);
}

// ---------- 5. Render code (once), inserted before the page-nav handler ----------
const JS_MARK = '/* ---------- Sales Analyze (ported from Volta_Analytics) ---------- */';
if (!html.includes(JS_MARK)) {
  const js = String.raw`
${JS_MARK}
const salesMonthlyStats = SALES_JSON.salesMonthlyStats, brandStats = SALES_JSON.brandStats, subcategoryStats = SALES_JSON.subcategoryStats, categoryBrandBreakdown = SALES_JSON.categoryBrandBreakdown;
const SALES_CUTOVER = SALES_JSON.cutover;
document.getElementById('salesHybridBanner').innerHTML = '<b>წყარო:</b> ' + SALES_CUTOVER + '-მდე ძველი ბაზა (myvolta.info: <code>instalment_products</code>), ' + SALES_CUTOVER + '-დან ახალი ბაზა (VoltaStoreDB: <code>order_items</code>, კატეგორია ახალი კატალოგის კატეგორიების ხიდან, იმავე mapping-ცხრილით). <b>Cogs ახალ ბაზაში არ არსებობს</b> (თვითღირებულების ველი ყველა პროდუქტზე ცარიელია) — ამიტომ ' + SALES_CUTOVER + '-დან Cogs/Mrg ვერ ითვლება: სექტემბერი &ldquo;&ndash;&rdquo;-ს აჩვენებს, აგვისტოს Mrg კი მხოლოდ იმ გაყიდვებზეა დათვლილი, რომლებსაც Cogs აქვს. ბრენდის ატრიბუტიც ახალ კატალოგში ნაწილობრივაა შევსებული &mdash; &ldquo;No Brand&rdquo; ' + SALES_CUTOVER + '-დან იზრდება.';

function bucketedColHead(rowLabel, periods, q1Periods, q2Periods) {
  const cells = [];
  const subCells = [];
  const summaryHead = label => '<td colspan="5" class="sm-total-col">' + label + '</td>';
  const summarySub = () => '<td class="sm-total-col">Sales</td><td>Cogs</td><td>Mrg</td><td>Qty</td><td>Share</td>';
  periods.forEach(p => {
    cells.push('<td colspan="4">' + monthLabel(p) + '</td>');
    subCells.push('<td>Sales</td><td>Cogs</td><td>Mrg</td><td>Qty</td>');
    if (q1Periods.length && p === q1Periods[q1Periods.length - 1]) { cells.push(summaryHead('Q1 Total')); subCells.push(summarySub()); }
    if (q2Periods.length && p === q2Periods[q2Periods.length - 1]) { cells.push(summaryHead('Q2 Total')); subCells.push(summarySub()); }
  });
  cells.push(summaryHead('Total'));
  subCells.push(summarySub());
  return '<tr class="rpt-colhead"><td>' + rowLabel + '</td>' + cells.join('') + '</tr><tr class="rpt-colhead"><td></td>' + subCells.join('') + '</tr>';
}
// Cogs is unavailable for new-DB lines: a cell whose sales are entirely cogs-unknown shows "–" for Cogs and Mrg;
// a mixed cell (August) shows the known Cogs and a margin computed over the cogs-known part of its sales only.
function bucketedCells(cell, isSummary) {
  const unknown = cell.cogsUnknownSales || 0;
  const known = cell.sales - unknown;
  const allUnknown = cell.sales > 0 && known <= 0.005;
  const margin = known > 0 ? (known - cell.cogs) / known : null;
  const cls = isSummary ? ' sm-total-col' : '';
  const cogsTxt = allUnknown ? '&ndash;' : fmt(cell.cogs);
  const mrgTxt = (margin === null || allUnknown) ? '&ndash;' : (pct(margin) + (unknown > 0 ? '*' : ''));
  let html = '<td class="' + cls + '">' + fmt(cell.sales) + '</td><td>' + cogsTxt + '</td><td>' + mrgTxt + '</td><td>' + fmt(cell.qty) + '</td>';
  if (isSummary) html += '<td>' + pct(cell.share || 0) + '</td>';
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
  if (!stats || !stats.rows) { table.querySelector('tbody').innerHTML = '<tr><td>No data.</td></tr>'; return; }
  const { periods, q1Periods, q2Periods, rows, grandTotal, grandQ1, grandQ2, uncategorized, garbage, cogsUnknown } = stats;
  const summaryGroups = 1 + (q1Periods.length ? 1 : 0) + (q2Periods.length ? 1 : 0);
  const colspan = 1 + periods.length * 4 + summaryGroups * 5;

  let html = rptPivotPlainSpan('rpt-title', titleText, colspan);
  html += bucketedColHead(rowLabel, periods, q1Periods, q2Periods);

  html += '<tr class="sm-grandtotal"><td>TOTAL (all)</td>';
  html += bucketedRowCells({
    byPeriod: Object.fromEntries(periods.map(p => [p, rows.reduce((acc, r) => {
      const c = r.byPeriod[p]; acc.sales += c.sales; acc.cogs += c.cogs; acc.qty += c.qty; acc.cogsUnknownSales += (c.cogsUnknownSales || 0); return acc;
    }, { sales: 0, cogs: 0, qty: 0, cogsUnknownSales: 0 })])),
    q1: { ...grandQ1, share: 1 }, q2: { ...grandQ2, share: 1 }, total: { ...grandTotal, share: 1 },
  }, periods, q1Periods, q2Periods);
  html += '</tr>';

  rows.forEach(row => {
    const rowCls = row.bucket === fallbackBucket ? 'sm-row sm-uncategorized' : 'sm-row';
    html += '<tr class="' + rowCls + '"><td>' + row.bucket + '</td>' + bucketedRowCells(row, periods, q1Periods, q2Periods) + '</tr>';
  });
  table.querySelector('tbody').innerHTML = html;

  const note = document.getElementById(footerId);
  const uncatPct = grandTotal.sales > 0 ? (100 * uncategorized.sales / grandTotal.sales).toFixed(1) : '0';
  const garbagePct = garbage.total > 0 ? (100 * garbage.count / garbage.total).toFixed(1) : '0';
  const garbageSalesPct = grandTotal.sales > 0 ? (100 * garbage.salesAffected / grandTotal.sales).toFixed(1) : '0';
  const unknownPct = grandTotal.sales > 0 ? (100 * (cogsUnknown ? cogsUnknown.sales : 0) / grandTotal.sales).toFixed(1) : '0';
  note.innerHTML = fallbackNoteHtml
    .replace('{count}', fmt(uncategorized.count)).replace('{sales}', fmt(uncategorized.sales)).replace('{pct}', uncatPct)
    + ' ' + garbageNoteHtml
      .replace('{gcount}', fmt(garbage.count)).replace('{gtotal}', fmt(garbage.total)).replace('{gpct}', garbagePct)
      .replace('{gsales}', fmt(garbage.salesAffected)).replace('{gsalespct}', garbageSalesPct)
    + (cogsUnknown && cogsUnknown.count ? ' <strong>Cogs unavailable (new DB):</strong> ' + fmt(cogsUnknown.count) + ' line items / ' + fmt(cogsUnknown.sales) + ' GEL (' + unknownPct + '% of sales) sold from ' + SALES_CUTOVER + ' on have no cost recorded in VoltaStoreDB, so their Cogs is excluded and any Mrg marked * is computed over the cogs-known part of that cell only.' : '');
}

const GARBAGE_NOTE = '<strong>Cogs data quality:</strong> {gcount} of {gtotal} line items ({gpct}%, {gsales} GEL / {gsalespct}% of sales) have a placeholder or clearly-wrong Cogs value (staff enters "1" because the system requires some value, then returns later with the real cost) &mdash; the Cogs column above is left raw/unmodified to match the business\'s own report exactly, so months with more unfilled Cogs will show an inflated Margin until those get backfilled.';

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
    '<strong>Uncategorized:</strong> {count} line items / {sales} GEL ({pct}% of total sales) have no usable product category (on the old DB recently-added products often sit under a category literally named "none"; on the new DB the product\'s catalog categories did not match the mapping sheet) &mdash; grouped into one row at the bottom rather than guessed at.',
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
    '<strong>No Brand:</strong> {count} line items / {sales} GEL ({pct}% of total sales) had no real brand recorded (combines "none" / "N/A" / "ბრენდის გარეშე" and, from ' + SALES_CUTOVER + ' on, products with no brand attribute in the new catalog, into one row) &mdash; grouped at the bottom rather than guessed at.',
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
    '<strong>Uncategorized:</strong> {count} line items / {sales} GEL ({pct}% of total sales) have no usable product category &mdash; same gap as Sales Monthly, grouped into one row at the bottom rather than guessed at.',
    GARBAGE_NOTE);
  subcategoryScrollUpdate();
}
renderSubcategoryForDealType();
wireDealTypeFilter('subcategoryDealTypeNav', dt => { subcategoryDealType = dt; renderSubcategoryForDealType(); });

function renderCategoryBrandTable(d) {
  const table = document.getElementById('categoryBrandTable');
  if (!d || !d.categories) { table.querySelector('tbody').innerHTML = '<tr><td>No data.</td></tr>'; return; }
  const marginCell = (m, unknown) => m === null ? '&ndash;' : (pct(m) + (unknown > 0 ? '*' : ''));
  const cogsCell = t => (t.sales > 0 && t.sales - (t.cogsUnknownSales || 0) <= 0.005) ? '&ndash;' : fmt(t.cogs);
  let html = '';
  d.categories.forEach(cat => {
    const isFallback = cat.category === 'Uncategorized';
    html += '<tr class="rpt-title"><td colspan="8">' + cat.category + '</td></tr>';
    html += '<tr class="rpt-colhead"><td>Brand</td><td>Q1 Sales</td><td>Q2 Sales</td><td>Total Sales</td><td>Share %</td><td>COGS</td><td>PR Mrg %</td><td>Q-ty</td></tr>';
    cat.brands.forEach(b => {
      const rowCls = (isFallback || b.brand === 'No Brand') ? 'sm-row sm-uncategorized' : 'sm-row';
      html += '<tr class="' + rowCls + '"><td>' + b.brand + '</td><td>' + fmt(b.q1.sales) + '</td><td>' + fmt(b.q2.sales) + '</td><td>' + fmt(b.total.sales) + '</td><td>' + pct(b.share) + '</td><td>' + cogsCell(b.total) + '</td><td>' + marginCell(b.margin, b.total.cogsUnknownSales) + '</td><td>' + fmt(b.total.qty) + '</td></tr>';
    });
    html += '<tr class="sm-grandtotal"><td>Total</td><td></td><td></td><td>' + fmt(cat.total.sales) + '</td><td>100.0%</td><td>' + cogsCell(cat.total) + '</td><td>' + marginCell(cat.margin, cat.total.cogsUnknownSales) + '</td><td>' + fmt(cat.total.qty) + '</td></tr>';
  });
  table.querySelector('tbody').innerHTML = html;
}
let categoryBrandDealType = 'all';
function renderCategoryBrandForDealType() { renderCategoryBrandTable(categoryBrandBreakdown ? categoryBrandBreakdown[categoryBrandDealType] : null); }
renderCategoryBrandForDealType();
wireDealTypeFilter('categoryBrandDealTypeNav', dt => { categoryBrandDealType = dt; renderCategoryBrandForDealType(); });
`;
  must('// ---- top-level page nav (grows as more reports get added) ----', 'nav handler');
  html = html.replace('// ---- top-level page nav (grows as more reports get added) ----', js + '\n// ---- top-level page nav (grows as more reports get added) ----');
  // re-measure the wide tables' scroll spacers when their tab becomes visible
  must("  if (btn.dataset.page === 'dailystats') dailyScrollUpdate();", 'nav handler updaters');
  html = html.replace("  if (btn.dataset.page === 'dailystats') dailyScrollUpdate();",
    "  if (btn.dataset.page === 'dailystats') dailyScrollUpdate();\n  if (btn.dataset.page === 'salesmonthly') salesMonthlyScrollUpdate();\n  if (btn.dataset.page === 'brandanalyze') brandScrollUpdate();\n  if (btn.dataset.page === 'subcategoryanalyze') subcategoryScrollUpdate();");
}

fs.writeFileSync(htmlPath, html);
const m = html.match(/<script>([\s\S]*)<\/script>/);
fs.writeFileSync(path.join(__dirname, 'extracted.js'), m[1]);
console.log('patched; html bytes:', html.length);
