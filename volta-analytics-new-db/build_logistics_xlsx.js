// logistics_data.json -> logistics_xlsx.json: the Logistics Daily tab as an Excel workbook (one sheet per table
// group), same row/column layout as the tab and the Volta logo palette (write_daily_mail_xlsx.ps1 renders it).
// Source counts are values; totals and shares are Excel formulas so the sheet recalculates if a number is edited.
//   node build_logistics_xlsx.js && powershell -File write_daily_mail_xlsx.ps1 -JsonPath logistics_xlsx.json -OutPath Volta_Logistics_New_DB.xlsx
const fs = require('fs');
const path = require('path');
const L = JSON.parse(fs.readFileSync(path.join(__dirname, 'logistics_data.json'), 'utf8'));
const MONTH = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const col = n => { let s = ''; n++; while (n > 0) { const m = (n - 1) % 26; s = String.fromCharCode(65 + m) + s; n = Math.floor((n - 1) / 26); } return s; };
const day = d => { const [, m, dd] = d.split('-').map(Number); return MONTH[m - 1] + ' ' + dd; };
const S = v => ({ v, t: 's' });
const N = v => ({ v: v === null || v === undefined ? 0 : v, t: 'n' });
const D = v => (v === null || v === undefined) ? DASH() : ({ v, t: 'd' });
const F = f => ({ f, t: 'n' });
const P = f => ({ f, t: 'p' });
const DASH = () => ({ v: '–', t: 's', dash: true });
const BLANK = () => ({ cls: 'blank', cells: [S('')] });
const DASHCH = '—';

// ---- generic "series table": title, head (label + dates [+ extras]), body rows, total row (SUM formulas) ----
// rows: [{label, vals, cls?}], extras(r, rowNo, totalRowNo) -> extra cell descriptors, extraHead -> labels
function seriesTable(rows, title, headLabel, dates, body, opts = {}) {
  const n = dates.length, extraHead = opts.extraHead || [];
  rows.push({ cls: 'logi-title', cells: [S(title)], span: n + 1 + extraHead.length });
  rows.push({ cls: 'logi-head', cells: [S(headLabel), ...dates.map((d, i) => S(day(d) + (i === n - 1 ? ' (today)' : ''))), ...extraHead.map(S)] });
  const first = rows.length + 1;
  body.forEach((r, i) => {
    const rowNo = rows.length + 1;
    rows.push({ cls: r.cls || (i % 2 ? 'logi-light' : 'logi-white'), cells: [S(r.label), ...r.vals.map(N), ...(opts.extras ? opts.extras(r, rowNo, first + body.length) : [])] });
  });
  const last = rows.length;
  const totalNo = last + 1;
  if (opts.total !== false) {
    rows.push({ cls: 'logi-total', cells: [S(opts.totalLabel || 'Total'), ...dates.map((_, c) => F(`=SUM(${col(c + 1)}${first}:${col(c + 1)}${last})`)), ...(opts.totalExtras ? opts.totalExtras(totalNo, first, last) : [])] });
  }
  if (opts.memo) opts.memo.forEach(m => rows.push({ cls: 'logi-plain', cells: [S(m.label), ...m.vals.map(m.fmt || N)] }));
  return { first, last, totalNo };
}
const note = (rows, text, span) => rows.push({ cls: 'footer', cells: [S(text)], span });

// ---------------- Sheet 1: Pending ----------------
function pendingSheet() {
  const rows = [], h = L.pending, n = h.dates.length;
  rows.push({ cls: 'logi-group', cells: [S('Volta Logistics Daily ' + DASHCH + ' SALES: Pending Status')], span: n + 1 });
  rows.push({ cls: 'logi-head', cells: [S('Status'), ...h.dates.map((d, i) => S(day(d) + (i === n - 1 ? ' (today)' : '')))] });
  const totalNo = rows.length + 1;
  rows.push({ cls: 'logi-total', cells: [S('Sales ' + DASHCH + ' Pending Status (Pending loan applications)'), ...h.dates.map((_, c) => F(`=SUM(${col(c + 1)}${totalNo + 1}:${col(c + 1)}${totalNo + 3})`))] });
  rows.push({ cls: 'logi-white', cells: [S('Up to 1 day'), ...h.upTo1.map(N)] });
  rows.push({ cls: 'logi-light', cells: [S('1 to 5 days'), ...h.oneTo5.map(N)] });
  rows.push({ cls: 'logi-white', cells: [S('>5 days'), ...h.over5.map(N)] });
  rows.push(BLANK());
  note(rows, `Loan applications submitted from ${h.dates[0]} on that are still in the "Pending" status (crm_order_status = 4) at the end of each day, by days since submission. Reconstructed from the CRM's status-change log. The CRM's own Pending figure is higher because it also counts applications migrated from the old CRM that were never closed. Generated ${L.generatedAt}.`, n + 1);
  return { name: 'Pending Status', rows, widths: [46, ...h.dates.map(() => 11)], freezeRow: 2, freezeCol: 1 };
}

