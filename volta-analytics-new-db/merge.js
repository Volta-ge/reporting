// Builds the hybrid daily + monthly series for the Daily Mail tabs.
//   Order_Date-keyed metrics (closed, amount):   old_daily_seg.tsv (<= 2026-08-30) + new_daily_seg.tsv (>= 2026-08-31)
//   Aplication_Date-keyed metrics (applications, terms, uw, dp): old_daily_apps.tsv + new_daily_apps.tsv, same cutover
// Every TSV is "d  seg  ..." grouped by day x segment. BOM-safe.
const fs = require('fs');
const path = require('path');
const CUTOVER = '2026-08-31';

function parseTsv(file) {
  let content = fs.readFileSync(path.join(__dirname, file), 'utf8');
  if (content.charCodeAt(0) === 0xFEFF) content = content.slice(1);
  const lines = content.split(/\r?\n/).filter(l => l.trim().length);
  const header = lines[0].split('\t');
  return lines.slice(1).map(line => {
    const cols = line.split('\t');
    const obj = {};
    header.forEach((h, i) => obj[h] = cols[i]);
    return obj;
  });
}

const METRICS = ['closed', 'amount', 'applications', 'terms', 'uw', 'dp'];
function emptySeg() { return { closed: 0, amount: 0, applications: 0, terms: 0, uw: 0, dp: 0 }; }
function emptyDay(d) { return { d, source: d >= CUTOVER ? 'new' : 'old', A: emptySeg(), B: emptySeg() }; }

const byDay = {};
function day(d) { return byDay[d] || (byDay[d] = emptyDay(d)); }

// Order_Date-keyed
for (const r of parseTsv('old_daily_seg.tsv')) { const s = day(r.d)[r.seg]; s.closed += +r.deals; s.amount += +r.amount; }
for (const r of parseTsv('new_daily_seg.tsv')) { const s = day(r.d)[r.seg]; s.closed += +r.deals; s.amount += +r.amount; }
// Aplication_Date-keyed
for (const r of parseTsv('old_daily_apps.tsv')) { const s = day(r.d)[r.seg]; s.applications += +r.applications; s.terms += +r.terms; s.uw += +r.uw; s.dp += +r.dp; }
for (const r of parseTsv('new_daily_apps.tsv')) { const s = day(r.d)[r.seg]; s.applications += +r.applications; s.terms += +r.terms; s.uw += +r.uw; s.dp += +r.dp; }

// Guard: a source file must never contribute rows on the wrong side of the cutover
for (const r of parseTsv('old_daily_seg.tsv').concat(parseTsv('old_daily_apps.tsv'))) if (r.d >= CUTOVER) throw new Error('old-DB row past cutover: ' + r.d);
for (const r of parseTsv('new_daily_seg.tsv').concat(parseTsv('new_daily_apps.tsv'))) if (r.d < CUTOVER) throw new Error('new-DB row before cutover: ' + r.d);

function fmt(dt) { return dt.toISOString().slice(0, 10); }
const start = new Date('2026-01-01T00:00:00Z');
// series ends at the last day present in the new-DB extracts (= the END passed to pull_new.sh, normally yesterday)
const lastNew = [...parseTsv('new_daily_apps.tsv'), ...parseTsv('new_daily_seg.tsv')].map(r => r.d).sort().pop();
const end = new Date((process.argv[2] || lastNew) + 'T00:00:00Z');
console.log('series end:', fmt(end));
const round2 = n => Math.round(n * 100) / 100;
const series = [];
for (let dt = new Date(start); dt <= end; dt.setUTCDate(dt.getUTCDate() + 1)) {
  const row = byDay[fmt(dt)] || emptyDay(fmt(dt));
  for (const seg of ['A', 'B']) { row[seg].amount = round2(row[seg].amount); row[seg].dp = round2(row[seg].dp); }
  series.push(row);
}
fs.writeFileSync(path.join(__dirname, 'merged_series.json'), JSON.stringify(series));

// monthly rollups
const byMonth = {};
for (const r of series) {
  const m = r.d.slice(0, 7);
  const b = byMonth[m] || (byMonth[m] = { m, sources: new Set(), A: emptySeg(), B: emptySeg() });
  b.sources.add(r.source);
  for (const seg of ['A', 'B']) for (const k of METRICS) b[seg][k] += r[seg][k];
}
const monthly = Object.values(byMonth).map(b => {
  for (const seg of ['A', 'B']) { b[seg].amount = round2(b[seg].amount); b[seg].dp = round2(b[seg].dp); }
  return { m: b.m, source: b.sources.size > 1 ? 'mixed' : [...b.sources][0], A: b.A, B: b.B };
});
fs.writeFileSync(path.join(__dirname, 'monthly_series.json'), JSON.stringify(monthly));

const tot = (r, k) => r.A[k] + r.B[k];
console.log('days:', series.length, '| old-sourced:', series.filter(s => s.source === 'old').length, '| new-sourced:', series.filter(s => s.source === 'new').length);
console.log('last 4 days:', series.slice(-4).map(r => `${r.d}: apps ${tot(r,'applications')} terms ${tot(r,'terms')} uw ${tot(r,'uw')} dp ${tot(r,'dp')} closed ${tot(r,'closed')} amount ${tot(r,'amount')}`).join('\n  '));
console.log('months:', monthly.map(m => `${m.m}(${m.source}) apps=${tot(m,'applications')} closed=${tot(m,'closed')}`).join(', '));
