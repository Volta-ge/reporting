r"""Phase 2 of the mapping-consolidation project (2026-09-25 plan, see
memory/volta_oris_financials_artifact.md): computes every P&L / Balance Sheet / Cash Flow actual
number from mapping_table.csv + dash_data.json, in ONE Python module - a faithful port of
dashboard_template.html's PL_ACTUALS / BS_ACTUALS / LIVE_CF_ACTUALS (as live 2026-09-25), which
today only exist client-side in JS. Read-only; does not touch the live dashboard or its data files.

Two kinds of statement line, exactly mirroring the JS:
  - DIRECT lines: sum of (debit-credit) or (credit-debit) over an account's own postings/balance -
    computed from dash_data.json's `agg` (already Python-built by build_data.py), reproducing the
    JS stats()/S()/close()/bsVal() tree-aggregation exactly.
  - ANCHOR+COUNTERPARTY lines: value = postings through a FIXED anchor account, bucketed by what the
    OTHER leg of the posting is classified as - covers all Cash Flow categories (reuses
    dash_data.json's `cf`/`cf_cat`, already computed by build_data.py's CF_CAT_CODES, so no
    reimplementation needed here) plus two PL-only splits that currently exist ONLY in JS and are
    reimplemented here from raw WIRING: salary (92/93, anchor 7 4 10/15/50) and loan interest
    (111/112/113, anchor 8 2 10).
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
cf_cat_rows = d.get('cf_cat', [])  # [category, m, in, out]

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


# ---------- Cash Flow: reuse build_data.py's own CF_CAT_CODES computation (already Python, no reimplementation) ----------
byCat = {}
for cat, m, i, o in cf_cat_rows:
    byCat.setdefault(cat, {})[m] = (i, o)


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
cat_multi = d.get('cat_multi', {})
SAL_ANCHORS = mt.OPX_SAL  # ['7 4 10','7 4 15','7 4 50']

sal_sh = [0.0] * NM
sal_other = [0.0] * NM
int_investors = [0.0] * NM
int_sh = [0.0] * NM
int_bank_other = [0.0] * NM

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
        sh = (ctr in mt.SH_PAYROLL and anchor == '7 4 10') or (ctr in mt.SH_CONSULT and anchor == '7 4 50')
        (sal_sh if sh else sal_other)[mm] += sg * money
    if starts_with(dp, '8 2 10') or starts_with(kp, '8 2 10'):
        ctr, sg = (kp, 1) if starts_with(dp, '8 2 10') else (dp, -1)
        cats = cat_multi.get(ctr, [])
        if 'interest_investors' in cats:
            int_investors[mm] += sg * money
        elif 'interest_sh' in cats:
            int_sh[mm] += sg * money
        else:
            int_bank_other[mm] += sg * money


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
    prov102 = [expA('9 1', j) + expA('9 2', j) for j in idx]
    util99 = [sum(expA(c, j) for c in mt.OPX_UTIL) - incA('6 1 90 2', j) for j in idx]
    misc = [expA('8 2', j) - expA('8 2 10', j) - expA('8 2 50', j) - expA('8 2 90 1', j)
             + expA('8 1', j) - expA('8 1 10', j) - expA('8 1 25', j) - expA('8 1 50', j) - expA('8 1 90 1', j) for j in idx]
    ppe110 = [expA('8 2 90 1', j) + expA('8 1 90 1', j) for j in idx]
    fxexp115 = [expA('8 2 50', j) for j in idx]
    fxinc116 = [expA('8 1 50', j) for j in idx]
    dep109 = [expA('7 4 55', j) for j in idx]
    int111, int112 = int_investors, int_sh
    int113 = [int_bank_other[j] + expA('8 1 10', j) for j in idx]
    sal92, sal93 = sal_sh, sal_other
    del91 = [sum(expA(c, j) for c in mt.OPX_DEL) for j in idx]
    sys98 = [sum(expA(c, j) for c in mt.OPX_SYS) for j in idx]
    mkt100 = [sum(expA(c, j) for c in mt.OPX_MKT) for j in idx]
    opx_expl7_codes = mt.OPX_EXPL7
    off95 = [expA('7 4', j) - sum(expA(c, j) for c in opx_expl7_codes) for j in idx]
    opex_base = [expA('7 3', j) + expA('7 4', j) - dep109[j] + prov102[j] + misc[j] for j in idx]
    opex_raw = [opex_base[j] - incA('6 1 90 2', j) for j in idx]
    nonop108 = [ppe110[j] + dep109[j] + int111[j] + int112[j] + int113[j] + fxexp115[j] + fxinc116[j] for j in idx]
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
        92: sal92, 93: sal93, 94: [expA('7 4 90 49', j) for j in idx], 95: off95, 96: [expA('7 4 20', j) for j in idx],
        98: sys98, 99: util99, 100: mkt100, 102: prov102, 103: [expA('7 4 90 44', j) for j in idx],
        104: [expA('7 4 18', j) + misc[j] + resid[j] for j in idx], 105: ebitda105,
        108: nonop108, 109: dep109, 110: ppe110, 111: int111, 112: int112, 113: int113,
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
    # string keys (JSON requires it) - dashboard_template.html's STMT lookup accesses these the
    # same way PL_ACTUALS[105] etc. already worked (JS coerces the numeric literal key to a string).
    out = {'months': months, 'pl': {str(k): v for k, v in pl.items()}, 'bs': {str(k): v for k, v in bs.items()},
           'cf': {str(k): v for k, v in cf.items()}}
    op = os.path.join(BASE, 'statements_data.json')
    json.dump(out, open(op, 'w', encoding='utf-8'), ensure_ascii=False, separators=(',', ':'))
    print('wrote', op, 'months', NM, 'pl', len(pl), 'bs', len(bs), 'cf', len(cf))
