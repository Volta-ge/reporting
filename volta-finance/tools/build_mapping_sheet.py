r"""Builds the human-readable mapping table for Google Sheets (2026-09-25 plan): same account->line
classification as mapping_table.csv, but with the actual Georgian line NAMES instead of bare row
numbers, so the finance team can read/edit it without memorizing budget row IDs. Read-only against
oris.sqlite/dash_data.json/budget_data.json; writes mapping_sheet.csv (does not touch the dashboard).
"""
import csv
import json
import os
import sqlite3

import mapping_table as mt

BASE = os.environ.get('VOLTA_FIN_DATA', 'D:/all/volta/Volta_Accounting')

d = json.load(open(os.path.join(BASE, 'dash_data.json'), encoding='utf-8'))
budget = json.load(open(os.path.join(BASE, 'budget_data.json'), encoding='utf-8'))
acc = d['acc']
cat_multi = d.get('cat_multi', {})

con = sqlite3.connect(os.path.join(BASE, 'oris.sqlite'))


def depth(code):
    t = code.split(' ')
    n = 3 if t[0] == '1' and len(t) > 1 and t[1] == '6' else 4
    return ' '.join(t[:n])


posting_codes = set()
cash_counter = set()
CASH = ('1 1', '1 2')


def is_cash(c):
    return c.startswith(CASH)


for d_, k_ in con.execute("SELECT DEBET, KREDIT FROM WIRING WHERE DATE>='2026-01-01' AND DATE<'2027-01-01' AND NOT (DEBET LIKE 'B%' OR KREDIT LIKE 'B%')"):
    dd, kk = depth(d_), depth(k_)
    posting_codes.add(dd)
    posting_codes.add(kk)
    dc, kc = is_cash(dd), is_cash(kk)
    if dc and not kc and kk != '1 6 35':
        cash_counter.add(kk)
    if kc and not dc and dd != '1 6 35':
        cash_counter.add(dd)

children_of = {}
for c in acc:
    t = c.split(' ')
    for i in range(1, len(t)):
        children_of.setdefault(' '.join(t[:i]), []).append(c)

pl_map, bs_map = mt.build_maps(posting_codes, children_of)

PL_LABEL = {r['row']: r['ka'] for r in budget['pl']}
BS_LABEL = {r['row']: r['ka'] for r in budget['bs']}
CAT_ROW = {'cogs': 34, 'salary_sh': 35, 'salary_other': 36, 'management_fee': 37, 'delivery': 38, 'marketing': 39,
           'marketing_salaries': 40, 'office_rent': 41, 'office_exp': 42, 'utility': 44,
           'interest_investors': 46, 'interest_sh': 47, 'interest_bank': 48, 'corporate_party': 50,
           'capex_sysdev': 58, 'capex_office': 59, 'capex_truck': 60, 'loan_bog': 63, 'loan_sh_draw': 64,
           'loan_zuk_draw': 65, 'dividend': 68, 'loan_to_finhub': 69, 'loan_sh_repay': 70,
           'loan_principal_all': 71, 'other_sales': 55}
CF_LABEL = {k: PL_LABEL.get(v) or BS_LABEL.get(v) or '' for k, v in {}.items()}  # placeholder, filled per-row below
CF_ROW_LABEL = {r['row']: r['ka'] for r in budget['cf']}


def pl_label(row):
    if row == 'sal':
        return PL_LABEL.get(92, '') + ' / ' + PL_LABEL.get(93, '')
    if row == 'int':
        return ' / '.join(x for x in (PL_LABEL.get(111, ''), PL_LABEL.get(112, ''), PL_LABEL.get(113, '')) if x)
    if row == 'none':
        return 'ბიუჯეტის მუხლის გარეშე'
    return PL_LABEL.get(row, str(row))


def bs_label(row):
    if isinstance(row, str) and row.startswith('t'):
        n = int(row[1:])
        return (BS_LABEL.get(n, '') or '') + ' (საკუთარი მუხლის გარეშე)'
    return BS_LABEL.get(row, str(row))


def cf_label(cat):
    if cat == 'op':
        return 'საოპერაციო ნაკადი (საკუთარი მუხლის გარეშე)'
    if cat == 'cash':
        return 'ფული (საწყისი/საბოლოო ნაშთი)'
    if cat == 'xfer':
        return 'შიდა გადარიცხვა (ფულად ნაკადში არ ითვლება)'
    return CF_ROW_LABEL.get(CAT_ROW.get(cat), cat)


# Scope (2026-09-25): only the accounts that are an actual POLICY decision point - i.e. the ~400
# accounts that appear as an explicit key somewhere in the classification (a PL/BS line's own account
# list, or a CF_CAT_CODES/salary-shareholder/interest-lender membership) - not all 7,947 accounts Oris
# has. Every other account (customer/vendor/item sub-ledgers) inherits its line from one of these via
# prefix match and isn't something a human would edit individually; keeping the sheet to the ~400 real
# decision points is what makes it editable at all (the full 7,947-row dump is 1.6MB, too large to be
# a usable spreadsheet and mostly not-independently-editable rows anyway).
POLICY = set(pl_map) | set(bs_map) | set(cat_multi) | set(mt.SH_PAYROLL) | set(mt.SH_CONSULT)

rows = []
for code in sorted(POLICY, key=lambda c: tuple(t.zfill(12) if t.isdigit() else t for t in c.split(' '))):
    name = (acc.get(code) or [''])[0]
    cls = mt.classify(code, pl_map, bs_map, cash_counter, cat_multi)
    rows.append({
        'code': code, 'name': name,
        'pl_line': '; '.join(pl_label(x) for x in cls['pl']),
        'bs_line': '; '.join(bs_label(x) for x in cls['bs']),
        'cf_line': '; '.join(cf_label(x) for x in cls['cf']),
        'pl_raw': ';'.join(str(x) for x in cls['pl']),
        'bs_raw': ';'.join(str(x) for x in cls['bs']),
        'cf_raw': ';'.join(str(x) for x in cls['cf']),
    })

out = os.path.join(BASE, 'mapping_sheet.csv')
with open(out, 'w', encoding='utf-8', newline='') as f:
    w = csv.DictWriter(f, fieldnames=['code', 'name', 'pl_line', 'bs_line', 'cf_line', 'pl_raw', 'bs_raw', 'cf_raw'])
    w.writeheader()
    w.writerows(rows)
print('wrote', out, len(rows), 'accounts')
