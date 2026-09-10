// Sales Analyze group (Sales Monthly / Brand Analyze / Subcategory Analyze / Category-Brand) — hybrid build.
//   old DB  (Jan 1 .. Aug 30):  old_sales_grouped.tsv  (period x Category_Name x Brand_Name x deal_type -> sales/cogs/qty)
//                               old_sales_lines.tsv    (line items for the COGS garbage stats)
//   new DB  (Aug 31 .. yesterday): new_sales_lines.tsv (one row per order_items line; cogs is NOT available on the new DB)
//                               new_categories.tsv + new_product_categories.tsv (category tree, for the mapping-sheet classifier)
// Output: sales_data.json with the exact shapes the original artifact's render functions expect.
const fs = require('fs');
const path = require('path');
const CUTOVER = '2026-08-31';

function parseTsv(file) {
  let content = fs.readFileSync(path.join(__dirname, file), 'utf8');
  if (content.charCodeAt(0) === 0xFEFF) content = content.slice(1);
  const lines = content.split(/\r?\n/).filter(l => l.length);
  const header = lines[0].split('\t');
  return lines.slice(1).map(line => { const cols = line.split('\t'); const o = {}; header.forEach((h, i) => o[h] = cols[i]); return o; });
}

// ---------- ProductClassifier (port of src/ProductClassifier.php) ----------
const mappingRaw = JSON.parse(fs.readFileSync(fs.existsSync(path.join(__dirname, 'product_mapping.json')) ? path.join(__dirname, 'product_mapping.json') : path.join(__dirname, '..', 'src', 'product_mapping.json'), 'utf8'));
const MAP = {};
for (const [k, v] of Object.entries(mappingRaw)) MAP[k.trim()] = v;
function lookup(raw) {
  if (raw === null || raw === undefined) return null;
  const name = String(raw).trim();
  if (name === '' || name === 'none') return null;
  if (MAP[name]) return MAP[name];
  const parts = name.split(',').map(s => s.trim()).filter(Boolean);
  for (const part of parts.reverse()) if (MAP[part]) return MAP[part];
  return null;
}
// a mapping entry whose EN label is literally "none"/blank is not a real bucket -> fallback
const label = v => { const s = String(v || '').trim(); return (s === '' || s.toLowerCase() === 'none') ? null : s; };
const classifyProduct = raw => label((lookup(raw) || {}).productEn);
const classifySubcategory = raw => label((lookup(raw) || {}).subcategoryEn);
const classifyCategory = raw => label((lookup(raw) || {}).categoryEn);
const NO_BRAND = new Set(['none', 'n/a', 'ბრენდის გარეშე', '']);
// Brand canonicalization: the new catalog stores brands as upper-case attribute labels ("HISENSE") while the
// old DB used mixed case ("Hisense"); the same brand must be one row, so a new-DB label that matches an
// old-DB brand case-insensitively adopts the old spelling. No other normalization (no fuzzy matching).
const oldBrandBySlug = {};
const classifyBrand = raw => {
  const n = String(raw || '').trim();
  if (n === '' || NO_BRAND.has(n.toLowerCase())) return null;
  return oldBrandBySlug[n.toLowerCase()] || n;
};

// ---------- New-DB category chain per product ----------
const cats = {};
for (const r of parseTsv('new_categories.tsv')) cats[r.id] = { id: r.id, parent: r.parent_id, name: (r.name || '').trim() };
function depth(id) { let d = 0, c = cats[id]; while (c && c.parent && cats[c.parent]) { d++; c = cats[c.parent]; } return d; }
const productCats = {};
for (const r of parseTsv('new_product_categories.tsv')) (productCats[r.product_id] ||= []).push(r.category_id);
// raw "category name" for a new-DB product = its category names, deepest first, joined as a comma path
// (so the same lookup() the old DB uses — exact, then per-segment from the end — applies unchanged).
function newProductRawCategory(productId) {
  const ids = (productCats[productId] || []).slice().sort((a, b) => depth(a) - depth(b)); // shallow -> deep
  return ids.map(id => cats[id] && cats[id].name).filter(n => n && n !== 'none').join(',');
}

