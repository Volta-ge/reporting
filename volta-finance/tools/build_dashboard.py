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
out = (tpl.replace('__DATA__', data.replace('</script', '<\\/script'))
          .replace('__BUDGET__', budget.replace('</script', '<\\/script')))
targets = [os.path.join(REPO, 'Volta_Finance.html'), os.path.join(BASE, 'Volta_Finance.html')]
if len(sys.argv) > 1: targets.append(sys.argv[1])
for p in targets:
    open(p, 'w', encoding='utf-8').write(out)
shutil.copy(os.path.join(BASE, 'source.json'), os.path.join(REPO, 'source.json'))
print(targets[0], round(os.path.getsize(targets[0])/1e6, 2), 'MB')
