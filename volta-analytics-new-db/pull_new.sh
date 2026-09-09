#!/usr/bin/env bash
# New-DB (VoltaStoreDB) extracts for Volta_Analytics_New DB, cutover (2026-08-31) through END (default: yesterday).
#   usage: bash pull_new.sh [YYYY-MM-DD]      (needs ./config.sh — see config.example.sh)
# Also writes every other tab's extracts in one pass: Logistics Daily, Marketing/Leads, Operations, Customers,
# Collections and Portfolio (their own day series run through TODAY, not END — see the TODAY comment below).
set -euo pipefail
S="$(cd "$(dirname "$0")" && pwd)"; source "$S/config.sh"
END="${1:-$(date -d 'yesterday' +%F 2>/dev/null || date -v-1d +%F)}"
CUTOVER='2026-08-31'
TODAY="$(date +%F)"   # today's date -- Marketing/Operations/Customers/Collections/Portfolio (and the Logistics
                       # section below) all run through today, independent of END, which only bounds Daily Mail/Sales Analyze
export MYSQL_PWD="$NEWDB_PWD"
Q() { "$MYSQL_BIN" -h "$NEWDB_HOST" -P 3306 -u "$NEWDB_USER" -D "$NEWDB_NAME" --default-character-set=utf8mb4 --connect-timeout=20 -e "$1"; }
# Segment A = phone/TV product name, or order amount > 2,500 GEL.
# base_grand_total is the fixed sale amount; grand_total is the remaining balance and shrinks as payments post.
SEG="CASE WHEN EXISTS (SELECT 1 FROM order_items oi JOIN product_flat pf ON pf.product_id=oi.product_id AND pf.locale='ka_GE' WHERE oi.order_id=o.id AND (pf.name LIKE 'ტელეფონი%' OR pf.name LIKE 'ტელევიზორ%')) OR o.base_grand_total>2500 THEN 'A' ELSE 'B' END"

# Deals Closed / Amount Sold — keyed to the day the loan reaches Active status (crm_activity_log,
# 'installment.status_change', metadata.to=1: the Signed-to-Active transition), active orders only. This is
# the date key the CRM's own Sales performance page uses (user's decision 2026-09-09, replacing the earlier
# order/disbursement date crm_creator_date -- only possible from the cutover on, since the activity log has no
# reliable pre-cutover data; old-DB months keep Order_Date via old_daily_seg.tsv, unaffected by this).
# amount = the full installment amount the customer pays (product price + financing markup) = the payment
# schedule total; the advance is added only when the schedule was built net of it (schedule + advance <= price),
# because in the other convention the advance is already posted against the first schedule row.
# price = product price (base_grand_total), kept for reference / the Segment A rule.
Q "WITH act AS (SELECT entity_id, MIN(created_at) t FROM crm_activity_log WHERE action='installment.status_change' AND JSON_EXTRACT(metadata,'\$.to')=1 GROUP BY entity_id)
   SELECT DATE(act.t) d, $SEG seg, COUNT(*) deals,
   SUM(CASE WHEN s.tot IS NULL THEN o.base_grand_total
            WHEN s.tot + COALESCE(o.crm_advance_amount,0) <= o.base_grand_total + 0.01 THEN s.tot + COALESCE(o.crm_advance_amount,0)
            ELSE s.tot END) amount,
   SUM(o.base_grand_total) price, SUM(s.tot IS NULL) no_schedule
   FROM act JOIN orders o ON o.id=act.entity_id AND o.crm_active=1
   LEFT JOIN (SELECT installment_id, SUM(schedule_amount) tot FROM crm_installment_schedules GROUP BY installment_id) s ON s.installment_id=o.id
   WHERE DATE(act.t) BETWEEN '$CUTOVER' AND '$END' GROUP BY d, seg ORDER BY d, seg;" > "$S/new_daily_seg.tsv"

# Applications / Terms / Underwriting — keyed to the application date (created_at). Downpayment moved to its
# own query below (payment date, actually collected only).
Q "SELECT DATE(o.created_at) d, $SEG seg, COUNT(*) applications, SUM(o.crm_underwriter_status_id IS NOT NULL) terms,
   SUM(o.crm_underwriter_status_id=16) uw
   FROM orders o WHERE DATE(o.created_at) BETWEEN '$CUTOVER' AND '$END' GROUP BY d, seg ORDER BY d, seg;" > "$S/new_daily_apps.tsv"

# Downpayment Collected — REAL cash collected, keyed to the payment date (not the application date, and not the
# amount merely recorded on the application). A downpayment payment = a crm_payments row whose amount equals
# the order's crm_advance_amount (the downpayment is posted as an ordinary payment; crm_payments' own `advance`
# column is a different, much rarer flag, verified against the CRM's payments export not to be the downpayment).
# User's decision 2026-09-09, after comparing the old application-date/recorded-amount figure (17,947 GEL,
# Sep 1-8) against the CRM's payments export: switched to what was actually paid (6,354 GEL for the same days).
Q "SELECT DATE(p.payment_date) d, $SEG seg, ROUND(SUM(p.amount),2) dp
   FROM crm_payments p JOIN orders o ON o.id=p.installment_id
   WHERE p.reversed_at IS NULL AND o.crm_advance_amount > 0 AND p.amount = o.crm_advance_amount
     AND DATE(p.payment_date) BETWEEN '$CUTOVER' AND '$END'
   GROUP BY d, seg ORDER BY d, seg;" > "$S/new_daily_dp.tsv"

# Sales Analyze line items — real sales only (crm_order_status 5 = installment, 99 = single payment); the new DB has no cost data
Q "SELECT DATE_FORMAT(COALESCE(o.crm_creator_date,o.created_at),'%Y-%m') period, DATE(COALESCE(o.crm_creator_date,o.created_at)) d, o.id order_id, oi.product_id, oi.qty_ordered qty, oi.base_total sales,
   CASE WHEN o.crm_order_status=99 THEN 'single' ELSE 'installment' END deal_type, COALESCE(ao.admin_name,'') brand, pf.sku, pf.name product_name
   FROM orders o JOIN order_items oi ON oi.order_id=o.id
   LEFT JOIN product_flat pf ON pf.product_id=oi.product_id AND pf.locale='ka_GE'
   LEFT JOIN product_attribute_values pb ON pb.product_id=oi.product_id AND pb.attribute_id=25
   LEFT JOIN attribute_options ao ON ao.id=pb.integer_value
   WHERE o.crm_order_status IN (5,99) AND COALESCE(o.crm_creator_date,o.created_at) >= '$CUTOVER' AND DATE(COALESCE(o.crm_creator_date,o.created_at)) <= '$END'
   ORDER BY d, o.id;" > "$S/new_sales_lines.tsv"

# category tree + product links (for the mapping-sheet classifier) — the catalog grows, so refresh these too
Q "SELECT c.id, c.parent_id, ct.name FROM categories c LEFT JOIN category_translations ct ON ct.category_id=c.id AND ct.locale='ka_GE' ORDER BY c.id;" > "$S/new_categories.tsv"
Q "SELECT product_id, category_id FROM product_categories ORDER BY product_id, category_id;" > "$S/new_product_categories.tsv"

# ---------------- Logistics Daily (all reconstructed from the CRM logs, so history is exact from the cutover) ----------------
LOGI_START='2026-09-02'   # first day of the CRM logistics module — the delivery series starts here
# population = orders that HAVE a crm_order_logistics row and are either active (crm_active=1) or in status 11 = CRM "Signed"
# (contract signed, logistics started, becomes Active = status 1 in the log / 5 in orders a few hours later; the CRM counts these too). Orders never entered into the module were fulfilled outside it and are not counted.
# pending loan applications (crm_order_status 4) at the end of each day, by age since the application — an order counts as
# pending on day D if it was ever pending (has a from=4 status change, or is pending now) and had not left status 4 by the end of D
# only applications submitted from PENDING_START on (user's choice, 2026-09-08): the ~358 pre-cutover applications migrated from the
# old CRM that are still 'pending' are stale and would swamp the table — the CRM's own Pending figure (~490) includes them
PENDING_START='2026-09-01'
Q "WITH RECURSIVE cal AS (SELECT DATE('$PENDING_START') d UNION ALL SELECT d + INTERVAL 1 DAY FROM cal WHERE d < DATE('$TODAY'))
   SELECT cal.d, SUM(DATEDIFF(cal.d, DATE(o.created_at)) <= 1) upTo1, SUM(DATEDIFF(cal.d, DATE(o.created_at)) BETWEEN 2 AND 5) oneTo5, SUM(DATEDIFF(cal.d, DATE(o.created_at)) > 5) over5
   FROM cal JOIN orders o ON o.created_at >= '$PENDING_START' AND o.created_at < cal.d + INTERVAL 1 DAY
   LEFT JOIN (SELECT entity_id, MIN(created_at) left_at FROM crm_activity_log WHERE action='installment.status_change' AND JSON_EXTRACT(metadata,'\$.from')=4 GROUP BY entity_id) x ON x.entity_id=o.id
   WHERE (x.entity_id IS NOT NULL OR o.crm_order_status=4) AND (x.left_at IS NULL OR x.left_at >= cal.d + INTERVAL 1 DAY)
   GROUP BY cal.d ORDER BY cal.d;" > "$S/logi_pending.tsv"
