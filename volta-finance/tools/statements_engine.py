r"""Phase 2 of the mapping-consolidation project (2026-09-25 plan, see
memory/volta_oris_financials_artifact.md): computes every P&L / Balance Sheet / Cash Flow actual
number from mapping_table.py + dash_data.json, in ONE Python module - a faithful port of
dashboard_template.html's PL_ACTUALS / BS_ACTUALS / LIVE_CF_ACTUALS (as live 2026-09-25), which
today only exist client-side in JS. Read-only; does not touch the live dashboard or its data files.

Phase 4 (same day): every line is now OVERRIDE-AWARE via mapping_table.py's apply_overrides() -
a hand-edit on the Google Sheet (see sync_mapping_from_sheet.py) changes the actual computed number
here, not just the Account Mapping tab's display label.

Two kinds of statement line, exactly mirroring the JS:
  - DIRECT lines: sum of (debit-credit) or (credit-debit) over an account's own postings/balance -
    computed from dash_data.json's `agg` (already Python-built by build_data.py), reproducing the
    JS stats()/S()/close()/bsVal() tree-aggregation exactly.
  - ANCHOR+COUNTERPARTY lines: value = postings through a FIXED anchor account, bucketed by what the
    OTHER leg of the posting is classified as - covers all Cash Flow categories (recomputed here from
    raw WIRING against the override-aware `cat_multi`, NOT reused from dash_data.json's precomputed
    `cf_cat`, so a Sheet reclassification changes these too - see the "Cash Flow categories" section
    below) plus two PL-only splits that currently exist ONLY in JS and are reimplemented here from raw
    WIRING: salary (92/93, anchor 7 4 10/15/50) and loan interest (111/112/113, anchor 8 2 10).
"""
import json
import os
import sqlite3

import mapping_table as mt

BASE = os.environ.get('VOLTA_FIN_DATA', 'D:/all/volta/Volta_Accounting')
YEAR = '2026'

d = json.load(open(os.path.join(BASE, 'dash_data.json'), encoding='utf-8'))
months = d['months']
NM = len(months)
agg = d['agg']            # leaf code -> [m,d,c, m,d,c, ...] (m=-1 = pre-YEAR opening)
cf_rows = d['cf']         # [cash4, counter3, m, in, out]

con = sqlite3.connect(os.path.join(BASE, 'oris.sqlite'))


def depth(code):
    t = code.split(' ')
    n = 3 if t[0] == '1' and len(t) > 1 and t[1] == '6' else 4
    return ' '.join(t[:n])


def parent_of(c):
    i = c.rfind(' ')
    return c[:i] if i >= 0 else None


# ---------- FX (matches build_data.py's rate()): raw WIRING MONEY is in MON_TYPE, not always GEL ----------
import bisect
import collections

_rates = collections.defaultdict(list)
for cur_, dt, qty, c in con.execute("SELECT MON_TYPE, DATE, QTY, CURS FROM Rate ORDER BY DATE"):
    _rates[cur_].append((dt, c / (qty or 1)))
_rate_dates = {k: [x[0] for x in v] for k, v in _rates.items()}


def fx_gel(mon, date, money):
    if mon == 'GEL':
        return money
    i = bisect.bisect_right(_rate_dates.get(mon, []), date)
    if i == 0:
        return None  # no rate on/before this date - build_data.py skips these too (stats['skip_fx_norate'])
    r = _rates[mon][i - 1][1]
    return round(money * r, 2)


# ---------- tree-aggregated opening balance + monthly (debit,credit), exactly like JS stats() ----------
opening0 = {}                                  # code -> pre-YEAR net (d-c), summed up every ancestor
monthly = {}                                    # code -> [[d0,c0],[d1,c1],...] summed up every ancestor


def _bump(code, m, dd, cc):
    p = code
    while p is not None:
        if m < 0:
            opening0[p] = opening0.get(p, 0.0) + dd - cc
        else:
            arr = monthly.setdefault(p, [[0.0, 0.0] for _ in range(NM)])
            arr[m][0] += dd
            arr[m][1] += cc
        p = parent_of(p)


for code, a in agg.items():
    for i in range(0, len(a), 3):
        m, dd, cc = a[i], a[i + 1], a[i + 2]
        _bump(code, m, dd, cc)


def close_bal(code, j):
    """Closing balance of `code` (and its whole subtree) as of end of month j - equivalent to
    JS bsVal()'s close(S(stats(0,j),code)) BEFORE the class sign flip / equity plNet adjustment."""
    o = opening0.get(code, 0.0)
    arr = monthly.get(code)
    if not arr:
        return o
    dd = sum(x[0] for x in arr[:j + 1])
    cc = sum(x[1] for x in arr[:j + 1])
    return o + dd - cc


def month_val(code, j, sign):
    """(debit-credit) if sign==1 (expA), (credit-debit) if sign==-1 (incA), for calendar month j only."""
    arr = monthly.get(code)
    dd, cc = (arr[j] if arr else (0.0, 0.0))
    return sign * (dd - cc)