// ---------- Raw rows in one common shape: {period, category, brand, deal_type, sales, cogs, qty, cogsUnknownSales} ----------
const oldGrouped = parseTsv('old_sales_grouped.tsv').map(r => ({
  period: r.period, category: r.category, brand: r.brand, deal_type: r.deal_type,
  sales: +r.sales, cogs: +r.cogs, qty: +r.qty, cogsUnknownSales: 0, source: 'old',
}));
if (oldGrouped.some(r => r.period >= CUTOVER.slice(0, 7) && r.period > '2026-08')) throw new Error('old rows past cutover month');
for (const r of oldGrouped) { const b = String(r.brand || '').trim(); if (b && !NO_BRAND.has(b.toLowerCase())) oldBrandBySlug[b.toLowerCase()] ||= b; }

const newLinesRaw = parseTsv('new_sales_lines.tsv');
if (newLinesRaw.some(r => r.d < CUTOVER)) throw new Error('new-DB line before cutover');
const newGrouped = newLinesRaw.map(r => ({
  period: r.period, category: newProductRawCategory(r.product_id), brand: r.brand, deal_type: r.deal_type,
  sales: +r.sales, cogs: 0, qty: +r.qty, cogsUnknownSales: +r.sales, source: 'new',
}));
const rawRows = oldGrouped.concat(newGrouped);

// coverage diagnostics for the new-DB classification
{
  const tot = newGrouped.reduce((s, r) => s + r.sales, 0);
  const cl = newGrouped.filter(r => classifyProduct(r.category)).reduce((s, r) => s + r.sales, 0);
  const br = newGrouped.filter(r => classifyBrand(r.brand)).reduce((s, r) => s + r.sales, 0);
  console.log(`new-DB lines: ${newGrouped.length}, sales ${tot.toFixed(0)} | classified by mapping: ${(100*cl/tot).toFixed(1)}% | with brand: ${(100*br/tot).toFixed(1)}%`);
  const unc = newGrouped.filter(r => !classifyProduct(r.category));
  console.log('  unclassified raw categories:', [...new Set(unc.map(r => r.category || '(none)'))].slice(0, 12).join(' | '));
}

// ---------- buildBucketedReport (port of FunnelRepository::buildBucketedReport) ----------
const emptyCell = () => ({ sales: 0, cogs: 0, qty: 0, cogsUnknownSales: 0 });
const addCell = (a, b) => { a.sales += b.sales; a.cogs += b.cogs; a.qty += b.qty; a.cogsUnknownSales += b.cogsUnknownSales; return a; };
const r2 = n => Math.round(n * 100) / 100;
const finish = c => ({ sales: r2(c.sales), cogs: r2(c.cogs), qty: c.qty, cogsUnknownSales: r2(c.cogsUnknownSales) });

