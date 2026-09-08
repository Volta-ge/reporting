"""Aggregate oris.sqlite into compact JSON (dash_data.json) for the Volta_Finance dashboard."""
import sqlite3, json, collections, sys, os, bisect
BASE = os.environ.get('VOLTA_FIN_DATA', 'D:/all/volta/Volta_Accounting')
DB = os.path.join(BASE, 'oris.sqlite')
OUT = os.path.join(BASE, 'dash_data.json')
con = sqlite3.connect(DB)
PL = ('6','7','8','9')
def depth(code):
    t = code.split(' ')
    d = 3 if t[0]=='1' and len(t)>1 and t[1]=='6' else 4
    return ' '.join(t[:d])
months = [r[0] for r in con.execute("SELECT DISTINCT substr(DATE,1,7) FROM WIRING ORDER BY 1")]
mi = {m:i for i,m in enumerate(months)}
agg = collections.defaultdict(lambda: collections.defaultdict(lambda: [0.0,0.0]))
cf = collections.defaultdict(lambda: [0.0,0.0])   # (cash4, counter3, m) -> [in,out]
closing = collections.defaultdict(float)          # m -> net closed (for audit)
stats = collections.Counter()
rates = collections.defaultdict(list)
for cur_, dt, qty, c in con.execute("SELECT MON_TYPE, DATE, QTY, CURS FROM Rate ORDER BY DATE"):
    rates[cur_].append((dt, c/(qty or 1)))
rate_dates = {k: [x[0] for x in v] for k,v in rates.items()}
def rate(cur_, dt):
    i = bisect.bisect_right(rate_dates[cur_], dt)
    return rates[cur_][i-1][1] if i else None
fx_gel = collections.defaultdict(float)
cur = con.execute("SELECT DATE, DEBET, KREDIT, MONEY, MON_TYPE FROM WIRING")
for date, d, k, money, mon in cur:
    stats['total'] += 1
    if mon != 'GEL':
        r = rate(mon, date)
        if r is None: stats['skip_fx_norate'] += 1; continue
        stats['fx_converted'] += 1; fx_gel[mon] += money*r
        money = round(money*r, 2)
    if d.startswith('B') or k.startswith('B'): stats['skip_offbal'] += 1; continue
    m = mi[date[:7]]
    if (d == '5 3 30' and k[0] in PL) or (k == '5 3 30' and d[0] in PL):
        stats['skip_closing'] += 1; closing[m] += money if d == '5 3 30' else -money; continue
    stats['kept'] += 1
    agg[depth(d)][m][0] += money
    agg[depth(k)][m][1] += money
    dc = d.startswith('1 1 ') or d.startswith('1 2 ')
    kc = k.startswith('1 1 ') or k.startswith('1 2 ')
    if dc: cf[(depth(d), ' '.join(k.split(' ')[:3]), m)][0] += money
    if kc: cf[(depth(k), ' '.join(d.split(' ')[:3]), m)][1] += money
# account names for every code in agg/cf + ancestors
names = {r[0]: (r[1], r[2], r[3]) for r in con.execute("SELECT COUNT, NAME, TYPEF, LEVEL FROM ACC_NAME")}
need = set(agg) | {c for c,_,_ in cf} | {x for _,x,_ in cf}
for c in list(need):
    t = c.split(' ')
    for i in range(1, len(t)): need.add(' '.join(t[:i]))
acc = {}
for c in sorted(need):
    n = names.get(c)
    acc[c] = [n[0].strip() if n else '(უცნობი ანგარიში)', n[1] if n else 0]
aggo = {c: [x for m in sorted(v) for x in (m, round(v[m][0],2), round(v[m][1],2))] for c,v in agg.items()}
cfo = [[c,x,m,round(v[0],2),round(v[1],2)] for (c,x,m),v in sorted(cf.items())]
_srcp = os.path.join(BASE, 'source.json')
_src = json.load(open(_srcp, encoding='utf-8')) if os.path.exists(_srcp) else {'source':'unknown','asof':months[-1]+'-01'}
meta = {'company':'შპს ვოლტა','tin':'405232715','source':_src['source'],'asof':_src['asof'],
        'first':con.execute("SELECT MIN(DATE) FROM WIRING").fetchone()[0],'last':con.execute("SELECT MAX(DATE) FROM WIRING").fetchone()[0],
        'stats':dict(stats),'fx_gel':{k:round(v,2) for k,v in fx_gel.items()},'closing':{months[m]:round(v,2) for m,v in sorted(closing.items())}}
json.dump({'meta':meta,'months':months,'acc':acc,'agg':aggo,'cf':cfo}, open(OUT,'w',encoding='utf-8'), ensure_ascii=False, separators=(',',':'))
print(meta['stats'], 'codes', len(acc), 'agg codes', len(aggo), 'cf rows', len(cfo), 'size MB', round(os.path.getsize(OUT)/1e6,2))
