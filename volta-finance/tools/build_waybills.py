"""Join RS.ge waybills (rs_waybills.json) with the waybills booked in Oris (oris.sqlite WIRING.DOC)
-> wb_data.json for the "ზედნადებები RS <-> ორისი" tab.

Oris side, per waybill number (WIRING.DOC, digits only, leading zeros ignored for matching):
  sales     : Dr 1 4 10 x (customer) against Cr 6 1 * (revenue) or 3 3 30 (VAT payable) -> gross amount;
              the reverse posting (Cr 1 4 10 x against Dr 6 1 * / 3 3 30) is a return and is subtracted.
  purchases : Cr 3 1 10 x (supplier) against Dr 1 6 * / 3 3 40 / 7 * / 2 1 * / 2 5 * -> gross amount;
              the reverse posting is a return to the supplier and is subtracted.
  Bank payments (1 2 *), cash and other postings carrying the same DOC are ignored, so the Oris amount is the
  invoice value of the waybill, comparable with RS FULL_AMOUNT.
Matching is by waybill number only. Period of a pair = RS BEGIN_DATE, of an Oris-only waybill = Oris posting date.
"""
import sqlite3, json, os, re, collections
BASE = os.environ.get('VOLTA_FIN_DATA', 'D:/all/volta/Volta_Accounting')
rs = json.load(open(os.path.join(BASE, 'rs_waybills.json'), encoding='utf-8'))
START = rs['start']
con = sqlite3.connect(os.path.join(BASE, 'oris.sqlite'))
names = dict(con.execute("SELECT COUNT, NAME FROM ACC_NAME"))

def key(n):
    d = re.sub(r'\D', '', n or '')
    return d.lstrip('0') or d

def oris_side(side):
    """-> {key: {'n','d','p','a','acc'}} for every DOC that has a sale/purchase posting (all dates)."""
    if side == 'sell':
        party, cnt = '1 4 10 ', ("KREDIT LIKE '6 1%' OR KREDIT LIKE '3 3 30%'", "DEBET LIKE '6 1%' OR DEBET LIKE '3 3 30%'")
        q = f"""SELECT DOC, DATE, DEBET AS party, MONEY, MON_TYPE FROM WIRING WHERE DEBET LIKE '{party}%' AND ({cnt[0]}) AND DOC<>''
                UNION ALL SELECT DOC, DATE, KREDIT, -MONEY, MON_TYPE FROM WIRING WHERE KREDIT LIKE '{party}%' AND ({cnt[1]}) AND DOC<>''"""
    else:
        party = '3 1 10 '
        c0 = "DEBET LIKE '1 6%' OR DEBET LIKE '3 3 40%' OR DEBET LIKE '7%' OR DEBET LIKE '2 1%' OR DEBET LIKE '2 5%'"
        c1 = "KREDIT LIKE '1 6%' OR KREDIT LIKE '3 3 40%' OR KREDIT LIKE '7%' OR KREDIT LIKE '2 1%' OR KREDIT LIKE '2 5%'"
        q = f"""SELECT DOC, DATE, KREDIT AS party, MONEY, MON_TYPE FROM WIRING WHERE KREDIT LIKE '{party}%' AND ({c0}) AND DOC<>''
                UNION ALL SELECT DOC, DATE, DEBET, -MONEY, MON_TYPE FROM WIRING WHERE DEBET LIKE '{party}%' AND ({c1}) AND DOC<>''"""
    out = {}
    fx = 0
    for doc, date, acc, money, mon in con.execute(q):
        k = key(doc)
        if not k: continue
        if mon != 'GEL': fx += 1  # FX-denominated rows are rare on these accounts; amounts stay as booked (currency) — flagged in meta
        o = out.get(k)
        if not o: o = out[k] = {'n': doc.strip().lstrip("'"), 'd': date, 'p': names.get(acc, acc).strip(), 'acc': acc, 'a': 0.0}
        o['a'] += money
        if date < o['d']: o['d'] = date
    for o in out.values(): o['a'] = round(o['a'], 2)
    return out, fx

result = {'meta': {'rs_fetched': rs['fetched'], 'start': START, 'oris_fx_rows': {}}}
for side in ('sell', 'buy'):
    oris, fx = oris_side(side)
    result['meta']['oris_fx_rows'][side] = fx
    rows = []
    seen = set()
    for r in rs[side]:
        k = key(r['n']); seen.add(k)
        o = oris.get(k)
        rows.append([r['n'], r['d'], r['p'], r['t'], r['a'], o['a'] if o else None, o['d'] if o else None, r['y'], r['s'], (o or {}).get('acc', '')])
    for k, o in oris.items():
        if k in seen or o['d'] < START: continue
        rows.append([o['n'], o['d'], o['p'], '', None, o['a'], o['d'], '', '', o['acc']])
    rows.sort(key=lambda x: x[1], reverse=True)
    result[side] = rows
    n_rs = len(rs[side]); n_both = sum(1 for x in rows if x[4] is not None and x[5] is not None)
    print(f"{side}: RS {n_rs}, Oris-only {len(rows)-n_rs}, matched {n_both}, RS-only {n_rs-n_both}")
out = os.path.join(BASE, 'wb_data.json')
json.dump(result, open(out, 'w', encoding='utf-8'), ensure_ascii=False, separators=(',', ':'))
print('written', out, round(os.path.getsize(out)/1e6, 2), 'MB')
