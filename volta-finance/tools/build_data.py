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

# Live, per-account-code CF category map (2026-09-18), replacing the earlier vendor-tracing/
# accrual-pairing heuristics above for the CF Actual-vs-Budget and Cash-Flow-by-month views.
# Source: the finance team's own workbook ("Badget VS Acctual Jul'2026.xlsx") turned out to
# contain a hidden "Oris" sheet - a 122,841-row raw WIRING export where THEY had already tagged
# each transaction with their own category/sub-category (columns X/Z/AA), used to build their
# CF'Jul'2026 model. That per-transaction tagging isn't itself live (it's a one-time snapshot, not
# a formula, and stops at Jul 2026) - but grouping it by COUNTER ACCOUNT CODE and taking each
# code's majority tag turns it into a durable, reusable mapping: for each code below, >=50% of its
# tagged cash-flow volume in that snapshot carried the given category. Validated by reproducing the
# workbook's own Jan-Jul 2026 Actual figures from live Oris data using exactly this mapping -
# matches to within 0-3% for nearly every category (several - Management Fee, Office Rent,
# Corporate Party, the interest splits, all three loan-draw categories - matched exactly). This
# ALSO corrected several codes this project had previously mis-classified by account NAME alone
# (see LOAN_SH/LOAN_ZUK below): e.g. '4 1 90 8019' ("Claret Horizon Holdings") is tagged "Loan From
# Zuk" here, not Shareholder, and '4 1 90 11677' ("Finhub Georgia") is its own "Loan To Finhub"
# category, not a shareholder loan - both of those were wrong in every earlier round of this file.
# Known weak spots (kept out of this map, left uncategorized): "Office Equipment Capex" only
# reconstructs to ~37% of the workbook's figure (too many vendors below the 50% bar to be useful);
# revenue-side "Principal" vs "Advance" can't be split by account at all (every customer's AR code
# carries both). "Salary of Shareholders" (2026-09-18): no PAYROLL account (`3 1 32`) distinguishes
# shareholder from staff - every one of the 71 employee codes, including the one confirmed
# shareholder on payroll, is tagged plain "Salary". The finance model pays it a different way
# instead: user-confirmed that '3 1 10 1684' ("შპს ვივო თრეიდი") - a vendor invoicing "consulting"
# services (accrues to '7 4 50'), not a payroll account at all - IS the shareholder's compensation.
# 'salary_other' below is every '3 1 32' code EXCEPT none (no shareholder is on payroll, so this
# is simply the whole payroll ledger) - queried live so it stays current as staff changes.
CF_CAT_CODES = {
    'salary_sh': ['3 1 10 1684'],
    'salary_other': [r[0] for r in con.execute("SELECT DISTINCT COUNT FROM ACC_NAME WHERE COUNT LIKE '3 1 32 %'")],
    'cogs': ['3 1 10 10009', '3 1 10 10362', '3 1 10 10404', '3 1 10 10459', '3 1 10 10828', '3 1 10 11006', '3 1 10 11227', '3 1 10 11264', '3 1 10 11485', '3 1 10 11592', '3 1 10 145', '3 1 10 173', '3 1 10 2054', '3 1 10 2106', '3 1 10 2124', '3 1 10 2175', '3 1 10 2256', '3 1 10 2872', '3 1 10 3860', '3 1 10 3870', '3 1 10 4', '3 1 10 4184', '3 1 10 4559', '3 1 10 4775', '3 1 10 5204', '3 1 10 5492', '3 1 10 55', '3 1 10 5697', '3 1 10 6', '3 1 10 663', '3 1 10 6957', '3 1 10 7', '3 1 10 70', '3 1 10 7081', '3 1 10 7117', '3 1 10 7294', '3 1 10 7430', '3 1 10 7464', '3 1 10 796', '3 1 10 7977', '3 1 10 8651', '3 1 10 878', '3 1 10 9154', '3 1 10 9241', '3 1 10 9344', '3 1 10 9454', '3 1 10 9468', '3 1 10 9549', '3 1 10 9789',
             '1 6 10', '3 1 10 11484', '7 2 10'],  # added 2026-09-18 from the newer mapping file, validated to reduce Jan-Jul error (42.1k -> 35.0k GEL)
    'management_fee': ['3 1 10 9019'],
    'office_rent': ['3 1 10 10794', '3 1 10 11837', '3 1 10 12513', '3 1 10 3755', '3 1 10 8190'],
    'corporate_party': ['1 4 30 0012', '3 1 10 6883', '3 1 10 8276', '3 1 10 8633'],
    'marketing': ['3 1 10 10232', '3 1 10 159', '3 1 10 5238', '3 1 10 5980', '3 1 10 6360', '3 1 10 6705', '3 1 10 9449', '3 1 10 9745', '3 1 10 9788', '3 1 31 0054', '3 1 31 0055', '3 1 31 0093', '3 1 31 0103', '3 1 31 0105', '3 1 31 0158', '3 1 31 0160', '3 1 31 11226', '3 1 31 11387', '3 1 31 12096', '3 1 31 8681', '3 1 31 9747', '3 1 31 9916', '7 4 90 39',
                  '3 1 10 12527', '3 1 10 12534', '3 1 31 12648', '3 1 31 12972'],  # added 2026-09-18, validated (Jan-Jul error 1.0k -> 0.5k GEL)
    'marketing_salaries': ['3 1 10 8992', '3 1 31 0064'],
    'delivery': ['3 1 10 11370', '3 1 10 1400', '3 1 10 1705', '3 1 10 2006', '3 1 10 2340', '3 1 10 3803', '3 1 10 5634', '3 1 10 723', '3 1 10 8094', '3 1 10 9110', '3 1 10 9649', '3 1 10 968', '3 1 10 9746', '3 1 31 0107', '3 1 31 11593', '3 1 31 11675', '3 1 31 11676', '3 1 31 11792', '3 1 31 11851', '3 1 31 11953', '3 1 31 11968', '3 1 31 11970', '3 1 31 11971', '7 4 90 18',
                 '7 4 90 12', '7 3 40', '3 1 10 12456', '3 1 10 12600', '7 4 90 24'],  # added 2026-09-18, all Aug/Sep-only postings, no effect on the already-validated Jan-Jul fit (checked individually)
    'utility': ['3 1 10 1120', '3 1 10 11756', '3 1 10 2450', '3 1 10 4298', '3 1 10 475', '3 1 10 9046'],
    'office_exp': ['1 4 10 12186', '3 1 10 10100', '3 1 10 10242', '3 1 10 10505', '3 1 10 11350', '3 1 10 11674', '3 1 10 11835', '3 1 10 12185', '3 1 10 12485', '3 1 10 1278', '3 1 10 1459', '3 1 10 1477', '3 1 10 174', '3 1 10 2', '3 1 10 2767', '3 1 10 3', '3 1 10 3121', '3 1 10 3200', '3 1 10 3708', '3 1 10 3950', '3 1 10 4119', '3 1 10 4474', '3 1 10 4582', '3 1 10 4588', '3 1 10 4825', '3 1 10 4880', '3 1 10 4936', '3 1 10 5214', '3 1 10 5570', '3 1 10 6802', '3 1 10 6927', '3 1 10 6944', '3 1 10 7526', '3 1 10 7708', '3 1 10 7981', '3 1 10 7990', '3 1 10 7991', '3 1 10 8093', '3 1 10 8131', '3 1 10 8136', '3 1 10 8143', '3 1 10 8184', '3 1 10 8185', '3 1 10 8186', '3 1 10 8189', '3 1 10 8191', '3 1 10 8299', '3 1 10 8331', '3 1 10 8377', '3 1 10 8449', '3 1 10 8451', '3 1 10 90', '3 1 10 9343', '3 1 10 9432', '3 1 10 9532', '3 1 10 9568', '3 1 10 9790', '3 1 31 0040', '3 1 31 0046', '3 1 31 0092', '3 1 31 0095', '3 1 31 0096', '3 1 31 0097', '3 1 31 0104', '3 1 31 0106', '3 1 31 0108', '3 1 31 0171', '3 1 31 11721', '3 1 31 11969', '3 1 31 12044', '7 4 12', '7 4 90 3', '7 4 90 37', '8 1 50', '8 2 50'],
    'dividend': ['3 4 20'],
    'loan_to_finhub': ['1 4 50 1309', '4 1 90 11677'],
    'interest_investors': ['3 4 10 1318', '3 4 10 8018', '3 4 10 9019'],
    'interest_bank': ['3 4 10 11658', '3 4 10 11677', '3 4 10 2426', '3 4 10 3951', '3 4 10 4820', '3 4 10 5606', '3 4 10 5719', '3 4 10 7536', '7 4 90 33'],
    # Found 2026-09-18 via the newer mapping file: interest on the two confirmed shareholder-loan
    # principals ('4 1 90 12284'/'4 1 90 11998', same account NAMES) only started being paid in
    # Aug 2026 - genuinely zero for Jan-Jul (matches the CF Actual-vs-Budget model's own zero for
    # "Interest of Shareholder's Equity" that whole period, so this doesn't contradict it, just
    # extends coverage past where the old file stopped).
    'interest_sh': ['3 4 10 12284', '3 4 10 12285'],
    'loan_bog': ['4 1 90 10611'],
    'loan_sh_draw': ['4 1 90 11998', '4 1 90 12284'],
    'loan_zuk_draw': ['4 1 90 8018', '4 1 90 8019'],
    'loan_sh_repay': ['4 1 90 12284'],
    'capex_sysdev': ['3 1 10 10213', '3 1 10 2424', '3 1 10 8376'],
    'capex_truck': ['3 1 10 2020'],
    'other_sales': ['1 4 10 4559'],
}
# '4 1 90 11677' (Finhub Georgia) is deliberately NOT in this list: it is a 'loan_to_finhub' account (see
# above) and being in both double-counted its flows in the Financing totals (found 2026-09-21 while
# building the postings drill-down; the finance team's own Excel actuals confirm it - Jul-2026 bank
# repayment 26,052 not 76,052, Financing in 976,705 not 1,076,705). Finhub is reported once, netted, in
# the 'Loan to Finhub' line.
LOAN_4190_ALL = ['4 1 90 10611', '4 1 90 11998', '4 1 90 12284', '4 1 90 1684',
                  '4 1 90 2426', '4 1 90 3746', '4 1 90 4693', '4 1 90 5506', '4 1 90 5959',
                  '4 1 90 7426', '4 1 90 8018', '4 1 90 8019']
