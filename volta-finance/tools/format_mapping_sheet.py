r"""Phase 4 (2026-09-25), follow-up: beautifies the live Google Sheet mapping table and adds dropdown
data validation on pl_line/bs_line/cf_line so the finance team can pick a line instead of typing exact
Georgian text (which sync_mapping_from_sheet.py's parser matches literally). The dropdown option lists
are generated from the SAME label functions build_mapping_sheet.py used to populate the sheet, applied
to the FULL domain of values classify() can ever produce for pl/bs/cf - not just the values currently
in use - so every legal reclassification target is selectable.

Validation is non-strict (a warning, not a hard block): some cells legitimately hold a '; '-joined
multi-value or a '/'-joined combo (see sync_mapping_from_sheet.py's parse_pl/parse_bs/parse_cf), which
a single-select dropdown can't produce directly - typing/pasting those still works, the dropdown just
covers the common single-line case.

Requires the same Service Account key as sync_mapping_from_sheet.py. Writes to the live Sheet - run
with --dry-run first to print the option lists without touching the Sheet.
"""
import json
import os
import sys

import gspread
from gspread.utils import ValidationConditionType

BASE = os.environ.get('VOLTA_FIN_DATA', 'D:/all/volta/Volta_Accounting')
SHEET_ID = os.environ.get('VOLTA_MAPPING_SHEET_ID', '19OUF0V6TYDaQtNU0Z2edbILCp1BjWrFXm0bygRHWUMI')
KEY_FILE = os.environ.get('VOLTA_SHEET_KEY', os.path.join(BASE, 'volta-finance-mapping-e3d25f75d8af.json'))

import mapping_table as mt

budget = json.load(open(os.path.join(BASE, 'budget_data.json'), encoding='utf-8'))

# ---------- exact mirror of build_mapping_sheet.py's label functions + CAT_ROW ----------
PL_LABEL = {r['row']: r['ka'] for r in budget['pl']}
BS_LABEL = {r['row']: r['ka'] for r in budget['bs']}
CF_ROW_LABEL = {r['row']: r['ka'] for r in budget['cf']}
CAT_ROW = {'cogs': 34, 'salary_sh': 35, 'salary_other': 36, 'management_fee': 37, 'delivery': 38, 'marketing': 39,
           'marketing_salaries': 40, 'office_rent': 41, 'office_exp': 42, 'utility': 44,
           'interest_investors': 46, 'interest_sh': 47, 'interest_bank': 48, 'corporate_party': 50,
           'capex_sysdev': 58, 'capex_office': 59, 'capex_truck': 60, 'loan_bog': 63, 'loan_sh_draw': 64,
           'loan_zuk_draw': 65, 'dividend': 68, 'loan_to_finhub': 69, 'loan_sh_repay': 70,
           'loan_principal_all': 71, 'other_sales': 55}


def pl_label(row):
    if row == 'sal':
        return PL_LABEL.get(92, '') + ' / ' + PL_LABEL.get(93, '')
    if row == 'int':
        return ' / '.join(x for x in (PL_LABEL.get(111, ''), PL_LABEL.get(112, ''), PL_LABEL.get(113, '')) if x)
    if row == 'none':
        return 'ბიუჯეტის მუხლის გარეშე'
    return PL_LABEL.get(row, str(row))


def bs_label(row):
    if isinstance(row, str) and row.startswith('t'):
        n = int(row[1:])
        return (BS_LABEL.get(n, '') or '') + ' (საკუთარი მუხლის გარეშე)'
    return BS_LABEL.get(row, str(row))


def cf_label(cat):
    if cat == 'op':
        return 'საოპერაციო ნაკადი (საკუთარი მუხლის გარეშე)'
    if cat == 'cash':
        return 'ფული (საწყისი/საბოლოო ნაშთი)'
    if cat == 'xfer':
        return 'შიდა გადარიცხვა (ფულად ნაკადში არ ითვლება)'
    return CF_ROW_LABEL.get(CAT_ROW.get(cat), cat)


# ---------- full domain of values classify()/apply_overrides() can ever produce ----------
PL_DOMAIN = list(mt.MP_PL_DETAIL_ORDER) + [92, 93, 111, 112, 113, 'sal', 'int', 'none']
# BS_FALLBACK's targets are exactly ['t124','t129','t137','t148','t154'] (see mapping_table.py) - the
# only 't<row>' forms bs_label() can ever produce, alongside MP_BS_DETAIL_ORDER's own rows and the 2
# equity rows (155/156) set directly in build_maps().
BS_DOMAIN = list(mt.MP_BS_DETAIL_ORDER) + [155, 156] + [r for _, r in mt.BS_FALLBACK]
CF_DOMAIN = list(CAT_ROW.keys()) + ['op', 'cash', 'xfer']