function buildBucketedReport(rows, classify, fallbackBucket) {
  const periodsSeen = new Set(); const byBucket = {}; let fallbackCount = 0, fallbackSales = 0;
  for (const row of rows) {
    periodsSeen.add(row.period);
    let bucket = classify(row);
    if (bucket === null) { fallbackCount += row.qty; fallbackSales += row.sales; bucket = fallbackBucket; }
    const cell = (byBucket[bucket] ||= {})[row.period] ||= emptyCell();
    addCell(cell, row);
  }
  const periods = [...periodsSeen].sort();
  const q1Periods = periods.filter(p => ['01', '02', '03'].includes(p.slice(5, 7)));
  const q2Periods = periods.filter(p => ['04', '05', '06'].includes(p.slice(5, 7)));
  const sum = cells => cells.reduce((a, c) => addCell(a, c), emptyCell());
  const out = []; const grandTotal = emptyCell(), grandQ1 = emptyCell(), grandQ2 = emptyCell();
  for (const [bucket, byPeriod] of Object.entries(byBucket)) {
    for (const p of periods) byPeriod[p] ||= emptyCell();
    const q1 = sum(q1Periods.map(p => byPeriod[p])), q2 = sum(q2Periods.map(p => byPeriod[p])), total = sum(periods.map(p => byPeriod[p]));
    addCell(grandTotal, total); addCell(grandQ1, q1); addCell(grandQ2, q2);
    const bp = {}; for (const p of periods) bp[p] = finish(byPeriod[p]);
    out.push({ bucket, byPeriod: bp, q1: finish(q1), q2: finish(q2), total: finish(total) });
  }
  for (const r of out) {
    r.q1.share = grandQ1.sales > 0 ? +(r.q1.sales / grandQ1.sales).toFixed(4) : 0;
    r.q2.share = grandQ2.sales > 0 ? +(r.q2.sales / grandQ2.sales).toFixed(4) : 0;
    r.total.share = grandTotal.sales > 0 ? +(r.total.sales / grandTotal.sales).toFixed(4) : 0;
  }
  out.sort((a, b) => a.bucket === fallbackBucket ? 1 : b.bucket === fallbackBucket ? -1 : b.total.sales - a.total.sales);
  return { periods, q1Periods, q2Periods, rows: out, grandTotal: finish(grandTotal), grandQ1: finish(grandQ1), grandQ2: finish(grandQ2),
           uncategorized: { count: fallbackCount, sales: r2(fallbackSales) } };
}

// ---------- cogsGarbageStats (port) — old-DB line items only; new-DB lines have no cogs at all ----------
const oldLines = parseTsv('old_sales_lines.tsv').map(r => ({ product_id: r.product_id, start_price: +r.start_price, final_price: +r.final_price, deal_type: r.deal_type }));
const isRepdigit = v => v > 0 && /^1+$/.test(String(Math.round(v)));
function garbageFor(lines) {
  const clean = {};
  for (const l of lines) if (l.start_price > 0 && !isRepdigit(l.start_price)) (clean[l.product_id] ||= []).push(l.start_price);
  const median = {};
  for (const [pid, vals] of Object.entries(clean)) { vals.sort((a, b) => a - b); const n = vals.length, m = Math.floor(n / 2); median[pid] = n % 2 ? vals[m] : (vals[m - 1] + vals[m]) / 2; }
  let count = 0, salesAffected = 0;
  for (const l of lines) {
    const med = median[l.product_id];
    const g = l.start_price <= 0 || isRepdigit(l.start_price) || (med !== undefined && (l.start_price < 0.5 * med || l.start_price > 2 * med));
    if (g) { count++; salesAffected += l.final_price; }
  }
  return { count, salesAffected: r2(salesAffected), total: lines.length };
}
const garbage = { all: garbageFor(oldLines), installment: garbageFor(oldLines.filter(l => l.deal_type === 'installment')), single: garbageFor(oldLines.filter(l => l.deal_type === 'single')) };
const cogsUnknown = {};
for (const dt of ['all', 'installment', 'single']) {
  const rows = newGrouped.filter(r => dt === 'all' || r.deal_type === dt);
  cogsUnknown[dt] = { count: rows.reduce((s, r) => s + r.qty, 0), sales: r2(rows.reduce((s, r) => s + r.sales, 0)) };
}

function threeWay(classify, fallback) {
  const result = {};
  for (const dt of ['all', 'installment', 'single']) {
    const rows = dt === 'all' ? rawRows : rawRows.filter(r => r.deal_type === dt);
    const rep = buildBucketedReport(rows, classify, fallback);
    rep.garbage = garbage[dt]; rep.cogsUnknown = cogsUnknown[dt];
    result[dt] = rep;
  }
  return result;
}
const salesMonthlyStats = threeWay(r => classifyProduct(r.category), 'Uncategorized');
const subcategoryStats = threeWay(r => classifySubcategory(r.category), 'Uncategorized');
const brandStats = threeWay(r => classifyBrand(r.brand), 'No Brand');

