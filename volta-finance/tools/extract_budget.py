r"""One-time extraction of the P&L Budget and Balance Sheet Budget lines from the finance
team's Excel model into budget_data.json, for the "PL Budget"/"BS Budget" tabs.

Source: D:\all\volta\Finance\Badget VS Acctual Jul'2026.xlsx, sheet FS-BvsA'Jul'26.
Only the BUDGET columns (B/2026-1 .. B/2026-12, i.e. columns G-R) are pulled - no Actual, no
Variance. This is a curated row list (not a generic dump): row numbers, indent level and
bold/section flags were picked by hand after inspecting the sheet, because the workbook has no
machine-readable outline/level markers of its own. Re-run this script (then build_dashboard.py)
if the user supplies an updated budget workbook; it is NOT part of the Oris refresh.py chain.
"""
import json, os, sys
import openpyxl

XLSX = os.environ.get('VOLTA_BUDGET_XLSX', r"D:\all\volta\Finance\Badget VS Acctual Jul'2026.xlsx")
BASE = os.environ.get('VOLTA_FIN_DATA', 'D:/all/volta/Volta_Accounting')
OUT = os.path.join(BASE, 'budget_data.json')
SHEET = "FS-BvsA'Jul'26"
MONTH_COLS = ['G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R']  # Budget Jan..Dec 2026

