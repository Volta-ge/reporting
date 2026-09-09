"""ORIS <-> RS.ge waybill reconciliation for the RS_New DB dashboard's "Oris <-> RS.ge" tab.

Moved here 2026-09-10 from the Volta_Finance project (per the user's request to keep this
comparison out of Volta_Finance and make RS_New DB a single self-contained file, as before).
Reuses the RS.ge waybill rows `refresh_dashboard_gia.main()` already fetches (`raw_wb`/`raw_pur`,
via `fetch_waybills()`/`fetch_purchase_waybills()`) - no second RS.ge call. The Oris side reads
`oris.sqlite` (built by the Volta_Finance project's own pipeline, see
D:/all/volta/Volta_Accounting/README.md) directly - a read-only cross-project data dependency,
not duplicated logic: the exact same query as volta-finance/tools/build_waybills.py's
`oris_side()`.

Oris side, per waybill number (WIRING.DOC, digits only, leading zeros ignored for matching):
  sales     : Dr 1 4 10 x (customer) against Cr 6 1 * (revenue) or 3 3 30 (VAT payable) -> gross amount;
              the reverse posting (Cr 1 4 10 x against Dr 6 1 * / 3 3 30) is a return and is subtracted.
  purchases : Cr 3 1 10 x (supplier) against Dr 1 6 * / 3 3 40 / 7 * / 2 1 * / 2 5 * -> gross amount;
              the reverse posting is a return to the supplier and is subtracted.
Matching is by waybill number only. Period of a pair = RS BEGIN_DATE, of an Oris-only waybill = Oris posting date.
"""
import os
import re
import sqlite3
import time

ORIS_DATA = os.environ.get('VOLTA_FIN_DATA', 'D:/all/volta/Volta_Accounting')


def _key(n):
    d = re.sub(r'\D', '', n or '')
    return d.lstrip('0') or d


def _compact(raw, side, own_tin):
    rows = []
    for r in raw:
        n = (r.get('WAYBILL_NUMBER') or '').strip()
        if not n or r.get('STATUS') == '-2':
            continue
        if side == 'buy' and r.get('SELLER_TIN') == own_tin:
            continue
        amt = float(r['FULL_AMOUNT']) if r.get('FULL_AMOUNT') else 0.0
        if r.get('TYPE') == '5':
            amt = -amt
        party = (r.get('BUYER_NAME') if side == 'sell' else r.get('SELLER_NAME')) or ''
        tin = (r.get('BUYER_TIN') if side == 'sell' else r.get('SELLER_TIN')) or ''
        rows.append({'n': n, 'd': (r.get('BEGIN_DATE') or r.get('CREATE_DATE') or '')[:10], 'p': party.strip(),
                     't': tin, 'a': round(amt, 2), 's': r.get('STATUS', ''), 'y': r.get('TYPE', '')})
    return rows


def _oris_side(con, names, side):
    """-> ({key: {'n','d','p','a','acc'}}, fx_row_count) for every DOC with a sale/purchase posting (all dates)."""
    if side == 'sell':
        party = '1 4 10 '
        c0 = "KREDIT LIKE '6 1%' OR KREDIT LIKE '3 3 30%'"
        c1 = "DEBET LIKE '6 1%' OR DEBET LIKE '3 3 30%'"
        q = f"""SELECT DOC, DATE, DEBET AS party, MONEY, MON_TYPE FROM WIRING WHERE DEBET LIKE '{party}%' AND ({c0}) AND DOC<>''
                UNION ALL SELECT DOC, DATE, KREDIT, -MONEY, MON_TYPE FROM WIRING WHERE KREDIT LIKE '{party}%' AND ({c1}) AND DOC<>''"""
    else:
        party = '3 1 10 '
        c0 = "DEBET LIKE '1 6%' OR DEBET LIKE '3 3 40%' OR DEBET LIKE '7%' OR DEBET LIKE '2 1%' OR DEBET LIKE '2 5%'"
        c1 = "KREDIT LIKE '1 6%' OR KREDIT LIKE '3 3 40%' OR KREDIT LIKE '7%' OR KREDIT LIKE '2 1%' OR KREDIT LIKE '2 5%'"
        q = f"""SELECT DOC, DATE, KREDIT AS party, MONEY, MON_TYPE FROM WIRING WHERE KREDIT LIKE '{party}%' AND ({c0}) AND DOC<>''
                UNION ALL SELECT DOC, DATE, DEBET, -MONEY, MON_TYPE FROM WIRING WHERE DEBET LIKE '{party}%' AND ({c1}) AND DOC<>''"""
    out = {}
    fx = 0
    for doc, date, acc, money, mon in con.execute(q):
        k = _key(doc)
        if not k:
            continue
        if mon != 'GEL':
            fx += 1
        o = out.get(k)
        if not o:
            o = out[k] = {'n': doc.strip().lstrip("'"), 'd': date, 'p': names.get(acc, acc).strip(), 'acc': acc, 'a': 0.0}
        o['a'] += money
        if date < o['d']:
            o['d'] = date
    for o in out.values():
        o['a'] = round(o['a'], 2)
    return out, fx


def build(raw_wb, raw_pur, own_tin, start_date):
    """raw_wb/raw_pur: the raw SOAP dicts already fetched by refresh_dashboard_gia.main().
    start_date: 'YYYY-MM-DD' string (refresh_dashboard.START), used the same way build_waybills.py
    used rs_waybills.json's own 'start' - the cutoff below which an Oris-only waybill is dropped
    (it predates the RS.ge pull window, so "missing on RS.ge" there would be meaningless)."""
    con = sqlite3.connect(os.path.join(ORIS_DATA, 'oris.sqlite'))
    names = dict(con.execute("SELECT COUNT, NAME FROM ACC_NAME"))
    rs = {'sell': _compact(raw_wb, 'sell', own_tin), 'buy': _compact(raw_pur, 'buy', own_tin)}
    result = {'meta': {'rs_fetched': time.strftime('%Y-%m-%d %H:%M'), 'start': start_date, 'oris_fx_rows': {}}}
    for side in ('sell', 'buy'):
        oris, fx = _oris_side(con, names, side)
        result['meta']['oris_fx_rows'][side] = fx
        rows = []
        seen = set()
        for r in rs[side]:
            k = _key(r['n'])
            seen.add(k)
            o = oris.get(k)
            rows.append([r['n'], r['d'], r['p'], r['t'], r['a'], o['a'] if o else None, o['d'] if o else None,
                         r['y'], r['s'], (o or {}).get('acc', '')])
        for k, o in oris.items():
            if k in seen or o['d'] < start_date:
                continue
            rows.append([o['n'], o['d'], o['p'], '', None, o['a'], o['d'], '', '', o['acc']])
        rows.sort(key=lambda x: x[1], reverse=True)
        result[side] = rows
    con.close()
    return result
