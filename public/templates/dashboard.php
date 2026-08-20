<?php
/**
 * @var array{mtd: array, yest: array}|null $data
 * @var string|null $connectionError
 * @var array{applications: int, amount: int, workingDaysLeft: int} $targets
 * @var string $headerYesterday
 * @var string $headerMtdRange
 * @var string $generatedDate
 * @var string $generatedAt
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

/* KPI row */
.kpi-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; }
.kpi {
  background: var(--surface-1); border: 1px solid var(--border); border-radius: 10px;
  padding: 14px 16px; display: flex; flex-direction: column; gap: 4px;
}
.kpi .label { font-size: 11.5px; color: var(--text-secondary); }
.kpi .value { font-size: 24px; font-weight: 650; font-variant-numeric: proportional-nums; }
.kpi .delta { font-size: 11.5px; color: var(--text-muted); display: flex; gap: 6px; }
.kpi .delta b { color: var(--text-secondary); font-weight: 600; }

/* period toggle */
.toggle { display: inline-flex; background: var(--surface-1); border: 1px solid var(--border); border-radius: 8px; padding: 3px; gap: 2px; }
.toggle button {
  border: none; background: transparent; color: var(--text-secondary); font: inherit; font-size: 12.5px; font-weight: 600;
  padding: 6px 14px; border-radius: 6px; cursor: pointer;
}
.toggle button.active { background: var(--text-primary); color: var(--surface-1); }

/* funnel cards */
.funnels { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media (max-width: 760px) { .funnels { grid-template-columns: 1fr; } }
.funnel-card { background: var(--surface-1); border: 1px solid var(--border); border-radius: 12px; padding: 18px 20px; }
.funnel-card .fc-head { display: flex; align-items: center; gap: 8px; margin-bottom: 4px; }
.funnel-card .fc-dot { width: 10px; height: 10px; border-radius: 3px; flex: none; }
.funnel-card h3 { font-size: 14.5px; font-weight: 650; margin: 0; }
.funnel-card .fc-desc { font-size: 12px; color: var(--text-muted); margin: 0 0 16px; }
.stage { margin-bottom: 12px; }
.stage:last-child { margin-bottom: 0; }
.stage-top { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 5px; }
.stage-name { font-size: 12.5px; color: var(--text-secondary); }
.stage-val { font-size: 14px; font-weight: 650; font-variant-numeric: tabular-nums; }
.stage-bar-track { height: 20px; background: var(--grid); border-radius: 4px; overflow: hidden; }
.stage-bar-fill { height: 100%; border-radius: 4px 0 0 4px; }
.stage-conv { font-size: 11px; color: var(--text-muted); margin-top: 4px; text-align: right; }

/* pacing */
.pacing { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media (max-width: 760px) { .pacing { grid-template-columns: 1fr; } }
.meter-card { background: var(--surface-1); border: 1px solid var(--border); border-radius: 12px; padding: 18px 20px; }
.meter-card .mc-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; gap: 10px; }
.meter-card h3 { font-size: 14.5px; font-weight: 650; margin: 0 0 2px; }
.meter-card .mc-desc { font-size: 12px; color: var(--text-muted); margin: 0; }
.status-pill { display: inline-flex; align-items: center; gap: 5px; font-size: 11.5px; font-weight: 650; padding: 3px 9px; border-radius: 999px; white-space: nowrap; }
.status-pill.good { color: var(--good); background: color-mix(in srgb, var(--good) 14%, transparent); }
.status-pill.warning { color: #9a6a00; background: color-mix(in srgb, var(--warning) 22%, transparent); }
:root[data-theme="dark"] .status-pill.warning { color: var(--warning); }
@media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) .status-pill.warning { color: var(--warning); } }
.status-pill.critical { color: var(--critical); background: color-mix(in srgb, var(--critical) 14%, transparent); }
.meter-track { height: 12px; background: var(--grid); border-radius: 999px; overflow: hidden; margin-bottom: 8px; }
.meter-fill { height: 100%; border-radius: 999px; }
.meter-nums { display: flex; justify-content: space-between; font-size: 12.5px; }
.meter-nums .actual { font-weight: 650; font-variant-numeric: tabular-nums; }
.meter-nums .target { color: var(--text-muted); font-variant-numeric: tabular-nums; }
.mc-foot { margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--grid); display: flex; gap: 18px; flex-wrap: wrap; }
.mc-foot .f-item .f-label { font-size: 11px; color: var(--text-muted); }
.mc-foot .f-item .f-val { font-size: 13.5px; font-weight: 650; font-variant-numeric: tabular-nums; }

