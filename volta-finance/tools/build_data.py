"""Aggregate oris.sqlite into compact JSON (dash_data.json) for the Volta_Finance dashboard.

Only YEAR (2026) transactions are kept at month-by-month detail. Everything dated before
YEAR-01-01 (all prior years) is NOT exposed month by month - it collapses into a single
per-account opening balance carried into January of YEAR, injected as a synthetic month
index -1 so the dashboard's existing opening/closing math (JS stats(), which already treats
"any month index less than the window start" as the opening balance) picks it up unchanged,
with no other code path needing to know about it. This is what the user asked for 2026-09-09:
pull only 2026 data, no other year's detail, so every tab compares 2026 months to each other.
"""
import sqlite3, json, collections, sys, os, bisect
BASE = os.environ.get('VOLTA_FIN_DATA', 'D:/all/volta/Volta_Accounting')
DB = os.path.join(BASE, 'oris.sqlite')
OUT = os.path.join(BASE, 'dash_data.json')
YEAR = '2026'
con = sqlite3.connect(DB)
PL = ('6', '7', '8', '9')


def depth(code):
    t = code.split(' ')
    d = 3 if t[0] == '1' and len(t) > 1 and t[1] == '6' else 4
    return ' '.join(t[:d])


# Cash-flow counter-account grouping (see `cf` below). Most counter prefixes collapse to 3 tokens
# (e.g. every "1 4 10 <customer>" becomes just "1 4 10") because they fan out into thousands of
# per-counterparty codes that aren't individually useful for a CF budget line. '4 1 90' (long-term
# loans) is the exception: only 11 sub-codes, and the sub-code identifies WHICH lender (Bank/Zuk/
# Shareholder - the same split already verified for the Balance Sheet Actual-vs-Budget mapping), so
# it keeps full depth() granularity to attribute cash draws/repayments to a specific budget line.
# ('7 4 90', the "other G&A" bucket, was tried the same way for marketing expense directly - it does
# have its own sub-code, '7 4 90 39' - but direct cash paid straight to that account totaled just
# 539 GEL for the whole year against ~352,000 GEL of real accrued spend; that specific expansion
# added no real coverage and was reverted, 2026-09-10.)
CF_EXPAND_PREFIXES = ('4 1 90', '3 4 10', '3 1 32')

# Vendor -> expense-category tracing (2026-09-10): most G&A/COGS bills settle through the general
# supplier-payable ledger ('3 1 10'/'3 1 31'), which is why most operating-expense CF rows have no
# isolable actual above. But many individual VENDOR codes under those ledgers turn out to be used
# for exactly one expense category all year (e.g. the landlord vendor only ever pairs with rent,
# 7 4 20) - checked empirically: of 107 vendors under '3 1 10' with any 2026 expense-side activity,
# 89 are single-category, covering 635k GEL of accrual (marketing alone: 186k GEL via 8 vendors -
# far more than the 539 GEL the direct '7 4 90 39' cash counter captured on its own). For those
# vendors, CASH paid to them is unambiguously attributable to that category; for the remaining
# multi-category vendors (which really did buy more than one kind of thing), attribution would
# require an arbitrary split, so those fall back to the generic 3-token bucket as before - this
# recovers real signal without guessing.
VENDOR_TRACE_LEDGERS = ('3 1 10', '3 1 31')


def _build_vendor_category_map():
    vendor_cats = collections.defaultdict(set)
    cur2 = con.execute(f"""SELECT DEBET, KREDIT FROM WIRING WHERE DATE LIKE '{YEAR}%' AND
                            ((DEBET LIKE '7 %' AND ({' OR '.join(f"KREDIT LIKE '{p} %'" for p in VENDOR_TRACE_LEDGERS)}))
                          OR (KREDIT LIKE '7 %' AND ({' OR '.join(f"DEBET LIKE '{p} %'" for p in VENDOR_TRACE_LEDGERS)})))""")
    for d2, k2 in cur2:
        if d2.startswith('7 '):
            vendor_cats[k2].add(depth(d2))
        else:
            vendor_cats[d2].add(depth(k2))
    return {v: next(iter(c)) for v, c in vendor_cats.items() if len(c) == 1}


VENDOR_CATEGORY = _build_vendor_category_map()


def cf_counter(code):
    if code.startswith(VENDOR_TRACE_LEDGERS) and code in VENDOR_CATEGORY:
        return VENDOR_CATEGORY[code]
    p3 = ' '.join(code.split(' ')[:3])
    return depth(code) if p3 in CF_EXPAND_PREFIXES else p3


all_months = [r[0] for r in con.execute("SELECT DISTINCT substr(DATE,1,7) FROM WIRING ORDER BY 1")]
months = [m for m in all_months if m.startswith(YEAR + '-')]
mi = {m: i for i, m in enumerate(months)}

agg = collections.defaultdict(lambda: collections.defaultdict(lambda: [0.0, 0.0]))
opening = collections.defaultdict(float)          # leaf code -> net (dr-cr) from all history before YEAR
cf = collections.defaultdict(lambda: [0.0, 0.0])  # (cash4, counter3, m) -> [in,out]   -- YEAR only
closing = collections.defaultdict(float)          # m -> net closed (audit)            -- YEAR only
stats = collections.Counter()