PL_OPTIONS = [pl_label(x) for x in PL_DOMAIN]
BS_OPTIONS = [bs_label(x) for x in BS_DOMAIN]
CF_OPTIONS = [cf_label(x) for x in CF_DOMAIN]


def _check(name, domain, options):
    bad = [d for d, o in zip(domain, options) if not o or not o.strip()]
    dup = {o for o in options if options.count(o) > 1}
    if bad:
        raise SystemExit(f'{name}: blank label for domain value(s): {bad}')
    if dup:
        raise SystemExit(f'{name}: duplicate labels: {dup}')
    print(name, len(options), 'options, all non-blank, no duplicates')


_check('PL', PL_DOMAIN, PL_OPTIONS)
_check('BS', BS_DOMAIN, BS_OPTIONS)
_check('CF', CF_DOMAIN, CF_OPTIONS)

if '--dry-run' in sys.argv:
    print('\nPL options:'); [print(' ', o) for o in PL_OPTIONS]
    print('\nBS options:'); [print(' ', o) for o in BS_OPTIONS]
    print('\nCF options:'); [print(' ', o) for o in CF_OPTIONS]
    sys.exit(0)

gc = gspread.service_account(filename=KEY_FILE)
sh = gc.open_by_key(SHEET_ID)
ws = sh.sheet1
n_rows = len(ws.get_all_values())
LAST = max(n_rows + 50, 1000)  # headroom for accounts added later, without re-running this script

HEADER_BG = {'red': 0.114, 'green': 0.204, 'blue': 0.267}  # dark navy, matches Volta's dashboard header tone
HEADER_FG = {'red': 1, 'green': 1, 'blue': 1}
RAW_BG = {'red': 0.93, 'green': 0.93, 'blue': 0.93}
RAW_FG = {'red': 0.55, 'green': 0.55, 'blue': 0.55}

ws.format('A1:H1', {
    'textFormat': {'bold': True, 'foregroundColor': HEADER_FG},
    'backgroundColor': HEADER_BG,
    'horizontalAlignment': 'CENTER',
    'verticalAlignment': 'MIDDLE',
})
ws.format(f'A2:H{LAST}', {
    'verticalAlignment': 'MIDDLE',
    'wrapStrategy': 'CLIP',
})
ws.format(f'F1:H{LAST}', {'backgroundColor': RAW_BG, 'textFormat': {'foregroundColor': RAW_FG, 'fontSize': 9}})
ws.format(f'A1:A{LAST}', {'textFormat': {'fontFamily': 'Consolas'}})

ws.freeze(rows=1, cols=2)

widths = [(1, 110), (2, 260), (3, 260), (4, 280), (5, 260), (6, 70), (7, 70), (8, 70)]
reqs = [{'updateDimensionProperties': {
    'range': {'sheetId': ws.id, 'dimension': 'COLUMNS', 'startIndex': i - 1, 'endIndex': i},
    'properties': {'pixelSize': w}, 'fields': 'pixelSize'}} for i, w in widths]
reqs.append({'addBanding': {'bandedRange': {
    'range': {'sheetId': ws.id, 'startRowIndex': 1, 'endRowIndex': LAST, 'startColumnIndex': 0, 'endColumnIndex': 5},
    'rowProperties': {
        'headerColor': HEADER_BG,
        'firstBandColor': {'red': 1, 'green': 1, 'blue': 1},
        'secondBandColor': {'red': 0.965, 'green': 0.973, 'blue': 0.980},
    }}}})
sh.batch_update({'requests': reqs})

ws.add_validation(f'C2:C{LAST}', ValidationConditionType.one_of_list, PL_OPTIONS, showCustomUi=True, strict=False,
                   inputMessage='აირჩიეთ მოგება-ზარალის მუხლი (ან ჩაწერეთ ხელით, თუ ერთზე მეტი მუხლი ან კომბინაცია გჭირდებათ)')
ws.add_validation(f'D2:D{LAST}', ValidationConditionType.one_of_list, BS_OPTIONS, showCustomUi=True, strict=False,
                   inputMessage='აირჩიეთ საბალანსო უწყისის მუხლი')
ws.add_validation(f'E2:E{LAST}', ValidationConditionType.one_of_list, CF_OPTIONS, showCustomUi=True, strict=False,
                   inputMessage='აირჩიეთ ფულადი ნაკადების კატეგორია (ან ჩაწერეთ ხელით რამდენიმე, "; "-ით გამოყოფილი)')

print('formatted + dropdowns added:', sh.url)
