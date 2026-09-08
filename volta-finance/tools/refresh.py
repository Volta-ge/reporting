r"""One-command refresh of the Volta_Finance dashboard from a new Oris copy.
Usage:  python refresh.py [path\to\VOLTA_ASLI_....RAR] [extra output path for the HTML]
Without an argument the newest VOLTA_ASLI_*.RAR in the data folder (env VOLTA_FIN_DATA,
default D:\all\volta\Volta_Accounting) is used.
Steps: unrar -> export_sqlite -> build_data -> build_dashboard. Then commit+push and republish the Artifact."""
import sys, os, glob, re, json, subprocess, shutil, time
BASE = os.environ.get('VOLTA_FIN_DATA', 'D:/all/volta/Volta_Accounting')  # data folder: RAR copies, extract/, oris.sqlite, dash_data.json
TOOLS = os.path.dirname(os.path.abspath(__file__))
UNRAR = 'C:/Program Files/WinRAR/UnRAR.exe'
PY = sys.executable
def log(*a): print(time.strftime('%H:%M:%S'), *a, flush=True)
rars = [sys.argv[1]] if len(sys.argv) > 1 else sorted(glob.glob(os.path.join(BASE, 'VOLTA_ASLI_*.RAR')) + glob.glob(os.path.join(BASE, 'VOLTA_ASLI_*.rar')), key=os.path.getmtime)
if not rars: sys.exit('No VOLTA_ASLI_*.RAR found in ' + BASE)
rar = rars[-1]
m = re.search(r'(\d{4}-\d{2}-\d{2})', os.path.basename(rar))
asof = m.group(1) if m else time.strftime('%Y-%m-%d', time.localtime(os.path.getmtime(rar)))
log('source:', rar, 'as of', asof)
ext = os.path.join(BASE, 'extract')
if os.path.isdir(ext): shutil.rmtree(ext)
os.makedirs(ext)
r = subprocess.run([UNRAR, 'x', '-y', '-inul', rar, ext + os.sep]); assert r.returncode == 0, 'unrar failed'
volta = os.path.join(ext, 'VOLTA')
if not os.path.isdir(volta):
    tps = glob.glob(os.path.join(ext, '**', 'WIRING.TPS'), recursive=True); assert tps, 'WIRING.TPS not found in archive'
    volta = os.path.dirname(tps[0])
log('extracted to', volta, '-', len(os.listdir(volta)), 'files')
json.dump({'source': os.path.basename(rar), 'asof': asof}, open(os.path.join(BASE, 'source.json'), 'w', encoding='utf-8'), ensure_ascii=False)
env = dict(os.environ, PYTHONIOENCODING='utf-8')
log('exporting TPS -> SQLite (~5 min)')
subprocess.run([PY, os.path.join(TOOLS, 'export_sqlite.py'), volta, os.path.join(BASE, 'oris.sqlite')], check=True, env=env)
log('aggregating'); subprocess.run([PY, os.path.join(TOOLS, 'build_data.py')], check=True, env=env)
log('fetching RS.ge waybills'); rc = subprocess.run([PY, os.path.join(TOOLS, 'fetch_rs_waybills.py')], env=env).returncode
if rc: log('RS.ge fetch failed (rc', rc, ') - using the previous rs_waybills.json if present')
if os.path.exists(os.path.join(BASE, 'rs_waybills.json')):
    log('joining waybills RS <-> Oris'); subprocess.run([PY, os.path.join(TOOLS, 'build_waybills.py')], check=True, env=env)
log('building HTML'); subprocess.run([PY, os.path.join(TOOLS, 'build_dashboard.py')] + sys.argv[2:3], check=True, env=env)
log('DONE -> Volta_Finance.html in', os.path.dirname(TOOLS), '| now commit+push and republish the Artifact from this file')