# delivery status per day: active orders in the module (entered = sale date or the day the module picked them up, whichever is later) not yet delivered/picked up at the end of D, by age since the sale;
# delivered = orders whose first delivered/picked-up event (crm_shipment_status_history, order level, status 80/81) fell on D
Q "WITH RECURSIVE cal AS (SELECT DATE('$LOGI_START') d UNION ALL SELECT d + INTERVAL 1 DAY FROM cal WHERE d < DATE('$TODAY')),
   pop AS (SELECT o.id, COALESCE(o.crm_creator_date, o.created_at) sale, GREATEST(COALESCE(o.crm_creator_date, o.created_at), l.created_at) entered, dl.delivered_at FROM orders o JOIN crm_order_logistics l ON l.order_id=o.id LEFT JOIN (SELECT order_id, MIN(created_at) delivered_at FROM crm_shipment_status_history WHERE entity_type=4 AND to_status IN (80,81) GROUP BY order_id) dl ON dl.order_id=o.id WHERE (o.crm_active=1 OR o.crm_order_status=11))
   SELECT cal.d,
     SUM(pop.entered < cal.d + INTERVAL 1 DAY AND (pop.delivered_at IS NULL OR pop.delivered_at >= cal.d + INTERVAL 1 DAY) AND DATEDIFF(cal.d, DATE(pop.sale)) <= 1) upTo1,
     SUM(pop.entered < cal.d + INTERVAL 1 DAY AND (pop.delivered_at IS NULL OR pop.delivered_at >= cal.d + INTERVAL 1 DAY) AND DATEDIFF(cal.d, DATE(pop.sale)) BETWEEN 2 AND 5) oneTo5,
     SUM(pop.entered < cal.d + INTERVAL 1 DAY AND (pop.delivered_at IS NULL OR pop.delivered_at >= cal.d + INTERVAL 1 DAY) AND DATEDIFF(cal.d, DATE(pop.sale)) > 5) over5,
     SUM(DATE(pop.delivered_at) = cal.d) delivered,
     ROUND(AVG(CASE WHEN DATE(pop.delivered_at) = cal.d THEN TIMESTAMPDIFF(HOUR, pop.sale, pop.delivered_at)/24 END),1) avgDays
   FROM cal LEFT JOIN pop ON pop.entered < cal.d + INTERVAL 1 DAY GROUP BY cal.d ORDER BY cal.d;" > "$S/logi_delivery.tsv"
# per order in the module (one row each): when it entered the series, when it was delivered, its city group and the product
# of its highest-value line — the build turns this into the daily "Orders by City" / "Orders by Goods Type" series
Q "SELECT o.id order_id, GREATEST(COALESCE(o.crm_creator_date, o.created_at), l.created_at) entered, dl.delivered_at,
     CASE WHEN a.city IS NULL OR a.city='' THEN 'Without City' WHEN a.city='თბილისი' THEN 'Tbilisi' ELSE 'Other Cities' END city_grp,
     (SELECT oi.product_id FROM order_items oi WHERE oi.order_id=o.id ORDER BY oi.base_total DESC, oi.id LIMIT 1) top_product_id
   FROM orders o JOIN crm_order_logistics l ON l.order_id=o.id
   LEFT JOIN (SELECT order_id, MIN(city) city FROM addresses WHERE address_type='order_shipping' GROUP BY order_id) a ON a.order_id=o.id
   LEFT JOIN (SELECT order_id, MIN(created_at) delivered_at FROM crm_shipment_status_history WHERE entity_type=4 AND to_status IN (80,81) GROUP BY order_id) dl ON dl.order_id=o.id
   WHERE (o.crm_active=1 OR o.crm_order_status=11) ORDER BY o.id;" > "$S/logi_orders.tsv"
# the 10 oldest not-yet-delivered active orders
Q "SELECT o.id order_id, TRIM(CONCAT(COALESCE(o.customer_first_name,''),' ',COALESCE(o.customer_last_name,''))) customer, DATE(COALESCE(o.crm_creator_date,o.created_at)) waiting_from, l.logistics_status, COALESCE(a.city,'') city
   FROM orders o LEFT JOIN crm_order_logistics l ON l.order_id=o.id LEFT JOIN (SELECT order_id, MIN(city) city FROM addresses WHERE address_type='order_shipping' GROUP BY order_id) a ON a.order_id=o.id
   LEFT JOIN (SELECT order_id FROM crm_shipment_status_history WHERE entity_type=4 AND to_status IN (80,81) GROUP BY order_id) dl ON dl.order_id=o.id
   WHERE (o.crm_active=1 OR o.crm_order_status=11) AND l.id IS NOT NULL AND dl.order_id IS NULL ORDER BY COALESCE(o.crm_creator_date,o.created_at), o.id LIMIT 10;" > "$S/logi_open.tsv"

# CRM status by day — how many orders (entity_type 4), order lines (1) and vendor collections (2) sat in each logistics
# status at the end of each day; the status of an entity on day D = its last status-history event up to the end of D.
# Today's column equals the live crm_order_logistics / crm_line_fulfillment / crm_vendor_collections status counts (verified).
# Same population as the other logistics tables (active or status-11 orders in the module).
Q "WITH RECURSIVE cal AS (SELECT DATE('$LOGI_START') d UNION ALL SELECT d + INTERVAL 1 DAY FROM cal WHERE d < DATE('$TODAY')),
   ev AS (SELECT h.id, h.entity_type, h.entity_id, h.to_status, h.created_at FROM crm_shipment_status_history h JOIN orders o ON o.id=h.order_id AND (o.crm_active=1 OR o.crm_order_status=11) WHERE h.entity_type IN (1,2,4))
   SELECT e.entity_type, cal.d, e.to_status status, COUNT(*) n FROM cal JOIN ev e ON e.created_at < cal.d + INTERVAL 1 DAY
   LEFT JOIN ev e2 ON e2.entity_type=e.entity_type AND e2.entity_id=e.entity_id AND e2.created_at < cal.d + INTERVAL 1 DAY AND (e2.created_at > e.created_at OR (e2.created_at=e.created_at AND e2.id>e.id))
   WHERE e2.id IS NULL GROUP BY e.entity_type, cal.d, e.to_status ORDER BY e.entity_type, cal.d, e.to_status;" > "$S/logi_status.tsv"

# memo row: module orders not yet activated (status 11, no 11->1 activation event by the end of day D)
Q "WITH RECURSIVE cal AS (SELECT DATE('$LOGI_START') d UNION ALL SELECT d + INTERVAL 1 DAY FROM cal WHERE d < DATE('$TODAY'))
   SELECT cal.d, COUNT(*) n FROM cal JOIN crm_order_logistics l ON l.created_at < cal.d + INTERVAL 1 DAY JOIN orders o ON o.id=l.order_id AND (o.crm_active=1 OR o.crm_order_status=11)
   LEFT JOIN (SELECT entity_id, MIN(created_at) activated_at FROM crm_activity_log WHERE action='installment.status_change' AND JSON_EXTRACT(metadata,'\$.to')=1 GROUP BY entity_id) act ON act.entity_id=o.id
   WHERE act.activated_at IS NULL OR act.activated_at >= cal.d + INTERVAL 1 DAY GROUP BY cal.d ORDER BY cal.d;" > "$S/logi_not_activated.tsv"

# ================ Marketing — Leads ================
# Lead status/step/city by day, lead-to-application conversion. No PII (see build_mkt.js). Runs through today.
LEADS_START='2026-03-19'   # first row in volta_leads (the lead-capture form went live that day)

# status catalogue (the CRM's marketing statuses; volta_leads.status stores the lower-cased name — verified in crm_activity_log lead.update rows)
Q "SELECT id, name, active FROM crm_marketing_statuses ORDER BY id;" > "$S/mkt_statuses.tsv"

# (a) new leads per day by CURRENT status (snapshot — volta_leads has no status history), plus two memo counts:
#     assigned = lead has a sales manager (crm_sales_manager_id), repeat_ = the same phone number already sent an earlier lead
Q "SELECT DATE(l.created_at) d, LOWER(l.status) status, COUNT(*) n, SUM(l.crm_sales_manager_id IS NOT NULL) assigned,
     SUM(EXISTS(SELECT 1 FROM volta_leads p WHERE p.telephone=l.telephone AND (p.created_at<l.created_at OR (p.created_at=l.created_at AND p.id<l.id)))) repeat_
   FROM volta_leads l WHERE DATE(l.created_at) BETWEEN '$LEADS_START' AND '$TODAY' GROUP BY d, status ORDER BY d, status;" > "$S/mkt_status.tsv"

# (b) new leads per day by the last form step the visitor reached (last_step 1..3)
Q "SELECT DATE(created_at) d, last_step step, COUNT(*) n FROM volta_leads WHERE DATE(created_at) BETWEEN '$LEADS_START' AND '$TODAY' GROUP BY d, step ORDER BY d, step;" > "$S/mkt_step.tsv"

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
   FROM volta_leads WHERE DATE(created_at) BETWEEN '$LEADS_START' AND '$TODAY' GROUP BY d, city_grp ORDER BY d, city_grp;" > "$S/mkt_city.tsv"

# (d) lead -> application conversion per lead-creation day. orders.crm_source_lead_id is never filled, so a lead is tied to
#     orders by phone number: the phone on the order's billing address (addresses.address_type='order_billing') or the
#     customer record (customers.phone / volta_leads.customer_id). Per lead: matched = at least one order of that phone
#     exists (any date); apps = an order created at/after the lead; apps30 = within 30 days of the lead; deals = such an
#     order is a loan (crm_order_status 5 Active / 99 single payment, or crm_active=1); before_ = an order existed BEFORE
#     the lead (an existing customer filling the form). Calendar-driven so days with no leads appear as zeros.
Q "WITH RECURSIVE cal AS (SELECT DATE('$LEADS_START') d UNION ALL SELECT d + INTERVAL 1 DAY FROM cal WHERE d < DATE('$TODAY')),
   lo AS (SELECT l.id lead_id, o.id order_id FROM volta_leads l JOIN customers c ON c.phone=l.telephone OR c.id=l.customer_id JOIN orders o ON o.customer_id=c.id
          UNION SELECT l.id, a.order_id FROM volta_leads l JOIN addresses a ON a.phone=l.telephone AND a.address_type='order_billing' AND a.order_id IS NOT NULL),
   m AS (SELECT l.id lead_id, MIN(CASE WHEN o.created_at>=l.created_at THEN o.created_at END) first_after,
                MIN(CASE WHEN o.created_at>=l.created_at AND (o.crm_order_status IN (5,99) OR o.crm_active=1) THEN o.created_at END) first_deal,
                SUM(o.created_at<l.created_at) before_n
         FROM volta_leads l JOIN lo ON lo.lead_id=l.id JOIN orders o ON o.id=lo.order_id GROUP BY l.id)
   SELECT cal.d, COUNT(l.id) leads, SUM(m.lead_id IS NOT NULL) matched, SUM(m.first_after IS NOT NULL) apps,
     SUM(m.first_after IS NOT NULL AND m.first_after < l.created_at + INTERVAL 30 DAY) apps30, SUM(m.first_deal IS NOT NULL) deals, SUM(m.before_n>0) before_
   FROM cal LEFT JOIN volta_leads l ON DATE(l.created_at)=cal.d LEFT JOIN m ON m.lead_id=l.id GROUP BY cal.d ORDER BY cal.d;" > "$S/mkt_conv.tsv"

