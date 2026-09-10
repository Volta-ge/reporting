#!/usr/bin/env bash
# Old-DB (myvolta.info) extracts for Volta_Analytics_New DB — the FROZEN pre-cutover history (2026-01-01 .. 2026-08-30).
# These never change; run only if the old_*.tsv files are lost.   (needs ./config.sh — see config.example.sh)
set -euo pipefail
S="$(cd "$(dirname "$0")" && pwd)"; source "$S/config.sh"
FROM='2026-01-01'; TO='2026-08-30'
export MYSQL_PWD="$OLDDB_PWD"
Q() { "$MYSQL_BIN" -h "$OLDDB_HOST" -P 3306 -u "$OLDDB_USER" -D "$OLDDB_NAME" --default-character-set=utf8mb4 --connect-timeout=20 -e "$1"; }
SEG="CASE WHEN p.Model LIKE 'ტელეფონი%' OR p.Model LIKE 'ტელევიზორ%' OR i.Full_Cost>2500 THEN 'A' ELSE 'B' END"

# Deals Closed / Amount Sold — Order_Date, Active = 1, Full_Cost
Q "SELECT DATE(i.Order_Date) d, $SEG seg, COUNT(*) deals, SUM(i.Full_Cost) amount
   FROM instalments i LEFT JOIN products p ON p.Product_ID=i.Product_ID
   WHERE i.Active=1 AND DATE(i.Order_Date) BETWEEN '$FROM' AND '$TO' GROUP BY d, seg ORDER BY d, seg;" > "$S/old_daily_seg.tsv"

# Applications / Terms / Underwriting / Downpayment — Aplication_Date, excluding the Product_ID = 1 "lead" placeholder rows
Q "SELECT DATE(i.Aplication_Date) d, $SEG seg, COUNT(*) applications, SUM(i.UnderWriter_Status_ID<>0) terms,
   SUM(i.UnderWriter_Status_ID=16) uw, ROUND(SUM(i.First_Payment),2) dp
   FROM instalments i LEFT JOIN products p ON p.Product_ID=i.Product_ID
   WHERE i.Product_ID > 1 AND DATE(i.Aplication_Date) BETWEEN '$FROM' AND '$TO' GROUP BY d, seg ORDER BY d, seg;" > "$S/old_daily_apps.tsv"

# Sales Analyze: real sales = Order_Status 5 (installment) or Order_Status IN (1,3) with Type_Of_Sales 99 (single payment)
Q "SELECT DATE_FORMAT(COALESCE(i.Order_Date,i.Aplication_Date),'%Y-%m') period, COALESCE(pc.Category_Name,'') category, COALESCE(pb.Brand_Name,'') brand,
   CASE WHEN i.Type_Of_Sales=99 THEN 'single' ELSE 'installment' END deal_type, SUM(ip.Final_Price) sales, SUM(ip.Start_Price) cogs, COUNT(*) qty
   FROM instalment_products ip JOIN instalments i ON i.Instalment_ID=ip.Instalment_ID
   JOIN products p ON p.Product_ID=ip.Product_ID LEFT JOIN product_category pc ON pc.Category_ID=p.Category_ID LEFT JOIN product_brands pb ON pb.Brand_ID=p.Brand_ID
   WHERE (i.Order_Status=5 OR (i.Order_Status IN (1,3) AND i.Type_Of_Sales=99)) AND DATE(COALESCE(i.Order_Date,i.Aplication_Date)) BETWEEN '$FROM' AND '$TO'
   GROUP BY period, category, brand, deal_type ORDER BY period, category, brand;" > "$S/old_sales_grouped.tsv"

# line items for the Cogs data-quality (placeholder/outlier) statistics
Q "SELECT ip.Product_ID product_id, ip.Start_Price start_price, ip.Final_Price final_price, CASE WHEN i.Type_Of_Sales=99 THEN 'single' ELSE 'installment' END deal_type
   FROM instalment_products ip JOIN instalments i ON i.Instalment_ID=ip.Instalment_ID
   WHERE (i.Order_Status=5 OR (i.Order_Status IN (1,3) AND i.Type_Of_Sales=99)) AND DATE(COALESCE(i.Order_Date,i.Aplication_Date)) BETWEEN '$FROM' AND '$TO';" > "$S/old_sales_lines.tsv"

wc -l "$S"/old_*.tsv | sed "s#$S/##"
