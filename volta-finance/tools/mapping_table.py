r"""Single Python source of truth for "which statement line does this Oris account belong to" -
the first step of consolidating the classification logic that currently lives split between
build_data.py (CF_CAT_CODES) and dashboard_template.html (AVPL_SPEC/AVBS_CODES/OPX_*/SH_PAYROLL/
interest-lender groups), per the 2026-09-25 plan agreed with the user (see
memory/volta_oris_financials_artifact.md and feedback_mirror_statements_and_mapping.md).

This is a FAITHFUL, byte-for-byte transcription of dashboard_template.html's mpIndex()/mpFind()/
mpLinesOf() (as of the version live 2026-09-25) - the goal of this first pass is not to change any
behaviour, only to prove the same classification can live in one Python table instead of scattered
JS constants. Validated against the live JS by build_mapping_table.py (spot-checks a broad sample
of accounts through the browser and diffs Python vs JS output - see that script's docstring).

Do NOT hand-edit this file to change a mapping once the Google Sheet exists (see the plan) - this
module will then read the Sheet instead of these constants. For now, editing here IS editing the
mapping (same as editing dashboard_template.html today), until the sheet-driven engine replaces it.
"""

# ---------- verbatim from dashboard_template.html ----------
PL_REV = ['6 1 10', '6 1 20']
PL_COGS = ['7 2', '7 1']

OPX_DEL = ['7 3', '7 4 90 12', '7 4 90 47', '7 4 27', '7 4 16', '7 4 90 18', '7 4 90 24', '7 4 90 25', '7 4 90 40', '7 4 29']
OPX_SYS = ['7 4 22', '7 4 90 32', '7 4 90 34', '7 4 90 42', '7 4 90 48', '7 4 90 52', '7 4 45']
OPX_UTIL = ['7 4 90 11', '7 4 90 27', '7 4 90 28', '7 4 90 50']
OPX_MKT = ['7 4 90 39', '7 4 90 10', '7 4 56']
OPX_SAL = ['7 4 10', '7 4 15', '7 4 50']
OPX_EXPL7 = [c for c in OPX_DEL if c != '7 3'] + ['7 4 90 49', '7 4 20'] + OPX_SYS + OPX_UTIL + OPX_MKT + ['7 4 90 44', '7 4 55', '7 4 18'] + OPX_SAL

LOAN_BANK = ['4 1 90 10611', '4 1 90 3746', '4 1 90 4693', '4 1 90 5506', '4 1 90 7426']
LOAN_ZUK = ['4 1 90 8018']
LOAN_SH = ['4 1 90 11677', '4 1 90 11998', '4 1 90 12284', '4 1 90 1318', '4 1 90 1684', '4 1 90 2426', '4 1 90 5959', '4 1 90 8019']

SH_PAYROLL = ['3 1 32 0109', '3 1 32 0011', '3 1 32 0004', '3 1 32 0012']
SH_CONSULT = ['3 1 10 1684']

# MISC_CODES / NINE_CODES are `leafCodes('8 2',[...])+leafCodes('8 1',[...])` / `leafCodes('9 1')+leafCodes('9 2')`
# in JS - computed dynamically below from whichever accounts actually have 2026 postings, same as JS.
MISC_EXCL = ['8 2 10', '8 2 50', '8 2 90 1', '8 1 10', '8 1 25', '8 1 50', '8 1 90 1']

# The explicit PL leaf-account lists behind each MP_PL_DETAIL row (mirrors AVPL_SPEC's PLI/PLE lists
# for exactly the rows mpIndex() reads: 79,81,84,85,86,91,94,95,96,98,99,100,102,103,104,109,110,115,116).
# 95/102/104 use dynamic leafCodes() lookups - filled in by build_pl_map() below, not listed here.
PL_EXPLICIT = {
    79: PL_REV, 81: PL_COGS, 84: ['6 1 91'], 85: ['8 1 25'], 86: ['6 1 90 1'],
    91: OPX_DEL, 94: ['7 4 90 49'], 96: ['7 4 20'], 98: OPX_SYS, 99: OPX_UTIL + ['6 1 90 2'],
    100: OPX_MKT, 103: ['7 4 90 44'], 109: ['7 4 55'], 110: ['8 2 90 1', '8 1 90 1'],
    115: ['8 2 50'], 116: ['8 1 50'],
}
MP_PL_DETAIL_ORDER = [79, 81, 84, 85, 86, 91, 94, 95, 96, 98, 99, 100, 102, 103, 104, 109, 110, 115, 116]