echo "END=$TODAY"
wc -l "$S/mkt_statuses.tsv" "$S/mkt_status.tsv" "$S/mkt_step.tsv" "$S/mkt_city.tsv" "$S/mkt_conv.tsv" | sed "s#$S/##"

# ================ Operations — Applications / Committee ================
# Application status flow, underwriting/committee decisions. Status-change log real from 2026-09-01. Runs through today.
# from MONTH_START. Everything here is an aggregate (no row dumps, no customer data); build_ops.js turns the TSVs
# into ops_data.json and injects them into the HTML.
# Applications count for Sep 1-7 exactly: 987).
OPS_MONTH_START='2026-01-01'  # month series start (application rows exist for these months, status history does not)

# 1. Applications by application day x CURRENT crm_order_status x current crm_underwriter_status_id (state, not history).
#    Feeds: Applications by current status (day/month), the funnel by application date, underwriting outcome by application date.
Q "SELECT DATE(o.created_at) d, o.crm_order_status st, COALESCE(o.crm_underwriter_status_id,0) uw, COUNT(*) n
   FROM orders o WHERE DATE(o.created_at) BETWEEN '$OPS_MONTH_START' AND '$TODAY' GROUP BY d, st, uw ORDER BY d, st, uw;" > "$S/ops_apps_state.tsv"

# 2. Status-change events (flow) by day: from -> to, events and distinct applications. Source: crm_activity_log,
#    action 'installment.status_change', JSON metadata {from,to}. Real history from 2026-09-01 only.
Q "SELECT DATE(l.created_at) d, JSON_EXTRACT(l.metadata,'\$.from') f, JSON_EXTRACT(l.metadata,'\$.to') t, COUNT(*) n, COUNT(DISTINCT l.entity_id) apps
   FROM crm_activity_log l WHERE l.action='installment.status_change' AND DATE(l.created_at) BETWEEN '$CUTOVER' AND '$TODAY'
   GROUP BY d, f, t ORDER BY d, f, t;" > "$S/ops_flow.tsv"

# 3. Stock at the end of each day: applications whose LAST status-change event up to the end of D put them in status 8
#    (at committee) or 15 (returned for clarification). Today's figure equals the live orders.crm_order_status count (verified 4/4, 7/7).
Q "WITH RECURSIVE cal AS (SELECT DATE('$CUTOVER') d UNION ALL SELECT d + INTERVAL 1 DAY FROM cal WHERE d < DATE('$TODAY')),
   ev AS (SELECT id, entity_id, JSON_EXTRACT(metadata,'\$.to') t, created_at FROM crm_activity_log WHERE action='installment.status_change')
   SELECT cal.d, e.t status, COUNT(*) n FROM cal JOIN ev e ON e.created_at < cal.d + INTERVAL 1 DAY
   LEFT JOIN ev e2 ON e2.entity_id=e.entity_id AND e2.created_at < cal.d + INTERVAL 1 DAY AND (e2.created_at > e.created_at OR (e2.created_at=e.created_at AND e2.id>e.id))
   WHERE e2.id IS NULL AND e.t IN (8,15) GROUP BY cal.d, e.t ORDER BY cal.d, e.t;" > "$S/ops_queue.tsv"

# 4. Committee decisions per decider: every status change OUT of status 8 (at committee), by day, actor and outcome.
#    Decider = the log's actor (crm_users.id; role 6 = Underwriter). orders.crm_underwriter_id is NOT used: the CRM also
#    stamps it with whichever sales manager rejects a case before committee ('Underwriter assigned by deciding the case').
Q "SELECT DATE(l.created_at) d, l.actor_id, COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''), l.actor_name) actor, JSON_EXTRACT(l.metadata,'\$.to') t, COUNT(*) n
   FROM crm_activity_log l LEFT JOIN crm_users u ON u.id=l.actor_id
   WHERE l.action='installment.status_change' AND JSON_EXTRACT(l.metadata,'\$.from')=8 AND DATE(l.created_at) BETWEEN '$CUTOVER' AND '$TODAY'
   GROUP BY d, l.actor_id, actor, t ORDER BY d, l.actor_id, t;" > "$S/ops_uw.tsv"

# 5. Rejection reasons (status -> 6) by day and by the stage the case was rejected from; the reason is the text after
#    'მიზეზი: ' in the log summary (also stored in metadata.reason / orders.crm_reason). Free text is trimmed to 80 chars;
#    the build keeps the standard labels and buckets everything else as Other.
Q "SELECT DATE(l.created_at) d, JSON_EXTRACT(l.metadata,'\$.from') f,
     LEFT(TRIM(REPLACE(REPLACE(REPLACE(CASE WHEN l.summary LIKE '%მიზეზი: %' THEN SUBSTRING_INDEX(l.summary,'მიზეზი: ',-1) ELSE '' END, CHAR(10),' '), CHAR(13),' '), CHAR(9),' ')), 80) reason, COUNT(*) n
   FROM crm_activity_log l WHERE l.action='installment.status_change' AND JSON_EXTRACT(l.metadata,'\$.to')=6 AND DATE(l.created_at) BETWEEN '$CUTOVER' AND '$TODAY'
   GROUP BY d, f, reason ORDER BY d, f, n DESC;" > "$S/ops_reasons.tsv"

echo "END=$TODAY"
wc -l "$S/ops_apps_state.tsv" "$S/ops_flow.tsv" "$S/ops_queue.tsv" "$S/ops_uw.tsv" "$S/ops_reasons.tsv" | sed "s#$S/##"

# ================ Customers — Customers Analyze ================
# Demographic aggregates only — no PII leaves the database. Runs through today.
CUST_MONTH_START='2024-01-01'   # first month of the by-month series (applications before it are counted in the customer totals, not in the series)
CUST_DAY_START='2026-08-01'     # first day of the by-day series (real application timestamps from the 2026-08-31 cutover; before it, migrated dates)

# ---- one row per application (= orders row) with its demographic bands; identity = the personal ID typed on the
# application form (volta_application_data.personal_ID, 99.4% of orders), else the ID number on the customer account
# (covers the applications staff create directly in the CRM), else the account id, else the e-mail.
# The identity is only ever used inside the query (GROUP BY / window functions) — it is never written out.
# Age is derived from the form's birth_date (the customer's date_of_birth as fallback) — never from the ID number.
CUST_BASE="ad AS (SELECT order_id,
    MAX(CASE WHEN field_code='personal_ID' AND field_value<>'' THEN field_value END) pid,
    MAX(CASE WHEN field_code='gender' THEN field_value END) g,
    MAX(CASE WHEN field_code='birth_date' THEN field_value END) dob_raw,
    MAX(CASE WHEN field_code='employment_status' THEN field_value END) emp,
    MAX(CASE WHEN field_code='social_status' THEN field_value END) soc,
    MAX(CASE WHEN field_code='salary' THEN field_value END) sal,
    MAX(CASE WHEN field_code='about_us_source' THEN field_value END) src,
    MAX(CASE WHEN field_code='position' AND TRIM(field_value)<>'' THEN TRIM(field_value) END) pos
  FROM volta_application_data WHERE field_code IN ('personal_ID','gender','birth_date','employment_status','social_status','salary','about_us_source','position') GROUP BY order_id),