// ---------------- Sheet 2: Delivery ----------------
function deliverySheet() {
  const rows = [], h = L.delivery, n = h.dates.length;
  rows.push({ cls: 'logi-group', cells: [S('Volta Logistics Daily ' + DASHCH + ' LOGISTICS: Delivery Status')], span: n + 1 });
  rows.push({ cls: 'logi-head', cells: [S('Status'), ...h.dates.map((d, i) => S(day(d) + (i === n - 1 ? ' (today)' : '')))] });
  const t = rows.length + 1;
  rows.push({ cls: 'logi-total', cells: [S('Logistics ' + DASHCH + ' Number of Not Delivered Orders'), ...h.dates.map((_, c) => F(`=SUM(${col(c + 1)}${t + 1}:${col(c + 1)}${t + 3})`))] });
  rows.push({ cls: 'logi-white', cells: [S('Up to 1 day'), ...h.upTo1.map(N)] });
  rows.push({ cls: 'logi-light', cells: [S('1 to 5 days'), ...h.oneTo5.map(N)] });
  rows.push({ cls: 'logi-white', cells: [S('>5 days'), ...h.over5.map(N)] });
  rows.push({ cls: 'logi-white', cells: [S('On Hold'), ...h.dates.map(() => DASH())] });
  rows.push({ cls: 'logi-plain', cells: [S('Delivered'), ...h.delivered.map(N)] });
  rows.push({ cls: 'logi-plain', cells: [S('Average Delivery Time (days)'), ...h.avgDeliveryTime.map(D)] });
  rows.push(BLANK());
  note(rows, `Not Delivered = orders in the CRM logistics module (statuses Signed / Active) with no delivered / picked-up event yet at the end of that day; an order enters on its sale date or the day the module picked it up, age is counted from the sale date. Delivered = orders whose first delivered / picked-up event (status 80/81) fell on that day; Average Delivery Time = mean days from sale to that event. "On Hold" has no equivalent in the new CRM. Generated ${L.generatedAt}.`, n + 1);
  return { name: 'Delivery Status', rows, widths: [46, ...h.dates.map(() => 11)], freezeRow: 2, freezeCol: 1 };
}

// ---------------- Sheets 3/4: City / Goods (ND by day + ALL by day, shares as trailing formula columns) ----------------
function groupSheet(data, name) {
  const rows = [], dates = data.dates, n = dates.length, S_ = data.summary;
  const monthLabel = MONTH[Number(S_.month.slice(5, 7)) - 1] + ' ' + S_.month.slice(0, 4);
  const lastCol = col(n); // last date column letter (dates start at column B = index 1)
  const monthOf = {}; S_.rows.forEach(r => { monthOf[r.label] = r; });
  rows.push({ cls: 'logi-group', cells: [S('Volta Logistics Daily ' + DASHCH + ' ' + data.title)], span: n + 4 });
  rows.push(BLANK());
  // Not delivered by day + share of last day
  const nd = data.daily[0];
  seriesTable(rows, data.title === 'Orders by City' ? 'Not Delivered Orders ' + DASHCH + ' by day' : nd.label, data.headLabel, dates, nd.rows, {
    extraHead: ['Share ' + day(S_.lastDay)],
    extras: (r, rowNo, totalNo) => [P(`=IF(${lastCol}${totalNo}=0,0,${lastCol}${rowNo}/${lastCol}${totalNo})`)],
    totalExtras: (totalNo) => [P(`=IF(${lastCol}${totalNo}=0,0,${lastCol}${totalNo}/${lastCol}${totalNo})`)],
  });
  rows.push(BLANK());
  // All orders by day + share of last day + month total + month share
  const all = data.daily[1];
  const monthCol = col(n + 2), shareMonthCol = col(n + 3);
  seriesTable(rows, all.label, data.headLabel, dates, all.rows, {
    extraHead: ['Share ' + day(S_.lastDay), monthLabel + ' (month)', 'Share ' + monthLabel],
    extras: (r, rowNo, totalNo) => [
      P(`=IF(${lastCol}${totalNo}=0,0,${lastCol}${rowNo}/${lastCol}${totalNo})`),
      N((monthOf[r.label] || {}).month || 0),
      P(`=IF(${monthCol}${totalNo}=0,0,${monthCol}${rowNo}/${monthCol}${totalNo})`),
    ],
    totalExtras: (totalNo, first, last) => [
      P(`=IF(${lastCol}${totalNo}=0,0,${lastCol}${totalNo}/${lastCol}${totalNo})`),
      F(`=SUM(${monthCol}${first}:${monthCol}${last})`),
      P(`=IF(${monthCol}${totalNo}=0,0,${monthCol}${totalNo}/${monthCol}${totalNo})`),
    ],
  });
  rows.push(BLANK());
  const what = data.title === 'Orders by City' ? "City = the order's shipping address (Tbilisi / other cities / no city recorded)." : "Goods Type = the product's top-level category from the mapping sheet; each order is counted once, under the goods type of its highest-value product line.";
  note(rows, `${what} Not Delivered = orders in the module with no delivered / picked-up event at the end of that day; ALL Orders = every order that had entered the logistics module by the end of that day (delivered ones included). Share = the row's share of the total on the last day; the month columns count the orders that entered the module during ${monthLabel}. Same rules as the Delivery Status sheet, so the Not Delivered totals match it day by day. Generated ${L.generatedAt}.`, n + 4);
  return { name, rows, widths: [30, ...dates.map(() => 11), 13, 16, 15], freezeRow: 0, freezeCol: 1 };
}