// ---------- Category / Brand (port of buildCategoryBrandReport) ----------
function buildCategoryBrandReport(rows) {
  const byCategory = {};
  const empty = () => ({ sales: 0, cogs: 0, qty: 0, cogsUnknownSales: 0 });
  for (const row of rows) {
    const category = classifyProduct(row.category) || 'Uncategorized';
    const brand = classifyBrand(row.brand) || 'No Brand';
    const m = row.period.slice(5, 7);
    const quarter = ['01', '02', '03'].includes(m) ? 'q1' : ['04', '05', '06'].includes(m) ? 'q2' : null;
    const e = (byCategory[category] ||= {})[brand] ||= { q1: empty(), q2: empty(), total: empty() };
    if (quarter) addCell(e[quarter], row);
    addCell(e.total, row);
  }
  const categories = [];
  for (const [category, brands] of Object.entries(byCategory)) {
    const brandRows = []; const catTotal = empty();
    for (const [brand, per] of Object.entries(brands)) {
      const known = per.total.sales - per.total.cogsUnknownSales;
      const margin = known > 0 ? (known - per.total.cogs) / known : null;
      brandRows.push({ brand, q1: finish(per.q1), q2: finish(per.q2), total: finish(per.total), margin });
      addCell(catTotal, per.total);
    }
    brandRows.sort((a, b) => b.total.sales - a.total.sales);
    for (const br of brandRows) br.share = catTotal.sales > 0 ? +(br.total.sales / catTotal.sales).toFixed(4) : 0;
    const knownCat = catTotal.sales - catTotal.cogsUnknownSales;
    categories.push({ category, brands: brandRows, total: finish(catTotal), margin: knownCat > 0 ? (knownCat - catTotal.cogs) / knownCat : null });
  }
  categories.sort((a, b) => a.category === 'Uncategorized' ? 1 : b.category === 'Uncategorized' ? -1 : b.total.sales - a.total.sales);
  return { categories };
}
const categoryBrandBreakdown = {};
for (const dt of ['all', 'installment', 'single']) categoryBrandBreakdown[dt] = buildCategoryBrandReport(dt === 'all' ? rawRows : rawRows.filter(r => r.deal_type === dt));

const payload = { salesMonthlyStats, brandStats, subcategoryStats, categoryBrandBreakdown, cutover: CUTOVER, generatedAt: new Date().toISOString().slice(0, 16).replace('T', ' ') + ' UTC' };
fs.writeFileSync(path.join(__dirname, 'sales_data.json'), JSON.stringify(payload));

// ---------- checks ----------
const g = salesMonthlyStats.all.grandTotal;
console.log('periods:', salesMonthlyStats.all.periods.join(','), '| rows product/subcat/brand:', salesMonthlyStats.all.rows.length, subcategoryStats.all.rows.length, brandStats.all.rows.length);
console.log('grandTotal all: sales', g.sales, 'cogs', g.cogs, 'qty', g.qty, '| cogs-unknown sales', g.cogsUnknownSales);
console.log('inst+single == all ?', r2(salesMonthlyStats.installment.grandTotal.sales + salesMonthlyStats.single.grandTotal.sales) === g.sales, '| brand/subcat grand == ?', brandStats.all.grandTotal.sales === g.sales, subcategoryStats.all.grandTotal.sales === g.sales);
const cbTotal = r2(categoryBrandBreakdown.all.categories.reduce((s, c) => s + c.total.sales, 0));
console.log('category/brand total == ?', cbTotal === g.sales, cbTotal);
const smart = salesMonthlyStats.all.rows.find(r => r.bucket === 'Smartphones');
console.log('Smartphones Jan (expect 36,450 / 18,861 / 23 for the DB-classifiable subset):', smart && JSON.stringify(smart.byPeriod['2026-01']));
console.log('uncategorized (all):', JSON.stringify(salesMonthlyStats.all.uncategorized), '| garbage all:', JSON.stringify(garbage.all), '| cogsUnknown all:', JSON.stringify(cogsUnknown.all));
console.log('sales_data.json bytes:', fs.statSync(path.join(__dirname, 'sales_data.json')).size);
