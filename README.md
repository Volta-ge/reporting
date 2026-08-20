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
| Deals Closed | `Aplication_Date` | `Active = 1` — instalment currently active/disbursed |
| **Amount Sold** | **`Order_Date`** | `SUM(Initial_Amount)` for `Active = 1` rows — keyed to the *disbursement* date, not application date, so a loan applied for on one day but issued the next counts toward the day it was issued |
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

## If you want to extend it

- All SQL lives in `FunnelRepository::segmentMetrics()` — one method, two queries (one
  keyed to Aplication_Date, one to Order_Date), called four times (2 segments × 2
  periods) by `public/index.php`.
- The HTML/CSS/client-side JS in `templates/dashboard.php` is otherwise unchanged from
  the Claude Artifact version — if you update one, consider updating the other so they
  don't drift apart.
- No caching layer exists on purpose (each of the ~8 underlying queries is cheap and this
  is an internal low-traffic tool) — add one only if load ever becomes a real concern.