def expA(code, j):
    return month_val(code, j, 1)


def incA(code, j):
    return month_val(code, j, -1)


def pl_net_opening_and_net(j):
    """JS plNet(stats(0,j)): opening + (d-c) summed over classes 6,7,8,9, as of month j."""
    o = n = 0.0
    for c in ('6', '7', '8', '9'):
        o += opening0.get(c, 0.0)
        arr = monthly.get(c)
        if arr:
            for x in arr[:j + 1]:
                n += x[0] - x[1]
    return o, n


def bv(code, j):
    """JS bsVal(stats(0,j),code): closing balance, class-1/2 positive else negated, with the
    P&L-not-yet-closed-to-equity adjustment on account 5 / 5 3 / 5 3 30."""
    x = close_bal(code, j)
    if code in ('5', '5 3', '5 3 30'):
        o, n = pl_net_opening_and_net(j)
        x += o + n
    return x if code[0] in ('1', '2') else -x


# ---------- account universe (for the classification maps) + overrides from the Google Sheet ----------
# Same construction as build_mapping_table.py: every account depth()-truncated code seen in a 2026
# posting, and which of those are a cash-account's counterparty (for the CF "op" fallback / Mapping tab).
acc = d['acc']
posting_codes = set()
cash_counter_accounts = set()
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
        cash_counter_accounts.add(kk)
    if kc and not dc and dd != '1 6 35':
        cash_counter_accounts.add(dd)

children_of = {}
for c in acc:
    t = c.split(' ')
    for i in range(1, len(t)):
        children_of.setdefault(' '.join(t[:i]), []).append(c)

cat_multi = {k: list(v) for k, v in d.get('cat_multi', {}).items()}  # mutable copy - overrides applied below
sh_payroll = list(mt.SH_PAYROLL)
sh_consult = list(mt.SH_CONSULT)
pl_map, bs_map = mt.build_maps(posting_codes, children_of)
_overrides = mt.load_overrides(BASE)
mt.apply_overrides(pl_map, bs_map, cat_multi, sh_payroll, sh_consult, _overrides)


def pl_bucket_sums(rows_wanted, j):
    """Sums expA(code,j) for every leaf account whose (override-aware) pl_map resolution is one of
    `rows_wanted` - the general mechanism that replaces the old hardcoded OPX_DEL/OPX_SYS/... lists,
    so a Sheet override that moves an account between these lines actually changes the computed
    number, not just the Account Mapping tab's display."""
    out = {r: 0.0 for r in rows_wanted}
    want = set(rows_wanted)
    for code in posting_codes:
        if code[0] not in '789':
            continue
        row = mt.mp_find(pl_map, code)
        if row in want:
            out[row] += expA(code, j)
    return out


PL_BUCKET_ROWS = [91, 94, 95, 96, 98, 100, 102, 103, 104, 109, 110, 115, 116]


# ---------- Cash Flow categories: recomputed from raw WIRING + the (override-aware) cat_multi above,
# not from dash_data.json's precomputed cf_cat - so a Sheet reclassification changes these too. Exact
# port of build_data.py's own cf_cat accumulation (same skip-closing / FX-conversion rules already
# applied to this connection above for the salary/interest scan). ----------
_cf_cat_acc = {}


def _cf_cat_add(cat, m, dir_, money):
    e = _cf_cat_acc.setdefault(cat, {}).setdefault(m, [0.0, 0.0])
    e[0 if dir_ == 'in' else 1] += money


byCat = {}  # filled after the WIRING scan below


def catIn(cat, j):
    return byCat.get(cat, {}).get(j, (0, 0))[0]


def catOut(cat, j):
    return byCat.get(cat, {}).get(j, (0, 0))[1]


def catInSum(cats, j):
    return sum(catIn(c, j) for c in cats)


def catOutSum(cats, j):
    return sum(catOut(c, j) for c in cats)


CASH = ('1 1', '1 2')


def is_cash(c):
    return c.startswith(CASH)


def is_cash_ctr(c):
    return is_cash(c) or c == '1 6 35'


totByMonth = {}
for cash, ctr, m, i, o in cf_rows:
    if is_cash_ctr(ctr):
        continue
    e = totByMonth.setdefault(m, [0.0, 0.0])
    e[0] += i
    e[1] += o


def cash_close(j):
    return close_bal('1 1', j) + close_bal('1 2', j)


# ---------- anchor+counterparty splits: salary (92/93) and loan interest (111/112/113) ----------
# Rebuilt from raw WIRING (not in dash_data.json's per-account `agg`, which loses the counterparty).
SAL_ANCHORS = mt.OPX_SAL  # ['7 4 10','7 4 15','7 4 50']

sal_sh = [0.0] * NM
sal_other = [0.0] * NM
int_investors = [0.0] * NM
int_sh = [0.0] * NM
int_bank_other = [0.0] * NM