sh AS (SELECT order_id, MIN(NULLIF(NULLIF(TRIM(city),''),'N/A')) city FROM addresses WHERE address_type='order_shipping' GROUP BY order_id),
rt AS (SELECT c.id_number pid, MAX(pr.rating) rating FROM customers c JOIN crm_customer_profile pr ON pr.customer_id=c.id WHERE c.id_number IS NOT NULL AND c.id_number<>'' GROUP BY c.id_number),
app0 AS (SELECT o.id, o.created_at,
    COALESCE(ad.pid, NULLIF(c.id_number,''), CONCAT('c',o.customer_id), CONCAT('e',NULLIF(o.customer_email,'')), CONCAT('o',o.id)) ident,
    (o.crm_active=1 AND o.crm_order_status<>4) act,
    (o.crm_order_status IN (5,99) OR o.crm_close_type IS NOT NULL OR (o.crm_active=1 AND o.crm_order_status<>4)) loan,
    CASE WHEN ad.g IN ('მდედრ.','female','Female') OR (ad.g IS NULL AND c.gender IN ('მდედრ.','Female')) THEN 'Female'
         WHEN ad.g IN ('მამრ.','male','Male') OR (ad.g IS NULL AND c.gender IN ('მამრ.','Male')) THEN 'Male' END gender,
    CASE WHEN ad.dob_raw LIKE '____-__-__' THEN STR_TO_DATE(ad.dob_raw,'%Y-%m-%d')
         WHEN ad.dob_raw LIKE '__.__.____' THEN STR_TO_DATE(ad.dob_raw,'%d.%m.%Y')
         WHEN ad.dob_raw LIKE '__/__/____' THEN STR_TO_DATE(ad.dob_raw,'%d/%m/%Y')
         WHEN c.date_of_birth > '1900-01-01' THEN c.date_of_birth END dob,
    CASE WHEN a.city='Tbilisi' THEN 'თბილისი' ELSE a.city END city,
    CASE WHEN ad.emp='კერძო' THEN 'Private sector' WHEN ad.emp='თვითდასაქმებული' THEN 'Self-employed' WHEN ad.emp='საჯარო' THEN 'Public sector'
         WHEN ad.emp='დაუსაქმებელი' THEN 'Unemployed' WHEN ad.emp IS NOT NULL THEN 'Not specified' END emp,
    CASE WHEN ad.soc='დიასახლისი' THEN 'Housewife' WHEN ad.soc='სტუდენტი' THEN 'Student' WHEN ad.soc='პენსიონერი' THEN 'Pensioner'
         WHEN ad.soc IS NOT NULL THEN 'None of these' END soc,
    CASE WHEN ad.sal REGEXP '^[0-9]+([.][0-9]+)?$' THEN
           CASE WHEN ad.sal+0=0 THEN '0:0 (no salary)' WHEN ad.sal+0<500 THEN '1:< 500' WHEN ad.sal+0<1000 THEN '2:500 - 999' WHEN ad.sal+0<2000 THEN '3:1,000 - 1,999'
                WHEN ad.sal+0<3000 THEN '4:2,000 - 2,999' WHEN ad.sal+0<5000 THEN '5:3,000 - 4,999' ELSE '6:5,000+' END
         WHEN ad.sal='500-1000' THEN '2:500 - 999' WHEN ad.sal='1000_2000' THEN '3:1,000 - 1,999' WHEN ad.sal='2000-3000' THEN '4:2,000 - 2,999'
         WHEN ad.sal IN ('3000-4000','4000-5000') THEN '5:3,000 - 4,999' WHEN ad.sal='5000-მეტი' THEN '6:5,000+'
         WHEN ad.sal IS NOT NULL AND ad.sal<>'' THEN '7:Not specified' END sal,
    CASE WHEN ad.src IN ('Facebook','Instagram','TikTok','Google') THEN ad.src WHEN ad.src='მეგობრის / ახლობლის რეკომენდაციით' THEN 'Friend / family recommendation'
         WHEN ad.src='ადრეც ვსარგებლობდი ვოლტას განვადებით' THEN 'Used Volta before' WHEN ad.src='გარე სარეკლამო ბანერით' THEN 'Outdoor banner'
         WHEN ad.src IS NOT NULL AND ad.src<>'' THEN 'Other' END src,
    CASE WHEN o.crm_risk_status IN ('საშუალო','medium','Medium') THEN 'Medium' WHEN o.crm_risk_status IN ('დაბალი','low','Low') THEN 'Low'
         WHEN o.crm_risk_status IN ('მაღალი','high','High') THEN 'High' END risk,
    ad.pos, COALESCE(NULLIF(rt.rating,0), NULLIF(pr2.rating,0)) rating
  FROM orders o LEFT JOIN ad ON ad.order_id=o.id LEFT JOIN sh a ON a.order_id=o.id LEFT JOIN customers c ON c.id=o.customer_id
  LEFT JOIN rt ON rt.pid=COALESCE(ad.pid, NULLIF(c.id_number,'')) LEFT JOIN crm_customer_profile pr2 ON pr2.customer_id=o.customer_id),
app AS (SELECT a.*, ROW_NUMBER() OVER (PARTITION BY ident ORDER BY created_at, id) rn,
    COALESCE(SUM(loan) OVER (PARTITION BY ident ORDER BY created_at, id ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING),0) prior_loans,
    CASE WHEN dob IS NULL OR TIMESTAMPDIFF(YEAR,dob,created_at) NOT BETWEEN 14 AND 100 THEN NULL
         WHEN TIMESTAMPDIFF(YEAR,dob,created_at)<18 THEN '1:< 18' WHEN TIMESTAMPDIFF(YEAR,dob,created_at)<25 THEN '2:18 - 24' WHEN TIMESTAMPDIFF(YEAR,dob,created_at)<35 THEN '3:25 - 34'
         WHEN TIMESTAMPDIFF(YEAR,dob,created_at)<45 THEN '4:35 - 44' WHEN TIMESTAMPDIFF(YEAR,dob,created_at)<60 THEN '5:45 - 59' ELSE '6:60+' END age
  FROM app0 a),
topc AS (SELECT city FROM app WHERE city IS NOT NULL GROUP BY city ORDER BY COUNT(*) DESC LIMIT 15),
topp AS (SELECT pos FROM app WHERE pos IS NOT NULL GROUP BY pos ORDER BY COUNT(*) DESC LIMIT 15),
cust AS (SELECT ident, MAX(act) act, MAX(loan) has_loan, COUNT(*) apps, SUM(loan) loans, MAX(rating) rating, MIN(created_at) first_at,
    SUBSTRING_INDEX(GROUP_CONCAT(gender ORDER BY created_at DESC, id DESC SEPARATOR '|'),'|',1) gender,
    SUBSTRING_INDEX(GROUP_CONCAT(dob ORDER BY created_at DESC, id DESC SEPARATOR '|'),'|',1) dob,
    SUBSTRING_INDEX(GROUP_CONCAT(city ORDER BY created_at DESC, id DESC SEPARATOR '|'),'|',1) city,
    SUBSTRING_INDEX(GROUP_CONCAT(emp ORDER BY created_at DESC, id DESC SEPARATOR '|'),'|',1) emp,
    SUBSTRING_INDEX(GROUP_CONCAT(soc ORDER BY created_at DESC, id DESC SEPARATOR '|'),'|',1) soc,
    SUBSTRING_INDEX(GROUP_CONCAT(sal ORDER BY created_at DESC, id DESC SEPARATOR '|'),'|',1) sal,
    SUBSTRING_INDEX(GROUP_CONCAT(src ORDER BY created_at DESC, id DESC SEPARATOR '|'),'|',1) src,
    SUBSTRING_INDEX(GROUP_CONCAT(pos ORDER BY created_at DESC, id DESC SEPARATOR '|'),'|',1) pos,
    SUBSTRING_INDEX(GROUP_CONCAT(risk ORDER BY act DESC, created_at DESC, id DESC SEPARATOR '|'),'|',1) risk
  FROM app GROUP BY ident),
custb AS (SELECT c.*, CASE WHEN dob IS NULL OR TIMESTAMPDIFF(YEAR,dob,CURDATE()) NOT BETWEEN 14 AND 100 THEN NULL
         WHEN TIMESTAMPDIFF(YEAR,dob,CURDATE())<18 THEN '1:< 18' WHEN TIMESTAMPDIFF(YEAR,dob,CURDATE())<25 THEN '2:18 - 24' WHEN TIMESTAMPDIFF(YEAR,dob,CURDATE())<35 THEN '3:25 - 34'
         WHEN TIMESTAMPDIFF(YEAR,dob,CURDATE())<45 THEN '4:35 - 44' WHEN TIMESTAMPDIFF(YEAR,dob,CURDATE())<60 THEN '5:45 - 59' ELSE '6:60+' END age,
    COALESCE(t.city, CASE WHEN c.city IS NULL THEN NULL ELSE 'Other cities' END) cityg,
    COALESCE(p.pos, CASE WHEN c.pos IS NULL THEN NULL ELSE 'Other' END) posg
  FROM cust c LEFT JOIN topc t ON t.city=c.city LEFT JOIN topp p ON p.pos=c.pos)"

# 1) customer-level distribution per dimension: all customers / customers with an active loan (attribute = latest application's value)
Q "WITH $CUST_BASE
   SELECT 'gender' dim, gender val, COUNT(*) all_c, SUM(act) active_c, SUM(has_loan) loan_c FROM custb GROUP BY 2
   UNION ALL SELECT 'age', age, COUNT(*), SUM(act), SUM(has_loan) FROM custb GROUP BY 2
   UNION ALL SELECT 'city', cityg, COUNT(*), SUM(act), SUM(has_loan) FROM custb GROUP BY 2
   UNION ALL SELECT 'emp', emp, COUNT(*), SUM(act), SUM(has_loan) FROM custb GROUP BY 2
   UNION ALL SELECT 'soc', soc, COUNT(*), SUM(act), SUM(has_loan) FROM custb GROUP BY 2
   UNION ALL SELECT 'sal', sal, COUNT(*), SUM(act), SUM(has_loan) FROM custb GROUP BY 2
   UNION ALL SELECT 'src', src, COUNT(*), SUM(act), SUM(has_loan) FROM custb GROUP BY 2
   UNION ALL SELECT 'pos', posg, COUNT(*), SUM(act), SUM(has_loan) FROM custb GROUP BY 2
   UNION ALL SELECT 'risk', risk, COUNT(*), SUM(act), SUM(has_loan) FROM custb GROUP BY 2
   UNION ALL SELECT 'rating', rating, COUNT(*), SUM(act), SUM(has_loan) FROM custb GROUP BY 2
   ORDER BY 1, 2;" > "$S/cust_dims.tsv"

