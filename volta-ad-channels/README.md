# Ad Channels — Meta / Google Ads / GA4

External marketing-channel performance for Volta: Meta Marketing API, Google Ads API, GA4 Data API. Separate
project, not part of `../volta-analytics-new-db/` — currently shipped as its own standalone artifact, linked
into the main dashboard's Marketing nav group only once the user asks for that.

Credentials come from the marketing team's handoff and live OUTSIDE this repo, at
`D:\all\volta\Marketing\Meta_Google Ads\` — **never copy them into the repo**:
- `meta_system_user_token.txt` — Meta System User access token, ad account `act_1466725951457412`.
- `volta-508613-05bed07fa6d8.json` — Google service-account key, used for BOTH Google Ads (customer
  `4580124546`) and GA4 (property `369140604`).

**Google Ads no longer needs a separate developer token.** Google sunset the classic developer-token system on
2026-09-09 — access is now granted to the Google Cloud project itself (`volta-508613`, currently at the
"Explorer" access level: 2,880 ops/day on production accounts, plenty for a daily pull). `pull_channels.py`
sends the `developer-token` request header empty; it's accepted and ignored server-side.

TikTok Ads was requested by the user but no credentials have been provided yet.

## What's in each report

- **Meta Ads** — Spend/Impressions/Clicks (+derived CTR/CPC) + a Top campaigns (last 30 days) mini table.
- **Google Ads** — Cost/Impressions/Clicks/Conversions (+derived CTR/CPC/Cost per Conversion) + Top campaigns.
- **Website Traffic (GA4)** — Sessions/Users/Conversions (+derived conversion rate); all traffic, not only paid.

Day tables: last 30 days through yesterday. Month tables: Jan 2026 → current month (MTD) — Meta/Google Ads show
0 for months before their first real campaign spend (Feb / May 2026 respectively); GA4 has full-year data. CTR/
CPC/conversion-rate rows are derived from summed numerator/denominator per column, never averaged daily ratios;
their "Last 7 days"/"Total" summary column is intentionally blank (a ratio doesn't sum).

## Files

| File | What |
|---|---|
| `pull_channels.py` | Pulls all three APIs, writes `channels_*.tsv` + `channels_info.json` (gitignored) |
| `build_ad_channels.js` | Builds the standalone tabbed page `ad_channels_standalone.html` (published as its own Artifact; also copied to `../docs/ad-channels.html` for GitHub Pages) |
| `build_channels.js` | Alternative build: injects the same report as a sub-tab into `../volta-analytics-new-db/deals_amount_migration.html` instead (nav button + page + `CHANNELS_JSON`, same idempotent-injection pattern as that project's `build_logistics.js`). Not currently used — kept in case the user prefers an inline tab over a linked-out page later. |

## Refresh

```
python pull_channels.py
node build_ad_channels.js      # standalone page -> ad_channels_standalone.html
```

Then publish `ad_channels_standalone.html` as the Artifact, and/or copy it to `../docs/ad-channels.html` and
commit+push for the public GitHub Pages copy (`https://volta-ge.github.io/reporting/ad-channels.html`).

If the inline-tab route is chosen instead: `node build_channels.js` (writes into
`../volta-analytics-new-db/deals_amount_migration.html`; that project's own refresh/publish rules apply from
there on).
