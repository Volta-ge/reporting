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


def load_overrides(base):
    """Reads sheet_overrides.json (written by sync_mapping_from_sheet.py from the live Google Sheet -
    2026-09-25 plan). Empty dict if the file doesn't exist yet (sheet never synced) - overrides are
    always OPTIONAL, everything works with the built-in classification alone."""
    import json
    import os
    p = os.path.join(base, 'sheet_overrides.json')
    if not os.path.exists(p):
        return {}
    return json.load(open(p, encoding='utf-8'))


CF_FOR_PL = {111: 'interest_investors', 112: 'interest_sh', 113: 'interest_bank'}

# The 5 anchor accounts (see SAL_ANCHORS/'8 2 10'/'8 1 10') get their OWN 92/93/111-113-flavoured pl
# value from a STATIC pl_map/PL_FALLBACK entry (e.g. '8 1 10'->113), not from cat_multi/SH_PAYROLL/
# SH_CONSULT membership - that membership mechanism classifies the COUNTERPARTY on the other side of
# a posting THROUGH the anchor, never the anchor's own code. An override on the anchor's own sheet
# row must not synthesize a cat_multi tag for it (see apply_overrides()).
ANCHOR_CODES = {'7 4 10', '7 4 15', '7 4 50', '8 2 10', '8 1 10'}

# classify() derives these 3 cf markers itself, unconditionally, from is_cash()/cash_counter_accounts/
# the literal '1 6 35' code - they are never genuine cat_multi members. build_mapping_sheet.py still
# renders them as a cf_line label (so the sheet shows *something* for a cash/transfer account), and
# sync_mapping_from_sheet.py parses that label straight back to one of these - apply_overrides() must
# drop them before writing cat_multi, or classify() ends up appending the SAME marker twice.
DERIVED_CF = {'cash', 'op', 'xfer'}


def apply_overrides(pl_map, bs_map, cat_multi, sh_payroll, sh_consult, overrides):
    """Mutates pl_map/bs_map/cat_multi/sh_payroll/sh_consult IN PLACE so every downstream consumer
    (build_maps()'s own callers, statements_engine.py) sees the same, already-overridden picture -
    single point of truth for "what does the sheet say", applied once per run.

    Special PL values 92/93 (salary) and 111/112/113 (interest) are never stored in pl_map - in the
    built-in classification they come from SH_PAYROLL/SH_CONSULT membership and cat_multi tags
    respectively (see classify()), so an override touching those moves the account between those
    lists/tags instead of writing a pl_map entry."""
    for code, ov in overrides.items():
        pl_vals = ov.get('pl', [])
        # --- salary membership (92 = Salary of Shareholders, 93 = Salary of Other Staff/default) ---
        if 92 in pl_vals:
            lst = sh_payroll if code.startswith('3 1 32 ') else sh_consult
            if code not in lst:
                lst.append(code)
        else:
            if code in sh_payroll:
                sh_payroll.remove(code)
            if code in sh_consult:
                sh_consult.remove(code)
        # --- interest membership (111/112/113 -> a cat_multi tag, same mechanism as any other CF category) ---
        # skipped for the anchor's own code - see ANCHOR_CODES.
        want_int = {CF_FOR_PL[v] for v in pl_vals if v in CF_FOR_PL} if code not in ANCHOR_CODES else set()
        # --- CF category override (replaces whatever categories the built-in rules gave this account) ---
        # DERIVED_CF markers are stripped - classify() always re-derives them itself (see DERIVED_CF).
        cf_final = (set(ov.get('cf', [])) - DERIVED_CF) | want_int
        if cf_final or code in cat_multi:
            cat_multi[code] = sorted(cf_final)
        # --- plain P&L row override (everything except the salary/interest special values) ---
        # NOTE: when pl_vals is ONLY 92/93/111-113 (no plain row), pl_map[code] is left AS-IS, never
        # deleted - a numeric 92/93/111-113 value can be either (a) genuine membership (SH_PAYROLL/
        # SH_CONSULT/cat_multi, for class 3/4 leaf accounts that classify() never even looks up in
        # pl_map, since class 3/4 isn't in '6789' - deleting is a no-op there) or (b) a legitimate
        # STATIC pl_map/PL_FALLBACK value for the anchor account itself (e.g. '8 1 10'->113, '7 4 15'
        # ->93, used when the anchor's OWN code is the posting leg, not a counterparty) - deleting it
        # in case (b) was a real bug: it stripped the anchor's fallback and let mp_find's prefix walk
        # fall through to an unrelated ancestor (e.g. '8 1'->104), wrongly bucketing the whole anchor
        # subtree's balance into that row.
        plain_pl = [v for v in pl_vals if v not in (92, 93) and v not in CF_FOR_PL]
        if plain_pl:
            pl_map[code] = plain_pl[0]
        # --- Balance Sheet row override ---
        bs_vals = ov.get('bs', [])
        if bs_vals:
            bs_map[code] = bs_vals[0]


def mp_find(m, code):
    c = code
    while c:
        if c in m:
            return m[c]
        i = c.rfind(' ')
        c = c[:i] if i >= 0 else ''
    return None


def classify(code, pl_map, bs_map, cash_counter_accounts, cat_multi, sh_payroll=None, sh_consult=None):
    """Faithful port of dashboard_template.html's mpLinesOf(). Returns {'pl': [...], 'bs': [...], 'cf': [...]}.
    sh_payroll/sh_consult default to the built-in SH_PAYROLL/SH_CONSULT constants - pass the
    override-aware lists from apply_overrides() to make this override-aware too (used by
    build_mapping_class.py so the Account Mapping tab's display matches statements_engine.py's
    computation, both sourced from the same apply_overrides() call)."""
    if sh_payroll is None:
        sh_payroll = SH_PAYROLL
    if sh_consult is None:
        sh_consult = SH_CONSULT
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
    if code in sh_payroll or code in sh_consult:
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
