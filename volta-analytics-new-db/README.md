# Volta_Analytics_New DB

Static build of the Volta_Analytics dashboard sourced from **VoltaStoreDB** (the new database — "Volta Database
Gia's" in Workbench), in the same spreadsheet-replica format as the old-DB dashboard. Published as the
claude.ai Artifact **Volta_Analytics_New DB** (`https://claude.ai/code/artifact/c743a673-9b73-4798-88bf-389d45cbe608`)
and refreshed on request — it is a snapshot, not a live page.

Tabs: **Daily Mail** (Report, MTD Statistics, Daily Statistics), **Sales Analyze** (Sales Monthly, Brand
Analyze, Subcategory Analyze, Category / Brand), **Logistics** (Logistics Daily), **Marketing** (Leads),
**Operations** (Applications, Committee), **Customers** (Customers Analyze), **Collections** (Collections
Analyze) and **Portfolio** (Portfolio Analyze). Income/Delinquency is not ported yet.

Everything lives in two files per side: `pull_new.sh` (one Bash script, every group's SQL, run once) /
`build_logistics.js` (one Node script, every group's aggregation + HTML injection, run once) on the static
side, and `src/NewDbReport.php` (one class per group in the same file) / `bin/newdb_dump.php` on the live side.

## Hybrid source

The new DB's historical rows (bulk-migrated on 2026-08-31) do not carry reliable order/application dates, so
every metric is **old DB (myvolta.info) before 2026-08-31, new DB from 2026-08-31 on**. The old-DB extracts
(`old_*.tsv`) are frozen; only the `new_*.tsv` extracts are re-pulled. From the cutover the old DB stopped
receiving underwriting status, downpayments and order dates (deals are now closed in the new CRM), so the
old-DB dashboard shows zeros there — this one has the real numbers.

Field mapping on the new DB: Applications = `orders` by `created_at`; Terms Approved =
`crm_underwriter_status_id IS NOT NULL`; Underwriting Approved = `= 16`; Deals Closed / Amount Sold =
`crm_active = 1` by `crm_creator_date`, amount `base_grand_total` (**not** `grand_total`, which is the
remaining balance and shrinks as payments post); Downpayment = `crm_advance_amount`; sales lines =
`order_items.base_total`, real sales `crm_order_status IN (5, 99)` (99 = single payment). The new DB has
**no cost data** (Cogs/Mrg show `–` from the cutover, mixed cells are marked `*`), and the category mapping
sheet (`../src/product_mapping.json`) matches only part of the new catalog's category names (the rest lands
in Uncategorized).

## Live on reporting.volta.ge

`public/index.php` serves this dashboard **live**: `src/NewDbReport.php` recomputes both data blocks from
VoltaStoreDB on request (same SQL as `pull_new.sh`, same math as the Node scripts — the two are
cross-checked to be identical for the same day), swaps them into this folder's `deals_amount_migration.html`,
and caches the result in `data/newdb_<yesterday>.json` for an hour (`?refresh=1` forces a recompute). The day
rolls over by itself, so nothing needs to be run daily. The server's `config.php` needs the `voltastoredb`
block from `config.example.php`; without it, or if the database is unreachable, the page falls back to the
last committed build and says so in a yellow note. The top-right stamp shows when the numbers were computed
(Tbilisi time) and the last day they cover. `bin/newdb_dump.php [YYYY-MM-DD]` writes the live JSON to
`php_*.json` here for cross-checking against the Node pipeline.

### Live PHP for the five newer groups

`NewDbMkt`, `NewDbOps`, `NewDbCust`, `NewDbColl` and `NewDbPf` are five more classes in `src/NewDbReport.php`
(same file as `NewDbReport` itself — PHP classes don't need their own file), the PHP twins of the matching
section of `build_logistics.js` (same SQL as `pull_new.sh`, same aggregation, verified byte-identical to
`<prefix>_data.json`). `NewDbReport::build()` instantiates all five directly and `public/index.php` swaps the
`MKT/OPS/CUST/COLL/PF_JSON` lines. They run through *today* (last column "(today)" / "(MTD)"). Measured live
on RDS: mkt 1.3 s, ops 1.0 s, cust 6.5 s, coll 4.3 s, pf 4.7 s - the whole page build (all 14 tabs) is ~23 s,
paid once per hour thanks to the cache; a group that throws falls back to the committed numbers for that tab
only (yellow note names it). Cross-check: `php bin/newdb_dump.php` writes every `php_<prefix>_data.json` in
one run (and the existing three) for comparison against the Node output.

## Refresh (static build — the Artifact copy and the fallback page)

```
cp config.example.sh config.sh    # once; fill in the passwords
bash pull_new.sh                  # every group's extracts through today (Daily Mail/Sales Analyze stop at yesterday; pass YYYY-MM-DD to override)
node merge.js && node build_report_data.js        # Daily Mail    -> REPORT_JSON in the HTML
node build_sales.js && node patch_sales_tabs.js   # Sales Analyze -> SALES_JSON in the HTML
node build_logistics.js                            # Logistics/Marketing/Operations/Customers/Collections/Portfolio -> the other six *_JSON lines
```

`build_logistics.js` runs all six of its groups in one pass, each self-contained (own CSS/nav/page/render
block, own `<prefix>_data.json`); pass `DASH_HTML=<path>` to build into a copy instead of the real file.

Then open `deals_amount_migration.html` over a local http server (a `file://` load does not run the JS),
check all 14 tabs, and republish the Artifact from that file.

Excel export of the Daily Mail group: `node build_daily_mail_xlsx.js`, then
`powershell -File write_daily_mail_xlsx.ps1 -JsonPath daily_mail_xlsx.json -OutPath Volta_Daily_Mail_New_DB.xlsx`
(use Windows-style absolute paths for -JsonPath/-OutPath — Excel's SaveAs rejects forward slashes).
Excel export of Logistics Daily: `node build_logistics_xlsx.js`, then the same writer with
`-JsonPath logistics_xlsx.json -OutPath Volta_Logistics_New_DB.xlsx` (6 sheets, Volta logo palette).
(Excel COM; every % and total is a live formula).

## Files

| File | Role |
|---|---|
| `deals_amount_migration.html` | the dashboard (single file; every tab's data embedded as its own `const ..._JSON`) |
| `pull_new.sh` / `pull_old.sh` | DB extracts for every group in one script each (new DB: rolling; old DB: frozen, only if the TSVs are lost) |
| `merge.js`, `build_report_data.js` | Daily Mail series (daily + monthly) and injection into the HTML |
| `build_logistics.js` | Logistics Daily, Marketing/Leads, Operations, Customers Analyze, Collections Analyze and Portfolio Analyze — one script, six self-contained sections (each its own CSS/nav/page/render block and its own `<prefix>_data.json`), run in that order |
| `build_sales.js`, `patch_sales_tabs.js` | Sales Analyze reports (JS port of `FunnelRepository`'s bucketed reports + `ProductClassifier`) and injection |
| `build_daily_mail_xlsx.js`, `build_logistics_xlsx.js`, `write_daily_mail_xlsx.ps1` | Excel exports (Daily Mail / Logistics Daily); the .ps1 is the shared Excel-COM writer |
| `old_*.tsv`, `new_*.tsv`, `logi_*.tsv`, `mkt_*.tsv`, `ops_*.tsv`, `cust_*.tsv`, `coll_*.tsv`, `pf_*.tsv` | inputs, all written by `pull_new.sh` |
| `src/NewDbReport.php` | six PHP classes in one file (`NewDbReport` + `NewDbMkt`/`NewDbOps`/`NewDbCust`/`NewDbColl`/`NewDbPf`), the live twin of the Node build above |

## Operations — Applications / Committee

Nav group **Operations** with two sub-tabs, both from the new CRM only (the Operations section of
`pull_new.sh` → `ops_*.tsv` → the Operations section of `build_logistics.js` → gitignored `ops_data.json` +
`const OPS_JSON` in the HTML; `DASH_HTML=<path>` builds into a copy). Day series run from
the cutover (2026-08-31; log-based tables from 2026-09-01, the first status-change event) through today; month series
from January 2026 (application rows exist for all of 2026, status history only from September). Dates are UTC calendar
days like every other tab (reproduces the CRM's Applications count for Sep 1–7 exactly, 987).

**Applications** — (1) *Applications by current status* (flow keyed to `orders.created_at`, rows = today's
`crm_order_status`); (2) *Status changes — applications entering each status* (`crm_activity_log`,
`action='installment.status_change'`, `metadata.to`); (3) *Funnel by application date* (state: approved
`crm_underwriter_status_id=16`, signed/active status 11/5/1, still in process, rejected 6, declined 12, expired 13,
% of applications); (4) status-code legend. Labels are verified from the CRM's own wording in the audit-trail summary
("სტატუსის შეცვლა: …"): 4 Pending, 7 In processing, 8 At committee, 15 Clarification needed, 16 Approved, 17 Disbursement
in process, 9 Approved – invoice & contract draft sent, 10 Contract sent for signing, 11 Signed, 5 Active (log code 1 =
activation), 6 Rejected, 12 Customer declined, 13 Expired, 99 Single payment; 1 = legacy Active on migrated 2019–2024
loans; 3 and 14 are unverified (migrated rows only).

**Committee** — decisions = status changes *out of* 8: → 16 approved, → 15 returned for clarification, → 6 rejected.
(1) *Committee decisions by decision date* (+ approval rate, sent to committee → 8, resubmitted 15 → 8, end-of-period
stock in 8 and 15 reconstructed from the log — today equals the live counts); (2) *Underwriting outcome by application
date* (state of `crm_underwriter_status_id`; note 6 is stamped on pre-committee rejections too); (3) *Decisions per
underwriter* (log actor → `crm_users`; `crm_underwriter_id` is not used because the CRM also sets it to the sales
manager who rejects a case before committee); (4) *Committee rejection reasons* (text after "მიზეზი:" in the summary,
= `orders.crm_reason`); (5) *All rejections by stage*. `crm_approve_date` is NULL on post-cutover approvals, so the
audit-trail timestamp is the only decision date.

Refresh: `bash pull_new.sh` then `node build_logistics.js` (both run every group; see Refresh above).

## Marketing — Leads

Nav group **Marketing**, sub-tab **Leads** (`data-page="leads"`), built by the Marketing sections of
`pull_new.sh` + `build_logistics.js` (data constant
`MKT_JSON`, gitignored `mkt_data.json`). Source: the new CRM's lead table `volta_leads` (the website's pre-application
lead form; rows exist from 2026-03-19, real history from the first day — nothing was migrated). Every table comes twice,
**by day** (2026-08-01 → today) and **by month** (Mar 2026 → current month MTD), Logistics look, with trailing share
columns (day tables: share of the last day, last 7 days and their share; month tables: share of the MTD month, all-time
total and its share). All counts are flows keyed to the lead's creation day (`DATE(created_at)`, UTC like the rest).

- **Leads by status** — rows = the CRM marketing statuses (`crm_marketing_statuses`: New / Contacted / Qualified / Won /
  Lost; `volta_leads.status` stores the lower-cased name, verified in `crm_activity_log` `lead.update` rows). It is the
  lead's **current** status — there is no status history, so this is a snapshot classified by creation date. Memo rows:
  assigned to a sales manager (`crm_sales_manager_id`), repeat submissions (same phone sent an earlier lead).
- **Leads by form step** — `last_step` 1–3 (3 = form completed). From 2026-09-02 the new CRM's form records one step
  only, so the split is meaningful up to 2026-09-01.
- **Leads by city** — free-text `city`, Georgian/Latin spellings folded; six biggest cities + Other Cities / Without City.
- **Lead → application conversion** — `orders.crm_source_lead_id` is never filled, so a lead is tied to applications by
  **phone number**: `addresses.phone` (`order_billing`) or `customers.phone` / `volta_leads.customer_id` →
  `orders.customer_id`. Rows: leads; phone matched to any order (any date); of which already a customer (order before the
  lead); applications after the lead (any time / within 30 days); loans issued (`crm_order_status IN (5, 99)` or
  `crm_active = 1`); and the rates as a share of that day's / month's leads. A lead is counted once whichever way it
  matched. The last 30 days' 30-day rate is still open; a floor, not a ceiling (a different phone on the application is
  not matched). Only one lead source exists so far (`source = 'application_form'`), so there is no by-source table.

Refresh: `bash pull_new.sh` then `node build_logistics.js` (both run every group; the Marketing section takes
≈40 s and writes daily aggregates only, no PII in the TSVs). Idempotent — re-injects the page, CSS and render code each run.

## Portfolio → Portfolio Analyze

The loan book itself — stocks and flows by day (from the cutover 2026-08-31, last column = today) and by month (from
Jan 2025, last column = current month to date), the structure of each month's disbursements, a vintage table and a
snapshot of the active book. Sub-tab `Portfolio Analyze` (`data-page="portfolio"`, data `PF_JSON`).

Definitions. **Loan book** = `orders` with `crm_order_status IN (5, 1)` (5 = installment; 1 = the same status on
rows migrated from the old CRM) and `crm_active IN (1, 0)`; pending / rejected / cancelled applications and
single-payment sales (99, memo row only) are not loans. **Disbursed** = keyed to `crm_creator_date` (exact from the
cutover; drifts by hours–days on migrated rows, so pre-cutover months reconcile with the old dashboard within a few
percent and days do not). **Contract amount** = the Daily Mail "Amount Sold" rule (schedule total + advance when the
schedule is net of it); price = `base_grand_total`. **Closed** = `crm_close_type` 1 paid off / 2 written off, keyed to
`crm_close_date` when sane (80 recorded closes carry 1924/1970 or pre-disbursement dates → last payment date instead);
`crm_active = 0` with no close type = **paid off, no close record** (the old CRM stopped recording closes in June 2026;
856 such loans, 854 with a fully paid schedule) keyed to the last non-reversed `crm_payments.payment_date`. The new CRM
records closes again from 2026-09-04 (`installment.close`, "paid_in_full"). **Outstanding balance** today =
`SUM(schedule_amount − paid_amount)` (= `orders.grand_total` on all but ~100 loans); on earlier dates = today's balance
+ principal (`crm_payments.principal_part`, non-reversed, one 99,999,999.99 placeholder excluded) paid after that date —
a ledger reconstruction, exact today. **Term** = schedule rows dated more than a week after disbursement (the first row
of a migrated 11-row schedule is the advance). Segment A/B, city and goods type use the Daily Mail / Logistics rules
(`src/product_mapping.json`; ~36% of loans since 2025 fall in Uncategorized because the sheet does not know the new
catalog's category names). Risk = `crm_risk_status` with Georgian/English spellings merged.

Refresh: `bash pull_new.sh` then `node build_logistics.js` (both run every group; the Portfolio section alone
is ~25 s of the pull and reads `new_categories.tsv` / `new_product_categories.tsv`, also from `pull_new.sh`,
for the goods-type mapping).

## Customers → Customers Analyze

Customer statistics from the new DB only (no old-DB half): who applies and who borrows, by gender, age, city, employment
status, social status, declared salary, "how did you hear about us", CRM risk status, job title and CRM rating, plus
new-vs-returning applicants by month and by day, applications/loans per customer, and the CRM's payment-behaviour
statistics. Aggregates only — no names, phones or ID numbers ever leave the database (the Customers section of
`pull_new.sh` uses the personal ID only inside `GROUP BY` / window functions).

Definitions: **customer** = a distinct person, identity = `volta_application_data.personal_ID` (99.4% of orders), else
`customers.id_number` through the account, else the account id / e-mail; **application** = one `orders` row by
`created_at`; **active loan** = `crm_active = 1 AND crm_order_status <> 4` (pending applications carry `crm_active = 1` by
default and are excluded); **loan** = `crm_order_status IN (5, 99)` or a closed loan (`crm_close_type`) or an active one;
**new** = the identity's first application ever, **returning (previous loan)** = an earlier loan existed before this
application, **returning (earlier application only)** = applied before, never borrowed. Customer-level tables take each
attribute from the customer's latest application that has it (risk: from the active loan first); by-month tables take the
application's own value (age at the application date). Age comes from `birth_date` on the form / `date_of_birth` on the
account, never from the ID number. Geography = `addresses.city` of the `order_shipping` address (top 15 + Other).
Validation: 94% of applicants who answered "I used Volta before" on the form are flagged returning by the identity key.

Coverage caveats (all shown live under each table): employment / social status / salary / source exist only on web-form
applications since Jan–Mar 2026 (~95% of web applications Mar–Aug 2026, but only ~15% since the 31 Aug cutover — the new
form asks fewer questions); CRM rating covers ~12% of customers; `crm_customer_payment_stats` is a per-customer cache
computed when the CRM opens a customer (1.7k rows, ~45% of active-loan customers with an account). Month series from
Jan 2024, day series from 1 Aug 2026 (timestamps exact from the cutover, migrated ±1 day before it).

Refresh: `bash pull_new.sh` then `node build_logistics.js` (both run every group; the Customers section alone is
~70 s of the pull and writes `cust_*.tsv` / `cust_data.json` / `const CUST_JSON`).

## Collections → Collections Analyze

Payments, overdue portfolio and collection activity from the new CRM. Day series from the cutover (2026-08-31) to
today, month series from 2023-01 (the schedule table covers every loan from 2023 on). Tables: **Overdue portfolio**
at the end of each day / month (stock) by the CRM's own DPD buckets (1–3, 4–10, 11–30, 31–60, 61–90, 90+ days) — rows
and amount at schedule-row level and loan level (each loan once, in the bucket of its oldest overdue row), reconstructed
from `crm_installment_schedules` + the post-cutover `crm_payment_events` settlement trail (today's column equals the live
`active=1 AND schedule_date < today` figures); **Cash collected** (flow, by `crm_payments.payment_date`; reversed payments
and the migrated 99,999,999.99 sentinel row excluded) with principal / advance / penalty parts and by bank; **Scheduled
dues** by due date with on-time / late / open performance (knowable for due dates since the cutover); **Collection
activity** (promises, calls, tasks, marks, comments… from the crm_* collections tables — live since the cutover);
today's snapshots by portfolio manager, CRM loan status and the customer payment-stats cache; a "what is populated"
note with row counts. Refresh: `bash pull_new.sh` then `node build_logistics.js` (both run every group).