# ---------- System Development / Marketing Salaries vendor-anchor scan (restored 2026-10-20, see
# mapping_table.py's SYSDEV_VENDOR/MKTSAL_VENDORS/VERA_SALARY_BY_MONTH comments for the source) -----
# Claret/Razvan/Guram's invoices land on whatever class-7 expense code the bookkeeper used that month
# (not always the same one), so - exactly like the salary/interest anchor scans above - this has to be
# a raw WIRING scan keyed on the VENDOR'S OWN payable account (the credit side of the accrual entry),
# not a pl_map leaf-account lookup. `sysmkt_excl` records which (bucket row, month) amount to subtract
# from the normal pl_bucket_sums() result so that money isn't double-counted in its original bucket
# (System Dev/OPX_SYS or Marketing/OPX_MKT, whichever GL code happened to be used) once it's re-added
# via sys98/mktsal101 below.
SYSDEV_MKT_ANCHORS = {mt.SYSDEV_VENDOR: 'claret', mt.MKTSAL_VENDORS[0]: 'razvan', mt.MKTSAL_VENDORS[1]: 'guram'}
claret_m = [0.0] * NM
razvan_m = [0.0] * NM
guram_m = [0.0] * NM
_SYSMKT_TARGETS = {'claret': claret_m, 'razvan': razvan_m, 'guram': guram_m}
sysmkt_excl = {}

def starts_with(code, prefix):
    """Same as JS's `x===p||x.startsWith(p+' ')` (salary anchor) - and, since every Oris account
    extension is a new space-separated token, an equivalent safe stand-in for JS's bare
    `.startsWith('8 2 10')` (interest anchor) too."""
    return code == prefix or code.startswith(prefix + ' ')


def is_sal_anchor(code):
    return any(starts_with(code, p) for p in SAL_ANCHORS)


def which_sal_anchor(code):
    for p in SAL_ANCHORS:
        if starts_with(code, p):
            return p
    return None


PL_CLASSES = ('6', '7', '8', '9')  # matches build_data.py's PL - skip the P&L-closing entries the ledger books
                                    # against 5 3 30, exactly like build_data.py does for D.journal/agg (2026-09-25 bugfix:
                                    # these entries were leaking into the interest/salary anchor scans as a huge, wrong
                                    # "other lender" counterparty '5 3 30' - caught by the validation below).


def is_closing(d_, k_):
    return (d_ == '5 3 30' and k_[0] in PL_CLASSES) or (k_ == '5 3 30' and d_[0] in PL_CLASSES)


for date, dd_acc, kk_acc, raw_money, mon in con.execute(
        "SELECT DATE,DEBET,KREDIT,MONEY,MON_TYPE FROM WIRING WHERE DATE>=? AND DATE<? AND NOT (DEBET LIKE 'B%' OR KREDIT LIKE 'B%')",
        (YEAR + '-01-01', str(int(YEAR) + 1) + '-01-01')):
    if is_closing(dd_acc, kk_acc):
        continue
    mm = int(date[5:7]) - 1
    if mm < 0 or mm >= NM:
        continue
    money = fx_gel(mon, date, raw_money)
    if money is None:
        continue
    dp, kp = depth(dd_acc), depth(kk_acc)
    if is_sal_anchor(dp) or is_sal_anchor(kp):
        acct, ctr, sg = (dp, kp, 1) if is_sal_anchor(dp) else (kp, dp, -1)
        anchor = which_sal_anchor(acct)
        # '7 4 56' (director-salary-top-up, see mapping_table.py) is unconditionally shareholder
        # salary - every posting through it is director payroll, no counterparty check needed.
        sh = (ctr in sh_payroll and anchor == '7 4 10') or (ctr in sh_consult and anchor == '7 4 50') or anchor == '7 4 56'
        (sal_sh if sh else sal_other)[mm] += sg * money
    sysmkt_anchor = SYSDEV_MKT_ANCHORS.get(kp)
    if sysmkt_anchor and dp[0] == '7':
        _SYSMKT_TARGETS[sysmkt_anchor][mm] += money
        excl_row = mt.mp_find(pl_map, dp)
        if excl_row in PL_BUCKET_ROWS:
            sysmkt_excl.setdefault(excl_row, [0.0] * NM)[mm] += money
    if starts_with(dp, '8 2 10') or starts_with(kp, '8 2 10'):
        ctr, sg = (kp, 1) if starts_with(dp, '8 2 10') else (dp, -1)
        cats = cat_multi.get(ctr, [])
        if 'interest_investors' in cats:
            int_investors[mm] += sg * money
        elif 'interest_sh' in cats:
            int_sh[mm] += sg * money
        else:
            int_bank_other[mm] += sg * money
    # cf_cat: cash-side leg's counter-account classified by (override-aware) cat_multi - exact port of
    # build_data.py's own cf_cat accumulation.
    dc, kc = is_cash(dp), is_cash(kp)
    if dc and not kc and kp != '1 6 35':
        for cat in cat_multi.get(kp, ()):
            _cf_cat_add(cat, mm, 'in', money)
    if kc and not dc and dp != '1 6 35':
        for cat in cat_multi.get(dp, ()):
            _cf_cat_add(cat, mm, 'out', money)