# 2) applications by calendar month per dimension (attribute = the application's own value; age at the application date)
Q "WITH $CUST_BASE
   SELECT 'gender' dim, gender val, DATE_FORMAT(created_at,'%Y-%m') m, COUNT(*) n FROM app WHERE created_at>='$CUST_MONTH_START' AND created_at < DATE('$TODAY') + INTERVAL 1 DAY GROUP BY 2,3
   UNION ALL SELECT 'age', age, DATE_FORMAT(created_at,'%Y-%m'), COUNT(*) FROM app WHERE created_at>='$CUST_MONTH_START' AND created_at < DATE('$TODAY') + INTERVAL 1 DAY GROUP BY 2,3
   UNION ALL SELECT 'city', COALESCE(t.city, CASE WHEN a.city IS NULL THEN NULL ELSE 'Other cities' END), DATE_FORMAT(a.created_at,'%Y-%m'), COUNT(*) FROM app a LEFT JOIN topc t ON t.city=a.city WHERE a.created_at>='$CUST_MONTH_START' AND a.created_at < DATE('$TODAY') + INTERVAL 1 DAY GROUP BY 2,3
   UNION ALL SELECT 'emp', emp, DATE_FORMAT(created_at,'%Y-%m'), COUNT(*) FROM app WHERE created_at>='$CUST_MONTH_START' AND created_at < DATE('$TODAY') + INTERVAL 1 DAY GROUP BY 2,3
   UNION ALL SELECT 'soc', soc, DATE_FORMAT(created_at,'%Y-%m'), COUNT(*) FROM app WHERE created_at>='$CUST_MONTH_START' AND created_at < DATE('$TODAY') + INTERVAL 1 DAY GROUP BY 2,3
   UNION ALL SELECT 'sal', sal, DATE_FORMAT(created_at,'%Y-%m'), COUNT(*) FROM app WHERE created_at>='$CUST_MONTH_START' AND created_at < DATE('$TODAY') + INTERVAL 1 DAY GROUP BY 2,3
   UNION ALL SELECT 'src', src, DATE_FORMAT(created_at,'%Y-%m'), COUNT(*) FROM app WHERE created_at>='$CUST_MONTH_START' AND created_at < DATE('$TODAY') + INTERVAL 1 DAY GROUP BY 2,3
   UNION ALL SELECT 'risk', risk, DATE_FORMAT(created_at,'%Y-%m'), COUNT(*) FROM app WHERE created_at>='$CUST_MONTH_START' AND created_at < DATE('$TODAY') + INTERVAL 1 DAY GROUP BY 2,3
   ORDER BY 1, 2, 3;" > "$S/cust_month.tsv"

# 3) new vs returning applicants — by month (from MONTH_START) and by day (from DAY_START through END)
#    New = the identity's first application ever; Returning (earlier application only) = had applied before but never had a loan;
#    Returning (previous loan) = had at least one earlier loan (crm_order_status 5/99 or a closed loan) before this application.
CUST_NR="CASE WHEN rn=1 THEN '1:New' WHEN prior_loans>0 THEN '3:Returning (previous loan)' ELSE '2:Returning (earlier application only)' END"
Q "WITH $CUST_BASE
   SELECT DATE_FORMAT(created_at,'%Y-%m') m, $CUST_NR type, COUNT(*) n, SUM(act) active_n, SUM(loan) loan_n FROM app
   WHERE created_at>='$CUST_MONTH_START' AND created_at < DATE('$TODAY') + INTERVAL 1 DAY GROUP BY 1,2 ORDER BY 1,2;" > "$S/cust_newret_month.tsv"
Q "WITH $CUST_BASE
   SELECT DATE(created_at) d, $CUST_NR type, COUNT(*) n, SUM(act) active_n, SUM(loan) loan_n FROM app
   WHERE created_at>='$CUST_DAY_START' AND created_at < DATE('$TODAY') + INTERVAL 1 DAY GROUP BY 1,2 ORDER BY 1,2;" > "$S/cust_newret_day.tsv"

# 4) applications / loans per customer (histogram) + totals
Q "WITH $CUST_BASE
   SELECT 'apps' kind, CASE WHEN apps>=5 THEN '5+' ELSE CAST(apps AS CHAR) END bucket, COUNT(*) n, SUM(act) active_c FROM cust GROUP BY 2
   UNION ALL SELECT 'loans', CASE WHEN loans>=5 THEN '5+' ELSE CAST(loans AS CHAR) END, COUNT(*), SUM(act) FROM cust GROUP BY 2
   ORDER BY 1,2;" > "$S/cust_hist.tsv"
Q "WITH $CUST_BASE
   SELECT COUNT(*) customers, SUM(act) active_customers, SUM(has_loan) loan_customers, SUM(apps) applications, SUM(loans) loans,
     SUM(first_at < '$CUST_MONTH_START') customers_before_series, (SELECT COUNT(*) FROM app WHERE created_at < '$CUST_MONTH_START') apps_before_series,
     (SELECT MIN(created_at) FROM app) first_app, (SELECT MAX(created_at) FROM app) last_app,
     (SELECT COUNT(*) FROM app WHERE ident LIKE 'c%' OR ident LIKE 'e%' OR ident LIKE 'o%') apps_without_pid,
     (SELECT SUM(act) FROM app) active_loans, (SELECT COUNT(*) FROM app WHERE created_at >= '2026-08-31') apps_since_cutover
   FROM cust;" > "$S/cust_summary.tsv"

# 5) payment behaviour (crm_customer_payment_stats — a per-customer cache the CRM computes when it opens a customer, so it covers
#    only part of the base; the build reports the coverage). worst_bucket 1..6 = max days late bands (decoded from max_days_late).
Q "SELECT 'worst' metric, CAST(COALESCE(worst_bucket,0) AS CHAR) val, COUNT(*) n FROM crm_customer_payment_stats GROUP BY 2
   UNION ALL SELECT 'overdue', CAST(currently_overdue AS CHAR), COUNT(*) FROM crm_customer_payment_stats GROUP BY 2
   UNION ALL SELECT 'dpd', CASE WHEN currently_overdue=0 OR current_dpd<=0 THEN '0:Not overdue' WHEN current_dpd<=7 THEN '1:1 - 7 days' WHEN current_dpd<=30 THEN '2:8 - 30 days' WHEN current_dpd<=60 THEN '3:31 - 60 days' WHEN current_dpd<=90 THEN '4:61 - 90 days' ELSE '5:90+ days' END, COUNT(*) FROM crm_customer_payment_stats GROUP BY 2
   UNION ALL SELECT 'ontime', CASE WHEN settled_count=0 THEN '9:No settled instalment yet' WHEN on_time_rate>=90 THEN '1:90%+' WHEN on_time_rate>=70 THEN '2:70 - 89%' WHEN on_time_rate>=50 THEN '3:50 - 69%' WHEN on_time_rate>=25 THEN '4:25 - 49%' ELSE '5:< 25%' END, COUNT(*) FROM crm_customer_payment_stats GROUP BY 2
   UNION ALL SELECT 'coverage', 'stats_rows', COUNT(*) FROM crm_customer_payment_stats
   UNION ALL SELECT 'coverage', 'active_customers_by_account', COUNT(DISTINCT customer_id) FROM orders WHERE crm_active=1 AND crm_order_status<>4 AND customer_id IS NOT NULL
   UNION ALL SELECT 'coverage', 'active_customers_with_stats', COUNT(DISTINCT o.customer_id) FROM orders o JOIN crm_customer_payment_stats p ON p.customer_id=o.customer_id WHERE o.crm_active=1 AND o.crm_order_status<>4
   UNION ALL SELECT 'coverage', 'computed_from', DATE_FORMAT(MIN(computed_at),'%Y-%m-%d') FROM crm_customer_payment_stats
   UNION ALL SELECT 'coverage', 'computed_to', DATE_FORMAT(MAX(computed_at),'%Y-%m-%d') FROM crm_customer_payment_stats;" > "$S/cust_paystats.tsv"

echo "END=$TODAY"
wc -l "$S"/cust_*.tsv | sed "s#$S/##"

# ================ Collections — Collections Analyze ================
# Cash collected, overdue portfolio (CRM DPD buckets), due-date performance, collection activity. Runs through today.
COLL_MSTART='2023-01-01'       # month series start: crm_installment_schedules covers every loan from 2023 on (2022: 97%), so due vs collected is complete from here

# Payment guards used everywhere: the migrated crm_payments table carries one 99,999,999.99 sentinel row (2026-08-31, same as
# the old DB's payments table), reversed payments are excluded from "collected", and one payment is dated in the future.
COLL_PAYOK="p.amount < 100000 AND p.reversed_at IS NULL"
# DPD buckets = the CRM's own dpd_bucket codes (crm_payment_events: 1 = 1-3 days, 2 = 4-10, 3 = 11-30, 4 = 31-60, 5 = 61-90, 6 = 90+)
COLL_BUCKET() { echo "CASE WHEN $1 <= 3 THEN 1 WHEN $1 <= 10 THEN 2 WHEN $1 <= 30 THEN 3 WHEN $1 <= 60 THEN 4 WHEN $1 <= 90 THEN 5 ELSE 6 END"; }

# ---- overdue portfolio at the end of each day (stock), reconstructed from the schedule + the post-cutover settlement events.
# A schedule row is overdue on day D when schedule_date < D (DPD = D - schedule_date >= 1) and it was not yet settled at the end
# of D: rows still open today (active = 1) were open on every earlier day; rows settled since the cutover have a
# crm_payment_events.schedule_settled event, so they count as open on the days before that event; rows settled before the
# cutover (active = 0, no event) are never overdue in this window. Outstanding as of D = schedule_amount - paid_amount today
# + the amounts applied by settle/partial events after D. Today's column therefore equals the live
# "active = 1 AND schedule_date < today" figures exactly (verified in the build). A settle event dated after END (one payment is
# future-dated, 2026-09-18) is treated as settled on END, so the row is open through END-1 and gone from today's column like it is live.
COLL_OD_CTE="WITH RECURSIVE cal AS (SELECT DATE('$CUTOVER') d UNION ALL SELECT d + INTERVAL 1 DAY FROM cal WHERE d < DATE('$TODAY')),
   ev AS (SELECT schedule_id, occurred_at, amount, event_type FROM crm_payment_events WHERE event_type IN ('schedule_settled','schedule_partial') AND schedule_id IS NOT NULL),
   se AS (SELECT schedule_id, LEAST(MIN(occurred_at), TIMESTAMP(DATE('$TODAY'))) settled_at FROM ev WHERE event_type='schedule_settled' GROUP BY schedule_id),
   r AS (SELECT s.id, s.installment_id, s.schedule_date, s.schedule_amount, s.paid_amount, s.active, se.settled_at, (o.crm_active=1) act, COALESCE(o.crm_portfolio_manager_id,0) pm
         FROM crm_installment_schedules s JOIN orders o ON o.id=s.installment_id LEFT JOIN se ON se.schedule_id=s.id
         WHERE s.schedule_date < DATE('$TODAY') AND (s.active=1 OR se.settled_at >= DATE('$CUTOVER'))),
   later AS (SELECT cal.d, e.schedule_id, SUM(e.amount) a FROM cal JOIN ev e ON e.occurred_at >= cal.d + INTERVAL 1 DAY GROUP BY cal.d, e.schedule_id),
   od AS (SELECT cal.d, r.installment_id, r.act, r.pm, DATEDIFF(cal.d, r.schedule_date) dpd, r.schedule_amount - r.paid_amount + COALESCE(l.a,0) outstanding
          FROM cal JOIN r ON r.schedule_date < cal.d AND (r.active=1 OR r.settled_at >= cal.d + INTERVAL 1 DAY)
          LEFT JOIN later l ON l.d=cal.d AND l.schedule_id=r.id)"
