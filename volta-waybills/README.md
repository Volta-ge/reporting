# Volta Waybills / Volta_Analytics publishing scripts

Two independent Python scripts:

1. **`refresh_dashboard.py`** — fetches Volta Group's seller-side **waybills** and **invoices**
   from RS.ge (WayBillService + ntosservice SOAP APIs), cross-matches them against **CRM sales**
   from Volta's MySQL DB to find sales missing a waybill (or waybills missing a CRM sale), and
   writes a standalone dashboard (`waybill_dashboard.html`) plus an Excel reconciliation report.
   This is a separate, standalone artifact from Volta_Analytics below — kept for its own sake.

2. **`refresh_volta_analytics.py`** — runs the sibling `php-dashboard` project (this same repo)
   directly via the PHP CLI and writes its output ready to publish as the "Volta_Analytics"
   artifact.

**As of 2026-08-27, Accounting (waybills/invoices/reconciliation) and the email-based nav filter
are native, permanent parts of `php-dashboard` itself** (`AccountingRepository.php`,
`access_permissions.json`) — computed fresh from RS.ge + the DB on every render. There is no more
merge step for Volta_Analytics: `refresh_volta_analytics.py` just runs `php public/index.php` and
strips the result down to the body-content-only shape the Artifact tool requires. See the
`volta_php_accounting_port` Claude memory for why/how, and `volta_analytics_merge` /
`volta_analytics_auth` for the history this replaced.

Both scripts publish their output as Claude Artifacts (not part of this repo — done manually via
Claude Code) and are run daily by a local scheduled task on a machine with RS.ge/DB network access,
since this can't run from an arbitrary cloud sandbox (see `volta_funnel_dashboard`'s "why refresh is
manual" note for the same constraint on the PHP side).

## Setup

```bash
pip install pymysql lxml openpyxl zeep
cp config.example.py config.py   # fill in real RS.ge + MySQL credentials
```

`refresh_volta_analytics.py` additionally needs:
- A portable PHP 8.3 CLI with `pdo_mysql`/`mysqli`/`curl`/`soap`/`openssl` enabled, at
  `./php83/php.exe` relative to this folder (not committed — download from
  https://windows.php.net/download/, or point `PHP_EXE` in the script at any PHP 8.3+ binary with
  those extensions).
- The sibling `php-dashboard` project (this same repo) checked out with its own `config.php`
  (DB **and** `rsge` credentials — see its own `config.example.php`) and its own
  `access_permissions.json` (copy `access_permissions.example.json`, see `volta_analytics_auth`
  memory for the schema — **cosmetic navigation filter only, not real security**), at the path
  `PHP_PROJECT_DIR` points to in the script — **currently a hardcoded absolute Windows path
  specific to the machine this was built on; update it for any other environment.**

## Run

```bash
python refresh_dashboard.py          # writes waybill_dashboard.html + an .xlsx reconciliation report (standalone)
python refresh_volta_analytics.py    # writes volta_analytics_merged.html (independent of the above)
```

Neither script publishes anywhere by itself — that's a separate manual/Claude-driven step. See the
Claude memory files named above for full context, validated business logic decisions (date-field
choices, sign conventions, the bipartite matching algorithm, etc.) — this README intentionally
doesn't duplicate that.
