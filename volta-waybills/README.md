# Volta Waybills / Volta_Analytics pipeline

Python pipeline that:

1. Fetches Volta Group's seller-side **waybills** and **invoices** from RS.ge (WayBillService +
   ntosservice SOAP APIs), and cross-matches them against **CRM sales** from Volta's MySQL DB to
   find sales missing a waybill (or waybills missing a CRM sale) — `refresh_dashboard.py`.
2. Renders the "Volta Funnel Dashboard" PHP app (see the sibling `php-dashboard` project in this
   same repo) live from the DB, merges in the output of step 1 as an "Accounting" nav-group, adds
   a simple email-based navigation filter, and produces one combined dashboard —
   `refresh_volta_analytics.py`.

Both scripts publish their output as Claude Artifacts (not part of this repo — done manually via
Claude Code, see the `volta_waybill_dashboard` / `volta_analytics_merge` / `volta_analytics_auth`
Claude memory files for the exact artifact URLs and publish workflow) and are run daily by a local
scheduled task on the machine that has RS.ge/DB network access, since this can't run from an
arbitrary cloud sandbox (see `volta_funnel_dashboard`'s "why refresh is manual" note for the same
constraint on the PHP side).

## Setup

```bash
pip install pymysql lxml openpyxl zeep
cp config.example.py config.py   # fill in real RS.ge + MySQL credentials
```

`refresh_volta_analytics.py` additionally needs:
- A portable PHP 8.3 CLI with `pdo_mysql`/`mysqli` enabled, at `./php83/php.exe` relative to this
  folder (not committed — download from https://windows.php.net/download/, or point `PHP_EXE` in
  the script at any PHP 8.3+ binary with those extensions).
- The sibling `php-dashboard` project (this same repo) checked out with its own `config.php` set
  up, at the path `PHP_PROJECT_DIR` points to in the script — **currently a hardcoded absolute
  Windows path specific to the machine this was built on; update it for any other environment.**
- `access_permissions.json` in this folder — copy `access_permissions.example.json` and fill in
  real email → tab/sub-tab access rows (see `volta_analytics_auth` memory for the exact schema
  and semantics). This is a **cosmetic navigation filter only, not real security** — every
  viewer's browser still downloads the full dataset for every tab regardless of what's hidden.
  Real per-role data security would need server-side auth added to `php-dashboard` itself.

## Run

```bash
python refresh_dashboard.py          # writes waybill_dashboard.html + an .xlsx reconciliation report
python refresh_volta_analytics.py    # writes volta_analytics_merged.html (needs the above to have run first)
```

Neither script publishes anywhere by itself — that's a separate manual/Claude-driven step. See the
Claude memory files named above for full context, validated business logic decisions (date-field
choices, sign conventions, the bipartite matching algorithm, the CSS-scoping approach used to merge
two independently-styled dashboards into one page, etc.) — this README intentionally doesn't
duplicate that.
