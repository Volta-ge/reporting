"""Inject dash_data.json into dashboard_template.html -> Volta_Finance.html
(written to the repo folder, the data folder, and an optional extra path such as the session scratchpad used for the Artifact publish)."""
import json, os, shutil, sys
TOOLS = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(TOOLS)
BASE = os.environ.get('VOLTA_FIN_DATA', 'D:/all/volta/Volta_Accounting')
tpl = open(os.path.join(TOOLS, 'dashboard_template.html'), encoding='utf-8').read()
data = open(os.path.join(BASE, 'dash_data.json'), encoding='utf-8').read()
budp = os.path.join(BASE, 'budget_data.json')
budget = open(budp, encoding='utf-8').read() if os.path.exists(budp) else 'null'
mapp = os.path.join(BASE, 'mapping_data.json')
mapping = open(mapp, encoding='utf-8').read() if os.path.exists(mapp) else 'null'

# --- Mapping tab <-> statements: the Mapping tab must show the mapping the statements really use (2026-09-21 rule) ---
# `cat_multi` (build_data.py's CF_CAT_CODES) is what the cash-flow rows are computed from. For every account the statements use
# we attach `stmt` (their category keys) and `sagree`: 'ok' = the finance file's own tag says the same thing, 'differs' = the
# file tags it differently (the statements follow the Excel model), 'notag' = the file has the account but no tag. Accounts the
# statements use that are missing from the file are added as rows so the tab is complete.
EXPECT = {'cogs': ['cogs'], 'salary_sh': ['salary'], 'salary_other': ['salary'], 'management_fee': ['management'], 'delivery': ['transportation'],
          'marketing': ['marketing'], 'marketing_salaries': ['marketing salaries'], 'office_rent': ['office rent'], 'office_exp': ['office'],
          'utility': ['utility'], 'interest_investors': ['interest'], 'interest_sh': ['interest'], 'interest_bank': ['interest'],
          'corporate_party': ['party'], 'capex_sysdev': ['capex - system', 'system dev'], 'capex_truck': ['truck'],
          'capex_office': ['office equipment', 'capex'], 'dividend': ['dividend'], 'loan_bog': ['loan'], 'loan_sh_draw': ['loan'],
          'loan_zuk_draw': ['loan'], 'loan_sh_repay': ['loan'], 'loan_principal_all': ['loan', 'principal'], 'loan_to_finhub': ['finhub', 'loan'],
          'other_sales': ['other sales']}
# What the Excel model (the authority) calls each statements category: (sub-category, sub-sub-category, direction).
EXCEL_TAG = {'cogs': ('operational', 'Cogs', 'Out'), 'salary_sh': ('operational', 'Salary', 'Out'), 'salary_other': ('operational', 'Salary', 'Out'),
             'management_fee': ('operational', 'Management Service Fee', 'Out'), 'delivery': ('operational', 'Transportation Exp', 'Out'),
             'marketing': ('operational', 'Marketing service fee', 'Out'), 'marketing_salaries': ('operational', 'Marketing salaries', 'Out'),
             'office_rent': ('operational', 'Office Rent', 'Out'), 'office_exp': ('operational', 'Office Exp', 'Out'), 'utility': ('operational', 'Utility Exp', 'Out'),
             'interest_investors': ('operational', 'Investors interest 15%', 'Out'), 'interest_sh': ('operational', 'Interest', 'Out'), 'interest_bank': ('operational', 'Interest', 'Out'),
             'corporate_party': ('operational', 'Corporate Party', 'Out'), 'capex_sysdev': ('Investment', 'Capex - system Development', 'Out'),
             'capex_truck': ('Investment', 'Capex - Trucks and updates', 'Out'), 'capex_office': ('Investment', 'Capex - office equipment', 'Out'),
             'other_sales': ('Investment', 'other sales', 'in'), 'dividend': ('Financial', 'Advance paid Dividend', 'Out'), 'loan_to_finhub': ('Financial', 'Loan To Finhub', ''),
             'loan_bog': ('Financial', 'Loan Principal', ''), 'loan_sh_draw': ('Financial', 'Loan', ''), 'loan_zuk_draw': ('Financial', 'Loan', ''),
             'loan_sh_repay': ('Financial', 'Loan', 'Out'), 'loan_principal_all': ('Financial', 'Loan Principal', '')}
if mapping != 'null':
    _d = json.loads(data); _m = json.loads(mapping); _cm = _d.get('cat_multi', {}); _acc = _d['acc']
    _rows = _m['rows']; _idx = {r['code']: r for r in _rows}; _added = 0
    for code, cats in _cm.items():
        r = _idx.get(code)
        if r is None:
            r = {'code': code, 'name': (_acc.get(code) or [''])[0], 'cat': '', 'subcat': '', 'subsub': '', 'inout': '', 'status': '', 'confidence': None}
            _rows.append(r); _idx[code] = r; _added += 1
        r['stmt'] = cats
        lab = (r.get('subsub') or '').lower()
        r['sagree'] = 'notag' if not r.get('cat') else ('ok' if any(x in lab for c in cats for x in EXPECT.get(c, [c])) else 'differs')
        if r['sagree'] != 'ok' and cats[0] in EXCEL_TAG:
            # show the Excel model's category (what the statements use); keep the file's own tag as a tooltip
            r['file'] = ' / '.join(x for x in (r.get('cat'), r.get('subcat'), r.get('subsub'), r.get('inout')) if x) or '-'
            sub, ss, io = EXCEL_TAG[cats[0]]
            r.update({'cat': 'CF', 'subcat': sub, 'subsub': ss, 'inout': io, 'status': 'Excel model', 'confidence': None, 'excel': True})
    # every account of the Oris chart (incl. group/parent accounts) must be searchable here, tagged or not
    for code, v in _acc.items():
        if code not in _idx:
            r = {'code': code, 'name': v[0], 'cat': '', 'subcat': '', 'subsub': '', 'inout': '', 'status': '', 'confidence': None}
            _rows.append(r); _idx[code] = r
    _rows.sort(key=lambda r: tuple(t.zfill(12) if t.isdigit() else t for t in r['code'].split(' ')))
    _m['stmtUsed'] = len(_cm); _m['stmtDiffers'] = sum(1 for r in _rows if r.get('sagree') == 'differs'); _m['stmtAdded'] = _added
    mapping = json.dumps(_m, ensure_ascii=False, separators=(',', ':'))
out = (tpl.replace('__DATA__', data.replace('</script', '<\\/script'))
          .replace('__BUDGET__', budget.replace('</script', '<\\/script'))
          .replace('__MAPPING__', mapping.replace('</script', '<\\/script')))
targets = [os.path.join(REPO, 'Volta_Finance.html'), os.path.join(BASE, 'Volta_Finance.html')]
if len(sys.argv) > 1: targets.append(sys.argv[1])
for p in targets:
    open(p, 'w', encoding='utf-8').write(out)
shutil.copy(os.path.join(BASE, 'source.json'), os.path.join(REPO, 'source.json'))
print(targets[0], round(os.path.getsize(targets[0])/1e6, 2), 'MB')
