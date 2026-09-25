r"""Generates mapping_table.csv (D:/all/volta/Volta_Accounting/) from mapping_table.py's classify()
applied to every account Oris has - the master table that will move to Google Sheets (2026-09-25
plan). Does not touch the live dashboard; read-only against oris.sqlite + dash_data.json.
"""
import csv
import json
import os
import sqlite3

BASE = os.environ.get('VOLTA_FIN_DATA', 'D:/all/volta/Volta_Accounting')
YEAR = '2026'

import mapping_table as mt

d = json.load(open(os.path.join(BASE, 'dash_data.json'), encoding='utf-8'))
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


for d_, k_ in con.execute("SELECT DEBET, KREDIT FROM WIRING WHERE DATE>=? AND DATE<? AND NOT (DEBET LIKE 'B%' OR KREDIT LIKE 'B%')",
                           (YEAR + '-01-01', str(int(YEAR) + 1) + '-01-01')):
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
        p = ' '.join(t[:i])
        children_of.setdefault(p, []).append(c)

pl_map, bs_map = mt.build_maps(posting_codes, children_of)

rows = []
for code, (name, level) in sorted(acc.items(), key=lambda kv: tuple(t.zfill(12) if t.isdigit() else t for t in kv[0].split(' '))):
    cls = mt.classify(code, pl_map, bs_map, cash_counter, cat_multi)
    rows.append({
        'code': code, 'name': name,
        'pl_line': ';'.join(str(x) for x in cls['pl']),
        'bs_line': ';'.join(str(x) for x in cls['bs']),
        'cf_category': ';'.join(str(x) for x in cls['cf']),
    })

out = os.path.join(BASE, 'mapping_table.csv')
with open(out, 'w', encoding='utf-8', newline='') as f:
    w = csv.DictWriter(f, fieldnames=['code', 'name', 'pl_line', 'bs_line', 'cf_category'])
    w.writeheader()
    w.writerows(rows)
print('wrote', out, len(rows), 'accounts')
print('posting_codes', len(posting_codes), 'cash_counter', len(cash_counter))