AVBS_CODES_STATIC = {
    124: ['1'], 125: ['1 1', '1 2'], 126: ['1 4 10'], 129: ['2'], 130: ['2 1 60'], 131: ['2 1 80'],
    132: ['2 1 90'], 133: ['2 5'], 134: ['2 2', '2 6'], 135: ['1', '2'],
    137: ['3'], 138: ['3 1 10'], 139: ['3 1 20'], 140: ['3 1 30', '3 1 32'], 141: ['3 3 10'],
    142: ['3 3 20'], 143: ['3 3 30'], 144: ['3 3 40'], 146: ['3 3 80'], 147: ['3 4 10'], 148: ['4'],
    149: LOAN_SH, 150: LOAN_BANK, 151: LOAN_ZUK, 152: ['3', '4'],
}
MP_BS_DETAIL_ORDER = [125, 126, 128, 130, 131, 132, 133, 134, 138, 139, 140, 141, 142, 143, 144, 146, 147, 149, 150, 151]

PL_FALLBACK = [
    ('8 1 10', 113), ('8 2 10', 'int'), ('8 2', 104), ('8 1', 104), ('9 1', 102), ('9 2', 102),
    ('7 4 10', 'sal'), ('7 4 50', 'sal'), ('7 4 15', 93), ('7 4', 95), ('7 3', 91),
    ('6', 'none'), ('7', 'none'), ('8', 'none'), ('9', 'none'),
]
BS_FALLBACK = [('1', 't124'), ('2', 't129'), ('3', 't137'), ('4', 't148'), ('5', 't154')]

CASH_PREFIXES = ('1 1', '1 2')


def is_cash(code):
    return code.startswith(CASH_PREFIXES)


def leaf_codes(root, excl, posting_codes):
    """Mirrors JS leafCodes(): every account with a 2026 posting under `root`, minus `excl`."""
    def under(c, r):
        return c == r or c.startswith(r + ' ')
    return [c for c in posting_codes if under(c, root) and not any(under(c, e) or c == e for e in excl)]


def build_maps(posting_codes, children_of):
    """posting_codes: set of depth()-truncated codes seen in 2026 WIRING (debit or credit side) -
    same universe as JS's `POSTINGS`/Object.keys(POSTINGS). children_of: code -> list of child codes
    in the account tree (for AVBS_CODES[128], mirroring JS's `children['1 6']`)."""
    pl_explicit = dict(PL_EXPLICIT)
    pl_explicit[95] = leaf_codes('7 4', OPX_EXPL7, posting_codes)
    nine_codes = leaf_codes('9 1', [], posting_codes) + leaf_codes('9 2', [], posting_codes)
    misc_codes = leaf_codes('8 2', MISC_EXCL, posting_codes) + leaf_codes('8 1', MISC_EXCL, posting_codes)
    pl_explicit[102] = nine_codes
    pl_explicit[104] = ['7 4 18'] + misc_codes

    pl_map = {}
    for row in MP_PL_DETAIL_ORDER:
        for c in pl_explicit[row]:
            pl_map.setdefault(c, row)
    for c, r in PL_FALLBACK:
        pl_map.setdefault(c, r)

    avbs = dict(AVBS_CODES_STATIC)
    avbs[128] = [c for c in children_of.get('1 6', []) if c != '1 6 35']
    bs_map = {}
    for row in MP_BS_DETAIL_ORDER:
        for c in avbs[row]:
            bs_map.setdefault(c, row)
    bs_map['5 3 10'] = 155
    bs_map['5 3 30'] = 156
    bs_map['1 6 35'] = 't124'
    for c, r in BS_FALLBACK:
        bs_map.setdefault(c, r)
    return pl_map, bs_map


def mp_find(m, code):
    c = code
    while c:
        if c in m:
            return m[c]
        i = c.rfind(' ')
        c = c[:i] if i >= 0 else ''
    return None


def classify(code, pl_map, bs_map, cash_counter_accounts, cat_multi):
    """Faithful port of dashboard_template.html's mpLinesOf(). Returns {'pl': [...], 'bs': [...], 'cf': [...]}."""
    cls = code[0]
    pl, bs, cf = [], [], []
    if cls in '6789':
        v = mp_find(pl_map, code)
        if v is not None:
            pl.append(v)
    if cls in '12345' and not is_cash(code):
        v = mp_find(bs_map, code)
        if v is not None:
            bs.append(v)
    if is_cash(code):
        bs.append(125)
    if code in SH_PAYROLL or code in SH_CONSULT:
        pl.append(92)
    elif code.startswith('3 1 32 '):
        pl.append(93)
    for c in cat_multi.get(code, ()):
        cf.append(c)
        if c == 'interest_investors':
            pl.append(111)
        elif c == 'interest_sh':
            pl.append(112)
        elif c == 'interest_bank':
            pl.append(113)
    if not cf and code in cash_counter_accounts:
        cf.append('op')
    if is_cash(code):
        cf.append('cash')
    if code == '1 6 35':
        cf.append('xfer')
    return {'pl': pl, 'bs': bs, 'cf': cf}
