# Volta Funnel Dashboard (PHP)

A live, server-rendered version of the Volta Funnel Dashboard. Every page load runs fresh
queries against `myvolta8_voltadb` (no caching, no static snapshot) — the same funnel
report and dashboard you've been using, but always current.

## Requirements

- PHP **8.3** with the `pdo_mysql` extension enabled
- Network access from wherever this runs to `myvolta.info:3306` (this is exactly why a
  version of this needs to run outside a sandbox with no route to that host — see below)

No Composer, no dependencies, no build step. Point a web server at `public/`, or run it
standalone with PHP's built-in server for local testing.

## Setup

1. Copy `config.example.php` to `config.php` and fill in the real database credentials
   (already in `config.php` in this delivered copy — **treat that file as a secret**, it
   is `.gitignore`d and must never be committed or exposed publicly).
2. Point your web server's document root at the `public/` folder, **not** the project
   root — `config.php` and `src/` must not be web-accessible.
   - Apache/Nginx: set `DocumentRoot`/`root` to the absolute path of `public/`.
   - Quick local test: `php -S localhost:8000 -t public`
3. Open the site. That's it — no login, no build step.

## Why this exists

The original dashboard (a Claude Artifact) is refreshed manually, because the cloud
sandbox that runs Claude Code has no outbound network route to `myvolta.info:3306` — only
this kind of app, running somewhere with real access to that host (your own server, or a
machine on the same network), can pull genuinely live data on every page view.

## Project layout

```
config.example.php        Template DB config — copy to config.php
config.php                 Real credentials (git-ignored, delivered filled in)
.gitignore
src/
  Segment.php               Enum: Segment A (high-downpayment) / B (standard)
  SegmentMetrics.php        Value object for one segment's six funnel figures
  Database.php              PDO connection factory
  DateHelper.php            Yesterday / MTD date ranges, live "remaining working days"
  FunnelRepository.php      All SQL — the only place queries live
public/
  index.php                 Entry point: computes date ranges, runs the queries, renders
  templates/
    dashboard.php            The page itself (CSS + HTML + client-side JS), fed by PHP
```

## What each metric means and where it comes from

