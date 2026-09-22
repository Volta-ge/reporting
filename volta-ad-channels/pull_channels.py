#!/usr/bin/env python3
# Pulls daily + last-30-days-campaign data from the three external marketing-channel APIs (Meta Marketing API,
# Google Ads API, GA4 Data API) for the Marketing > Ad Channels tab. Credentials live OUTSIDE this repo
# (D:\all\volta\Marketing\Meta_Google Ads\, handed off by the marketing team) — never copy them into the repo.
# Writes plain TSVs (same convention as pull_new.sh) that build_channels.js reads. Idempotent: safe to re-run.
import os
import sys
import json
import datetime
import requests
from google.oauth2 import service_account
import google.auth.transport.requests as ga_requests
from google.ads.googleads.client import GoogleAdsClient
from google.analytics.data_v1beta import BetaAnalyticsDataClient
from google.analytics.data_v1beta.types import RunReportRequest, DateRange, Metric, Dimension

CREDS_DIR = r"D:\all\volta\Marketing\Meta_Google Ads"
META_TOKEN = open(os.path.join(CREDS_DIR, "meta_system_user_token.txt"), encoding="utf-8").read().strip()
META_ACCOUNT = "act_1466725951457412"
GADS_CUSTOMER_ID = "4580124546"
GA4_PROPERTY_ID = "369140604"
SA_JSON = os.path.join(CREDS_DIR, "volta-508613-05bed07fa6d8.json")

START = "2026-01-01"
END = sys.argv[1] if len(sys.argv) > 1 else (datetime.date.today() - datetime.timedelta(days=1)).isoformat()

OUT = os.path.dirname(os.path.abspath(__file__))


def write_tsv(name, header, rows):
    with open(os.path.join(OUT, name), "w", encoding="utf-8", newline="") as f:
        f.write("\t".join(header) + "\n")
        for r in rows:
            f.write("\t".join("" if v is None else str(v) for v in r) + "\n")
    print(name, len(rows), "rows")


# ---------------- Meta Marketing API ----------------
def pull_meta():
    time_range = json.dumps({"since": START, "until": END})
    url = f"https://graph.facebook.com/v21.0/{META_ACCOUNT}/insights"
    params = {
        "fields": "spend,impressions,clicks,reach,outbound_clicks",
        "time_range": time_range,
        "time_increment": 1,
        "limit": 100,
        "access_token": META_TOKEN,
    }
    data = []
    while url:
        r = requests.get(url, params=params)
        r.raise_for_status()
        body = r.json()
        data.extend(body.get("data", []))
        url = (body.get("paging", {}) or {}).get("next")
        params = None  # cursor URL already carries the query string

    def outbound(d):
        # "clicks" is Meta's "Clicks (all)" -- every tap on the ad unit (likes, comments, shares, photo
        # expand, page-name tap...), NOT just clicks that leave Facebook/Instagram for the site. Verified
        # live 2026-09-22: Sep MTD clicks=71,000 vs outbound_clicks=40,304 (a 76% overcount) -- the user
        # caught this from Ad Channels' numbers looking too high next to GA4 Sessions. outbound_clicks is
        # the metric that actually corresponds to a site visit.
        for a in d.get("outbound_clicks") or []:
            if a.get("action_type") == "outbound_click":
                return a.get("value", 0)
        return 0

    rows = sorted(
        [(d["date_start"], d.get("spend", 0), d.get("impressions", 0), d.get("clicks", 0), outbound(d)) for d in data],
        key=lambda x: x[0],
    )
    write_tsv("channels_meta_daily.tsv", ["d", "spend", "impressions", "clicks", "outbound_clicks"], rows)

    acct = requests.get(
        f"https://graph.facebook.com/v21.0/{META_ACCOUNT}",
        params={"fields": "name,currency", "access_token": META_TOKEN},
    ).json()

    camp = []
    curl, cparams = f"https://graph.facebook.com/v21.0/{META_ACCOUNT}/campaigns", {
        "fields": "name,status,insights.date_preset(last_30d){spend,impressions,clicks}",
        "limit": 200,
        "access_token": META_TOKEN,
    }
    while curl:
        body = requests.get(curl, params=cparams).json()
        camp.extend(body.get("data", []))
        curl = (body.get("paging", {}) or {}).get("next")
        cparams = None
    crows = []
    for c in camp:
        ins = (c.get("insights", {}) or {}).get("data", [])
        ins = ins[0] if ins else {}
        spend = float(ins.get("spend", 0) or 0)
        if spend <= 0:
            continue
        crows.append((c["name"], c.get("status", ""), spend, ins.get("impressions", 0), ins.get("clicks", 0)))
    crows.sort(key=lambda x: -x[2])
    write_tsv("channels_meta_campaigns.tsv", ["name", "status", "spend", "impressions", "clicks"], crows[:15])
    return {"currency": acct.get("currency", "USD"), "account": acct.get("name", "")}


