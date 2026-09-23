// Builds a STANDALONE Ad Channels page (Meta Ads / Google Ads / GA4 website traffic), separate from the main
// Volta_Analytics_New DB dashboard artifact. The user wants this as its own artifact for now; a link gets added
// to the Marketing nav group of the main dashboard later, on request (see README's Marketing > Ad Channels section
// for the credentials/refresh notes -- unchanged, same channels_*.tsv inputs from `python pull_channels.py`).
// Reuses the exact same CSS classes/colors/render logic as the main dashboard's logi-table/logi-mini tables so it
// looks identical once it does become a tab there.
const fs = require('fs');
const path = require('path');
const GA4_PROPERTY_ID = '369140604';

function parseTsv(file) {
  let c = fs.readFileSync(path.join(__dirname, file), 'utf8');
  if (c.charCodeAt(0) === 0xFEFF) c = c.slice(1);
  const lines = c.split(/\r?\n/).filter(l => l.length);
  if (!lines.length) return [];
  const header = lines[0].split('\t');
  return lines.slice(1).map(line => { const cols = line.split('\t'); const o = {}; header.forEach((h, i) => o[h] = cols[i]); return o; });
}
const num = v => (v === '' || v === 'NULL' || v === undefined) ? 0 : +v;
const info = JSON.parse(fs.readFileSync(path.join(__dirname, 'channels_info.json'), 'utf8'));
const END = info.end;

const DAY_WINDOW = 30;
function addDays(d, n) { const dt = new Date(d + 'T00:00:00Z'); dt.setUTCDate(dt.getUTCDate() + n); return dt.toISOString().slice(0, 10); }
const days = Array.from({ length: DAY_WINDOW }, (_, i) => addDays(END, i - (DAY_WINDOW - 1)));
const firstMonth = '2026-01';
function monthsBetween(a, b) {
  const out = []; let y = +a.slice(0, 4), m = +a.slice(5, 7); const endY = +b.slice(0, 4), endM = +b.slice(5, 7);
  while (y < endY || (y === endY && m <= endM)) { out.push(String(y) + '-' + String(m).padStart(2, '0')); m++; if (m > 12) { m = 1; y++; } }
  return out;
}
const months = monthsBetween(firstMonth, END);
const last7 = vals => vals.slice(-7).reduce((a, b) => a + b, 0);
const total = vals => vals.reduce((a, b) => a + b, 0);

function toMap(rows, valueCols) {
  const m = {};
  for (const r of rows) { const o = {}; valueCols.forEach(c => o[c] = num(r[c])); m[r.d] = o; }
  return m;
}
function seriesFromMap(map, col, cols) { return cols.map(d => (map[d] ? map[d][col] : 0)); }
function monthSums(map, col, allDays) {
  return months.map(mo => allDays.filter(d => d.slice(0, 7) === mo).reduce((t, d) => t + ((map[d] ? map[d][col] : 0)), 0));
}
function fullDaySpan(start, end) { const out = []; let d = start; while (d <= end) { out.push(d); d = addDays(d, 1); } return out; }
const fullDays = fullDaySpan('2026-01-01', END);

function pack(baseDefs, deriveDefs, map) {
  function build(colsFn) {
    const baseVals = {}; baseDefs.forEach(b => baseVals[b.key] = colsFn(b.col));
    const rows = baseDefs.map(b => ({ label: b.label, fmt: b.fmt, vals: baseVals[b.key] }));
    const n = baseVals[baseDefs[0].key].length;
    deriveDefs.forEach(d => {
      const vals = Array.from({ length: n }, (_, i) => { const o = {}; baseDefs.forEach(b => o[b.key] = baseVals[b.key][i]); return d.fn(o); });
      rows.push({ label: d.label, fmt: d.fmt, vals });
    });
    return rows;
  }
  const dayRows = build(col => seriesFromMap(map, col, days));
  const monthRows = build(col => monthSums(map, col, fullDays));
  const withEx = (rows, isDay) => rows.map(r => ({ ...r, ex: isDay ? { label: 'Last 7 days', v: last7(r.vals) } : { label: 'Total', v: total(r.vals) } }));
  return { day: withEx(dayRows, true), month: withEx(monthRows, false) };
}

