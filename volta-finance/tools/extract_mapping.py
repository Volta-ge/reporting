r"""One-time extraction of the Oris account-to-CF-category mapping from the finance team's own
working file into mapping_data.json, for the "Mapping" tab (under General).

Source: D:\all\volta\Finance\Oris Register Mapping for next time use (Aug'2026).xlsx, sheet "Oris"
(a raw Oris WIRING export the finance team has hand-tagged, columns C/D/E/F = debit/credit account
+ name, X/Y/Z/AA = their manual category/direction/sub-category/sub-sub-category, AC/AD = their own
review-status flag and consistency-% score). Per user instruction (2026-09-18): use columns
C,D,E,F,X,Y,Z,AA, collapse to one row per UNIQUE account code (excluding cash accounts 1 1*/1 2*,
which aren't "a category" themselves - they're the cash side of every transaction). For each
account, keep the MOST RECENT (by date) tagged posting's own category(X)/sub-category(Z)/
sub-sub-category(AA)/direction(Y)/status/confidence rather than a volume-weighted aggregate, since
the finance team's tagging is a manual, evolving process and a later classification supersedes an
earlier one for the same account rather than needing to be blended with it. The output is EVERY
non-cash account that appears anywhere in the file, not only the tagged ones (an earlier version of
this script filtered to tagged-only, which read as "some accounts are missing" - per user follow-up
2026-09-18, untagged accounts are now included too, with blank category fields, so nothing the file
touches is silently dropped) - 'accountsInFile'/'accountsTagged' in the output let the dashboard
disclose the real tagged-vs-total split. NOT part of refresh.py - re-run by hand (then
build_dashboard.py) if a newer copy is supplied.
"""
import json, os
import openpyxl

XLSX = os.environ.get('VOLTA_MAPPING_XLSX', r"D:\all\volta\Finance\Oris Register Mapping for next time use (Aug'2026).xlsx")
BASE = os.environ.get('VOLTA_FIN_DATA', 'D:/all/volta/Volta_Accounting')
OUT = os.path.join(BASE, 'mapping_data.json')


def norm_code(raw):
    if raw is None:
        return None
    raw = str(raw).strip()
    if not raw or raw in ('0', 'None'):
        return None
    parts = raw.split(' ', 1)
    head = parts[0]
    rest = parts[1] if len(parts) > 1 else ''
    if not head.isdigit() or len(head) < 3:
        return None
    out = head[0] + ' ' + head[1] + ' ' + head[2:]
    if rest:
        out += ' ' + rest.strip()
    return out


def is_cash(code):
    return code is not None and (code.startswith('1 1') or code.startswith('1 2'))


wb = openpyxl.load_workbook(XLSX, data_only=True, read_only=True)
ws = wb['Oris']

def code_sort_key(code):
    # zero-pad each space-separated token so string comparison sorts numerically too (avoids a
    # type-mismatch crash from mixing int/str tuples if a code ever has a non-numeric token)
    return tuple(t.zfill(12) if t.isdigit() else t for t in code.split(' '))


acc = {}  # code -> {date, name, cat, subcat, subsub, inout, status, confidence} (only for TAGGED postings)
name_by_code = {}  # code -> (date, name), tracked for every posting so untagged accounts still get a name
total_rows = 0
for row in ws.iter_rows(min_row=3, values_only=True):
    total_rows += 1
    date = row[1]
    c, dname, e, fname = row[2], row[3], row[4], row[5]
    x, y, z, aa = row[23], row[24], row[25], row[26]
    status = row[28] if len(row) > 28 else None
    conf = row[29] if len(row) > 29 else None
    for code, name in [(norm_code(c), dname), (norm_code(e), fname)]:
        if code is None or is_cash(code):
            continue
        if name and date is not None:
            cur_n = name_by_code.get(code)
            if cur_n is None or date >= cur_n[0]:
                name_by_code[code] = (date, str(name).strip())
        if not (z or aa) or date is None:
            continue
        cur = acc.get(code)
        if cur is None or date >= cur['date']:
            acc[code] = {
                'date': date,
                'cat': str(x).strip() if x else '',
                'subcat': str(z).strip() if z else '',
                'subsub': str(aa).strip() if aa else '',
                'inout': str(y).strip() if y else '',
                'status': str(status).strip() if status else '',
                'confidence': round(float(conf), 1) if conf is not None else None,
            }

# Every non-cash account seen anywhere in the file - per user instruction (2026-09-18), the table
# is NOT limited to tagged accounts; untagged ones are included too, with blank category fields, so
# nothing is silently dropped. "Coverage" (below) is how the dashboard discloses which ones those are.
all_codes = set(name_by_code) | set(acc)
rows = []
for code in sorted(all_codes, key=code_sort_key):
    r = acc.get(code)
    name = name_by_code.get(code, (None, ''))[1]
    if r:
        rows.append({'code': code, 'name': name, 'cat': r['cat'], 'subcat': r['subcat'], 'subsub': r['subsub'],
                      'inout': r['inout'], 'status': r['status'], 'confidence': r['confidence']})
    else:
        rows.append({'code': code, 'name': name, 'cat': '', 'subcat': '', 'subsub': '',
                      'inout': '', 'status': '', 'confidence': None})

# Coverage: of every non-cash account that appears ANYWHERE in this file, how many actually got a
# tag - an account this file never touches couldn't have been tagged regardless of how thorough
# the finance team's tagging pass was, so this is the honest denominator, not the full chart of
# accounts (which spans years this file doesn't cover at all).
out = {'source': os.path.basename(XLSX), 'totalRows': total_rows,
       'accountsInFile': len(all_codes), 'accountsTagged': len(acc), 'rows': rows}
os.makedirs(BASE, exist_ok=True)
json.dump(out, open(OUT, 'w', encoding='utf-8'), ensure_ascii=False, separators=(',', ':'))
print('wrote', OUT, 'accounts', len(rows), 'from', total_rows, 'source rows')
