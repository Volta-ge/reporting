#!/usr/bin/env bash
# Portfolio Analyze extracts (VoltaStoreDB) for Volta_Analytics_New DB — the loan book by day and by month.
#   usage: bash pull_pf.sh [YYYY-MM-DD]      (END = last day of the series, default: today; needs ./config.sh)
# Writes pf_*.tsv (aggregates only) next to this script; build_pf.js turns them into pf_data.json + the tab.
set -euo pipefail
S="$(cd "$(dirname "$0")" && pwd)"; source "$S/config.sh"
END="${1:-$(date +%F)}"
CUTOVER='2026-08-31'      # day series starts here (the CRM cutover; every date from here on is real history)
MSTART='2025-01-01'       # month series / structure tables start here (see README: 2025 is the first year fully covered)
export MYSQL_PWD="$NEWDB_PWD"
Q() { "$MYSQL_BIN" -h "$NEWDB_HOST" -P 3306 -u "$NEWDB_USER" -D "$NEWDB_NAME" --default-character-set=utf8mb4 --connect-timeout=20 -e "$1"; }
# Segment A = phone/TV product name, or order amount > 2,500 GEL (same rule as pull_new.sh)
SEG="CASE WHEN EXISTS (SELECT 1 FROM order_items oi JOIN product_flat pf ON pf.product_id=oi.product_id AND pf.locale='ka_GE' WHERE oi.order_id=o.id AND (pf.name LIKE 'ტელეფონი%' OR pf.name LIKE 'ტელევიზორ%')) OR o.base_grand_total>2500 THEN 'A' ELSE 'B' END"

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
BOOK="sched AS (SELECT s.installment_id, SUM(s.schedule_amount) tot, SUM(s.schedule_amount - s.paid_amount) rem, SUM(DATEDIFF(s.schedule_date, DATE(o.crm_creator_date)) > 7) term
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
STOCK="act AS (SELECT cal.d, b.id, b.rem, b.amount, b.price FROM cal JOIN book b ON b.disb < cal.d + INTERVAL 1 DAY AND (b.closed_at IS NULL OR b.closed_at >= cal.d + INTERVAL 1 DAY)),
 pa AS (SELECT a.d, SUM(p.principal_part) paid_after FROM act a JOIN crm_payments p ON p.installment_id = a.id AND p.reversed_at IS NULL AND p.amount < 1000000 AND p.payment_date > a.d AND p.payment_date <= DATE('$END') GROUP BY a.d)
 SELECT a.d, COUNT(*) n, ROUND(SUM(a.rem), 2) rem_now, ROUND(SUM(a.amount), 2) contract, ROUND(SUM(a.price), 2) price, ROUND(COALESCE(MAX(pa.paid_after), 0), 2) paid_after
 FROM act a LEFT JOIN pa ON pa.d = a.d GROUP BY a.d ORDER BY a.d"
Q "WITH RECURSIVE cal AS (SELECT DATE('$CUTOVER') d UNION ALL SELECT d + INTERVAL 1 DAY FROM cal WHERE d < DATE('$END')), $BOOK, $STOCK;" > "$S/pf_stock_day.tsv"
Q "WITH RECURSIVE cal AS (SELECT LEAST(LAST_DAY(DATE('$MSTART')), DATE('$END')) d UNION ALL SELECT LEAST(LAST_DAY(d + INTERVAL 1 DAY), DATE('$END')) FROM cal WHERE d < DATE('$END')), $BOOK, $STOCK;" > "$S/pf_stock_month.tsv"

# ---- flows by day (MSTART .. END; build_pf.js rolls them up to months): disbursed loans keyed to the disbursement date,
# closed loans keyed to closed_at, by kind. term_sum / adv_n / adv_sum feed the averages; rem = balance left on the closed loans
Q "WITH $BOOK SELECT DATE(b.disb) d, COUNT(*) n, ROUND(SUM(b.amount), 2) amount, ROUND(SUM(b.price), 2) price, SUM(b.term) term_sum, SUM(b.adv > 0) adv_n, ROUND(SUM(b.adv), 2) adv_sum, SUM(b.seg = 'A') seg_a
   FROM book b WHERE b.disb >= '$MSTART' AND DATE(b.disb) <= '$END' GROUP BY d ORDER BY d;" > "$S/pf_disb_day.tsv"
Q "WITH $BOOK SELECT DATE(b.closed_at) d, b.kind, COUNT(*) n, ROUND(SUM(b.amount), 2) amount, ROUND(SUM(b.price), 2) price, ROUND(SUM(b.rem), 2) rem
   FROM book b WHERE b.kind <> 'active' AND b.closed_at >= '$MSTART' AND DATE(b.closed_at) <= '$END' GROUP BY d, b.kind ORDER BY d, b.kind;" > "$S/pf_close_day.tsv"
