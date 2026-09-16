// Builds the Marketing > Ad Channels sub-tab (Meta Ads, Google Ads, Website Traffic/GA4) from the TSVs written by
// `python pull_channels.py`, and injects it into deals_amount_migration.html as `const CHANNELS_JSON = ...;` plus,
// on first run, the "Ad Channels" nav button, page markup, CSS and render code. Idempotent (mirrors build_logistics.js's
// pattern): each run removes the previously injected page/JS blocks first and re-adds them; the nav button is
// inserted once and left alone after that.
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

// ---- calendar: day table = last 30 days ending END; month table = Jan 2026 .. END's month
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
const mtd = months.at(-1);
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
// full day span (Jan 1 .. END) used only to compute correct month sums even though the day TABLE only shows the last 30
function fullDaySpan(start, end) { const out = []; let d = start; while (d <= end) { out.push(d); d = addDays(d, 1); } return out; }
const fullDays = fullDaySpan('2026-01-01', END);

function pack(baseDefs, deriveDefs, map) {
  // baseDefs: [{key,col,label,fmt}]  deriveDefs: [{key,label,fmt,fn(base)}] fn receives {key:value} per column
  function build(colsFn, sumsFn) {
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

// ---- Meta
const metaMap = toMap(parseTsv('channels_meta_daily.tsv'), ['spend', 'impressions', 'clicks']);
const meta = pack(
  [{ key: 'spend', col: 'spend', label: 'Spend', fmt: 'money' }, { key: 'impressions', col: 'impressions', label: 'Impressions', fmt: 'int' }, { key: 'clicks', col: 'clicks', label: 'Clicks', fmt: 'int' }],
  [{ key: 'ctr', label: 'CTR', fmt: 'pct', fn: o => o.impressions ? o.clicks / o.impressions : 0 },
   { key: 'cpc', label: 'CPC', fmt: 'money', fn: o => o.clicks ? o.spend / o.clicks : 0 }],
  metaMap
);
const metaCampaigns = parseTsv('channels_meta_campaigns.tsv').map(r => ({ name: r.name, status: r.status, spend: num(r.spend), impressions: num(r.impressions), clicks: num(r.clicks) }));

// ---- Google Ads
const gadsMap = toMap(parseTsv('channels_gads_daily.tsv'), ['cost', 'impressions', 'clicks', 'conversions']);
const gads = pack(
  [{ key: 'cost', col: 'cost', label: 'Cost', fmt: 'money' }, { key: 'impressions', col: 'impressions', label: 'Impressions', fmt: 'int' }, { key: 'clicks', col: 'clicks', label: 'Clicks', fmt: 'int' }, { key: 'conversions', col: 'conversions', label: 'Conversions', fmt: 'dec1' }],
  [{ key: 'ctr', label: 'CTR', fmt: 'pct', fn: o => o.impressions ? o.clicks / o.impressions : 0 },
   { key: 'cpc', label: 'CPC', fmt: 'money', fn: o => o.clicks ? o.cost / o.clicks : 0 },
   { key: 'cpa', label: 'Cost / Conversion', fmt: 'money', fn: o => o.conversions ? o.cost / o.conversions : 0 }],
  gadsMap
);
const gadsCampaigns = parseTsv('channels_gads_campaigns.tsv').map(r => ({ name: r.name, status: r.status, spend: num(r.cost), impressions: num(r.impressions), clicks: num(r.clicks) }));

// ---- GA4
const ga4Map = toMap(parseTsv('channels_ga4_daily.tsv'), ['sessions', 'users', 'conversions']);
const ga4 = pack(
  [{ key: 'sessions', col: 'sessions', label: 'Sessions', fmt: 'int' }, { key: 'users', col: 'users', label: 'Users', fmt: 'int' }, { key: 'conversions', col: 'conversions', label: 'Conversions', fmt: 'int' }],
  [{ key: 'convrate', label: 'Conversion rate', fmt: 'pct', fn: o => o.sessions ? o.conversions / o.sessions : 0 }],
  ga4Map
);

const payload = { info, dayStart: days[0], days, months, mtd, end: END, meta, metaCampaigns, gads, gadsCampaigns, ga4, generatedAt: new Date().toISOString().slice(0, 16).replace('T', ' ') + ' UTC' };
fs.writeFileSync(path.join(__dirname, 'channels_data.json'), JSON.stringify(payload));
console.log('days:', days.length, days[0], '..', END, '| months:', months.length, months[0], '..', mtd,
  '| meta MTD spend:', meta.month.find(r => r.label === 'Spend').vals.at(-1),
  '| gads MTD cost:', gads.month.find(r => r.label === 'Cost').vals.at(-1),
  '| ga4 MTD sessions:', ga4.month.find(r => r.label === 'Sessions').vals.at(-1));

// ---------------- inject into the HTML ----------------
const htmlPath = process.env.DASH_HTML ? path.resolve(process.env.DASH_HTML) : path.join(__dirname, '..', 'volta-analytics-new-db', 'deals_amount_migration.html');
let html = fs.readFileSync(htmlPath, 'utf8');
const __hadCRLF = html.includes('\r\n'); if (__hadCRLF) html = html.replace(/\r\n/g, '\n');
const NL = String.fromCharCode(10);
const must = (n, what) => { if (!html.includes(n)) throw new Error('anchor not found: ' + what); };
function removeBetween(startMark, endMark, what) {
  const s = html.indexOf(startMark);
  if (s < 0) return;
  const e = html.indexOf(endMark, s);
  if (e < 0) throw new Error(what + ' end marker not found');
  html = html.slice(0, s) + html.slice(e + endMark.length);
}

// nav button (static: inserted once, right after the Leads button)
if (!html.includes('data-page="adchannels"')) {
  const a = '<button data-page="leads">Leads</button>';
  must(a, 'leads nav button');
  html = html.replace(a, a + NL + '        <button data-page="adchannels">Ad Channels</button>');
}

// page (re-injected on every run)
const PAGE_START = '<!-- chan-page-start -->', PAGE_END = '<!-- chan-page-end -->';
removeBetween(PAGE_START, PAGE_END + NL, 'chan page');
{
  const card = id => `    <div class="report-card">
      <div class="report-scroll-top" id="${id}ScrollTop"><div></div></div>
      <div class="report-scroll" id="${id}ScrollBody"><table class="logi-table" id="${id}Table"><tbody></tbody></table></div>
    </div>`;
  const group = (title, id) => `  <div class="logi-group">
    <div class="logi-group-title">${title}</div>
${card(id + 'Day')}
${card(id + 'Month')}
  </div>`;
  const miniTable = id => `  <div class="table-card"><table class="logi-mini" id="${id}Table"><colgroup><col class="logi-mini-name"></colgroup><tbody></tbody></table></div>`;
  const page = NL + PAGE_START + `
<div class="page" data-page="adchannels" id="page-adchannels">
<div class="wrap">
  <p class="section-title">Marketing &mdash; Ad Channels</p>
  <div class="banner" id="chanBanner"></div>

${group('Meta Ads (Facebook / Instagram)', 'chanMeta')}
${miniTable('chanMetaCamp')}

${group('Google Ads', 'chanGads')}
${miniTable('chanGadsCamp')}

${group('Website Traffic (Google Analytics 4)', 'chanGa4')}
  <p class="note">Sessions/Users/Conversions cover all traffic to the site (every channel, not only paid), from GA4 property ${GA4_PROPERTY_ID}. Conversions = GA4 key events.</p>
</div>
</div>
` + PAGE_END + NL;
  const li = html.indexOf('id="page-leads"');
  if (li < 0) throw new Error('anchor not found: page-leads');
  const si = html.slice(li).search(/<!-- mkt-page-end -->/);
  if (si < 0) throw new Error('anchor not found: mkt-page-end');
  const insertAt = li + si + '<!-- mkt-page-end -->'.length;
  html = html.slice(0, insertAt) + page + html.slice(insertAt);
}

// data line
const dataLine = 'var CHANNELS_JSON = ' + JSON.stringify(payload) + ';';
if (/^(?:const|var) CHANNELS_JSON = .*;$/m.test(html)) html = html.replace(/^(?:const|var) CHANNELS_JSON = .*;$/m, () => dataLine);
else {
  must('var MKT_JSON = ', 'mkt data line');
  html = html.replace(/^(?:const|var) MKT_JSON = .*;$/m, l => l + NL + dataLine);
}

// render code (re-injected on every run)
const JS_MARK = '/* ---------- chan ---------- */', JS_END = '/* ---------- /chan ---------- */';
removeBetween(JS_MARK, JS_END + NL, 'chan js');
{
  const js = NL + JS_MARK + `
window.__registerPage(['adchannels'], function () {
  const C = CHANNELS_JSON;
  const dayLabelC = d => { const [, m, day] = d.split('-').map(Number); return MONTH_NAMES[m - 1] + ' ' + day; };
  const monthLabelC = m => MONTH_NAMES[Number(m.slice(5, 7)) - 1] + ' ' + m.slice(0, 4);
  document.getElementById('chanBanner').innerHTML = '<b>Source:</b> Meta Marketing API (' + C.info.meta.account + ', ' + C.info.meta.currency + '), Google Ads API (' + C.info.gads.account + ', ' + C.info.gads.currency + ') and GA4 Data API (property ${GA4_PROPERTY_ID}). Day tables: last 30 days through ' + C.end + '; month tables: ' + monthLabelC(C.months[0]) + ' through the current month (MTD). Updated ' + C.generatedAt + '.';

  function fmtRow(v, kind) {
    if (v === null || v === undefined) return '&ndash;';
    if (kind === 'money') return '$' + v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (kind === 'pct') return pct(v);
    if (kind === 'dec1') return v.toLocaleString('en-US', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
    return fmt(v);
  }
  function rowHtml(cls, r) {
    var exCell = r.fmt === 'pct' ? '&ndash;' : fmtRow(r.ex.v, r.fmt);
    return '<tr class="' + cls + '"><td>' + r.label + '</td>' + r.vals.map(v => '<td>' + fmtRow(v, r.fmt) + '</td>').join('')
      + '<td class="logi-extra logi-extra-first">' + exCell + '</td></tr>';
  }
  function render(id, rows, isDay) {
    const cols = isDay ? C.days : C.months, n = cols.length;
    const heads = cols.map((c, i) => '<td>' + (isDay ? dayLabelC(c) + (i === n - 1 ? ' (today)' : '') : monthLabelC(c) + (i === n - 1 ? ' (MTD)' : '')) + '</td>').join('');
    const exHead = isDay ? 'Last 7 days' : 'Total';
    let h = '<tr class="logi-head"><td>Metric</td>' + heads + '<td class="logi-extra logi-extra-first">' + exHead + '</td></tr>';
    rows.forEach((r, i) => { h += rowHtml((i % 2) ? 'logi-light' : 'logi-white', r); });
    document.getElementById(id + 'Table').querySelector('tbody').innerHTML = h;
  }
  render('chanMetaDay', C.meta.day, true); render('chanMetaMonth', C.meta.month, false);
  render('chanGadsDay', C.gads.day, true); render('chanGadsMonth', C.gads.month, false);
  render('chanGa4Day', C.ga4.day, true); render('chanGa4Month', C.ga4.month, false);
  window.chanScrollUpdaters = ['chanMetaDay', 'chanMetaMonth', 'chanGadsDay', 'chanGadsMonth', 'chanGa4Day', 'chanGa4Month'].map(id => setupTopScrollSync(id + 'ScrollTop', id + 'ScrollBody'));

  function renderCamp(id, rows, currency) {
    let h = '<tr class="logi-mini-title"><td colspan="5">Top campaigns &mdash; last 30 days</td></tr>';
    h += '<tr class="logi-mini-head"><td>Campaign</td><td>Status</td><td>Spend</td><td>Impressions</td><td>Clicks</td></tr>';
    rows.forEach((r, i) => {
      h += '<tr class="logi-mini-data' + (i % 2 ? ' logi-mini-alt' : '') + '"><td>' + r.name + '</td><td>' + r.status + '</td><td>$' + r.spend.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</td><td>' + fmt(r.impressions) + '</td><td>' + fmt(r.clicks) + '</td></tr>';
    });
    document.getElementById(id + 'Table').querySelector('tbody').innerHTML = h;
  }
  renderCamp('chanMetaCamp', C.metaCampaigns);
  renderCamp('chanGadsCamp', C.gadsCampaigns);
});
` + JS_END + NL;
  const a = '// ---- top-level page nav (grows as more reports get added) ----';
  must(a, 'nav handler');
  html = html.replace(a, () => js.replace(/^\n/, '') + a);
}

if (__hadCRLF) html = html.replace(/\n/g, '\r\n');
fs.writeFileSync(htmlPath, html);
console.log('injected Ad Channels tab into', htmlPath);