All queries run against `instalments` (optionally joined to `products` for the segment
classification), filtered to `Product_ID > 1` to exclude unassigned "lead" placeholder
rows (a client who hasn't picked a product yet).

**Segment A** = `products.Model LIKE 'ტელეფონი%'` (phone) OR `LIKE 'ტელევიზორ%'` (TV) OR
`instalments.Full_Cost > 2500` (any single product over 2,500 GEL). **Segment B** =
everything else. See `Segment::sqlCondition()`.

| Metric | Date field | Definition |
|---|---|---|
| Applications | `Aplication_Date` | Count of applications with a real product selected |
| Terms Approved | `Aplication_Date` | `UnderWriter_Status_ID != 0` — case has entered underwriting review (the report's own definition of "customer approved terms") |
| Underwriting Approved | `Aplication_Date` | `UnderWriter_Status_ID = 16` (Approved) |
| **Deals Closed** | **`Order_Date`** | `Active = 1`, keyed to the *disbursement* date (changed from `Aplication_Date` on 2026-08-25 — see below) |
| **Amount Sold** | **`Order_Date`** | `SUM(Full_Cost)` — i.e. `Initial_Amount + First_Payment`, the full sale price of the product, not just the financed principal — for `Active = 1` rows, keyed to the *disbursement* date, not application date, so a loan applied for on one day but issued the next counts toward the day it was issued. Downpayment Collected is reported separately but is already included inside this figure — don't add the two together. |
| Downpayment Collected | `Aplication_Date` | `SUM(First_Payment)` for **every** row, regardless of status — once collected it isn't refunded, so it counts even on a rejected or still-pending deal |

Two dates matter for every query: **Yesterday** (the calendar day before "now") and
**MTD** (the 1st of the current month through yesterday — today is excluded because it
isn't finished yet). Both are computed fresh from the server clock on every request in
`DateHelper`.

**Remaining Working Days** (used for the Required Daily Sales calculation) is every
calendar day from today through month-end, inclusive of today — confirmed with the
business as not excluding weekends. It's genuinely live: reload the page tomorrow and
it's one lower, with Required Daily Sales recalculated accordingly.

**Budget targets** (2,500 applications / 1,900,000 GEL per month) are a business goal,
not derived from the database — edit `config.php` when they change.

**Deals Closed, changed 2026-08-25**: originally keyed to `Aplication_Date` like the other
funnel-stage metrics. Changed to `Order_Date` (same date field and `Active = 1` filter as
Amount Sold, now computed in the same query) after a business decision — a report explaining
the original per-metric logic was sent out, and the reply favored counting by disbursement
date instead: "these timing differences should naturally balance out" over time, and it
"align[s] the number of closed deals with the daily sales amount (GEL), which is already
reported based on the date of sale." Concretely this means a deal applied for on one day and
disbursed the next now counts toward the disbursement day for *both* Deals Closed and Amount
Sold, instead of Deals Closed counting it a day earlier than Amount Sold did.

## Pages

- **Report** — the original spreadsheet replica (Yesterday + MTD side by side).
- **MTD Statistics** and **Daily Statistics** — both reuse the *exact* Section A/B/C
  report layout from the Report tab (same row labels, same colored bands, collapsible
  section headers), but pivoted: instead of two fixed columns (Yesterday | MTD), each
  column pair is one period — one calendar **month** (Jan through the current month) for
  MTD Statistics, one calendar **day** (from June 1 through yesterday) for Daily
  Statistics. A completed month/day shows its final total; the current, still-in-progress
  period shows figures to-date. This format was specified directly by the business (an
  example workbook with the same pivot idea, built by hand with a few placeholder
  months), replacing an earlier flat-comparison-table design.
  - The metric column stays pinned (`position: sticky`) while scrolling horizontally.
  - A duplicate scrollbar strip (`.report-scroll-top`) sits above the table header and
    stays in sync with the real one at the bottom (`setupTopScrollSync()`), since with
    many columns the bottom scrollbar can be far below the fold.
  - Daily Statistics has an extra header row grouping days by month, each clickable to
    hide/show every day in that month at once (`rptPivotGroupRow()` + a `pc-<index>`
    class on every cell in a given column, toggled via `.col-collapsed { display:none }`).
    MTD Statistics skips this row since each column there already IS a month.
- **Logistics Daily** — order fulfillment data (PO/pickup/warehouse/delivery status),
  sourced from a separate Google Sheet ("Volta Order Managment", not `myvolta8_voltadb`).
  Two tables: a **Not Delivered Orders** snapshot (age buckets &le;1 / 1&ndash;5 / &gt;5
  days + On Hold, as of the date shown) and a **Delivered Orders & Average Delivery
  Time** trend (last 21 days). The snapshot numbers are only valid for the specific date
  they were pulled — this server has no Google Sheets credentials, so `logisticsSnapshot`
  in `dashboard.php` is a **hardcoded static JS literal**, not a live query; it needs
  manual refresh the same way the whole dashboard did before the live-DB PHP version
  existed. The Delivered/Avg-Delivery-Time trend, by contrast, is reconstructed from each
  order's real Delivery/Pickup Date, so those two rows are trustworthy for any past day
  even though the snapshot isn't. Real automation (refresh at 20:00 daily, values
  persisted since "not delivered as of a past day" can't be recomputed later once orders
  ship) needs two things this project doesn't have yet: a Google Sheets API service
  account for the PHP server to read the sheet unattended, and a cron entry on the host
  server — both deliberately deferred pending the user's decision on how to set them up.

## Cron setup — Loan Applications Pending snapshot

The "Loan Applications — Pending" table on the Logistics Daily tab (count of
`instalments.Order_Status = 4`) is a point-in-time snapshot, not something the app can
compute retroactively for a past day — it needs to be captured live, once a day, by a
scheduled job on whatever server actually runs this app:

```
0 20 * * * /usr/bin/php /full/path/to/volta-funnel-php/bin/capture_pending_status.php >> /full/path/to/volta-funnel-php/data/capture.log 2>&1
```

Add that line via `crontab -e` on the server. Two things to double-check before relying on it:
- **Timezone**: cron uses the server's own system clock. Run `date` on the server first —
  if it's not already Asia/Tbilisi, adjust the `20` in the cron line to whatever hour
  corresponds to 20:00 there (or prefix the line with `TZ=Asia/Tbilisi`, if your cron
  supports per-line `TZ=`).
- **Write permission**: the script writes to `data/pending_status_log.json` — make sure
  the user cron runs as (often `www-data` or similar) can write to the `data/` directory.

You can test it manually any time with `php bin/capture_pending_status.php` — it prints
the captured count and is safe to re-run (it overwrites *today's* entry rather than
creating a duplicate).

## If you want to extend it

- All SQL lives in `FunnelRepository.php` — `segmentMetrics()` (one method, two queries,
  called four times: 2 segments × 2 periods) for the Report tab, and
  `monthlySegmentStats()` / `dailySegmentStats()` (both wrap a shared private
  `periodSegmentStats()` — two queries total per call, using `CASE WHEN` to compute both
  segments in one pass, grouped by `DATE_FORMAT(..., '%Y-%m')` or `'%Y-%m-%d'`) for the
  MTD/Daily Statistics tabs.
- The HTML/CSS/client-side JS in `templates/dashboard.php` is otherwise unchanged from
  the Claude Artifact version — if you update one, consider updating the other so they
  don't drift apart.
- No caching layer exists on purpose (each of the ~10 underlying queries is cheap and
  this is an internal low-traffic tool) — add one only if load ever becomes a real
  concern.
