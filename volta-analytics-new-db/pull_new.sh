#!/usr/bin/env bash
# New-DB (VoltaStoreDB) extracts for Volta_Analytics_New DB, cutover (2026-08-31) through END (default: yesterday).
#   usage: bash pull_new.sh [YYYY-MM-DD]      (needs ./config.sh — see config.example.sh)
set -euo pipefail
S="$(cd "$(dirname "$0")" && pwd)"; source "$S/config.sh"
END="${1:-$(date -d 'yesterday' +%F 2>/dev/null || date -v-1d +%F)}"
CUTOVER='2026-08-31'
export MYSQL_PWD="$NEWDB_PWD"
Q() { "$MYSQL_BIN" -h "$NEWDB_HOST" -P 3306 -u "$NEWDB_USER" -D "$NEWDB_NAME" --default-character-set=utf8mb4 --connect-timeout=20 -e "$1"; }
# Segment A = phone/TV product name, or order amount > 2,500 GEL.
# base_grand_total is the fixed sale amount; grand_total is the remaining balance and shrinks as payments post.
SEG="CASE WHEN EXISTS (SELECT 1 FROM order_items oi JOIN product_flat pf ON pf.product_id=oi.product_id AND pf.locale='ka_GE' WHERE oi.order_id=o.id AND (pf.name LIKE 'ტელეფონი%' OR pf.name LIKE 'ტელევიზორ%')) OR o.base_grand_total>2500 THEN 'A' ELSE 'B' END"

# Deals Closed / Amount Sold — keyed to the order (disbursement) date, active orders only.
# amount = the full installment amount the customer pays (product price + financing markup) = the payment
# schedule total; the advance is added only when the schedule was built net of it (schedule + advance <= price),
# because in the other convention the advance is already posted against the first schedule row.
# price = product price (base_grand_total), kept for reference / the Segment A rule.
Q "SELECT DATE(o.crm_creator_date) d, $SEG seg, COUNT(*) deals,
   SUM(CASE WHEN s.tot IS NULL THEN o.base_grand_total
            WHEN s.tot + COALESCE(o.crm_advance_amount,0) <= o.base_grand_total + 0.01 THEN s.tot + COALESCE(o.crm_advance_amount,0)
            ELSE s.tot END) amount,
   SUM(o.base_grand_total) price, SUM(s.tot IS NULL) no_schedule
   FROM orders o LEFT JOIN (SELECT installment_id, SUM(schedule_amount) tot FROM crm_installment_schedules GROUP BY installment_id) s ON s.installment_id=o.id
   WHERE o.crm_active=1 AND DATE(o.crm_creator_date) BETWEEN '$CUTOVER' AND '$END' GROUP BY d, seg ORDER BY d, seg;" > "$S/new_daily_seg.tsv"

# Applications / Terms / Underwriting / Downpayment — keyed to the application date (created_at)
Q "SELECT DATE(o.created_at) d, $SEG seg, COUNT(*) applications, SUM(o.crm_underwriter_status_id IS NOT NULL) terms,
   SUM(o.crm_underwriter_status_id=16) uw, ROUND(SUM(COALESCE(o.crm_advance_amount,0)),2) dp
   FROM orders o WHERE DATE(o.created_at) BETWEEN '$CUTOVER' AND '$END' GROUP BY d, seg ORDER BY d, seg;" > "$S/new_daily_apps.tsv"

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
TODAY="$(date +%F)"
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

echo "END=$END"
wc -l "$S/new_daily_seg.tsv" "$S/new_daily_apps.tsv" "$S/new_sales_lines.tsv" "$S/logi_pending.tsv" "$S/logi_delivery.tsv" "$S/logi_orders.tsv" "$S/logi_status.tsv" | sed "s#$S/##"