# schedule-row level: rows and amount per DPD bucket, split by whether the loan is active in the CRM (crm_active = 1)
Q "$COLL_OD_CTE SELECT d, act, $(COLL_BUCKET dpd) b, COUNT(*) rows_od, ROUND(SUM(outstanding),2) amt FROM od GROUP BY d, act, b ORDER BY d, act, b;" > "$S/coll_od_rows.tsv"
# loan level: each overdue loan once, in the bucket of its oldest overdue row, with its total overdue amount; by portfolio manager
Q "$COLL_OD_CTE, ln AS (SELECT d, act, pm, installment_id, MAX(dpd) dpd, SUM(outstanding) amt FROM od GROUP BY d, act, pm, installment_id)
   SELECT d, act, pm, $(COLL_BUCKET dpd) wb, COUNT(*) loans, ROUND(SUM(amt),2) amt FROM ln GROUP BY d, act, pm, wb ORDER BY d, act, pm, wb;" > "$S/coll_od_loans.tsv"

# ---- cash collected (flow) by payment_date: count, paying loans, amount and its parts (principal net of advance / advance /
# penalty; advance is a part of principal_part, so the three add up to amount) and by bank (crm_banks: 2 BOG, 1 TBC, 6 TBCPay)
COLL_PAYSEL="COUNT(*) n, COUNT(DISTINCT p.installment_id) loans, ROUND(SUM(p.amount),2) amt,
   ROUND(SUM(COALESCE(p.principal_part,0)-COALESCE(p.advance,0)),2) prin, ROUND(SUM(COALESCE(p.penalty_part,0)),2) pen, ROUND(SUM(COALESCE(p.advance,0)),2) adv,
   ROUND(SUM(CASE WHEN p.bank_id=2 THEN p.amount ELSE 0 END),2) bog, ROUND(SUM(CASE WHEN p.bank_id=1 THEN p.amount ELSE 0 END),2) tbc,
   ROUND(SUM(CASE WHEN p.bank_id=6 THEN p.amount ELSE 0 END),2) tbcpay, ROUND(SUM(CASE WHEN p.bank_id IN (1,2,6) THEN 0 ELSE p.amount END),2) other"
Q "SELECT p.payment_date d, $COLL_PAYSEL FROM crm_payments p WHERE $COLL_PAYOK AND p.payment_date BETWEEN '$CUTOVER' AND '$TODAY' GROUP BY d ORDER BY d;" > "$S/coll_pay_day.tsv"
Q "SELECT DATE_FORMAT(p.payment_date,'%Y-%m') m, $COLL_PAYSEL FROM crm_payments p WHERE $COLL_PAYOK AND p.payment_date BETWEEN '$COLL_MSTART' AND '$TODAY' GROUP BY m ORDER BY m;" > "$S/coll_pay_month.tsv"
# reversed payments by the day they were reversed (post-cutover only — the reversal trail starts with the CRM)
Q "SELECT DATE(p.reversed_at) d, COUNT(*) n, ROUND(SUM(p.amount),2) amt FROM crm_payments p WHERE p.amount < 100000 AND p.reversed_at IS NOT NULL AND DATE(p.reversed_at) BETWEEN '$CUTOVER' AND '$TODAY' GROUP BY d ORDER BY d;" > "$S/coll_reversed.tsv"

# ---- scheduled dues by due date (schedule_date). Day file (due dates cutover -> END) also carries the due-date performance,
# knowable only for due dates since the cutover: on time = settled on or before the due date (settled before the cutover with
# no event, or a settle event dated <= due date), late = settle event after the due date, open = still unpaid today.
Q "WITH se AS (SELECT schedule_id, MIN(occurred_at) settled_at FROM crm_payment_events WHERE event_type='schedule_settled' AND schedule_id IS NOT NULL GROUP BY schedule_id)
   SELECT s.schedule_date d, COUNT(*) rows_due, ROUND(SUM(s.schedule_amount),2) amt, COUNT(DISTINCT s.installment_id) loans,
     SUM(s.active=0 AND (se.settled_at IS NULL OR DATE(se.settled_at) <= s.schedule_date)) ontime_rows, ROUND(SUM(CASE WHEN s.active=0 AND (se.settled_at IS NULL OR DATE(se.settled_at) <= s.schedule_date) THEN s.schedule_amount ELSE 0 END),2) ontime_amt,
     SUM(s.active=0 AND DATE(se.settled_at) > s.schedule_date) late_rows, ROUND(SUM(CASE WHEN s.active=0 AND DATE(se.settled_at) > s.schedule_date THEN s.schedule_amount ELSE 0 END),2) late_amt,
     SUM(s.active=1) open_rows, ROUND(SUM(CASE WHEN s.active=1 THEN s.schedule_amount - s.paid_amount ELSE 0 END),2) open_amt
   FROM crm_installment_schedules s LEFT JOIN se ON se.schedule_id=s.id WHERE s.schedule_date BETWEEN '$CUTOVER' AND '$TODAY' GROUP BY d ORDER BY d;" > "$S/coll_due_day.tsv"
Q "SELECT DATE_FORMAT(s.schedule_date,'%Y-%m') m, COUNT(*) rows_due, ROUND(SUM(s.schedule_amount),2) amt, COUNT(DISTINCT s.installment_id) loans,
     SUM(s.active=1) open_rows, ROUND(SUM(CASE WHEN s.active=1 THEN s.schedule_amount - s.paid_amount ELSE 0 END),2) open_amt
   FROM crm_installment_schedules s WHERE s.schedule_date BETWEEN '$COLL_MSTART' AND '$TODAY' GROUP BY m ORDER BY m;" > "$S/coll_due_month.tsv"

# ---- collections activity (flow, by the day it happened), long format: d, metric, n, amt — all tables are live since the cutover
Q "SELECT DATE(c.created_at) d, CONCAT('call_', c.outcome) metric, COUNT(*) n, 0 amt FROM crm_case_calls c WHERE c.created_at >= '$CUTOVER' AND DATE(c.created_at) <= '$TODAY' GROUP BY d, metric
   UNION ALL SELECT DATE(c.created_at), CONCAT('chan_', COALESCE(c.channel,'none')), COUNT(*), 0 FROM crm_case_calls c WHERE c.created_at >= '$CUTOVER' AND DATE(c.created_at) <= '$TODAY' GROUP BY 1, 2
   UNION ALL SELECT DATE(c.created_at), 'call_loans', COUNT(DISTINCT c.installment_id), 0 FROM crm_case_calls c WHERE c.created_at >= '$CUTOVER' AND DATE(c.created_at) <= '$TODAY' GROUP BY 1
   UNION ALL SELECT DATE(a.created_at), CONCAT('status_', JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '\$.statusName'))), COUNT(*), 0 FROM crm_activity_log a WHERE a.action='collections.contact_status' AND a.created_at >= '$CUTOVER' AND DATE(a.created_at) <= '$TODAY' GROUP BY 1, 2
   UNION ALL SELECT DATE(h.created_at), CONCAT('sms_', h.status), COUNT(*), 0 FROM crm_sms_history h WHERE h.created_at >= '$CUTOVER' AND DATE(h.created_at) <= '$TODAY' GROUP BY 1, 2
   UNION ALL SELECT DATE(pr.created_at), 'promise_made', COUNT(*), ROUND(SUM(pr.amount),2) FROM crm_promises pr WHERE pr.created_at >= '$CUTOVER' AND DATE(pr.created_at) <= '$TODAY' GROUP BY 1
   UNION ALL SELECT DATE(a.created_at), 'promise_kept', COUNT(*), 0 FROM crm_activity_log a WHERE a.action='collections.promise_settled' AND a.created_at >= '$CUTOVER' AND DATE(a.created_at) <= '$TODAY' GROUP BY 1
   UNION ALL SELECT DATE(e.occurred_at), 'promise_broken', COUNT(*), 0 FROM crm_payment_events e WHERE e.event_type='promise_broken' AND e.occurred_at >= '$CUTOVER' AND DATE(e.occurred_at) <= '$TODAY' GROUP BY 1
   UNION ALL SELECT DATE(rr.created_at), 'restructure_requested', COUNT(*), ROUND(SUM(rr.total_amount),2) FROM crm_restructure_requests rr WHERE rr.created_at >= '$CUTOVER' AND DATE(rr.created_at) <= '$TODAY' GROUP BY 1
   UNION ALL SELECT DATE(rr.decided_at), CONCAT('restructure_', rr.status), COUNT(*), 0 FROM crm_restructure_requests rr WHERE rr.decided_at IS NOT NULL AND rr.decided_at >= '$CUTOVER' AND DATE(rr.decided_at) <= '$TODAY' GROUP BY 1, 2
   UNION ALL SELECT DATE(pa.created_at), CONCAT('pm_assigned_', pa.role), COUNT(*), 0 FROM crm_portfolio_assignments pa WHERE pa.created_at >= '$CUTOVER' AND DATE(pa.created_at) <= '$TODAY' GROUP BY 1, 2
   UNION ALL SELECT DATE(rm.reminder_date), 'reminder_due', COUNT(*), 0 FROM crm_reminders rm WHERE rm.reminder_date >= '$CUTOVER' AND DATE(rm.reminder_date) <= '$TODAY' GROUP BY 1
   UNION ALL SELECT DATE(rm.created_at), 'reminder_created', COUNT(*), 0 FROM crm_reminders rm WHERE rm.created_at >= '$CUTOVER' AND DATE(rm.created_at) <= '$TODAY' GROUP BY 1
   UNION ALL SELECT DATE(e.occurred_at), CONCAT('crm_', e.event_type), COUNT(*), 0 FROM crm_payment_events e WHERE e.event_type IN ('overdue_entered','overdue_cured','overdue_bucket_up','case_settled') AND e.occurred_at >= '$CUTOVER' AND DATE(e.occurred_at) <= '$TODAY' GROUP BY 1, 2
   ORDER BY 1, 2;" > "$S/coll_activity.tsv"

