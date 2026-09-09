#!/usr/bin/env bash
# Customers Analyze extracts (VoltaStoreDB) for Volta_Analytics_New DB — aggregates only, no PII leaves the database.
#   usage: bash pull_cust.sh [YYYY-MM-DD]      (END = last day of the by-day series, default today; needs ./config.sh)
# Writes cust_*.tsv next to this script; build_cust.js turns them into cust_data.json and injects the tab into the HTML.
set -euo pipefail
S="$(cd "$(dirname "$0")" && pwd)"; source "$S/config.sh"
END="${1:-$(date +%F)}"
export MYSQL_PWD="$NEWDB_PWD"
Q() { "$MYSQL_BIN" -h "$NEWDB_HOST" -P 3306 -u "$NEWDB_USER" -D "$NEWDB_NAME" --default-character-set=utf8mb4 --connect-timeout=20 -e "$1"; }
MONTH_START='2024-01-01'   # first month of the by-month series (applications before it are counted in the customer totals, not in the series)
DAY_START='2026-08-01'     # first day of the by-day series (real application timestamps from the 2026-08-31 cutover; before it, migrated dates)

# ---- one row per application (= orders row) with its demographic bands; identity = the personal ID typed on the
# application form (volta_application_data.personal_ID, 99.4% of orders), else the ID number on the customer account
# (covers the applications staff create directly in the CRM), else the account id, else the e-mail.
# The identity is only ever used inside the query (GROUP BY / window functions) — it is never written out.
# Age is derived from the form's birth_date (the customer's date_of_birth as fallback) — never from the ID number.
BASE="ad AS (SELECT order_id,
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
Q "WITH $BASE
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
Q "WITH $BASE
   SELECT 'gender' dim, gender val, DATE_FORMAT(created_at,'%Y-%m') m, COUNT(*) n FROM app WHERE created_at>='$MONTH_START' AND created_at < DATE('$END') + INTERVAL 1 DAY GROUP BY 2,3
   UNION ALL SELECT 'age', age, DATE_FORMAT(created_at,'%Y-%m'), COUNT(*) FROM app WHERE created_at>='$MONTH_START' AND created_at < DATE('$END') + INTERVAL 1 DAY GROUP BY 2,3
   UNION ALL SELECT 'city', COALESCE(t.city, CASE WHEN a.city IS NULL THEN NULL ELSE 'Other cities' END), DATE_FORMAT(a.created_at,'%Y-%m'), COUNT(*) FROM app a LEFT JOIN topc t ON t.city=a.city WHERE a.created_at>='$MONTH_START' AND a.created_at < DATE('$END') + INTERVAL 1 DAY GROUP BY 2,3
   UNION ALL SELECT 'emp', emp, DATE_FORMAT(created_at,'%Y-%m'), COUNT(*) FROM app WHERE created_at>='$MONTH_START' AND created_at < DATE('$END') + INTERVAL 1 DAY GROUP BY 2,3
   UNION ALL SELECT 'soc', soc, DATE_FORMAT(created_at,'%Y-%m'), COUNT(*) FROM app WHERE created_at>='$MONTH_START' AND created_at < DATE('$END') + INTERVAL 1 DAY GROUP BY 2,3
   UNION ALL SELECT 'sal', sal, DATE_FORMAT(created_at,'%Y-%m'), COUNT(*) FROM app WHERE created_at>='$MONTH_START' AND created_at < DATE('$END') + INTERVAL 1 DAY GROUP BY 2,3
   UNION ALL SELECT 'src', src, DATE_FORMAT(created_at,'%Y-%m'), COUNT(*) FROM app WHERE created_at>='$MONTH_START' AND created_at < DATE('$END') + INTERVAL 1 DAY GROUP BY 2,3
   UNION ALL SELECT 'risk', risk, DATE_FORMAT(created_at,'%Y-%m'), COUNT(*) FROM app WHERE created_at>='$MONTH_START' AND created_at < DATE('$END') + INTERVAL 1 DAY GROUP BY 2,3
   ORDER BY 1, 2, 3;" > "$S/cust_month.tsv"

# 3) new vs returning applicants — by month (from MONTH_START) and by day (from DAY_START through END)
#    New = the identity's first application ever; Returning (earlier application only) = had applied before but never had a loan;
#    Returning (previous loan) = had at least one earlier loan (crm_order_status 5/99 or a closed loan) before this application.
NR="CASE WHEN rn=1 THEN '1:New' WHEN prior_loans>0 THEN '3:Returning (previous loan)' ELSE '2:Returning (earlier application only)' END"
Q "WITH $BASE
   SELECT DATE_FORMAT(created_at,'%Y-%m') m, $NR type, COUNT(*) n, SUM(act) active_n, SUM(loan) loan_n FROM app
   WHERE created_at>='$MONTH_START' AND created_at < DATE('$END') + INTERVAL 1 DAY GROUP BY 1,2 ORDER BY 1,2;" > "$S/cust_newret_month.tsv"
Q "WITH $BASE
   SELECT DATE(created_at) d, $NR type, COUNT(*) n, SUM(act) active_n, SUM(loan) loan_n FROM app
   WHERE created_at>='$DAY_START' AND created_at < DATE('$END') + INTERVAL 1 DAY GROUP BY 1,2 ORDER BY 1,2;" > "$S/cust_newret_day.tsv"

# 4) applications / loans per customer (histogram) + totals
Q "WITH $BASE
   SELECT 'apps' kind, CASE WHEN apps>=5 THEN '5+' ELSE CAST(apps AS CHAR) END bucket, COUNT(*) n, SUM(act) active_c FROM cust GROUP BY 2
   UNION ALL SELECT 'loans', CASE WHEN loans>=5 THEN '5+' ELSE CAST(loans AS CHAR) END, COUNT(*), SUM(act) FROM cust GROUP BY 2
   ORDER BY 1,2;" > "$S/cust_hist.tsv"
Q "WITH $BASE
   SELECT COUNT(*) customers, SUM(act) active_customers, SUM(has_loan) loan_customers, SUM(apps) applications, SUM(loans) loans,
     SUM(first_at < '$MONTH_START') customers_before_series, (SELECT COUNT(*) FROM app WHERE created_at < '$MONTH_START') apps_before_series,
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

echo "END=$END"
wc -l "$S"/cust_*.tsv | sed "s#$S/##"
