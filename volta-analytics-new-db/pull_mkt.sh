#!/usr/bin/env bash
# Marketing -> Leads extracts for Volta_Analytics_New DB (VoltaStoreDB `volta_leads`), daily aggregates only — no PII.
#   usage: bash pull_mkt.sh [YYYY-MM-DD]      (needs ./config.sh — see config.example.sh; END defaults to today)
# Every file is keyed to the lead's creation day (DATE(volta_leads.created_at), UTC like the rest of the dashboard);
# build_mkt.js sums the days into months and cuts the day series at MKT_DAY_START.
set -euo pipefail
S="$(cd "$(dirname "$0")" && pwd)"; source "$S/config.sh"
END="${1:-$(date +%F)}"
LEADS_START='2026-03-19'   # first row in volta_leads (the lead-capture form went live that day)
export MYSQL_PWD="$NEWDB_PWD"
Q() { "$MYSQL_BIN" -h "$NEWDB_HOST" -P 3306 -u "$NEWDB_USER" -D "$NEWDB_NAME" --default-character-set=utf8mb4 --connect-timeout=20 -e "$1"; }

# status catalogue (the CRM's marketing statuses; volta_leads.status stores the lower-cased name — verified in crm_activity_log lead.update rows)
Q "SELECT id, name, active FROM crm_marketing_statuses ORDER BY id;" > "$S/mkt_statuses.tsv"

# (a) new leads per day by CURRENT status (snapshot — volta_leads has no status history), plus two memo counts:
#     assigned = lead has a sales manager (crm_sales_manager_id), repeat_ = the same phone number already sent an earlier lead
Q "SELECT DATE(l.created_at) d, LOWER(l.status) status, COUNT(*) n, SUM(l.crm_sales_manager_id IS NOT NULL) assigned,
     SUM(EXISTS(SELECT 1 FROM volta_leads p WHERE p.telephone=l.telephone AND (p.created_at<l.created_at OR (p.created_at=l.created_at AND p.id<l.id)))) repeat_
   FROM volta_leads l WHERE DATE(l.created_at) BETWEEN '$LEADS_START' AND '$END' GROUP BY d, status ORDER BY d, status;" > "$S/mkt_status.tsv"

# (b) new leads per day by the last form step the visitor reached (last_step 1..3)
Q "SELECT DATE(created_at) d, last_step step, COUNT(*) n FROM volta_leads WHERE DATE(created_at) BETWEEN '$LEADS_START' AND '$END' GROUP BY d, step ORDER BY d, step;" > "$S/mkt_step.tsv"

# (c) new leads per day by city group (free-text city, Georgian and Latin spellings folded together)
Q "SELECT DATE(created_at) d,
     CASE WHEN city IS NULL OR TRIM(city)='' THEN 'Without City'
          WHEN LOWER(TRIM(city)) IN ('თბილისი','tbilisi','t''bilisi','tbilisi ') THEN 'Tbilisi'
          WHEN LOWER(TRIM(city)) IN ('ბათუმი','batumi') THEN 'Batumi'
          WHEN LOWER(TRIM(city)) IN ('ქუთაისი','kutaisi','qutaisi') THEN 'Kutaisi'
          WHEN LOWER(TRIM(city)) IN ('რუსთავი','rustavi') THEN 'Rustavi'
          WHEN LOWER(TRIM(city)) IN ('გორი','gori') THEN 'Gori'
          WHEN LOWER(TRIM(city)) IN ('ზუგდიდი','zugdidi') THEN 'Zugdidi'
          ELSE 'Other Cities' END city_grp, COUNT(*) n
   FROM volta_leads WHERE DATE(created_at) BETWEEN '$LEADS_START' AND '$END' GROUP BY d, city_grp ORDER BY d, city_grp;" > "$S/mkt_city.tsv"

# (d) lead -> application conversion per lead-creation day. orders.crm_source_lead_id is never filled, so a lead is tied to
#     orders by phone number: the phone on the order's billing address (addresses.address_type='order_billing') or the
#     customer record (customers.phone / volta_leads.customer_id). Per lead: matched = at least one order of that phone
#     exists (any date); apps = an order created at/after the lead; apps30 = within 30 days of the lead; deals = such an
#     order is a loan (crm_order_status 5 Active / 99 single payment, or crm_active=1); before_ = an order existed BEFORE
#     the lead (an existing customer filling the form). Calendar-driven so days with no leads appear as zeros.
Q "WITH RECURSIVE cal AS (SELECT DATE('$LEADS_START') d UNION ALL SELECT d + INTERVAL 1 DAY FROM cal WHERE d < DATE('$END')),
   lo AS (SELECT l.id lead_id, o.id order_id FROM volta_leads l JOIN customers c ON c.phone=l.telephone OR c.id=l.customer_id JOIN orders o ON o.customer_id=c.id
          UNION SELECT l.id, a.order_id FROM volta_leads l JOIN addresses a ON a.phone=l.telephone AND a.address_type='order_billing' AND a.order_id IS NOT NULL),
   m AS (SELECT l.id lead_id, MIN(CASE WHEN o.created_at>=l.created_at THEN o.created_at END) first_after,
                MIN(CASE WHEN o.created_at>=l.created_at AND (o.crm_order_status IN (5,99) OR o.crm_active=1) THEN o.created_at END) first_deal,
                SUM(o.created_at<l.created_at) before_n
         FROM volta_leads l JOIN lo ON lo.lead_id=l.id JOIN orders o ON o.id=lo.order_id GROUP BY l.id)
   SELECT cal.d, COUNT(l.id) leads, SUM(m.lead_id IS NOT NULL) matched, SUM(m.first_after IS NOT NULL) apps,
     SUM(m.first_after IS NOT NULL AND m.first_after < l.created_at + INTERVAL 30 DAY) apps30, SUM(m.first_deal IS NOT NULL) deals, SUM(m.before_n>0) before_
   FROM cal LEFT JOIN volta_leads l ON DATE(l.created_at)=cal.d LEFT JOIN m ON m.lead_id=l.id GROUP BY cal.d ORDER BY cal.d;" > "$S/mkt_conv.tsv"

echo "END=$END"
wc -l "$S/mkt_statuses.tsv" "$S/mkt_status.tsv" "$S/mkt_step.tsv" "$S/mkt_city.tsv" "$S/mkt_conv.tsv" | sed "s#$S/##"