for _cat, _by_m in _cf_cat_acc.items():
    byCat[_cat] = {_m: tuple(_v) for _m, _v in _by_m.items()}


# ---------- assemble every row, 0..NM-1 ----------
def build():
    idx = range(NM)
    rev79 = [incA('6 1 10', j) + incA('6 1 20', j) for j in idx]
    cogs81 = [expA('7 2', j) + expA('7 1', j) for j in idx]
    gross82 = [rev79[j] - cogs81[j] for j in idx]
    serv84 = [incA('6 1 91', j) for j in idx]
    pen85 = [incA('8 1 25', j) for j in idx]
    pbs86 = [incA('6 1 90 1', j) for j in idx]
    oth87 = [serv84[j] + pen85[j] + pbs86[j] for j in idx]
    # every leaf account bucketed once (via the override-aware pl_map) into whichever of these rows it
    # resolves to - replaces the old hardcoded OPX_DEL/OPX_SYS/OPX_MKT/OPX_EXPL7-complement/MISC_CODES
    # sums, so a Sheet reclassification actually moves the number, not just the Mapping tab label.
    buckets = [pl_bucket_sums(PL_BUCKET_ROWS, j) for j in idx]
    # pull the Claret/Razvan/Guram invoice amounts (see SYSDEV_MKT_ANCHORS scan above) back out of
    # whichever bucket row they naturally landed in via pl_map, before re-adding them via sys98/
    # mktsal101 below - otherwise they'd be double-counted (once in their original GL-code bucket,
    # once in the vendor-anchored one).
    for _row, _arr in sysmkt_excl.items():
        for _j in idx:
            buckets[_j][_row] -= _arr[_j]
    vera_m = [mt.VERA_SALARY_BY_MONTH.get(months[j], 0.0) for j in idx]
    del91 = [buckets[j][91] for j in idx]
    sys98 = [buckets[j][98] + claret_m[j] - vera_m[j] for j in idx]
    mktsal101 = [vera_m[j] + razvan_m[j] + guram_m[j] for j in idx]
    mkt100 = [buckets[j][100] for j in idx]
    off95 = [buckets[j][95] for j in idx]
    row94 = [buckets[j][94] for j in idx]
    # 2026-10-01: sublease income (6 1 90 2) nets against Office Rent here (briefly tried Other Revenue
    # (87) and Utility Cost (99) first, both reverted - the user settled on Office Rent) - like util99
    # before it, this is a direct formula rather than a pl_bucket_sums() member since bucketing only
    # scans class 7/8/9 leaf accounts and 6 1 90 2 is class 6 (credit-normal, so it SUBTRACTS here).
    row96 = [buckets[j][96] - incA('6 1 90 2', j) for j in idx]
    row103 = [buckets[j][103] for j in idx]
    row109 = [buckets[j][109] for j in idx]
    row110 = [buckets[j][110] for j in idx]
    row115 = [buckets[j][115] for j in idx]
    row116 = [buckets[j][116] for j in idx]
    row104_bucket = [buckets[j][104] for j in idx]
    # Provision (restored 2026-10-20, see mapping_table.py's PROVISION_RATE/PROVISION_OFFSET comment):
    # a cumulative target (live portfolio balance x RATE) expensed as its own month-over-month change,
    # not the raw class-9 bucket sum - January's charge nets against the Dec'25 opening target
    # (PROVISION_OFFSET).
    _portfolio_m = [bv('1 4 10', j) for j in idx]
    _prov_target = [_portfolio_m[j] * mt.PROVISION_RATE for j in idx]
    prov102 = [_prov_target[j] - (_prov_target[j - 1] if j > 0 else mt.PROVISION_OFFSET) for j in idx]
    # 2026-10-01: sublease income (6 1 90 2) moved to Other Revenue (87, see sub87 above) per the user
    # - no longer netted against Utility here. util99 stays a direct sum over mt.OPX_UTIL rather than
    # the general pl_bucket_sums() mechanism (row 99 is deliberately not in PL_BUCKET_ROWS) so a
    # reclassification into/out of Utility is a plain edit to mt.OPX_UTIL (mapping_table.py), mirrored
    # in dashboard_template.html's OPX_UTIL - same pattern as every other OPX_* edit this session.
    util99 = [sum(expA(c, j) for c in mt.OPX_UTIL) for j in idx]
    ppe110 = row110
    fxexp115 = row115
    fxinc116 = row116
    dep109 = row109
    int111, int112 = int_investors, int_sh
    int113 = [int_bank_other[j] + expA('8 1 10', j) for j in idx]  # '8 1 10' interest income also stays special (see mapping_table.py's PL_FALLBACK)
    sal92, sal93 = list(sal_sh), sal_other
    # One-off manual correction (restored 2026-10-20): June 2026 Zaza's salary was booked 4,027.30 GEL
    # too high against the shareholder-salary anchor - reclassed to Corporate Party (103), the account
    # the user identified it actually belonged to. Zero-sum, Net Profit unaffected.
    try:
        _june = months.index('2026-06')
        sal92[_june] -= 4027.30
        row103[_june] += 4027.30
    except ValueError:
        pass
    # Securing-a-Loan-with-Giorgi's-Property fee (restored 2026-10-20, see mapping_table.py's
    # SECURING_LOAN_FEE_BY_MONTH comment) - not a real Oris posting, netted out of Salary of
    # Shareholders and shown as its own non-operating line instead; zero-sum, Net Profit unaffected.
    row114 = [mt.SECURING_LOAN_FEE_BY_MONTH.get(months[j], 0.0) for j in idx]
    for _j in idx:
        sal92[_j] -= row114[_j]
    # Opex total = sum of every Opex-member row bucket (91/94/95/96/98/99/100/101/102/103/104) PLUS
    # salary (92+93) - salary is NOT a pl_map bucket at all (the whole 7 4 10/7 4 50 anchor subtrees
    # resolve to the STRING 'sal' via PL_FALLBACK, deliberately excluded from PL_BUCKET_ROWS, since
    # their real split between shareholder/other-staff salary comes from the counterparty anchor-scan,
    # not from mp_find) - each account resolves to EXACTLY one of these via pl_map (or the anchor
    # scan for salary/interest), so this reconstructs "class 7 3 + class 7 4 (minus depreciation,
    # moved to non-op) + class 9 provision + class 8 1/8 2 leftover" with no double-count, and stays
    # correct under ANY Sheet override since it's the same bucketing the individual rows use (util99
    # already nets the 6 1 90 2 sublease income once, inside itself).
    opex_raw = [del91[j] + row94[j] + off95[j] + row96[j] + sys98[j] + util99[j] + mkt100[j] + mktsal101[j] + prov102[j] + row103[j] + row104_bucket[j] + sal92[j] + sal93[j] for j in idx]
    nonop108 = [ppe110[j] + dep109[j] + int111[j] + int112[j] + int113[j] + fxexp115[j] + fxinc116[j] + row114[j] for j in idx]
    net_raw = [gross82[j] - opex_raw[j] + oth87[j] - nonop108[j] for j in idx]
    # "true" net profit computed directly from the 6/7/8/9 classes (== JS's plLines().net), for the residual safety net
    net_direct = []
    for j in idx:
        rev = incA('6', j)
        cogs = expA('7 2', j) + expA('7 1', j)
        gross = rev - cogs
        dist = expA('7 3', j)
        adm = expA('7 4', j)
        op = gross - dist - adm
        noi = incA('8 1', j)
        noe = expA('8 2', j)
        ext = incA('9 1', j)
        oth = expA('9 2', j)
        net_direct.append(op + noi - noe + ext - oth)
    resid = [net_raw[j] - net_direct[j] for j in idx]
    opex90 = [opex_raw[j] + resid[j] for j in idx]
    ebitda105 = [gross82[j] - opex90[j] + oth87[j] for j in idx]
    net118 = [ebitda105[j] - nonop108[j] for j in idx]

    pl = {
        78: [rev79[j] * 1.18 for j in idx], 79: rev79, 80: [cogs81[j] * 1.18 for j in idx], 81: cogs81, 82: gross82,
        84: serv84, 85: pen85, 86: pbs86, 87: oth87, 90: opex90, 91: del91,
        92: sal92, 93: sal93, 94: row94, 95: off95, 96: row96,
        98: sys98, 99: util99, 100: mkt100, 101: mktsal101, 102: prov102, 103: row103,
        104: [row104_bucket[j] + resid[j] for j in idx], 105: ebitda105,
        108: nonop108, 109: dep109, 110: ppe110, 111: int111, 112: int112, 113: int113,
        114: row114,
        115: fxexp115, 116: fxinc116, 118: net118,
    }

    LOAN_SH, LOAN_BANK, LOAN_ZUK = mt.LOAN_SH, mt.LOAN_BANK, mt.LOAN_ZUK
    ytd_net = []
    run = 0.0
    for j in idx:
        run += net118[j]
        ytd_net.append(run)
    bs = {
        124: [bv('1', j) for j in idx], 125: [cash_close(j) for j in idx], 126: [bv('1 4 10', j) for j in idx],
        128: [sum(bv(c, j) for c in mt._AVBS_128) for j in idx],
        129: [bv('2', j) for j in idx], 130: [bv('2 1 60', j) for j in idx], 131: [bv('2 1 80', j) for j in idx],
        132: [bv('2 1 90', j) for j in idx], 133: [bv('2 5', j) for j in idx],
        134: [bv('2 2', j) + bv('2 6', j) for j in idx],
        135: [bv('1', j) + bv('2', j) for j in idx],
        137: [bv('3', j) for j in idx], 138: [bv('3 1 10', j) for j in idx], 139: [bv('3 1 20', j) for j in idx],
        140: [bv('3 1 30', j) + bv('3 1 32', j) for j in idx],
        141: [bv('3 3 10', j) for j in idx], 142: [bv('3 3 20', j) for j in idx], 143: [bv('3 3 30', j) for j in idx],
        144: [bv('3 3 40', j) for j in idx], 146: [bv('3 3 80', j) for j in idx], 147: [bv('3 4 10', j) for j in idx],
        148: [bv('4', j) for j in idx],
        149: [sum(bv(c, j) for c in LOAN_SH) for j in idx], 150: [sum(bv(c, j) for c in LOAN_BANK) for j in idx],
        151: [sum(bv(c, j) for c in LOAN_ZUK) for j in idx],
        152: [bv('3', j) + bv('4', j) for j in idx], 154: [bv('5', j) for j in idx],
        155: [bv('5', j) - ytd_net[j] for j in idx], 156: ytd_net,
        157: [bv('3', j) + bv('4', j) + bv('5', j) for j in idx],
    }

    finhub_net = [catOut('loan_to_finhub', j) - catIn('loan_to_finhub', j) for j in idx]
    fin_in = [catInSum(['loan_principal_all'], j) for j in idx]
    fin_out = [catOut('loan_principal_all', j) + finhub_net[j] + catOut('dividend', j) for j in idx]
    inv_in = [catInSum(['other_sales'], j) for j in idx]
    inv_out = [catOutSum(['capex_sysdev', 'capex_truck', 'capex_office'], j) for j in idx]
    fin_net = [fin_in[j] - fin_out[j] for j in idx]
    inv_net = [inv_in[j] - inv_out[j] for j in idx]
    cash_at = [cash_close(j) for j in idx]
    opening_cash = bv_open_cash()
    start_at = [opening_cash if j == 0 else cash_at[j - 1] for j in idx]
    tot_in = [totByMonth.get(j, [0.0, 0.0])[0] for j in idx]
    op_net = [(cash_at[j] - start_at[j]) - fin_net[j] - inv_net[j] for j in idx]
    op_in = [tot_in[j] - fin_in[j] - catIn('loan_to_finhub', j) - inv_in[j] for j in idx]
    op_out = [op_in[j] - op_net[j] for j in idx]
    sh_repay = [catOutSum(['loan_sh_repay'], j) for j in idx]
    bank_repay = [catOut('loan_principal_all', j) - sh_repay[j] for j in idx]

    cf = {
        26: op_net, 27: op_in, 33: op_out,
        52: inv_net, 53: inv_in, 55: inv_in, 57: inv_out, 58: [catOutSum(['capex_sysdev'], j) for j in idx],
        59: [catOutSum(['capex_office'], j) for j in idx], 60: [catOutSum(['capex_truck'], j) for j in idx],
        34: [catOutSum(['cogs'], j) for j in idx], 35: [catOutSum(['salary_sh'], j) for j in idx],
        36: [catOutSum(['salary_other'], j) for j in idx], 37: [catOutSum(['management_fee'], j) for j in idx],
        38: [catOutSum(['delivery'], j) for j in idx], 39: [catOutSum(['marketing'], j) for j in idx],
        40: [catOutSum(['marketing_salaries'], j) for j in idx], 41: [catOutSum(['office_rent'], j) for j in idx],
        42: [catOutSum(['office_exp'], j) for j in idx], 44: [catOutSum(['utility'], j) for j in idx],
        46: [catOutSum(['interest_investors'], j) for j in idx], 47: [catOutSum(['interest_sh'], j) for j in idx],
        48: [catOutSum(['interest_bank'], j) for j in idx], 50: [catOutSum(['corporate_party'], j) for j in idx],
        61: fin_net, 62: fin_in, 63: [catInSum(['loan_bog'], j) for j in idx], 64: [catInSum(['loan_sh_draw'], j) for j in idx],
        65: [catInSum(['loan_zuk_draw'], j) for j in idx],
        67: fin_out, 68: [catOutSum(['dividend'], j) for j in idx], 69: finhub_net, 70: sh_repay, 71: bank_repay,
        72: [cash_at[j] - start_at[j] for j in idx], 73: start_at, 74: cash_at,
    }
    return pl, bs, cf