# single-payment sales (crm_order_status 99) — memo row under the disbursement tables, not part of the loan book
Q "SELECT DATE(o.crm_creator_date) d, COUNT(*) n, ROUND(SUM(o.base_grand_total), 2) price FROM orders o WHERE o.crm_order_status = 99 AND o.crm_creator_date >= '$MSTART' AND DATE(o.crm_creator_date) <= '$END' GROUP BY d ORDER BY d;" > "$S/pf_single_day.tsv"

# ---- structure of the loans disbursed each month (MSTART .. END), long format: dim, month, key, n, amount, rem (balance left today), act (still active)
#   term (months) | amt (contract amount band) | seg (A/B) | city | risk (crm_risk_status, raw) | goods (highest-value product id; build_pf.js maps it to a category) | kind (vintage: active/paid/wo/norec)
Q "WITH $BOOK, m AS (SELECT b.*, DATE_FORMAT(b.disb, '%Y-%m') mo FROM book b WHERE b.disb >= '$MSTART' AND DATE(b.disb) <= '$END')
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
Q "WITH $BOOK, a AS (SELECT b.* FROM book b WHERE b.kind = 'active')
   SELECT 'rm' dim, CAST(COALESCE(rm, -1) AS CHAR) COLLATE utf8mb4_unicode_ci k, COUNT(*) n, ROUND(SUM(amount), 2) amount, ROUND(SUM(rem), 2) rem FROM a GROUP BY k
   UNION ALL SELECT 'age', CAST(TIMESTAMPDIFF(MONTH, disb, DATE('$END')) AS CHAR) COLLATE utf8mb4_unicode_ci, COUNT(*), ROUND(SUM(amount), 2), ROUND(SUM(rem), 2) FROM a GROUP BY 2
   UNION ALL SELECT 'risk', risk COLLATE utf8mb4_unicode_ci, COUNT(*), ROUND(SUM(amount), 2), ROUND(SUM(rem), 2) FROM a GROUP BY 2
   UNION ALL SELECT 'term', CAST(term AS CHAR) COLLATE utf8mb4_unicode_ci, COUNT(*), ROUND(SUM(amount), 2), ROUND(SUM(rem), 2) FROM a GROUP BY 2
   UNION ALL SELECT 'amt', CASE WHEN amount < 500 THEN '1' WHEN amount < 1000 THEN '2' WHEN amount < 2000 THEN '3' WHEN amount < 3000 THEN '4' WHEN amount < 5000 THEN '5' ELSE '6' END COLLATE utf8mb4_unicode_ci, COUNT(*), ROUND(SUM(amount), 2), ROUND(SUM(rem), 2) FROM a GROUP BY 2
   UNION ALL SELECT 'seg', seg COLLATE utf8mb4_unicode_ci, COUNT(*), ROUND(SUM(amount), 2), ROUND(SUM(rem), 2) FROM a GROUP BY 2
   UNION ALL SELECT 'city', city COLLATE utf8mb4_unicode_ci, COUNT(*), ROUND(SUM(amount), 2), ROUND(SUM(rem), 2) FROM a GROUP BY 2
   UNION ALL SELECT 'goods', CAST(top_product_id AS CHAR) COLLATE utf8mb4_unicode_ci, COUNT(*), ROUND(SUM(amount), 2), ROUND(SUM(rem), 2) FROM a GROUP BY 2
   ORDER BY 1, 2;" > "$S/pf_book.tsv"

# ---- data-quality memo (whole book): how each kind is recorded, and the loans the rules had to repair
Q "WITH $BOOK SELECT b.kind, COUNT(*) n, SUM(b.closed_at IS NULL) no_close_at, ROUND(SUM(b.rem), 2) rem, SUM(b.rem <= 1) fully_paid, MIN(DATE(b.disb)) first_disb, MAX(DATE(b.disb)) last_disb, MIN(DATE(b.closed_at)) first_close, MAX(DATE(b.closed_at)) last_close FROM book b GROUP BY b.kind ORDER BY b.kind;" > "$S/pf_quality.tsv"
Q "SELECT SUM(o.crm_close_type IN (1,2) AND (o.crm_close_date < o.crm_creator_date OR YEAR(o.crm_close_date) < 2019)) bad_close_dates, SUM(o.crm_active = 1 AND o.crm_order_status = 5 AND o.grand_total <= 0) active_zero_balance, SUM(o.crm_order_status = 99) single_all, SUM(o.crm_close_type IN (1,2) AND o.crm_close_date >= '$CUTOVER') closes_since_cutover FROM orders o;" > "$S/pf_quality2.tsv"

echo "END=$END"
wc -l "$S"/pf_*.tsv | sed "s#$S/##"
