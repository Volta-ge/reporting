# Volta_Analytics_New DB

Static build of the Volta_Analytics dashboard sourced from **VoltaStoreDB** (the new database — "Volta Database
Gia's" in Workbench), in the same spreadsheet-replica format as the old-DB dashboard. Published as the
claude.ai Artifact **Volta_Analytics_New DB** (`https://claude.ai/code/artifact/c743a673-9b73-4798-88bf-389d45cbe608`)
and refreshed on request — it is a snapshot, not a live page.

Tabs: **Daily Mail** (Report, MTD Statistics, Daily Statistics) and **Sales Analyze** (Sales Monthly, Brand
Analyze, Subcategory Analyze, Category / Brand). Income/Delinquency is not ported yet.

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

## Refresh

```
cp config.example.sh config.sh    # once; fill in the passwords
bash pull_new.sh                  # new-DB extracts through yesterday (or pass YYYY-MM-DD)
node merge.js && node build_report_data.js        # Daily Mail    -> REPORT_JSON in the HTML
node build_sales.js && node patch_sales_tabs.js   # Sales Analyze -> SALES_JSON in the HTML
```

Then open `deals_amount_migration.html` over a local http server (a `file://` load does not run the JS),
check the three Daily Mail tabs and the four Sales tabs, and republish the Artifact from that file.

Excel export of the Daily Mail group: `node build_daily_mail_xlsx.js`, then
`powershell -File write_daily_mail_xlsx.ps1 -JsonPath daily_mail_xlsx.json -OutPath Volta_Daily_Mail_New_DB.xlsx`
(Excel COM; every % and total is a live formula).

## Files

| File | Role |
|---|---|
| `deals_amount_migration.html` | the dashboard (single file; data embedded as `REPORT_JSON` / `SALES_JSON`) |
| `pull_new.sh` / `pull_old.sh` | DB extracts (new DB: rolling; old DB: frozen, only if the TSVs are lost) |
| `merge.js`, `build_report_data.js` | Daily Mail series (daily + monthly) and injection into the HTML |
| `build_sales.js`, `patch_sales_tabs.js` | Sales Analyze reports (JS port of `FunnelRepository`'s bucketed reports + `ProductClassifier`) and injection |
| `build_daily_mail_xlsx.js`, `write_daily_mail_xlsx.ps1` | Excel export |
| `old_*.tsv`, `new_*.tsv` | inputs |
