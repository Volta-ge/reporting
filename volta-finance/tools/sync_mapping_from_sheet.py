r"""Phase 4 of the mapping-consolidation project (2026-09-25): reads the live Google Sheet (the
finance team's editable "policy" mapping table - see tools/build_mapping_sheet.py for how it was
built) via a Service Account and turns any hand-edited pl_line/bs_line/cf_line cell into an
OVERRIDE that mapping_table.py / statements_engine.py apply on top of the built-in classification -
so a reclassification made on the Sheet reaches the Account Mapping tab AND every statement that
uses that account, automatically on the next refresh.

Requires: `pip install gspread` (already installed 2026-09-25) and the Service Account key at
VOLTA_SHEET_KEY (default: D:/all/volta/Volta_Accounting/volta-finance-mapping-e3d25f75d8af.json -
NOT committed to git, see feedback_no_passwords_in_git). The Sheet itself must be shared with that
Service Account's client_email as at least Viewer (done 2026-09-25).

Output: sheet_overrides.json in VOLTA_FIN_DATA - {code: {pl: [...], bs: [...], cf: [...]}}, using the
SAME value vocabulary as mapping_table.py's classify() (numeric budget rows, 't<row>', 'sal', 'int',
'none', 'op', 'cash', 'xfer' for pl/bs; CF_CAT_CODES category keys for cf) - a label the parser
cannot match to a known line is an ERROR (surfaced, never silently dropped or guessed).
"""
import json
import os
import sys

import gspread

import mapping_table as mt

BASE = os.environ.get('VOLTA_FIN_DATA', 'D:/all/volta/Volta_Accounting')
SHEET_ID = os.environ.get('VOLTA_MAPPING_SHEET_ID', '19OUF0V6TYDaQtNU0Z2edbILCp1BjWrFXm0bygRHWUMI')
KEY_FILE = os.environ.get('VOLTA_SHEET_KEY', os.path.join(BASE, 'volta-finance-mapping-e3d25f75d8af.json'))

budget = json.load(open(os.path.join(BASE, 'budget_data.json'), encoding='utf-8'))

# ---------- reverse of build_mapping_sheet.py's pl_label()/bs_label()/cf_label() ----------
PL_LABEL_KA = {r['row']: r['ka'] for r in budget['pl']}
BS_LABEL_KA = {r['row']: r['ka'] for r in budget['bs']}
CF_ROW_LABEL_KA = {r['row']: r['ka'] for r in budget['cf']}
CAT_ROW = {'cogs': 34, 'salary_sh': 35, 'salary_other': 36, 'management_fee': 37, 'delivery': 38, 'marketing': 39,
           'marketing_salaries': 40, 'office_rent': 41, 'office_exp': 42, 'utility': 44,
           'interest_investors': 46, 'interest_sh': 47, 'interest_bank': 48, 'corporate_party': 50,
           'capex_sysdev': 58, 'capex_office': 59, 'capex_truck': 60, 'loan_bog': 63, 'loan_sh_draw': 64,
           'loan_zuk_draw': 65, 'dividend': 68, 'loan_to_finhub': 69, 'loan_sh_repay': 70,
           'loan_principal_all': 71, 'other_sales': 55}
ROW_TO_CAT = {v: k for k, v in CAT_ROW.items()}  # a budget cf row can map from >1 cat; last-wins is fine, see NOTE below

PL_LABEL_TO_ROW = {v: k for k, v in PL_LABEL_KA.items()}
PL_LABEL_TO_ROW['ბიუჯეტის მუხლის გარეშე'] = 'none'
BS_LABEL_TO_ROW = {v: k for k, v in BS_LABEL_KA.items()}
for row, label in BS_LABEL_KA.items():
    BS_LABEL_TO_ROW[label + ' (საკუთარი მუხლის გარეშე)'] = 't' + str(row)
