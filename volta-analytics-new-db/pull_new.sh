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

# Deals Closed / Amount Sold — keyed to the order (disbursement) date, active orders only
Q "SELECT DATE(o.crm_creator_date) d, $SEG seg, COUNT(*) deals, SUM(o.base_grand_total) amount
   FROM orders o WHERE o.crm_active=1 AND DATE(o.crm_creator_date) BETWEEN '$CUTOVER' AND '$END' GROUP BY d, seg ORDER BY d, seg;" > "$S/new_daily_seg.tsv"

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

echo "END=$END"
wc -l "$S/new_daily_seg.tsv" "$S/new_daily_apps.tsv" "$S/new_sales_lines.tsv" | sed "s#$S/##"