// Each channel shows a "raw platform metric" alongside its "qualified/outbound" counterpart, so the three
// tabs read consistently and a viewer can see why e.g. Meta's own reported clicks run far ahead of GA4
// Sessions: Meta Clicks (all) counts every tap on the ad unit (likes/comments/shares/photo-expand, not just
// outbound clicks); Google Ads Interactions is the analogous broader count (clicks + other engagement, e.g.
// shopping-ad swipes); GA4 Engaged Sessions is the "qualified visit" counterpart to raw Sessions. Verified
// live 2026-09-22 that all three pairs actually differ for this account (see pull_channels.py's comments for
// the numbers). CTR/CPC are derived from the qualified metric (Outbound Clicks / Clicks), not the raw one --
// that was the original bug the user caught (CTR/CPC looked inflated next to GA4).
const metaMap = toMap(parseTsv('channels_meta_daily.tsv'), ['spend', 'impressions', 'clicks', 'outbound_clicks']);
const meta = pack(
  [{ key: 'spend', col: 'spend', label: 'Spend', fmt: 'money' },
   { key: 'impressions', col: 'impressions', label: 'Impressions', fmt: 'int' },
   { key: 'clicks', col: 'clicks', label: 'Clicks (all)', fmt: 'int' },
   { key: 'outbound', col: 'outbound_clicks', label: 'Outbound Clicks', fmt: 'int' }],
  [{ key: 'ctr', label: 'CTR (outbound)', fmt: 'pct', fn: o => o.impressions ? o.outbound / o.impressions : 0 },
   { key: 'cpc', label: 'CPC (outbound)', fmt: 'money', fn: o => o.outbound ? o.spend / o.outbound : 0 },
   { key: 'clicksPerDollar', label: 'Outbound Clicks per $1', fmt: 'dec2', fn: o => o.spend ? o.outbound / o.spend : 0 }],
  metaMap
);
const metaCampaigns = parseTsv('channels_meta_campaigns.tsv').map(r => {
  const spend = num(r.spend), impressions = num(r.impressions), clicks = num(r.clicks), outbound = num(r.outbound_clicks);
  return { name: r.name, status: r.status, spend, impressions, clicks, outbound, ctr: impressions ? outbound / impressions : 0, cpc: outbound ? spend / outbound : 0, clicksPerDollar: spend ? outbound / spend : 0 };
});

const gadsMap = toMap(parseTsv('channels_gads_daily.tsv'), ['cost', 'impressions', 'clicks', 'interactions', 'conversions']);
const gads = pack(
  [{ key: 'cost', col: 'cost', label: 'Cost', fmt: 'money' },
   { key: 'impressions', col: 'impressions', label: 'Impressions', fmt: 'int' },
   { key: 'interactions', col: 'interactions', label: 'Interactions', fmt: 'int' },
   { key: 'clicks', col: 'clicks', label: 'Clicks', fmt: 'int' },
   { key: 'conversions', col: 'conversions', label: 'Conversions', fmt: 'dec1' }],
  [{ key: 'ctr', label: 'CTR', fmt: 'pct', fn: o => o.impressions ? o.clicks / o.impressions : 0 },
   { key: 'cpc', label: 'CPC', fmt: 'money', fn: o => o.clicks ? o.cost / o.clicks : 0 },
   { key: 'cpa', label: 'Cost / Conversion', fmt: 'money', fn: o => o.conversions ? o.cost / o.conversions : 0 },
   { key: 'clicksPerDollar', label: 'Clicks per $1', fmt: 'dec2', fn: o => o.cost ? o.clicks / o.cost : 0 }],
  gadsMap
);
const gadsCampaigns = parseTsv('channels_gads_campaigns.tsv').map(r => {
  const spend = num(r.cost), impressions = num(r.impressions), clicks = num(r.clicks), interactions = num(r.interactions), conversions = num(r.conversions);
  return { name: r.name, status: r.status, spend, impressions, clicks, interactions, conversions, ctr: impressions ? clicks / impressions : 0, cpc: clicks ? spend / clicks : 0, cpa: conversions ? spend / conversions : 0, clicksPerDollar: spend ? clicks / spend : 0 };
});