# ---- today's snapshots: portfolio managers, CRM loan status, the CRM's customer payment-stats cache
Q "SELECT COALESCE(o.crm_portfolio_manager_id,0) pm, COALESCE(CONCAT(u.first_name,' ',u.last_name),'Unassigned') name, SUM(o.crm_active=1) active_loans,
     COUNT(DISTINCT CASE WHEN s.open_rows > 0 THEN o.id END) open_loans, ROUND(SUM(COALESCE(s.outstanding,0)),2) outstanding
   FROM orders o LEFT JOIN crm_users u ON u.id=o.crm_portfolio_manager_id
   LEFT JOIN (SELECT installment_id, SUM(active=1) open_rows, SUM(CASE WHEN active=1 THEN schedule_amount-paid_amount ELSE 0 END) outstanding FROM crm_installment_schedules GROUP BY installment_id) s ON s.installment_id=o.id
   WHERE o.crm_active=1 OR s.open_rows > 0 GROUP BY pm, name ORDER BY pm;" > "$S/coll_pm.tsv"
Q "SELECT COALESCE(o.crm_status_id,0) sid, COALESCE(st.name,'(no status)') name, COUNT(DISTINCT o.id) loans, ROUND(SUM(s.schedule_amount-s.paid_amount),2) outstanding,
     COUNT(DISTINCT CASE WHEN s.schedule_date < DATE('$TODAY') THEN o.id END) od_loans, ROUND(SUM(CASE WHEN s.schedule_date < DATE('$TODAY') THEN s.schedule_amount-s.paid_amount ELSE 0 END),2) od_amt
   FROM orders o JOIN crm_installment_schedules s ON s.installment_id=o.id AND s.active=1 LEFT JOIN crm_installment_statuses st ON st.id=o.crm_status_id
   GROUP BY sid, name ORDER BY loans DESC;" > "$S/coll_status.tsv"
Q "SELECT COALESCE(worst_bucket,0) wb, COUNT(*) customers, SUM(currently_overdue=1) cur_od, ROUND(AVG(on_time_rate),1) on_time_rate, ROUND(AVG(avg_days_late),1) avg_days_late,
     SUM(promises_made) promises_made, SUM(promises_kept) promises_kept, SUM(promises_broken) promises_broken, ROUND(SUM(total_paid),2) total_paid, ROUND(SUM(total_penalty_paid),2) penalty_paid, MAX(computed_at) computed_at
   FROM crm_customer_payment_stats GROUP BY wb ORDER BY wb;" > "$S/coll_cps.tsv"
# row counts of the collections tables (for the "what is populated" note)
Q "SELECT 'crm_promises' t, COUNT(*) n FROM crm_promises UNION ALL SELECT 'crm_case_calls', COUNT(*) FROM crm_case_calls UNION ALL SELECT 'crm_call_interviews', COUNT(*) FROM crm_call_interviews
   UNION ALL SELECT 'crm_collection_items', COUNT(*) FROM crm_collection_items UNION ALL SELECT 'crm_portfolio_assignments', COUNT(*) FROM crm_portfolio_assignments UNION ALL SELECT 'crm_loan_marks', COUNT(*) FROM crm_loan_marks
   UNION ALL SELECT 'crm_loan_comments', COUNT(*) FROM crm_loan_comments UNION ALL SELECT 'crm_tasks', COUNT(*) FROM crm_tasks UNION ALL SELECT 'crm_loans', COUNT(*) FROM crm_loans UNION ALL SELECT 'crm_charges', COUNT(*) FROM crm_charges
   UNION ALL SELECT 'crm_restructure_requests', COUNT(*) FROM crm_restructure_requests UNION ALL SELECT 'crm_reminders', COUNT(*) FROM crm_reminders UNION ALL SELECT 'crm_sms_history', COUNT(*) FROM crm_sms_history
   UNION ALL SELECT 'crm_customer_payment_stats', COUNT(*) FROM crm_customer_payment_stats UNION ALL SELECT 'crm_payment_events', COUNT(*) FROM crm_payment_events UNION ALL SELECT 'crm_payments', COUNT(*) FROM crm_payments
   UNION ALL SELECT 'crm_installment_schedules', COUNT(*) FROM crm_installment_schedules;" > "$S/coll_meta.tsv"

echo "END=$TODAY"
wc -l "$S"/coll_*.tsv | sed "s#$S/##"

# ================ Portfolio — Portfolio Analyze ================
# Loan book size/flows/structure/vintages. Runs through today.
PF_MSTART='2025-01-01'       # month series / structure tables start here (see README: 2025 is the first year fully covered)
# Segment A = phone/TV product name, or order amount > 2,500 GEL (same rule as pull_new.sh)

# ---- the loan book: one row per installment loan that was ever disbursed (crm_order_status 5, or 1 = the same status on
# rows migrated from the old CRM), i.e. active now (crm_active=1) or inactive (crm_active=0: closed). Pending / rejected /
# cancelled applications (statuses 4, 6, 7, 12, 13, ... and crm_active=-1) are not loans and are left out; single-payment
# sales (status 99) are not loans either (memo row only).
#   disb      = crm_creator_date (disbursement / sale date; exact from the cutover, drifts by hours-days on migrated rows)
#   amount    = the full installment amount the customer pays (schedule total, + advance when the schedule is net of it — the
#               Daily Mail "Amount Sold" rule); price = product price (base_grand_total)
#   rem       = remaining balance today = SUM(schedule_amount - paid_amount) (equals orders.grand_total on all but ~100 loans)
#   term      = number of monthly schedule rows (rows dated more than a week after disbursement — the first row of a migrated
#               11-row schedule is the advance, posted on the disbursement day)
#   kind      = active | paid (crm_close_type 1) | wo (written off, crm_close_type 2) | norec (inactive with no close record:
#               the old CRM stopped recording closes in June 2026; these loans have a fully paid schedule)
#   closed_at = crm_close_date when it is sane (not before disbursement, not a 1924/1970 placeholder), else the last
#               non-reversed payment date (closes are posted within 3 days of the last payment on 87% of recorded closes)
PF_BOOK="sched AS (SELECT s.installment_id, SUM(s.schedule_amount) tot, SUM(s.schedule_amount - s.paid_amount) rem, SUM(DATEDIFF(s.schedule_date, DATE(o.crm_creator_date)) > 7) term
        FROM crm_installment_schedules s JOIN orders o ON o.id = s.installment_id GROUP BY s.installment_id),
 pay AS (SELECT installment_id, MAX(payment_date) last_pay FROM crm_payments WHERE reversed_at IS NULL GROUP BY installment_id),
 book AS (SELECT o.id, o.crm_creator_date disb, o.base_grand_total price, COALESCE(o.crm_advance_amount, 0) adv,
     CASE WHEN s.tot IS NULL THEN o.base_grand_total WHEN s.tot + COALESCE(o.crm_advance_amount, 0) <= o.base_grand_total + 0.01 THEN s.tot + COALESCE(o.crm_advance_amount, 0) ELSE s.tot END amount,
     COALESCE(s.rem, o.grand_total) rem, COALESCE(s.term, 0) term, o.crm_remaining_months rm, COALESCE(o.crm_risk_status, '') risk,
     CASE WHEN o.crm_active = 1 THEN 'active' WHEN o.crm_close_type = 2 THEN 'wo' WHEN o.crm_close_type = 1 THEN 'paid' ELSE 'norec' END kind,
     CASE WHEN o.crm_active = 1 THEN NULL WHEN o.crm_close_type IN (1, 2) AND o.crm_close_date >= o.crm_creator_date AND YEAR(o.crm_close_date) >= 2019 THEN o.crm_close_date
          ELSE COALESCE(p.last_pay, o.crm_close_date, o.crm_creator_date) END closed_at,
     $SEG seg,
     CASE WHEN a.city IS NULL OR a.city = '' THEN 'Without City' WHEN a.city IN ('თბილისი', 'Tbilisi') THEN 'Tbilisi' ELSE 'Other Cities' END city,
     (SELECT oi.product_id FROM order_items oi WHERE oi.order_id = o.id ORDER BY oi.base_total DESC, oi.id LIMIT 1) top_product_id
   FROM orders o LEFT JOIN sched s ON s.installment_id = o.id LEFT JOIN pay p ON p.installment_id = o.id
   LEFT JOIN (SELECT order_id, MIN(city) city FROM addresses WHERE address_type = 'order_shipping' GROUP BY order_id) a ON a.order_id = o.id
   WHERE o.crm_order_status IN (1, 5) AND o.crm_active IN (0, 1) AND o.crm_creator_date IS NOT NULL)"

# ---- stocks at the end of each day (cutover .. END) and each month-end (MSTART .. END, last point = END itself = MTD)
# active = loans disbursed by the end of D and not closed by then; rem_now = their remaining balance TODAY; paid_after = principal
# they paid after D (non-reversed payments; one 99,999,999.99 placeholder payment is excluded) — balance at D = rem_now + paid_after (exact for today, reconstructed for earlier days)
PF_STOCK="act AS (SELECT cal.d, b.id, b.rem, b.amount, b.price FROM cal JOIN book b ON b.disb < cal.d + INTERVAL 1 DAY AND (b.closed_at IS NULL OR b.closed_at >= cal.d + INTERVAL 1 DAY)),
 pa AS (SELECT a.d, SUM(p.principal_part) paid_after FROM act a JOIN crm_payments p ON p.installment_id = a.id AND p.reversed_at IS NULL AND p.amount < 1000000 AND p.payment_date > a.d AND p.payment_date <= DATE('$TODAY') GROUP BY a.d)
 SELECT a.d, COUNT(*) n, ROUND(SUM(a.rem), 2) rem_now, ROUND(SUM(a.amount), 2) contract, ROUND(SUM(a.price), 2) price, ROUND(COALESCE(MAX(pa.paid_after), 0), 2) paid_after
 FROM act a LEFT JOIN pa ON pa.d = a.d GROUP BY a.d ORDER BY a.d"
