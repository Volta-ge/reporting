# -*- coding: utf-8 -*-
"""
One-off / resumable backfill: geocodes every currently-ambiguous unique
delivery address (Tbilisi-but-unknown-district, or no recognized city at
all) via OpenStreetMap Nominatim and saves results into geo_cache.json.

Reads addresses straight out of the last-generated waybill_dashboard.html
(no live RS.ge/DB refetch needed) so it can run independently of the normal
pipeline. Safe to interrupt and rerun — already-cached addresses are
skipped instantly, so this always resumes rather than restarting. Useful to
run manually after adding new keywords to TBILISI_DISTRICT_KEYWORDS/
REGIONAL_CITY_KEYWORDS (shrinks the ambiguous set) or if geo_cache.json is
ever deleted/corrupted and needs rebuilding from scratch — the normal daily
refresh_dashboard.py run only geocodes up to GEOCODE_MAX_NEW_PER_RUN new
addresses per day, so a large one-time backfill is faster run here directly.
"""
import json
import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from refresh_dashboard import classify_address, load_geocode_cache, resolve_ambiguous_addresses

HERE = Path(__file__).resolve().parent
html = (HERE / "waybill_dashboard.html").read_text(encoding="utf-8")
addrs = set(re.findall(r'"e":"((?:[^"\\]|\\.)*)"', html))

ambiguous = {}
for addr in addrs:
    kind, canon = classify_address(addr)
    if (kind == "tbilisi" and canon == "სხვა რაიონი") or kind == "other":
        ambiguous[addr] = (kind == "tbilisi")

cache = load_geocode_cache()
already = sum(1 for a in ambiguous if a in cache)
print(f"{len(ambiguous)} ambiguous unique addresses, {already} already cached, "
      f"{len(ambiguous) - already} to geocode", file=sys.stderr)

import refresh_dashboard
refresh_dashboard.GEOCODE_MAX_NEW_PER_RUN = 10**9  # no cap for this one-off backfill
resolved = resolve_ambiguous_addresses(list(ambiguous.items()), cache)
print(f"resolved {len(resolved)} / {len(ambiguous)} to a specific city/district", file=sys.stderr)