# ---------- Financial ratios (2026-10-20, per the user's "Volta FS montly Aug'26.xlsx" Ratios'26 tab) ----------
# Same groups/ratios as that sheet, but with STANDARD formulas rather than copying it byte-for-byte -
# the user confirmed (after a review) that sheet has real formula bugs: Gross Profit Margin references
# the wrong row for 8 of 12 months, DSCR adds Services Revenue instead of a debt-service item, Jan'26
# DSCR/Interest-Coverage point at a blank spacer column, and Debt-to-Equity's numerator nets ST against
# LT liabilities instead of just using LT (financial) debt. "Quick Ratio"/"Cash Ratio" were also swapped
# against standard terminology - this engine uses the conventional names/definitions throughout.
def ratios(pl, bs):
    idx = range(NM)

    def avg2(lst, open_val, j):
        prev = open_val if j == 0 else lst[j - 1]
        return (prev + lst[j]) / 2

    def delta(lst, open_val, j):
        prev = open_val if j == 0 else lst[j - 1]
        return lst[j] - prev

    def safe_div(num, den, positive_only=False):
        if den is None or den == 0 or (positive_only and den < 0):
            return None
        return num / den

    # bv()'s sign convention already flips classes 3/4/5 (liabilities/equity) to a POSITIVE
    # display magnitude (verified against statements_data.json: bs[148]/bs[154] are positive
    # throughout, bs[137]/bs[138] mostly positive but can dip negative in a month where advances/
    # VAT-input exceed payables) - no extra negation needed here, unlike the source Excel's own
    # convention where those rows are stored negative.
    open_assets = bv('1', -1) + bv('2', -1)
    open_cl = bv('3', -1)
    open_equity = bv('5', -1)
    open_inventory = sum(bv(c, -1) for c in mt._AVBS_128)
    open_ar = bv('1 4 10', -1)
    open_ap = bv('3 1 10', -1)
    open_ltbank = sum(bv(c, -1) for c in mt.LOAN_BANK)

    cap_employed = [bs[135][j] - bs[137][j] for j in idx]           # Total Assets minus ST Liabilities
    ltbank_mag = [sum(bv(c, j) for c in mt.LOAN_BANK) for j in idx]
    interest_exp = [pl[111][j] + pl[112][j] + pl[113][j] for j in idx]

    roce = [safe_div(pl[118][j], avg2(cap_employed, open_assets - open_cl, j)) for j in idx]
    roe = [safe_div(pl[118][j], avg2(bs[154], open_equity, j)) for j in idx]
    roa = [safe_div(pl[118][j], avg2(bs[135], open_assets, j)) for j in idx]
    gross_margin = [safe_div(pl[82][j], pl[79][j]) for j in idx]
    op_margin = [safe_div(pl[105][j], pl[79][j]) for j in idx]
    net_margin = [safe_div(pl[118][j], pl[79][j]) for j in idx]

    current_ratio = [safe_div(bs[124][j], bs[137][j], True) for j in idx]
    quick_ratio = [safe_div(bs[124][j] - bs[128][j], bs[137][j], True) for j in idx]
    cash_ratio = [safe_div(bs[125][j], bs[137][j], True) for j in idx]

    inv_turns = [safe_div(pl[81][j], avg2(bs[128], open_inventory, j), True) for j in idx]
    # Receivables Turnover uses the plain Revenue/AvgAR form (not the Revenue-minus-ΔAR "cash
    # collections" proxy the source sheet used) - Volta's "AR" here is the installment PORTFOLIO
    # balance, which grows faster than monthly revenue while the book is expanding, so the ΔAR
    # adjustment produced a near-zero/negative numerator and a nonsensical multi-year DSO.
    ar_turns = [safe_div(pl[79][j], avg2(bs[126], open_ar, j), True) for j in idx]
    asset_turns = [safe_div(pl[79][j], avg2(bs[135], open_assets, j), True) for j in idx]
    ap_turns = [safe_div(pl[81][j], avg2(bs[138], open_ap, j), True) for j in idx]

    dsi = [safe_div(365, inv_turns[j], True) for j in idx]
    dso = [safe_div(365, ar_turns[j], True) for j in idx]
    dpo = [safe_div(365, ap_turns[j], True) for j in idx]

    debt_to_assets = [safe_div(bs[148][j], bs[135][j], True) for j in idx]
    debt_to_equity = [safe_div(bs[148][j], bs[154][j], True) for j in idx]

    debt_service = [delta(ltbank_mag, open_ltbank, j) * -1 + interest_exp[j] for j in idx]
    dscr = [safe_div(pl[105][j], debt_service[j], True) for j in idx]
    interest_cov = [safe_div(pl[105][j], interest_exp[j], True) for j in idx]

    def avg_defined(vals):
        got = [v for v in vals if v is not None]
        return sum(got) / len(got) if got else None

    def row(ka, en, vals, fmt):
        return {'ka': ka, 'en': en, 'fmt': fmt, 'vals': vals, 'avg': avg_defined(vals)}

    groups = [
        {'ka': 'მომგებიანობის რეშიოები', 'en': 'Profitability Ratios', 'subgroups': [
            {'ka': 'უკუგების რეშიოები', 'en': 'Return Ratios', 'rows': [
                row('კაპიტალის უკუგება (ROCE)', 'Return on Capital Employed', roce, 'pct'),
                row('საკუთარი კაპიტალის უკუგება (ROE)', 'Return on Equity', roe, 'pct'),
                row('აქტივების უკუგება (ROA)', 'Return on Assets', roa, 'pct'),
            ]},
            {'ka': 'მარჟის რეშიოები', 'en': 'Margin Ratios', 'rows': [
                row('საერთო მოგების მარჟა', 'Gross Profit Margin', gross_margin, 'pct'),
                row('საოპერაციო მოგების მარჟა (EBITDA)', 'Operating Profit Margin', op_margin, 'pct'),
                row('წმინდა მოგების მარჟა', 'Net Profit Margin', net_margin, 'pct'),
            ]},
        ]},
        {'ka': 'ეფექტურობის რეშიოები', 'en': 'Efficiency Ratios', 'subgroups': [
            {'ka': 'ლიკვიდურობის რეშიოები', 'en': 'Liquidity Ratios', 'rows': [
                row('მიმდინარე რეშიო', 'Current Ratio', current_ratio, 'x'),
                row('სასწრაფო რეშიო (Quick/Acid-test)', 'Quick Ratio', quick_ratio, 'x'),
                row('ფულადი რეშიო', 'Cash Ratio', cash_ratio, 'x'),
            ]},
            {'ka': 'ბრუნვის რეშიოები', 'en': 'Turnover Ratios', 'rows': [
                row('მარაგების ბრუნვა', 'Inventory Turnover Ratio', inv_turns, 'x'),
                row('მოთხოვნების ბრუნვა', 'Receivables Turnover Ratio', ar_turns, 'x'),
                row('აქტივების ბრუნვა', 'Asset Turnover Ratio', asset_turns, 'x'),
                row('გადასახდელების ბრუნვა', 'Payables Turnover Ratio', ap_turns, 'x'),
            ]},
            {'ka': 'ფულადი ციკლი', 'en': 'Cash Conversion Cycle', 'rows': [
                row('მარაგების გაყიდვის დღეები (DSI)', 'Days Sales of Inventory', dsi, 'days'),
                row('მოთხოვნების ამოღების დღეები (DSO)', 'Days Sales Outstanding', dso, 'days'),
                row('გადასახდელების დღეები (DPO)', 'Days Payable Outstanding', dpo, 'days'),
            ]},
        ]},
        {'ka': 'ლევერიჯის რეშიოები', 'en': 'Leverage Ratios', 'subgroups': [
            {'ka': 'ბალანსის რეშიოები', 'en': 'Balance Sheet Ratios', 'rows': [
                row('ვალი აქტივებთან', 'Debt to Assets Ratio', debt_to_assets, 'pct'),
                row('ვალი კაპიტალთან', 'Debt to Equity Ratio', debt_to_equity, 'x'),
            ]},
            {'ka': 'საშემოსავლო რეშიოები', 'en': 'Income Statement Ratio', 'rows': [
                row('ვალის მომსახურების კოეფიციენტი (DSCR)', 'Debt Service Coverage Ratio', dscr, 'x'),
                row('პროცენტის დაფარვის კოეფიციენტი', 'Interest Coverage Ratio', interest_cov, 'x'),
            ]},
        ]},
    ]
    return groups


