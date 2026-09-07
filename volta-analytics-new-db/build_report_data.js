// merged_series.json / monthly_series.json -> report_data.json (shape consumed by the artifact's render code),
// then injects it into deals_amount_migration.html, replacing either the __REPORT_JSON__ placeholder or a
// previously injected `const REPORT_JSON = ...;` line, so it can be re-run on every refresh.
const fs = require('fs');
const path = require('path');

const daily = JSON.parse(fs.readFileSync(path.join(__dirname, 'merged_series.json'), 'utf8'));
const monthly = JSON.parse(fs.readFileSync(path.join(__dirname, 'monthly_series.json'), 'utf8'));
const METRICS = ['closed', 'amount', 'applications', 'terms', 'uw', 'dp'];

function sumRows(rows) {
  const acc = { A: {}, B: {} };
  for (const seg of ['A', 'B']) for (const k of METRICS) acc[seg][k] = Math.round(rows.reduce((s, r) => s + r[seg][k], 0) * 100) / 100;
  return acc;
}

const last = daily[daily.length - 1];
const mtdRows = daily.filter(r => r.d.slice(0, 7) === last.d.slice(0, 7));
const reportData = {
  yest: { date: last.d, A: last.A, B: last.B },
  mtd: { start: mtdRows[0].d, end: last.d, ...sumRows(mtdRows) },
};

const dailyStatsObj = {};
daily.filter(r => r.d >= '2026-06-01').forEach(r => { dailyStatsObj[r.d] = { A: r.A, B: r.B, source: r.source }; });
const monthlyStatsObj = {};
monthly.forEach(r => { monthlyStatsObj[r.m] = { A: r.A, B: r.B, source: r.source }; });

const payload = {
  reportData, dailyStatsObj, monthlyStatsObj,
  cutover: '2026-08-31',
  generatedAt: new Date().toISOString().slice(0, 16).replace('T', ' ') + ' UTC',
};
const json = JSON.stringify(payload);
fs.writeFileSync(path.join(__dirname, 'report_data.json'), json);

const htmlPath = path.join(__dirname, 'deals_amount_migration.html');
let html = fs.readFileSync(htmlPath, 'utf8');
const before = html.length;
if (html.includes('__REPORT_JSON__')) html = html.replace('__REPORT_JSON__', json);
else if (/^const REPORT_JSON = .*;$/m.test(html)) html = html.replace(/^const REPORT_JSON = .*;$/m, 'const REPORT_JSON = ' + json + ';');
else throw new Error('no REPORT_JSON injection point found');
fs.writeFileSync(htmlPath, html);

const tot = (o, k) => o.A[k] + o.B[k];
console.log('yest', reportData.yest.date, ':', METRICS.map(k => `${k}=${tot(reportData.yest, k)}`).join(' '));
console.log('mtd', reportData.mtd.start, '..', reportData.mtd.end, ':', METRICS.map(k => `${k}=${tot(reportData.mtd, k)}`).join(' '));
console.log('dailyStats days:', Object.keys(dailyStatsObj).length, '| months:', Object.keys(monthlyStatsObj).join(','));
console.log('html', before, '->', html.length, 'bytes');