# ---------------- Google Ads API ----------------
def pull_gads():
    creds = service_account.Credentials.from_service_account_file(
        SA_JSON, scopes=["https://www.googleapis.com/auth/adwords"]
    )
    client = GoogleAdsClient(credentials=creds, developer_token="", use_proto_plus=True)
    svc = client.get_service("GoogleAdsService")

    info = list(svc.search(customer_id=GADS_CUSTOMER_ID, query="SELECT customer.currency_code, customer.descriptive_name FROM customer LIMIT 1"))
    currency = info[0].customer.currency_code if info else "GEL"
    name = info[0].customer.descriptive_name if info else ""

    daily = list(svc.search(
        customer_id=GADS_CUSTOMER_ID,
        query=f"""
            SELECT segments.date, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.interactions, metrics.conversions
            FROM customer
            WHERE segments.date BETWEEN '{START}' AND '{END}'
            ORDER BY segments.date
        """,
    ))
    # metrics.interactions is Google Ads' broader "main user action" count (clicks + other engagement,
    # e.g. shopping-ad swipes) -- the Meta-style "all interactions" counterpart to metrics.clicks. Verified
    # live 2026-09-22: this month clicks=4,356 vs interactions=4,469 (small but real, mostly ENGAGEMENT
    # events on top of CLICK events).
    rows = [(r.segments.date, r.metrics.cost_micros / 1e6, r.metrics.impressions, r.metrics.clicks, r.metrics.interactions, r.metrics.conversions) for r in daily]
    write_tsv("channels_gads_daily.tsv", ["d", "cost", "impressions", "clicks", "interactions", "conversions"], rows)

    camps = list(svc.search(
        customer_id=GADS_CUSTOMER_ID,
        query="""
            SELECT campaign.name, campaign.status, metrics.cost_micros, metrics.impressions, metrics.clicks
            FROM campaign
            WHERE segments.date DURING LAST_30_DAYS
            ORDER BY metrics.cost_micros DESC
            LIMIT 15
        """,
    ))
    crows = [(c.campaign.name, c.campaign.status.name, c.metrics.cost_micros / 1e6, c.metrics.impressions, c.metrics.clicks) for c in camps if c.metrics.cost_micros > 0]
    write_tsv("channels_gads_campaigns.tsv", ["name", "status", "cost", "impressions", "clicks"], crows)
    return {"currency": currency, "account": name}


# ---------------- GA4 Data API ----------------
def pull_ga4():
    creds = service_account.Credentials.from_service_account_file(
        SA_JSON, scopes=["https://www.googleapis.com/auth/analytics.readonly"]
    )
    client = BetaAnalyticsDataClient(credentials=creds)
    req = RunReportRequest(
        property=f"properties/{GA4_PROPERTY_ID}",
        dimensions=[Dimension(name="date")],
        # engagedSessions is GA4's "qualified visit" counterpart to raw Sessions (a session lasting 10s+,
        # having 2+ pageviews, or a conversion event) -- the GA4-side analogue of Meta's outbound_clicks /
        # Google Ads' clicks. Verified live 2026-09-22: Sep 1-21 sessions=66,527 vs engagedSessions=38,224.
        metrics=[Metric(name="sessions"), Metric(name="engagedSessions"), Metric(name="activeUsers"), Metric(name="conversions")],
        date_ranges=[DateRange(start_date=START, end_date=END)],
    )
    resp = client.run_report(req)
    rows = []
    for row in resp.rows:
        d = row.dimension_values[0].value  # YYYYMMDD
        d = f"{d[0:4]}-{d[4:6]}-{d[6:8]}"
        vals = [v.value for v in row.metric_values]
        rows.append((d, vals[0], vals[1], vals[2], vals[3]))
    rows.sort(key=lambda x: x[0])
    write_tsv("channels_ga4_daily.tsv", ["d", "sessions", "engaged_sessions", "users", "conversions"], rows)


if __name__ == "__main__":
    meta_info = pull_meta()
    gads_info = pull_gads()
    pull_ga4()
    with open(os.path.join(OUT, "channels_info.json"), "w", encoding="utf-8") as f:
        json.dump({"meta": meta_info, "gads": gads_info, "start": START, "end": END}, f)
    print("channels_info.json written:", meta_info, gads_info)
