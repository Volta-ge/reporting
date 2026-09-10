"""Oris (Clarion/TPS) reader helpers: Georgian byte encoding 0xC0..0xE4 -> U+10D0..U+10F4"""
import warnings, datetime; warnings.filterwarnings('ignore')
from tpsread import TPS

GEO = "აბგდევზჱთიკლმნჲოპჟრსტჳუფქღყშჩცძწჭხჴჯჰ"  # byte 0xC0.. in old alphabetical order (archaic letters included)
_MAP = {0xC0 + i: ch for i, ch in enumerate(GEO)}
def geo(s):
    if not isinstance(s, str): return s
    return ''.join(_MAP.get(ord(ch), ch) for ch in s)

DATE_FIELDS = ['date','real_date','daterel','operdate','usedate','makedate','comedate','userdate','valuedate','date1','date2','invoicedate','proxydate']

def rows(fn, limit=None, keep_empty=False):
    t = TPS(fn, encoding='latin-1', cached=True, check=False, current_tablename='UNNAMED', date_fieldname=DATE_FIELDS)
    for i, rec in enumerate(t):
        if limit is not None and i >= limit: break
        d = {}
        for k, v in rec.items():
            key = k.split(':')[-1].rstrip("'")
            if isinstance(v, str): v = geo(v)
            if keep_empty or v not in ('', 0, None): d[key] = v
        yield d