# (row, label_ka, level, bold)  -- level 1 = section/total line, 2 = detail line
PL_ROWS = [
    (78, 'შემოსავალი (გაყიდვები)', 1, False),
    (79, 'შემოსავალი დღგ-ს გარეშე', 2, False),
    (80, 'თვითღირებულება დღგ-ს ჩათვლით', 2, False),
    (81, 'თვითღირებულება', 1, False),
    (82, 'საერთო მოგება (გაყიდვები)', 1, True),
    (84, 'შემოსავალი მომსახურებიდან', 1, False),
    (85, 'ჯარიმები', 1, False),
    (86, 'პორტფელის ჩამოწერილი ვალის რეალიზაცია', 1, False),
    (87, 'სხვა შემოსავალი', 1, False),
    (90, 'საოპერაციო ხარჯები', 1, True),
    (91, 'მიწოდების ხარჯები', 2, False),
    (92, 'პარტნიორების ხელფასი', 2, False),
    (93, 'სხვა პერსონალის ხელფასი', 2, False),
    (94, 'მენეჯმენტის საკომისიო', 2, False),
    (95, 'საოფისე ხარჯები', 2, False),
    (96, 'ოფისის ქირა', 2, False),
    (98, 'სისტემის განვითარება/მხარდაჭერა', 2, False),
    (99, 'კომუნალური ხარჯები', 2, False),
    (100, 'მარკეტინგის ხარჯები', 2, False),
    (101, 'მარკეტინგის ხელფასები', 2, False),
    (102, 'საეჭვო მოთხოვნების რეზერვი', 2, False),
    (103, 'კორპორატიული ღონისძიება', 2, False),
    (104, 'გაუთვალისწინებელი ხარჯები', 2, False),
    (105, 'EBITDA', 1, True),
    (108, 'არასაოპერაციო ხარჯები', 1, True),
    (109, 'ცვეთა', 2, False),
    (111, 'საინვესტორო პროცენტი 15%', 2, False),
    (112, 'პარტნიორის კაპიტალის პროცენტი', 2, False),
    (113, 'საბანკო პროცენტი', 2, False),
    (114, 'სესხის უზრუნველყოფა გიორგის ქონებით', 2, False),
    (117, 'დივიდენდები', 2, False),
    (118, 'წმინდა მოგება', 1, True),
]
# (row, level, bold) -- BS rows already carry both Georgian (col B) and English (col C) labels
BS_ROWS = [
    (124, 1, True), (125, 2, False), (126, 2, False), (127, 2, False), (128, 2, False),
    (129, 1, True), (130, 2, False), (131, 2, False), (132, 2, False), (133, 2, False), (134, 2, False),
    (135, 1, True),
    (137, 1, True), (138, 2, False), (139, 2, False), (140, 2, False), (141, 2, False), (142, 2, False),
    (143, 2, False), (144, 2, False), (145, 2, False), (146, 2, False), (147, 2, False),
    (148, 1, True), (149, 2, False), (150, 2, False), (151, 2, False),
    (152, 1, True),
    (154, 1, True), (155, 2, False), (156, 2, False),
    (157, 1, True),
]
CF_ROWS = [
    (26, 'ნაღდი ფული საოპერაციო საქმიანობიდან', 1, True),
    (27, 'შემოსვლა', 1, False),
    (28, 'ძირითადი თანხის ამონაგები', 2, False),
    (29, 'ავანსი (თავდებული)', 2, False),
    (30, 'შემოსავალი მომსახურებიდან', 2, False),
    (31, 'ჯარიმები', 2, False),
    (32, 'პორტფელის ჩამოწერილი ვალის რეალიზაცია', 2, False),
    (33, 'გასავალი', 1, False),
    (34, 'თვითღირებულება', 2, False),
    (35, 'პარტნიორების ხელფასი', 2, False),
    (36, 'სხვა პერსონალის ხელფასი', 2, False),
    (37, 'მენეჯმენტის საკომისიო', 2, False),
    (38, 'მიწოდების ხარჯები', 2, False),
    (39, 'მარკეტინგის ხარჯები', 2, False),
    (40, 'მარკეტინგის ხელფასები', 2, False),
    (41, 'ოფისის ქირა', 2, False),
    (42, 'საოფისე ხარჯები', 2, False),
    (43, 'სისტემის განვითარება/მხარდაჭერა', 2, False),
    (44, 'კომუნალური ხარჯები', 2, False),
    (45, 'დღგ', 2, False),
    (46, 'საინვესტორო პროცენტი 15%', 2, False),
    (48, 'საბანკო პროცენტი', 2, False),
    (49, 'სესხის უზრუნველყოფა გიორგის ქონებით', 2, False),
    (50, 'კორპორატიული ღონისძიება', 2, False),
    (51, 'გაუთვალისწინებელი ხარჯები', 2, False),
    (52, 'ნაღდი ფული საინვესტიციო საქმიანობიდან', 1, True),
    (53, 'შემოსვლა', 1, False),
    (54, 'სისტემის განვითარების რეალიზაცია', 2, False),
    (55, 'სხვა რეალიზაცია', 2, False),
    (56, 'მიწოდების ავტომობილის რეალიზაცია', 2, False),
    (57, 'გასავალი', 1, False),
    (58, 'სისტემის განვითარება', 2, False),
    (59, 'გამყიდველების ოფისის ინვენტარი', 2, False),
    (60, 'მიწოდების ავტომობილის შესყიდვა', 2, False),
    (61, 'ნაღდი ფული საფინანსო საქმიანობიდან', 1, True),
    (62, 'შემოსვლა', 1, False),
    (63, 'ახალი სესხი BOG-სგან', 2, False),
    (65, 'ახალი სესხი ზუკისგან', 2, False),
    (67, 'გასავალი', 1, False),
    (71, 'ბანკის სესხის დაფარვა', 2, False),
    (72, 'ფულის მთლიანი მოძრაობა', 1, True),
    (73, 'ნაშთი დასაწყისში', 1, True),
    (74, 'ნაშთი ბოლოს', 1, True),
]
CF_EN = {26: 'Cash From Operations', 27: 'Cash In', 28: 'Principal', 29: 'Downpayment',
         30: 'Revenue From Services', 31: 'Penalty', 32: 'Sales of Portfolio Bad Debts', 33: 'Cash Out',
         34: 'Cogs', 35: 'Salary of Shareholders', 36: 'Salary of Other Staff', 37: 'Management Fee',
         38: 'Delivery Expenses', 39: 'Marketing Expenses', 40: 'Marketing Salaries', 41: 'Office Rent',
         42: 'Office Expenses', 43: 'System Development/Maintenance', 44: 'Utility Cost', 45: 'Vat',
         46: 'Investors Interest 15%', 48: 'Bank Interest', 49: "Securing a Loan with Giorgi's Property",
         50: 'Corporate Party', 51: 'Unpredictable Expenses', 52: 'Cash From Investment', 53: 'Cash In',
         54: 'Sales of System Development', 55: 'Other Sales', 56: 'Sales of Car for Delivery',
         57: 'Cash Out', 58: 'System Development', 59: 'Salesman Office Equipment Capex',
         60: 'Car Purchase for Delivery', 61: 'Cash From Financing', 62: 'Cash In',
         63: 'New Loan From BOG', 65: 'New Loan From Zuk', 67: 'Cash Out', 71: 'Bank Repayments',
         72: 'Total Cash Movement', 73: 'Starting Balance of Cash', 74: 'Ending Balance of Cash'}
