"""Fetch Volta's RS.ge waybills (seller side = sales, buyer side = purchases) for the
Oris <-> RS.ge waybill reconciliation tab and cache them as rs_waybills.json in the data folder.

Reuses the SOAP fetchers of the waybill dashboard project (Desktop/Volta_Waybills/refresh_dashboard.py),
so credentials stay in that project's gitignored config.py and are never copied here.
Window: refresh_dashboard.START (2025-09-01) -> now.  Cancelled (STATUS=-2) and unnumbered waybills are dropped,
TYPE=5 (returns) keep a negative amount, Volta->Volta internal transfers are dropped on the buyer side.
If the fetch fails the previous cache (if any) is kept and the exit code is non-zero.
"""
import sys, os, json, time
BASE = os.environ.get('VOLTA_FIN_DATA', 'D:/all/volta/Volta_Accounting')
RS_TOOLS = os.environ.get('VOLTA_RS_TOOLS', 'C:/Users/Lenovo/Desktop/Volta_Waybills')
OUT = os.path.join(BASE, 'rs_waybills.json')
sys.path.insert(0, RS_TOOLS)
import refresh_dashboard as rd   # noqa: E402  (imports config.py from RS_TOOLS)

def compact(raw, side):
    rows = []
    for r in raw:
        n = (r.get('WAYBILL_NUMBER') or '').strip()
        if not n or r.get('STATUS') == '-2': continue
        if side == 'buy' and r.get('SELLER_TIN') == rd.OWN_TIN: continue
        amt = float(r['FULL_AMOUNT']) if r.get('FULL_AMOUNT') else 0.0
        if r.get('TYPE') == '5': amt = -amt
        party = (r.get('BUYER_NAME') if side == 'sell' else r.get('SELLER_NAME')) or ''
        tin = (r.get('BUYER_TIN') if side == 'sell' else r.get('SELLER_TIN')) or ''
        rows.append({'n': n, 'd': (r.get('BEGIN_DATE') or r.get('CREATE_DATE') or '')[:10], 'p': party.strip(), 't': tin,
                     'a': round(amt, 2), 's': r.get('STATUS', ''), 'y': r.get('TYPE', '')})
    return rows

t0 = time.time()
try:
    sell = compact(rd.fetch_waybills(), 'sell')
    print(f'seller waybills: {len(sell)} ({time.time()-t0:.0f}s)', flush=True)
    buy = compact(rd.fetch_purchase_waybills(), 'buy')
    print(f'buyer waybills: {len(buy)} ({time.time()-t0:.0f}s)', flush=True)
except Exception as e:
    print('RS.ge fetch FAILED, keeping previous cache:', repr(e), file=sys.stderr)
    sys.exit(2)
json.dump({'fetched': time.strftime('%Y-%m-%d %H:%M'), 'start': rd.START.strftime('%Y-%m-%d'), 'own_tin': rd.OWN_TIN,
           'sell': sell, 'buy': buy}, open(OUT, 'w', encoding='utf-8'), ensure_ascii=False, separators=(',', ':'))
print('written', OUT, round(os.path.getsize(OUT)/1e6, 2), 'MB')