def bv_open_cash():
    """JS: S(stats(0,0),'1 1').o + S(stats(0,0),'1 2').o - opening cash as of the very start of the year."""
    return opening0.get('1 1', 0.0) + opening0.get('1 2', 0.0)


def _children_of(root, acc_dict):
    out = []
    prefix = root + ' '
    for c in acc_dict:
        if c.startswith(prefix) and c.count(' ') == root.count(' ') + 1:
            out.append(c)
    return out


mt._AVBS_128 = [c for c in _children_of('1 6', d['acc']) if c != '1 6 35']

if __name__ == '__main__':
    pl, bs, cf = build()
    ratio_groups = ratios(pl, bs)
    # string keys (JSON requires it) - dashboard_template.html's STMT lookup accesses these the
    # same way PL_ACTUALS[105] etc. already worked (JS coerces the numeric literal key to a string).
    out = {'months': months, 'pl': {str(k): v for k, v in pl.items()}, 'bs': {str(k): v for k, v in bs.items()},
           'cf': {str(k): v for k, v in cf.items()}, 'ratios': ratio_groups}
    op = os.path.join(BASE, 'statements_data.json')
    json.dump(out, open(op, 'w', encoding='utf-8'), ensure_ascii=False, separators=(',', ':'))
    print('wrote', op, 'months', NM, 'pl', len(pl), 'bs', len(bs), 'cf', len(cf), 'ratio rows',
          sum(len(sg['rows']) for g in ratio_groups for sg in g['subgroups']))