/* table */
.table-card { background: var(--surface-1); border: 1px solid var(--border); border-radius: 12px; padding: 18px 20px; overflow-x: auto; }
.table-card table { width: 100%; border-collapse: collapse; font-size: 12.5px; min-width: 640px; }
.table-card th, .table-card td { text-align: right; padding: 7px 10px; border-bottom: 1px solid var(--grid); font-variant-numeric: tabular-nums; white-space: nowrap; }
.table-card th:first-child, .table-card td:first-child { text-align: left; font-variant-numeric: normal; }
.table-card th { color: var(--text-secondary); font-weight: 650; font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.03em; }
.table-card tbody tr:hover { background: color-mix(in srgb, var(--text-primary) 4%, transparent); }
.seg-label { display: inline-flex; align-items: center; gap: 6px; }
.seg-dot { width: 8px; height: 8px; border-radius: 2px; flex: none; }
tr.total td { font-weight: 650; border-top: 2px solid var(--baseline); border-bottom: none; }

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
    <button data-page="report" class="active">Report</button>
    <button data-page="dashboard">Dashboard</button>
  </div>

  <div class="page" id="page-dashboard">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
      <p class="section-title">Overview</p>
      <div class="toggle" id="periodToggle">
        <button data-period="mtd" class="active">MTD</button>
        <button data-period="yest">Yesterday</button>
      </div>
    </div>

    <div class="kpi-row" id="kpiRow"></div>

    <p class="section-title">Funnel by segment</p>
    <div class="funnels" id="funnelRow"></div>

    <p class="section-title">Budget &amp; pacing (MTD actual vs. monthly target)</p>
    <div class="pacing" id="pacingRow"></div>

    <p class="section-title">Detail</p>
    <div class="table-card">
      <table id="detailTable"></table>
    </div>

    <p class="note">Segment A = TV, Phone, or any single product with full price &gt; 2,500 GEL (requires downpayment). Segment B = all other products (standard terms). Applications exclude unassigned "lead" placeholder rows. Underwriting Approved and Deals Closed reflect current status as of report generation (<?= htmlspecialchars($generatedDate, ENT_QUOTES, 'UTF-8') ?>), not a same-day cohort, and are keyed to Application Date. Amount Sold is keyed to Order Date (the disbursement/issue date) instead, so a loan applied for on one day but issued the next counts toward the day it was issued. Downpayment Collected counts every first payment received in the period regardless of whether the deal later went active, was rejected, or is still pending &mdash; collected money is not refunded. Budget &amp; pacing monthly targets are carried over from config.php &mdash; confirm with the team; Remaining Working Days is a live calendar calculation, not fixed.</p>
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
</div>

