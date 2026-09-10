"""Export all readable Oris TPS tables into one SQLite DB. Usage: python export_sqlite.py <VOLTA dir> <out.sqlite>"""
import sys, os, glob, sqlite3, datetime, time, warnings
warnings.filterwarnings('ignore')
sys.path.insert(0, os.path.dirname(__file__))
from oris import rows
src, out = sys.argv[1], sys.argv[2]
if os.path.exists(out): os.remove(out)
con = sqlite3.connect(out)
skip = {'DBEval','DBFilter','DBHeader','DBMCheck','DBTemp','Db','Tree_opn','KDR_SAL','KDR_TAX','Sumkdrtx','Salpar'}
for fn in sorted(glob.glob(os.path.join(src, '*.[Tt][Pp][Ss]'))):
    name = os.path.splitext(os.path.basename(fn))[0]
    if name in skip: continue
    t0 = time.time(); cols = None; buf = []; n = 0
    try:
        it = rows(fn, keep_empty=True)
        for d in it:
            if cols is None:
                cols = list(d.keys())
                con.execute(f'CREATE TABLE "{name}" ({", ".join(chr(34)+c+chr(34) for c in cols)})')
            buf.append([v.isoformat() if isinstance(v, (datetime.date, datetime.datetime)) else v for v in d.values()])
            n += 1
            if len(buf) >= 5000:
                con.executemany(f'INSERT INTO "{name}" VALUES ({",".join("?"*len(cols))})', buf); buf = []
        if buf: con.executemany(f'INSERT INTO "{name}" VALUES ({",".join("?"*len(cols))})', buf)
        con.commit()
        print(f"{name}: {n} rows, {time.time()-t0:.0f}s", flush=True)
    except Exception as e:
        print(f"{name}: FAILED {e!r}", flush=True)
for tbl, col in [('WIRING','DATE'),('WIRING','DEBET'),('WIRING','KREDIT'),('WIRING','DOC'),('ACC_NAME','COUNT'),('ACC_YEAR','COUNT'),('TRE_WIR','DATE')]:
    try: con.execute(f'CREATE INDEX "ix_{tbl}_{col}" ON "{tbl}"("{col}")')
    except Exception as e: print("index", tbl, col, e)
con.commit(); con.close(); print("DONE")