CF_LABEL_TO_CAT = {v: k for k, v in CF_ROW_LABEL_KA.items() if v in ROW_TO_CAT.values() or True}
CF_LABEL_TO_CAT = {}
for cat, row in CAT_ROW.items():
    lab = CF_ROW_LABEL_KA.get(row)
    if lab:
        CF_LABEL_TO_CAT.setdefault(lab, cat)  # first category wins a shared label (none currently shared)
CF_LABEL_TO_CAT['საოპერაციო ნაკადი (საკუთარი მუხლის გარეშე)'] = 'op'
CF_LABEL_TO_CAT['ფული (საწყისი/საბოლოო ნაშთი)'] = 'cash'
CF_LABEL_TO_CAT['შიდა გადარიცხვა (ფულად ნაკადში არ ითვლება)'] = 'xfer'

PL_SPECIAL = {'პარტნიორების ხელფასი': 92, 'სხვა პერსონალის ხელფასი': 93}  # bare (non-'/'-joined) label on a leaf account


def parse_pl(text):
    text = text.strip()
    if not text:
        return []
    if ' / ' in text:  # 'sal' or 'int' combo cell, e.g. from a 7 4 10 / 8 2 10 anchor row itself
        parts = [p.strip() for p in text.split(' / ')]
        if set(parts) <= {PL_LABEL_KA.get(92), PL_LABEL_KA.get(93)}:
            return ['sal']
        if set(parts) <= {PL_LABEL_KA.get(111), PL_LABEL_KA.get(112), PL_LABEL_KA.get(113)}:
            return ['int']
        raise ValueError('unrecognised P&L combo label: ' + text)
    if '; ' in text:  # an account that lands on >1 P&L line at once (e.g. both an Opex line and an interest split)
        out = []
        for part in text.split('; '):
            out.extend(parse_pl(part))
        return out
    if text in PL_SPECIAL:
        return [PL_SPECIAL[text]]
    if text not in PL_LABEL_TO_ROW:
        raise ValueError('unrecognised P&L line label: ' + text)
    return [PL_LABEL_TO_ROW[text]]


def parse_bs(text):
    text = text.strip()
    if not text:
        return []
    if text not in BS_LABEL_TO_ROW:
        raise ValueError('unrecognised Balance Sheet line label: ' + text)
    return [BS_LABEL_TO_ROW[text]]


def parse_cf(text):
    text = text.strip()
    if not text:
        return []
    out = []
    for part in text.split('; '):
        part = part.strip()
        if not part:
            continue
        if part not in CF_LABEL_TO_CAT:
            raise ValueError('unrecognised Cash Flow line label: ' + part)
        out.append(CF_LABEL_TO_CAT[part])
    return out


def main():
    gc = gspread.service_account(filename=KEY_FILE)
    ws = gc.open_by_key(SHEET_ID).sheet1
    rows = ws.get_all_records()  # list of dicts keyed by header row

    overrides = {}
    errors = []
    for i, r in enumerate(rows, start=2):  # sheet row 1 is the header
        code = str(r.get('code', '')).strip()
        if not code:
            continue
        try:
            pl = parse_pl(str(r.get('pl_line', '')))
            bs = parse_bs(str(r.get('bs_line', '')))
            cf = parse_cf(str(r.get('cf_line', '')))
        except ValueError as e:
            errors.append(f'row {i} ({code}): {e}')
            continue
        overrides[code] = {'pl': pl, 'bs': bs, 'cf': cf}

    if errors:
        print(f'{len(errors)} row(s) could not be parsed - NOT written, previous overrides file (if any) is untouched:', file=sys.stderr)
        for e in errors:
            print('  ' + e, file=sys.stderr)
        sys.exit(1)

    out = os.path.join(BASE, 'sheet_overrides.json')
    json.dump(overrides, open(out, 'w', encoding='utf-8'), ensure_ascii=False, indent=0)
    print('wrote', out, len(overrides), 'account overrides (from', len(rows), 'sheet rows)')


if __name__ == '__main__':
    main()