# Individual journal postings for the Expense Analysis tab's drill-down ("show postings" toggle,
# so the finance team can eyeball whether the bookkeeper's entries look right, not just the totals).
# Scoped to just these 5 root classes - the only ones the EX tab shows - since embedding every
# WIRING row for the whole ledger would be far larger than needed (~2.7k rows here vs 267k total).
EX_ROOTS = ('7 4', '7 3', '7 1', '8 2', '9 2')
postings = collections.defaultdict(list)  # depth()-truncated code -> [[date, story, counter_code, signed_amount], ...]
# `story` is WIRING.STORY, a free-text narrative the bookkeeper enters per posting (e.g. "ხელფასის
# დარიცხვა", "შეძენილია საქონელი შპს X-სგან") - used as the "purpose" column in the Expense
# Analysis tab's per-cell drill-down. About a quarter of rows have it blank; the frontend falls
# back to the counter-account's name in that case (`counter_code` is kept for exactly that).


def is_ex(code):
    return any(code == r or code.startswith(r + ' ') for r in EX_ROOTS)

rates = collections.defaultdict(list)
for cur_, dt, qty, c in con.execute("SELECT MON_TYPE, DATE, QTY, CURS FROM Rate ORDER BY DATE"):
    rates[cur_].append((dt, c / (qty or 1)))
rate_dates = {k: [x[0] for x in v] for k, v in rates.items()}


def rate(cur_, dt):
    i = bisect.bisect_right(rate_dates[cur_], dt)
    return rates[cur_][i - 1][1] if i else None


fx_gel = collections.defaultdict(float)
cur = con.execute("SELECT DATE, STORY, DEBET, KREDIT, MONEY, MON_TYPE FROM WIRING")
for date, story, d, k, money, mon in cur:
    stats['total'] += 1
    if mon != 'GEL':
        r = rate(mon, date)
        if r is None:
            stats['skip_fx_norate'] += 1
            continue
        stats['fx_converted'] += 1
        fx_gel[mon] += money * r
        money = round(money * r, 2)
    if d.startswith('B') or k.startswith('B'):
        stats['skip_offbal'] += 1
        continue
    if (d == '5 3 30' and k[0] in PL) or (k == '5 3 30' and d[0] in PL):
        stats['skip_closing'] += 1
        if date[:4] == YEAR:
            closing[mi[date[:7]]] += money if d == '5 3 30' else -money
        continue
    stats['kept'] += 1
    if date[:4] == YEAR:
        m = mi[date[:7]]
        agg[depth(d)][m][0] += money
        agg[depth(k)][m][1] += money
        if is_ex(d):
            postings[depth(d)].append([date, (story or '').strip(), depth(k), round(money, 2)])
        if is_ex(k):
            postings[depth(k)].append([date, (story or '').strip(), depth(d), round(-money, 2)])
        dc = d.startswith('1 1 ') or d.startswith('1 2 ')
        kc = k.startswith('1 1 ') or k.startswith('1 2 ')
        if dc:
            cf[(depth(d), cf_counter(k), m)][0] += money
        if kc:
            cf[(depth(k), cf_counter(d), m)][1] += money
    else:
        stats['pre2026'] += 1
        opening[depth(d)] += money
        opening[depth(k)] -= money

# Inject each account's pre-YEAR net balance as a synthetic month=-1 entry (never a real month
# index, always < any real one), so JS's stats()/close() reproduce the correct Jan-1 opening and
# every later month's cumulative balance without any pre-YEAR monthly detail being present.
for code, bal in opening.items():
    if abs(bal) < 0.005:
        continue
    agg[code][-1][0] += max(bal, 0.0)
    agg[code][-1][1] += max(-bal, 0.0)

# account names for every code in agg/cf + ancestors
names = {r[0]: (r[1], r[2], r[3]) for r in con.execute("SELECT COUNT, NAME, TYPEF, LEVEL FROM ACC_NAME")}
need = set(agg) | {c for c, _, _ in cf} | {x for _, x, _ in cf}
for c in list(need):
    t = c.split(' ')
    for i in range(1, len(t)):
        need.add(' '.join(t[:i]))
acc = {}
for c in sorted(need):
    n = names.get(c)
    acc[c] = [n[0].strip() if n else '(უცნობი ანგარიში)', n[1] if n else 0]

aggo = {c: [x for m in sorted(v) for x in (m, round(v[m][0], 2), round(v[m][1], 2))] for c, v in agg.items()}
cfo = [[c, x, m, round(v[0], 2), round(v[1], 2)] for (c, x, m), v in sorted(cf.items())]
postingso = {c: sorted(v, key=lambda x: x[0], reverse=True) for c, v in postings.items()}

_srcp = os.path.join(BASE, 'source.json')
_src = json.load(open(_srcp, encoding='utf-8')) if os.path.exists(_srcp) else {'source': 'unknown', 'asof': YEAR + '-01-01'}
first_2026 = con.execute("SELECT MIN(DATE) FROM WIRING WHERE DATE>=?", (YEAR + '-01-01',)).fetchone()[0]
meta = {
    'company': 'შპს ვოლტა', 'tin': '405232715', 'source': _src['source'], 'asof': _src['asof'],
    'scopeYear': YEAR,
    'first': first_2026, 'last': con.execute("SELECT MAX(DATE) FROM WIRING").fetchone()[0],
    'stats': dict(stats), 'fx_gel': {k: round(v, 2) for k, v in fx_gel.items()},
    'closing': {months[m]: round(v, 2) for m, v in sorted(closing.items())},
}
json.dump({'meta': meta, 'months': months, 'acc': acc, 'agg': aggo, 'cf': cfo, 'postings': postingso}, open(OUT, 'w', encoding='utf-8'),
           ensure_ascii=False, separators=(',', ':'))
print(meta['stats'], 'codes', len(acc), 'agg codes', len(aggo), 'cf rows', len(cfo),
      'postings codes', len(postingso), 'postings rows', sum(len(v) for v in postingso.values()),
      'size MB', round(os.path.getsize(OUT) / 1e6, 2))