// ---------------- Sheet 5: CRM status by day ----------------
function statusSheet() {
  const rows = [], SB = L.statusByDay, dates = SB.dates, n = dates.length;
  rows.push({ cls: 'logi-group', cells: [S('Volta Logistics Daily ' + DASHCH + ' CRM Logistics: Status by Day')], span: n + 1 });
  rows.push(BLANK());
  for (const key of ['orders', 'lines', 'collections']) {
    const sec = SB[key];
    seriesTable(rows, sec.title, 'Status', dates, sec.rows, {
      memo: key === 'orders' && SB.notActivated ? [{ label: 'of which Signed, not yet Active (CRM status)', vals: SB.notActivated }] : undefined,
    });
    rows.push(BLANK());
  }
  note(rows, `The same stages the CRM's Logistics page shows, as they stood at the end of each day. Orders = one row per order in the logistics module; Order lines = each product line's own fulfillment stage (what the CRM's "Lines by status" counts); Vendor collections = pickup runs from vendors. "Partially ready" = an order whose lines are at different stages; "Signed, not yet Active" is a memo line inside the Total. Reconstructed from the CRM's shipment-status history; today's column equals the CRM's live counts at the last refresh. Generated ${L.generatedAt}.`, n + 1);
  return { name: 'CRM Status by Day', rows, widths: [40, ...dates.map(() => 11)], freezeRow: 0, freezeCol: 1 };
}

// ---------------- Sheet 6: Open cases ----------------
function openSheet() {
  const rows = [];
  rows.push({ cls: 'logi-group', cells: [S('Volta Logistics Daily ' + DASHCH + ' Open Cases: Still Waiting for Delivery (today)')], span: 5 });
  rows.push({ cls: 'logi-head', cells: [S('Customer'), S('Waiting from'), S('Status'), S('City'), S('Order #')] });
  if (!L.openCases.length) rows.push({ cls: 'logi-white', cells: [S('No open cases.')], span: 5 });
  L.openCases.forEach((c, i) => rows.push({ cls: i % 2 ? 'logi-light' : 'logi-white', cells: [S(c.customer), S(day(c.waitingFrom)), S(c.status), S(c.city), N(c.orderNum)] }));
  rows.push(BLANK());
  note(rows, `The 10 oldest orders in the logistics module (by sale date) with no delivered / picked-up event yet. Status = the CRM logistics stage of the order. Generated ${L.generatedAt}.`, 5);
  return { name: 'Open Cases', rows, widths: [28, 14, 40, 16, 10], freezeRow: 2, freezeCol: 0 };
}

const sheets = [pendingSheet(), deliverySheet(), groupSheet(L.byCity, 'By City'), groupSheet(L.byGoods, 'By Goods Type'), statusSheet(), openSheet()];
fs.writeFileSync(path.join(__dirname, 'logistics_xlsx.json'), JSON.stringify({ sheets }));
sheets.forEach(s => console.log(s.name, ': rows', s.rows.length, ', max cols', Math.max(...s.rows.map(r => r.cells.length))));