CF_CAT_CODES['loan_principal_all'] = LOAN_4190_ALL
CODE_TO_CAT = {c: cat for cat, codes in CF_CAT_CODES.items() for c in codes}
CAT_MULTI = {c: [cat for cat, codes in CF_CAT_CODES.items() if c in codes] for c in
             {c for codes in CF_CAT_CODES.values() for c in codes}}


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
cf_cat = collections.defaultdict(lambda: [0.0, 0.0])  # (category, m) -> [in,out], see CF_CAT_CODES above
closing = collections.defaultdict(float)          # m -> net closed (audit)            -- YEAR only
stats = collections.Counter()

# Full-year journal for the dashboard's "click any number -> see its postings" drill-down (Expense
# Analysis, the Statements tabs and Actual-vs-Budget). Each WIRING row is stored ONCE as
# [mmdd, story_idx, debit_idx, credit_idx, money_gel] with the story text and the (depth-truncated)
# account codes dictionary-encoded - the JS builds the per-account index (debit +, credit -) from it.
# ~85k rows for 2026 -> ~3.5 MB instead of the ~2.7k EX-only rows it used to embed.
jr_story, jr_code, jr_rows = {}, {}, []


def _ji(dct, key):
    i = dct.get(key)
    if i is None:
        i = dct[key] = len(dct)
    return i


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
        jr_rows.append([int(date[5:7]) * 100 + int(date[8:10]), _ji(jr_story, (story or '').strip()),
                        _ji(jr_code, depth(d)), _ji(jr_code, depth(k)), round(money, 2)])
        dc = d.startswith('1 1 ') or d.startswith('1 2 ')
        kc = k.startswith('1 1 ') or k.startswith('1 2 ')
        if dc:
            cf[(depth(d), cf_counter(k), m)][0] += money
            dk = depth(k)
            if dk != '1 6 35':
                for cat in CAT_MULTI.get(dk, ()):
                    cf_cat[(cat, m)][0] += money
        if kc:
            cf[(depth(k), cf_counter(d), m)][1] += money
            dd = depth(d)
            if dd != '1 6 35':
                for cat in CAT_MULTI.get(dd, ()):
                    cf_cat[(cat, m)][1] += money
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
cfcato = [[cat, m, round(v[0], 2), round(v[1], 2)] for (cat, m), v in sorted(cf_cat.items())]
journal = {'s': list(jr_story), 'c': list(jr_code), 'r': jr_rows}

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
json.dump({'meta': meta, 'months': months, 'acc': acc, 'agg': aggo, 'cf': cfo, 'cf_cat': cfcato, 'journal': journal, 'cat_multi': CAT_MULTI}, open(OUT, 'w', encoding='utf-8'),
           ensure_ascii=False, separators=(',', ':'))
print(meta['stats'], 'codes', len(acc), 'agg codes', len(aggo), 'cf rows', len(cfo),
      'journal rows', len(jr_rows), 'stories', len(jr_story),
      'size MB', round(os.path.getsize(OUT) / 1e6, 2))