Q "WITH RECURSIVE cal AS (SELECT DATE('$CUTOVER') d UNION ALL SELECT d + INTERVAL 1 DAY FROM cal WHERE d < DATE('$TODAY')), $PF_BOOK, $PF_STOCK;" > "$S/pf_stock_day.tsv"
Q "WITH RECURSIVE cal AS (SELECT LEAST(LAST_DAY(DATE('$PF_MSTART')), DATE('$TODAY')) d UNION ALL SELECT LEAST(LAST_DAY(d + INTERVAL 1 DAY), DATE('$TODAY')) FROM cal WHERE d < DATE('$TODAY')), $PF_BOOK, $PF_STOCK;" > "$S/pf_stock_month.tsv"

# ---- flows by day (MSTART .. END; build_pf.js rolls them up to months): disbursed loans keyed to the disbursement date,
# closed loans keyed to closed_at, by kind. term_sum / adv_n / adv_sum feed the averages; rem = balance left on the closed loans
Q "WITH $PF_BOOK SELECT DATE(b.disb) d, COUNT(*) n, ROUND(SUM(b.amount), 2) amount, ROUND(SUM(b.price), 2) price, SUM(b.term) term_sum, SUM(b.adv > 0) adv_n, ROUND(SUM(b.adv), 2) adv_sum, SUM(b.seg = 'A') seg_a
   FROM book b WHERE b.disb >= '$PF_MSTART' AND DATE(b.disb) <= '$TODAY' GROUP BY d ORDER BY d;" > "$S/pf_disb_day.tsv"
Q "WITH $PF_BOOK SELECT DATE(b.closed_at) d, b.kind, COUNT(*) n, ROUND(SUM(b.amount), 2) amount, ROUND(SUM(b.price), 2) price, ROUND(SUM(b.rem), 2) rem
   FROM book b WHERE b.kind <> 'active' AND b.closed_at >= '$PF_MSTART' AND DATE(b.closed_at) <= '$TODAY' GROUP BY d, b.kind ORDER BY d, b.kind;" > "$S/pf_close_day.tsv"
# single-payment sales (crm_order_status 99) — memo row under the disbursement tables, not part of the loan book
Q "SELECT DATE(o.crm_creator_date) d, COUNT(*) n, ROUND(SUM(o.base_grand_total), 2) price FROM orders o WHERE o.crm_order_status = 99 AND o.crm_creator_date >= '$PF_MSTART' AND DATE(o.crm_creator_date) <= '$TODAY' GROUP BY d ORDER BY d;" > "$S/pf_single_day.tsv"

# ---- structure of the loans disbursed each month (MSTART .. END), long format: dim, month, key, n, amount, rem (balance left today), act (still active)
#   term (months) | amt (contract amount band) | seg (A/B) | city | risk (crm_risk_status, raw) | goods (highest-value product id; build_pf.js maps it to a category) | kind (vintage: active/paid/wo/norec)
Q "WITH $PF_BOOK, m AS (SELECT b.*, DATE_FORMAT(b.disb, '%Y-%m') mo FROM book b WHERE b.disb >= '$PF_MSTART' AND DATE(b.disb) <= '$TODAY')
   SELECT 'term' dim, mo, CAST(term AS CHAR) COLLATE utf8mb4_unicode_ci k, COUNT(*) n, ROUND(SUM(amount), 2) amount, ROUND(SUM(rem), 2) rem, SUM(kind = 'active') act FROM m GROUP BY mo, k
   UNION ALL SELECT 'amt', mo, CASE WHEN amount < 500 THEN '1' WHEN amount < 1000 THEN '2' WHEN amount < 2000 THEN '3' WHEN amount < 3000 THEN '4' WHEN amount < 5000 THEN '5' ELSE '6' END COLLATE utf8mb4_unicode_ci, COUNT(*), ROUND(SUM(amount), 2), ROUND(SUM(rem), 2), SUM(kind = 'active') FROM m GROUP BY 2, 3
   UNION ALL SELECT 'seg', mo, seg COLLATE utf8mb4_unicode_ci, COUNT(*), ROUND(SUM(amount), 2), ROUND(SUM(rem), 2), SUM(kind = 'active') FROM m GROUP BY 2, 3
   UNION ALL SELECT 'city', mo, city COLLATE utf8mb4_unicode_ci, COUNT(*), ROUND(SUM(amount), 2), ROUND(SUM(rem), 2), SUM(kind = 'active') FROM m GROUP BY 2, 3
   UNION ALL SELECT 'risk', mo, risk COLLATE utf8mb4_unicode_ci, COUNT(*), ROUND(SUM(amount), 2), ROUND(SUM(rem), 2), SUM(kind = 'active') FROM m GROUP BY 2, 3
   UNION ALL SELECT 'goods', mo, CAST(top_product_id AS CHAR) COLLATE utf8mb4_unicode_ci, COUNT(*), ROUND(SUM(amount), 2), ROUND(SUM(rem), 2), SUM(kind = 'active') FROM m GROUP BY 2, 3
   UNION ALL SELECT 'kind', mo, kind COLLATE utf8mb4_unicode_ci, COUNT(*), ROUND(SUM(amount), 2), ROUND(SUM(rem), 2), SUM(kind = 'active') FROM m GROUP BY 2, 3
   ORDER BY 1, 2, 3;" > "$S/pf_struct.tsv"

# ---- the active book today, long format: dim, key, n, amount, rem
#   rm = crm_remaining_months | age = full months since disbursement | risk | term | amt band | seg | city | goods (product id)
Q "WITH $PF_BOOK, a AS (SELECT b.* FROM book b WHERE b.kind = 'active')
   SELECT 'rm' dim, CAST(COALESCE(rm, -1) AS CHAR) COLLATE utf8mb4_unicode_ci k, COUNT(*) n, ROUND(SUM(amount), 2) amount, ROUND(SUM(rem), 2) rem FROM a GROUP BY k
   UNION ALL SELECT 'age', CAST(TIMESTAMPDIFF(MONTH, disb, DATE('$TODAY')) AS CHAR) COLLATE utf8mb4_unicode_ci, COUNT(*), ROUND(SUM(amount), 2), ROUND(SUM(rem), 2) FROM a GROUP BY 2
   UNION ALL SELECT 'risk', risk COLLATE utf8mb4_unicode_ci, COUNT(*), ROUND(SUM(amount), 2), ROUND(SUM(rem), 2) FROM a GROUP BY 2
   UNION ALL SELECT 'term', CAST(term AS CHAR) COLLATE utf8mb4_unicode_ci, COUNT(*), ROUND(SUM(amount), 2), ROUND(SUM(rem), 2) FROM a GROUP BY 2
   UNION ALL SELECT 'amt', CASE WHEN amount < 500 THEN '1' WHEN amount < 1000 THEN '2' WHEN amount < 2000 THEN '3' WHEN amount < 3000 THEN '4' WHEN amount < 5000 THEN '5' ELSE '6' END COLLATE utf8mb4_unicode_ci, COUNT(*), ROUND(SUM(amount), 2), ROUND(SUM(rem), 2) FROM a GROUP BY 2
   UNION ALL SELECT 'seg', seg COLLATE utf8mb4_unicode_ci, COUNT(*), ROUND(SUM(amount), 2), ROUND(SUM(rem), 2) FROM a GROUP BY 2
   UNION ALL SELECT 'city', city COLLATE utf8mb4_unicode_ci, COUNT(*), ROUND(SUM(amount), 2), ROUND(SUM(rem), 2) FROM a GROUP BY 2
   UNION ALL SELECT 'goods', CAST(top_product_id AS CHAR) COLLATE utf8mb4_unicode_ci, COUNT(*), ROUND(SUM(amount), 2), ROUND(SUM(rem), 2) FROM a GROUP BY 2
   ORDER BY 1, 2;" > "$S/pf_book.tsv"

# ---- data-quality memo (whole book): how each kind is recorded, and the loans the rules had to repair
Q "WITH $PF_BOOK SELECT b.kind, COUNT(*) n, SUM(b.closed_at IS NULL) no_close_at, ROUND(SUM(b.rem), 2) rem, SUM(b.rem <= 1) fully_paid, MIN(DATE(b.disb)) first_disb, MAX(DATE(b.disb)) last_disb, MIN(DATE(b.closed_at)) first_close, MAX(DATE(b.closed_at)) last_close FROM book b GROUP BY b.kind ORDER BY b.kind;" > "$S/pf_quality.tsv"
Q "SELECT SUM(o.crm_close_type IN (1,2) AND (o.crm_close_date < o.crm_creator_date OR YEAR(o.crm_close_date) < 2019)) bad_close_dates, SUM(o.crm_active = 1 AND o.crm_order_status = 5 AND o.grand_total <= 0) active_zero_balance, SUM(o.crm_order_status = 99) single_all, SUM(o.crm_close_type IN (1,2) AND o.crm_close_date >= '$CUTOVER') closes_since_cutover FROM orders o;" > "$S/pf_quality2.tsv"

echo "END=$TODAY"
wc -l "$S"/pf_*.tsv | sed "s#$S/##"

echo "END=$END"
wc -l "$S"/new_*.tsv "$S"/logi_*.tsv "$S"/mkt_*.tsv "$S"/ops_*.tsv "$S"/cust_*.tsv "$S"/coll_*.tsv "$S"/pf_*.tsv | sed "s#$S/##"