// GA4 itself carries no cost data (it's not an ad platform) -- "per $1" here is necessarily BLENDED: total
// Sessions (every source, not just paid) against the two paid channels' combined spend. Not a per-channel
// efficiency number like Meta's/Google Ads' own (it mixes organic/direct/referral traffic into the numerator),
// but a common blended view marketers do track. Labeled and explained as such below, not left ambiguous.
const combinedSpendByDay = {};
for (const d of Object.keys(metaMap)) combinedSpendByDay[d] = (combinedSpendByDay[d] || 0) + metaMap[d].spend;
for (const d of Object.keys(gadsMap)) combinedSpendByDay[d] = (combinedSpendByDay[d] || 0) + gadsMap[d].cost;

const ga4Map = toMap(parseTsv('channels_ga4_daily.tsv'), ['sessions', 'engaged_sessions', 'users', 'conversions']);
for (const d of Object.keys(ga4Map)) ga4Map[d].adSpend = combinedSpendByDay[d] || 0;
const ga4 = pack(
  [{ key: 'sessions', col: 'sessions', label: 'Sessions', fmt: 'int' },
   { key: 'engaged', col: 'engaged_sessions', label: 'Engaged Sessions', fmt: 'int' },
   { key: 'users', col: 'users', label: 'Users', fmt: 'int' },
   { key: 'conversions', col: 'conversions', label: 'Conversions', fmt: 'int' },
   { key: 'adSpend', col: 'adSpend', label: 'Combined Ad Spend (Meta + Google Ads)', fmt: 'money' }],
  [{ key: 'engrate', label: 'Engagement rate', fmt: 'pct', fn: o => o.sessions ? o.engaged / o.sessions : 0 },
   { key: 'convrate', label: 'Conversion rate', fmt: 'pct', fn: o => o.sessions ? o.conversions / o.sessions : 0 },
   { key: 'sessPerDollar', label: 'Sessions per $1 (blended, all traffic)', fmt: 'dec2', fn: o => o.adSpend ? o.sessions / o.adSpend : 0 }],
  ga4Map
);

const payload = { info, dayStart: days[0], days, months, end: END, meta, metaCampaigns, gads, gadsCampaigns, ga4, generatedAt: new Date().toISOString().slice(0, 16).replace('T', ' ') + ' UTC' };
fs.writeFileSync(path.join(__dirname, 'channels_data.json'), JSON.stringify(payload));
console.log('meta MTD spend:', meta.month.find(r => r.label === 'Spend').vals.at(-1), '| gads MTD cost:', gads.month.find(r => r.label === 'Cost').vals.at(-1), '| ga4 MTD sessions:', ga4.month.find(r => r.label === 'Sessions').vals.at(-1));