# Portfolio: (row, label_en, label_ka)
PORTFOLIO_ROWS = [
    (14, 'Total Collection', 'სულ ინკასაცია'),
    (15, 'Portfolio at Start of Month', 'პორტფელი თვის დასაწყისში'),
    (16, 'Remaining Portfolio', 'დარჩენილი პორტფელი'),
    (21, 'CAC (Marketing KPI)', 'მარკეტინგის მოზიდვის ღირებულება (CAC)'),
    (22, 'Remaining Clients', 'დარჩენილი კლიენტები'),
]
# Assumptions: (row, label_en, label_ka) -- all percentages
ASSUMPTIONS_ROWS = [
    (4, 'Sales Growth', 'გაყიდვების ზრდის ტემპი'),
    (5, 'Mark Up (on Installments + Cash Sales)', 'მარკირება (განვადება + ნაღდი გაყიდვები)'),
    (6, 'Markup (on Installments)', 'მარკირება (განვადებაზე)'),
    (7, 'Provision Rate', 'რეზერვის განაკვეთი'),
    (8, 'Marketing Expense (% of Revenue)', 'მარკეტინგის ხარჯი, % შემოსავლიდან'),
    (9, 'Fine Income (% of Revenue)', 'ჯარიმის შემოსავალი, % შემოსავლიდან'),
    (10, 'Monthly Principal Collection Rate', 'ძირითადი თანხის ყოველთვიური ინკასაციის განაკვეთი'),
    (11, 'Downpayment %', 'თავდებულის %'),
]
PL_EN = {78: 'Revenue', 79: 'Revenue W/O VAT', 80: 'Cogs W VAT', 81: 'Cogs', 82: 'Gross Profit of Sales',
         84: 'Revenue From Services', 85: 'Penalty', 86: 'Sales of Portfolio Bad Debts', 87: 'Other Revenue',
         90: 'Opex', 91: 'Delivery Expenses', 92: 'Salary of Shareholders', 93: 'Salary of Other Staff',
         94: 'Management Fee', 95: 'Office Expenses', 96: 'Office Rent', 98: 'System Development/Maintenance',
         99: 'Utility Cost', 100: 'Marketing Expenses', 101: 'Marketing Salaries', 102: 'Provision',
         103: 'Corporate Party', 104: 'Unpredictable Expenses', 105: 'EBITDA', 108: 'Non-Operational Expenses',
         109: 'Depreciation', 111: 'Investors Interest 15%', 112: "Interest of Shareholder's Equity",
         113: 'Bank Interest', 114: "Securing a Loan with Giorgi's Property", 117: 'Dividends', 118: 'Net Profit'}

wb = openpyxl.load_workbook(XLSX, read_only=True, data_only=True)
ws = wb[SHEET]


def col(r, letter):
    return ws.cell(row=r, column=openpyxl.utils.column_index_from_string(letter)).value


def month_vals(r):
    return [round(v, 2) if isinstance(v, (int, float)) else 0.0 for v in (col(r, c) for c in MONTH_COLS)]


pl = [{'row': r, 'ka': ka, 'en': PL_EN.get(r, ka), 'level': lv, 'bold': b, 'vals': month_vals(r)}
      for r, ka, lv, b in PL_ROWS]
def bs_vals(r):
    # Assets (rows < 137) are stored positive in the sheet; liabilities & equity (>=137) are
    # stored negative (credit balances) - flip them so Assets = Liabilities + Equity, matching
    # the sign convention already used by the rest of this dashboard (everything shown positive).
    vals = month_vals(r)
    return vals if r < 137 else [-v for v in vals]


bs = [{'row': r, 'ka': (col(r, 'B') or '').strip(), 'en': (col(r, 'C') or '').strip(), 'level': lv, 'bold': b,
       'vals': bs_vals(r)}
      for r, lv, b in BS_ROWS]

cf = [{'row': r, 'ka': ka, 'en': CF_EN.get(r, ka), 'level': lv, 'bold': b, 'vals': month_vals(r)}
      for r, ka, lv, b in CF_ROWS]
portfolio = [{'row': r, 'ka': ka, 'en': en, 'level': 1, 'bold': r == 16, 'vals': month_vals(r)}
             for r, en, ka in PORTFOLIO_ROWS]
def pct_vals(r):  # ratios need more precision than the 2dp money rounding
    return [round(v, 6) if isinstance(v, (int, float)) else 0.0 for v in (col(r, c) for c in MONTH_COLS)]


assumptions = [{'row': r, 'ka': ka, 'en': en, 'level': 1, 'bold': False, 'pct': True, 'vals': pct_vals(r)}
               for r, en, ka in ASSUMPTIONS_ROWS]

months = [f'2026-{m:02d}' for m in range(1, 13)]
out = {'source': os.path.basename(XLSX), 'sheet': SHEET, 'months': months,
       'pl': pl, 'bs': bs, 'cf': cf, 'portfolio': portfolio, 'assumptions': assumptions}
os.makedirs(BASE, exist_ok=True)
json.dump(out, open(OUT, 'w', encoding='utf-8'), ensure_ascii=False, separators=(',', ':'))
print('wrote', OUT, 'pl', len(pl), 'bs', len(bs), 'cf', len(cf), 'portfolio', len(portfolio), 'assumptions', len(assumptions))