<script>
const data = <?= json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const targets = <?= json_encode($targets, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const generatedAt = <?= json_encode($generatedAt, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

const fmt = n => Math.round(n).toLocaleString('en-US');
const fmt1 = n => n.toLocaleString('en-US', { maximumFractionDigits: 1 });
const pct = n => (n*100).toLocaleString('en-US', { maximumFractionDigits: 1 }) + '%';

function totalOf(period, key) {
  const d = data[period];
  return d.A[key] + d.B[key];
}

function renderKPIs(period) {
  const apps = totalOf(period, 'applications');
  const closed = totalOf(period, 'closed');
  const amount = totalOf(period, 'amount');
  const dp = totalOf(period, 'dp');
  const convRate = apps ? closed / apps : 0;
  document.getElementById('kpiRow').innerHTML = `
    <div class="kpi"><div class="label">Applications</div><div class="value">${fmt(apps)}</div><div class="delta">A <b>${fmt(data[period].A.applications)}</b> &middot; B <b>${fmt(data[period].B.applications)}</b></div></div>
    <div class="kpi"><div class="label">Deals Closed</div><div class="value">${fmt(closed)}</div><div class="delta">${pct(convRate)} of applications</div></div>
    <div class="kpi"><div class="label">Amount Sold (GEL)</div><div class="value">${fmt(amount)}</div><div class="delta">A <b>${fmt(data[period].A.amount)}</b> &middot; B <b>${fmt(data[period].B.amount)}</b></div></div>
    <div class="kpi"><div class="label">Downpayment Collected (GEL)</div><div class="value">${fmt(dp)}</div><div class="delta">${amount ? pct(dp/amount) : '0%'} of amount sold</div></div>
  `;
}

function funnelCard(segName, segKey, color, colorSoft, desc, seg) {
  const stages = [
    { name: 'Applications', val: seg.applications, base: seg.applications },
    { name: 'Terms Approved by Customer', val: seg.terms, base: seg.applications },
    { name: 'Underwriting Approved', val: seg.uw, base: seg.applications },
    { name: 'Deals Closed', val: seg.closed, base: seg.applications },
  ];
  let stagesHTML = '';
  stages.forEach((s, i) => {
    const width = seg.applications ? Math.max((s.val / seg.applications) * 100, s.val > 0 ? 2 : 0) : 0;
    let convNote = '';
    if (i > 0) {
      const prev = stages[i-1].val;
      const stepConv = prev ? s.val / prev : 0;
      convNote = `<div class="stage-conv">${pct(stepConv)} of "${stages[i-1].name}"</div>`;
    }
    stagesHTML += `
      <div class="stage">
        <div class="stage-top"><span class="stage-name">${s.name}</span><span class="stage-val">${fmt(s.val)}</span></div>
        <div class="stage-bar-track"><div class="stage-bar-fill" style="width:${width}%; background:${color}"></div></div>
        ${convNote}
      </div>`;
  });
  return `
    <div class="funnel-card">
      <div class="fc-head"><span class="fc-dot" style="background:${color}"></span><h3>Segment ${segKey} &mdash; ${segName}</h3></div>
      <p class="fc-desc">${desc}</p>
      ${stagesHTML}
    </div>`;
}

function renderFunnels(period) {
  const d = data[period];
  document.getElementById('funnelRow').innerHTML =
    funnelCard('High-Downpayment', 'A', 'var(--series-a)', 'var(--series-a-soft)', 'TV, Phone, or a single product priced over 2,500 GEL', d.A) +
    funnelCard('Standard', 'B', 'var(--series-b)', 'var(--series-b-soft)', 'All other products &mdash; standard terms, no product-driven downpayment', d.B);
}

function meterCard(title, desc, actual, target, unit, footItems) {
  const ratio = target ? actual / target : 0;
  let status = 'good', statusLabel = 'On pace';
  if (ratio < 0.5) { status = 'critical'; statusLabel = 'Behind pace'; }
  else if (ratio < 0.85) { status = 'warning'; statusLabel = 'Watch'; }
  const fillColor = status === 'good' ? 'var(--good)' : status === 'warning' ? 'var(--warning)' : 'var(--critical)';
  const fillWidth = Math.min(ratio, 1) * 100;
  let footHTML = footItems.map(f => `<div class="f-item"><div class="f-label">${f.label}</div><div class="f-val">${f.val}</div></div>`).join('');
  return `
    <div class="meter-card">
      <div class="mc-top">
        <div><h3>${title}</h3><p class="mc-desc">${desc}</p></div>
        <span class="status-pill ${status}">${statusLabel}</span>
      </div>
      <div class="meter-track"><div class="meter-fill" style="width:${fillWidth}%; background:${fillColor}"></div></div>
      <div class="meter-nums"><span class="actual">${unit}${fmt(actual)}</span><span class="target">of ${unit}${fmt(target)} target &middot; ${pct(ratio)}</span></div>
      <div class="mc-foot">${footHTML}</div>
    </div>`;
}

function renderPacing() {
  const apps = totalOf('mtd', 'applications');
  const amount = totalOf('mtd', 'amount');
  const remaining = targets.amount - amount;
  const requiredDaily = targets.workingDaysLeft ? remaining / targets.workingDaysLeft : 0;

  document.getElementById('pacingRow').innerHTML =
    meterCard('Applications (MTD vs. target)', 'Actual applications this month vs. the monthly target', apps, targets.applications, '', [
      { label: 'Remaining', val: fmt(Math.max(targets.applications - apps, 0)) },
    ]) +
    meterCard('Amount Sold (MTD vs. target)', 'Actual GEL disbursed this month vs. the monthly target', amount, targets.amount, 'GEL ', [
      { label: 'Remaining to target', val: fmt(remaining) + ' GEL' },
      { label: 'Required daily sales', val: fmt(requiredDaily) + ' GEL' },
      { label: 'Working days left', val: targets.workingDaysLeft },
    ]);
}

function renderTable() {
  const rows = [
    ['A', 'Applications', 'applications'], ['A', 'Terms Approved', 'terms'], ['A', 'UW Approved', 'uw'], ['A', 'Deals Closed', 'closed'], ['A', 'Amount Sold (GEL)', 'amount'], ['A', 'DP Collected (GEL)', 'dp'],
    ['B', 'Applications', 'applications'], ['B', 'Terms Approved', 'terms'], ['B', 'UW Approved', 'uw'], ['B', 'Deals Closed', 'closed'], ['B', 'Amount Sold (GEL)', 'amount'], ['B', 'DP Collected (GEL)', 'dp'],
  ];
  const colorFor = seg => seg === 'A' ? 'var(--series-a)' : 'var(--series-b)';
  let thead = '<thead><tr><th>Metric</th><th>Yesterday</th><th>MTD</th></tr></thead>';
  let tbody = '<tbody>' + rows.map(([seg, name, key]) => {
    const yv = data.yest[seg][key], mv = data.mtd[seg][key];
    return `<tr><td><span class="seg-label"><span class="seg-dot" style="background:${colorFor(seg)}"></span>${seg} &middot; ${name}</span></td><td>${fmt(yv)}</td><td>${fmt(mv)}</td></tr>`;
  }).join('') + '</tbody>';
  let tfoot = `<tfoot>
    <tr class="total"><td>Total Applications</td><td>${fmt(totalOf('yest','applications'))}</td><td>${fmt(totalOf('mtd','applications'))}</td></tr>
    <tr class="total"><td>Total Deals Closed</td><td>${fmt(totalOf('yest','closed'))}</td><td>${fmt(totalOf('mtd','closed'))}</td></tr>
    <tr class="total"><td>Total Amount Sold (GEL)</td><td>${fmt(totalOf('yest','amount'))}</td><td>${fmt(totalOf('mtd','amount'))}</td></tr>
  </tfoot>`;
  document.getElementById('detailTable').innerHTML = thead + tbody + tfoot;
}

let currentPeriod = 'mtd';
function renderAll() {
  renderKPIs(currentPeriod);
  renderFunnels(currentPeriod);
}
renderAll();
renderPacing();
renderTable();

document.querySelectorAll('#periodToggle button').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('#periodToggle button').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    currentPeriod = btn.getAttribute('data-period');
    renderAll();
  });
});

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

document.querySelectorAll('#pageNav button').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('#pageNav button').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const page = btn.getAttribute('data-page');
    document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
    document.getElementById('page-' + page).classList.add('active');
  });
});
</script>
</body>
</html>