// ---------------- standalone HTML ----------------
const html = `<meta charset="utf-8">
<title>Volta &mdash; Marketing: Ad Channels</title>
<style>
:root{
  color-scheme: light;
  --surface-1:      #fcfcfb;
  --page:           #f9f9f7;
  --text-primary:   #0b0b0b;
  --text-secondary: #52514e;
  --text-muted:     #898781;
  --border:         rgba(11,11,11,0.10);
  --series-a:       #2a78d6;
  --warning:        #fab219;
}
@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) {
    color-scheme: dark;
    --surface-1:      #1a1a19;
    --page:           #0d0d0d;
    --text-primary:   #ffffff;
    --text-secondary: #c3c2b7;
    --text-muted:     #898781;
    --border:         rgba(255,255,255,0.10);
    --series-a:       #3987e5;
    --warning:        #fab219;
  }
}
:root[data-theme="dark"] {
  color-scheme: dark;
  --surface-1:      #1a1a19;
  --page:           #0d0d0d;
  --text-primary:   #ffffff;
  --text-secondary: #c3c2b7;
  --text-muted:     #898781;
  --border:         rgba(255,255,255,0.10);
  --series-a:       #3987e5;
  --warning:        #fab219;
}
*{box-sizing:border-box}
body{background:var(--page);color:var(--text-primary);font:14px/1.5 -apple-system,BlinkMacSystemFont,"Noto Sans Georgian",sans-serif;margin:0;padding:0}
.wrap{max-width:1180px;margin:0 auto;padding:24px 20px 60px}
.app-title{font-size:19px;font-weight:700;margin:0 0 2px}
.app-sub{color:var(--text-muted);font-size:12.5px;margin:0 0 18px}
.section-title{font-size:15px;font-weight:700;margin:0 0 4px}
.note{color:var(--text-muted);font-size:12px;margin:0 0 14px}
.banner{background:var(--surface-1);border:1px solid var(--border);border-left:3px solid var(--warning);border-radius:8px;padding:12px 16px;font-size:12.5px;color:var(--text-secondary);margin-bottom:18px;line-height:1.6}
.banner b{color:var(--text-primary)}
.report-card{background:var(--surface-1);border:1px solid var(--border);border-radius:12px;overflow:hidden}
.report-scroll{overflow-x:auto}
.report-scroll-top{overflow-x:auto;overflow-y:hidden;height:14px;margin-bottom:-1px}
.report-scroll-top>div{height:1px}
.table-card{background:var(--surface-1);border:1px solid var(--border);border-radius:12px;overflow:hidden}
.logi-group{border:2px solid #1a1a34;border-radius:12px;padding:10px;display:flex;flex-direction:column;gap:12px;background:var(--surface-1);margin:6px 0 14px}
.logi-group-title{background:#1a1a34;color:#c2ff00;font-weight:700;font-size:13px;padding:7px 12px;border-radius:8px}
.logi-open-title{color:var(--text-primary);font-weight:700;font-size:13px;margin:4px 0 -6px}
table.logi-table{border-collapse:collapse;font-size:11.5px;table-layout:auto;width:100%}
table.logi-table td{padding:5px 8px;border:1px solid #d4d4de;text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;color:#1a1a34;background:#fff}
table.logi-table td:first-child{text-align:left;font-variant-numeric:normal;position:sticky;left:0;z-index:2;box-shadow:1px 0 0 #d4d4de}
table.logi-table tr.logi-head td{background:#2b2b4f;color:#fff;font-weight:700;border-color:#2b2b4f}
table.logi-table tr.logi-light td{background:#f5ffd6;color:#1a1a34}
table.logi-table tr.logi-white td{background:#fff;color:#1a1a34}
table.logi-mini{border-collapse:collapse;font-size:12px;width:100%}
table.logi-mini td{padding:7px 10px;border:none;border-bottom:1px solid #e3e3ea;text-align:right;font-variant-numeric:tabular-nums}
table.logi-mini td:first-child{text-align:left;font-variant-numeric:normal}
table.logi-mini tr.logi-mini-title td{background:#1a1a34;color:#c2ff00;font-weight:700;text-align:left}
table.logi-mini tr.logi-mini-head td{background:#2b2b4f;color:#fff;font-weight:700}
table.logi-mini tr.logi-mini-data td{color:#3a3a55;background:#fff}
table.logi-mini tr.logi-mini-data td:first-child{color:#1a1a34}
table.logi-mini tr.logi-mini-data.logi-mini-alt td{background:#f5ffd6}
table.logi-extra{font-style:italic}
table.logi-table td.logi-extra-first{border-left:3px solid #1a1a34}
.page-nav{display:inline-flex;background:var(--surface-1);border:1px solid var(--border);border-radius:8px;padding:3px;gap:2px;margin-bottom:14px}
.page-nav button{border:none;background:transparent;color:var(--text-secondary);font:inherit;font-size:13px;font-weight:600;padding:7px 16px;border-radius:6px;cursor:pointer}
.page-nav button.active{background:var(--text-primary);color:var(--surface-1)}
.chan-tab{display:none}
.chan-tab.active{display:block}
.chan-glossary{background:var(--surface-1);border:1px solid var(--border);border-radius:12px;padding:16px 18px;margin-top:6px}
.chan-glossary-title{font-size:13px;font-weight:700;margin-bottom:10px}
.chan-glossary-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px}
@media (max-width:900px){.chan-glossary-grid{grid-template-columns:1fr}}
.chan-glossary-grid b{font-size:12px;color:var(--text-primary)}
.chan-glossary-grid dl{margin:8px 0 0}
.chan-glossary-grid dt{font-size:11.5px;font-weight:700;color:var(--text-primary);margin-top:8px}
.chan-glossary-grid dt:first-child{margin-top:0}
.chan-glossary-grid dd{font-size:11.5px;color:var(--text-secondary);margin:2px 0 0;line-height:1.5}
.theme-toggle-btn{position:fixed;top:16px;right:16px;z-index:1000;width:34px;height:34px;border-radius:50%;border:1px solid var(--border);background:var(--surface-1);color:var(--text-secondary);display:flex;align-items:center;justify-content:center;cursor:pointer;padding:0;box-shadow:0 1px 3px rgba(0,0,0,.12)}
.theme-toggle-btn:hover{color:var(--text-primary);border-color:var(--text-muted)}
.theme-toggle-btn svg{width:18px;height:18px}
@media (max-width:700px){.theme-toggle-btn{top:10px;right:10px;width:30px;height:30px}.theme-toggle-btn svg{width:16px;height:16px}}
</style>

<script>
(function () {
  try {
    var t = localStorage.getItem('voltaTheme');
    if (t === 'dark' || t === 'light') document.documentElement.setAttribute('data-theme', t);
  } catch (e) {}
})();
</script>
<button id="themeToggle" class="theme-toggle-btn" type="button" aria-label="Toggle dark/light mode" title="Dark/light mode"><svg id="themeToggleIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></svg></button>

<div class="wrap">
  <div class="app-title">Volta &mdash; Marketing: Ad Channels</div>
  <p class="app-sub">Meta Ads, Google Ads and website traffic (GA4), pulled directly from each platform's API.</p>
  <div class="banner" id="chanBanner"></div>

  <div class="page-nav" id="chanTabNav">
    <button data-tab="meta" class="active">Meta Ads</button>
    <button data-tab="gads">Google Ads</button>
    <button data-tab="ga4">Website Traffic</button>
  </div>

  <div class="chan-tab active" data-tab="meta">
    <div class="logi-group">
      <div class="logi-group-title">Meta Ads (Facebook / Instagram)</div>
      <div class="report-card"><div class="report-scroll-top" id="chanMetaDayScrollTop"><div></div></div><div class="report-scroll" id="chanMetaDayScrollBody"><table class="logi-table" id="chanMetaDayTable"><tbody></tbody></table></div></div>
      <div class="report-card"><div class="report-scroll-top" id="chanMetaMonthScrollTop"><div></div></div><div class="report-scroll" id="chanMetaMonthScrollBody"><table class="logi-table" id="chanMetaMonthTable"><tbody></tbody></table></div></div>
    </div>
    <div class="table-card"><table class="logi-mini" id="chanMetaCampTable"><colgroup><col style="width:24%"></colgroup><tbody></tbody></table></div>
  </div>

  <div class="chan-tab" data-tab="gads">
    <div class="logi-group">
      <div class="logi-group-title">Google Ads</div>
      <div class="report-card"><div class="report-scroll-top" id="chanGadsDayScrollTop"><div></div></div><div class="report-scroll" id="chanGadsDayScrollBody"><table class="logi-table" id="chanGadsDayTable"><tbody></tbody></table></div></div>
      <div class="report-card"><div class="report-scroll-top" id="chanGadsMonthScrollTop"><div></div></div><div class="report-scroll" id="chanGadsMonthScrollBody"><table class="logi-table" id="chanGadsMonthTable"><tbody></tbody></table></div></div>
    </div>
    <div class="table-card"><table class="logi-mini" id="chanGadsCampTable"><colgroup><col style="width:24%"></colgroup><tbody></tbody></table></div>
  </div>

  <div class="chan-tab" data-tab="ga4">
    <div class="logi-group">
      <div class="logi-group-title">Website Traffic (Google Analytics 4)</div>
      <div class="report-card"><div class="report-scroll-top" id="chanGa4DayScrollTop"><div></div></div><div class="report-scroll" id="chanGa4DayScrollBody"><table class="logi-table" id="chanGa4DayTable"><tbody></tbody></table></div></div>
      <div class="report-card"><div class="report-scroll-top" id="chanGa4MonthScrollTop"><div></div></div><div class="report-scroll" id="chanGa4MonthScrollBody"><table class="logi-table" id="chanGa4MonthTable"><tbody></tbody></table></div></div>
    </div>
    <p class="note">Sessions/Users/Conversions cover all traffic to the site (every channel, not only paid), from GA4 property ${GA4_PROPERTY_ID}. Engaged Sessions = sessions lasting 10s+, with 2+ pageviews, or with a conversion (GA4's own "real visit" filter, comparable to Meta's Outbound Clicks / Google Ads' Interactions above). Conversions = GA4 key events.</p>
  </div>

  <div class="chan-glossary">
    <div class="chan-glossary-title">What each metric counts</div>
    <div class="chan-glossary-grid">
      <div>
        <b>Meta Ads</b>
        <dl>
          <dt>Spend</dt><dd>Total amount spent, in the account's own currency.</dd>
          <dt>Impressions</dt><dd>How many times an ad was shown on screen. The same person seeing it twice counts as 2.</dd>
          <dt>Clicks (all)</dt><dd>Every tap anywhere on the ad unit &mdash; likes, comments, shares, photo-expand, page-name tap &mdash; not just clicks that leave Facebook/Instagram for the site.</dd>
          <dt>Outbound Clicks</dt><dd>Only the clicks that actually took someone to the website. The metric that corresponds to a real site visit.</dd>
          <dt>CTR (outbound)</dt><dd>Outbound Clicks &divide; Impressions &mdash; the share of ad views that sent someone to the site.</dd>
          <dt>CPC (outbound)</dt><dd>Spend &divide; Outbound Clicks &mdash; the real cost per site visit driven.</dd>
          <dt>Outbound Clicks per $1</dt><dd>Outbound Clicks &divide; Spend &mdash; the inverse of CPC: how many site visits one dollar buys.</dd>
        </dl>
      </div>
      <div>
        <b>Google Ads</b>
        <dl>
          <dt>Cost</dt><dd>Total amount spent, in the account's own currency.</dd>
          <dt>Impressions</dt><dd>How many times an ad was shown.</dd>
          <dt>Interactions</dt><dd>The platform's broader "main user action" count &mdash; clicks plus other engagement (e.g. swiping a Shopping ad's images).</dd>
          <dt>Clicks</dt><dd>Clicks specifically. Usually close to Interactions; the two differ when a campaign has non-click engagement.</dd>
          <dt>Conversions</dt><dd>Completed target actions (as defined in the Google Ads account), attributed to the campaign/day.</dd>
          <dt>CTR</dt><dd>Clicks &divide; Impressions.</dd>
          <dt>CPC</dt><dd>Cost &divide; Clicks.</dd>
          <dt>Cost / Conversion</dt><dd>Cost &divide; Conversions &mdash; how much each completed conversion cost.</dd>
          <dt>Clicks per $1</dt><dd>Clicks &divide; Cost &mdash; the inverse of CPC: how many clicks one dollar buys.</dd>
        </dl>
      </div>
      <div>
        <b>Website Traffic (GA4)</b>
        <dl>
          <dt>Sessions</dt><dd>One visit episode &mdash; starts when someone arrives, ends after 30 minutes of inactivity. One person can create several sessions in a day.</dd>
          <dt>Users</dt><dd>Unique people, regardless of how many sessions each had.</dd>
          <dt>Engaged Sessions</dt><dd>Sessions lasting 10 seconds or more, with 2+ pageviews, or that included a conversion &mdash; GA4's own filter for a "real" visit vs. an instant bounce.</dd>
          <dt>Conversions</dt><dd>GA4 key events (e.g. form submits, purchases) reached during the session.</dd>
          <dt>Engagement rate</dt><dd>Engaged Sessions &divide; Sessions.</dd>
          <dt>Conversion rate</dt><dd>Conversions &divide; Sessions.</dd>
          <dt>Combined Ad Spend</dt><dd>Meta Spend + Google Ads Cost for that day/month &mdash; GA4 itself has no cost data, this is pulled in from the other two tabs.</dd>
          <dt>Sessions per $1 (blended)</dt><dd>Sessions &divide; Combined Ad Spend. Unlike Meta's/Google Ads' own per-$1 metrics, this is <b>blended</b>: the numerator is ALL site traffic (paid + organic + direct + referral), not just the ads' own visitors, so it is not a clean per-channel efficiency number &mdash; a rough "how far did total ad spend go" view, nothing more.</dd>
        </dl>
      </div>
    </div>
    <p class="note" style="margin-top:10px">All three channels show a "raw platform count" next to its "qualified/outbound" counterpart (Meta: Clicks (all) vs Outbound Clicks; Google Ads: Interactions vs Clicks; GA4: Sessions vs Engaged Sessions) so the numbers stay comparable across tabs &mdash; e.g. why an ad platform's own click count can run far ahead of GA4 Sessions.</p>
  </div>
</div>

<script>
var CHANNELS_JSON = ${JSON.stringify(payload)};
(function () {
  var DASH = '\\u2013';
  var MONTH_NAMES = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  function fmt(n) { return (n === null || n === undefined) ? DASH : Math.round(n).toLocaleString('en-US'); }
  function pct(n) { return (n === null || n === undefined) ? DASH : (n*100).toLocaleString('en-US', { maximumFractionDigits: 1 }) + '%'; }
  function setupTopScrollSync(topId, bodyId) {
    var top = document.getElementById(topId), body = document.getElementById(bodyId);
    if (!top || !body) return function(){};
    function sync() {
      var table = body.querySelector('table');
      top.firstElementChild.style.width = (table ? table.scrollWidth : 0) + 'px';
    }
    top.addEventListener('scroll', function () { body.scrollLeft = top.scrollLeft; });
    body.addEventListener('scroll', function () { top.scrollLeft = body.scrollLeft; });
    sync();
    return sync;
  }

  var C = CHANNELS_JSON;
  var dayLabelC = function (d) { var p = d.split('-').map(Number); return MONTH_NAMES[p[1] - 1] + ' ' + p[2]; };
  var monthLabelC = function (m) { return MONTH_NAMES[Number(m.slice(5, 7)) - 1] + ' ' + m.slice(0, 4); };
  document.getElementById('chanBanner').innerHTML = '<b>Source:</b> Meta Marketing API (' + C.info.meta.account + ', ' + C.info.meta.currency + '), Google Ads API (' + C.info.gads.account + ', ' + C.info.gads.currency + ') and GA4 Data API (property ${GA4_PROPERTY_ID}). Day tables: last 30 days through ' + C.end + '; month tables: ' + monthLabelC(C.months[0]) + ' through the current month (MTD). Updated ' + C.generatedAt + '.';

  function fmtRow(v, kind) {
    if (v === null || v === undefined) return DASH;
    if (kind === 'money') return '$' + v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (kind === 'pct') return pct(v);
    if (kind === 'dec1') return v.toLocaleString('en-US', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
    if (kind === 'dec2') return v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return fmt(v);
  }
  function rowHtml(cls, r) {
    var exCell = r.fmt === 'pct' ? DASH : fmtRow(r.ex.v, r.fmt);
    return '<tr class="' + cls + '"><td>' + r.label + '</td>' + r.vals.map(function (v) { return '<td>' + fmtRow(v, r.fmt) + '</td>'; }).join('')
      + '<td class="logi-extra logi-extra-first">' + exCell + '</td></tr>';
  }
  function render(id, rows, isDay) {
    var cols = isDay ? C.days : C.months, n = cols.length;
    var heads = cols.map(function (c, i) { return '<td>' + (isDay ? dayLabelC(c) + (i === n - 1 ? ' (today)' : '') : monthLabelC(c) + (i === n - 1 ? ' (MTD)' : '')) + '</td>'; }).join('');
    var exHead = isDay ? 'Last 7 days' : 'Total';
    var h = '<tr class="logi-head"><td>Metric</td>' + heads + '<td class="logi-extra logi-extra-first">' + exHead + '</td></tr>';
    rows.forEach(function (r, i) { h += rowHtml((i % 2) ? 'logi-light' : 'logi-white', r); });
    document.getElementById(id + 'Table').querySelector('tbody').innerHTML = h;
  }
  render('chanMetaDay', C.meta.day, true); render('chanMetaMonth', C.meta.month, false);
  render('chanGadsDay', C.gads.day, true); render('chanGadsMonth', C.gads.month, false);
  render('chanGa4Day', C.ga4.day, true); render('chanGa4Month', C.ga4.month, false);
  var syncFns = {
    meta: ['chanMetaDay', 'chanMetaMonth'].map(function (id) { return setupTopScrollSync(id + 'ScrollTop', id + 'ScrollBody'); }),
    gads: ['chanGadsDay', 'chanGadsMonth'].map(function (id) { return setupTopScrollSync(id + 'ScrollTop', id + 'ScrollBody'); }),
    ga4: ['chanGa4Day', 'chanGa4Month'].map(function (id) { return setupTopScrollSync(id + 'ScrollTop', id + 'ScrollBody'); })
  };
  document.getElementById('chanTabNav').addEventListener('click', function (e) {
    var btn = e.target.closest('button[data-tab]');
    if (!btn) return;
    document.querySelectorAll('#chanTabNav button').forEach(function (b) { b.classList.remove('active'); });
    btn.classList.add('active');
    document.querySelectorAll('.chan-tab').forEach(function (t) { t.classList.remove('active'); });
    document.querySelector('.chan-tab[data-tab="' + btn.dataset.tab + '"]').classList.add('active');
    // tables inside an inactive tab are display:none, so their scrollWidth was 0 at the initial sync() call
    (syncFns[btn.dataset.tab] || []).forEach(function (fn) { fn(); });
  });

  var money = function (v) { return '$' + v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  function renderCamp(id, rows, cols) {
    var h = '<tr class="logi-mini-title"><td colspan="' + (cols.length + 1) + '">Top campaigns &mdash; last 30 days</td></tr>';
    h += '<tr class="logi-mini-head"><td>Campaign</td>' + cols.map(function (c) { return '<td>' + c.label + '</td>'; }).join('') + '</tr>';
    rows.forEach(function (r, i) {
      h += '<tr class="logi-mini-data' + (i % 2 ? ' logi-mini-alt' : '') + '"><td>' + r.name + ' <span style="color:var(--text-muted);font-size:10.5px">(' + r.status + ')</span></td>'
        + cols.map(function (c) { return '<td>' + c.fmt(r[c.key]) + '</td>'; }).join('') + '</tr>';
    });
    document.getElementById(id + 'Table').querySelector('tbody').innerHTML = h;
  }
  renderCamp('chanMetaCamp', C.metaCampaigns, [
    { key: 'spend', label: 'Spend', fmt: money },
    { key: 'impressions', label: 'Impressions', fmt: fmt },
    { key: 'clicks', label: 'Clicks (all)', fmt: fmt },
    { key: 'outbound', label: 'Outbound Clicks', fmt: fmt },
    { key: 'ctr', label: 'CTR (outbound)', fmt: pct },
    { key: 'cpc', label: 'CPC (outbound)', fmt: money },
    { key: 'clicksPerDollar', label: 'Outbound Clicks per $1', fmt: function (v) { return v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); } },
  ]);
  renderCamp('chanGadsCamp', C.gadsCampaigns, [
    { key: 'spend', label: 'Cost', fmt: money },
    { key: 'impressions', label: 'Impressions', fmt: fmt },
    { key: 'interactions', label: 'Interactions', fmt: fmt },
    { key: 'clicks', label: 'Clicks', fmt: fmt },
    { key: 'ctr', label: 'CTR', fmt: pct },
    { key: 'cpc', label: 'CPC', fmt: money },
    { key: 'conversions', label: 'Conversions', fmt: function (v) { return v.toLocaleString('en-US', { minimumFractionDigits: 1, maximumFractionDigits: 1 }); } },
    { key: 'cpa', label: 'Cost / Conversion', fmt: money },
    { key: 'clicksPerDollar', label: 'Clicks per $1', fmt: function (v) { return v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); } },
  ]);
})();
(function () {
  var SUN = '<circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>';
  var MOON = '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>';
  var btn = document.getElementById('themeToggle');
  var icon = document.getElementById('themeToggleIcon');
  if (!btn || !icon) return;
  function isDark() {
    var t = document.documentElement.getAttribute('data-theme');
    if (t === 'dark') return true;
    if (t === 'light') return false;
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  }
  function paint() { icon.innerHTML = isDark() ? SUN : MOON; }
  paint();
  btn.addEventListener('click', function () {
    var next = isDark() ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    try { localStorage.setItem('voltaTheme', next); } catch (e) {}
    paint();
  });
})();
</script>
`;
fs.writeFileSync(path.join(__dirname, 'ad_channels_standalone.html'), html);
console.log('wrote ad_channels_standalone.html,', html.length, 'chars');
