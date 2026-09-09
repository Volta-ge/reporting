#!/usr/bin/env bash
# Collections Analyze extracts (VoltaStoreDB) for Volta_Analytics_New DB: day series cutover (2026-08-31) -> END
# (default: today), month series 2023-01 -> END.   usage: bash pull_coll.sh [YYYY-MM-DD]   (needs ./config.sh)
set -euo pipefail
S="$(cd "$(dirname "$0")" && pwd)"; source "$S/config.sh"
END="${1:-$(date +%F)}"
CUTOVER='2026-08-31'      # CRM cutover: the payment-event trail (crm_payment_events) and the collections activity tables start here
MSTART='2023-01-01'       # month series start: crm_installment_schedules covers every loan from 2023 on (2022: 97%), so due vs collected is complete from here
export MYSQL_PWD="$NEWDB_PWD"
Q() { "$MYSQL_BIN" -h "$NEWDB_HOST" -P 3306 -u "$NEWDB_USER" -D "$NEWDB_NAME" --default-character-set=utf8mb4 --connect-timeout=20 -e "$1"; }

# Payment guards used everywhere: the migrated crm_payments table carries one 99,999,999.99 sentinel row (2026-08-31, same as
# the old DB's payments table), reversed payments are excluded from "collected", and one payment is dated in the future.
PAYOK="p.amount < 100000 AND p.reversed_at IS NULL"
# DPD buckets = the CRM's own dpd_bucket codes (crm_payment_events: 1 = 1-3 days, 2 = 4-10, 3 = 11-30, 4 = 31-60, 5 = 61-90, 6 = 90+)
BUCKET() { echo "CASE WHEN $1 <= 3 THEN 1 WHEN $1 <= 10 THEN 2 WHEN $1 <= 30 THEN 3 WHEN $1 <= 60 THEN 4 WHEN $1 <= 90 THEN 5 ELSE 6 END"; }

# ---- overdue portfolio at the end of each day (stock), reconstructed from the schedule + the post-cutover settlement events.
# A schedule row is overdue on day D when schedule_date < D (DPD = D - schedule_date >= 1) and it was not yet settled at the end
# of D: rows still open today (active = 1) were open on every earlier day; rows settled since the cutover have a
# crm_payment_events.schedule_settled event, so they count as open on the days before that event; rows settled before the
# cutover (active = 0, no event) are never overdue in this window. Outstanding as of D = schedule_amount - paid_amount today
# + the amounts applied by settle/partial events after D. Today's column therefore equals the live
# "active = 1 AND schedule_date < today" figures exactly (verified in the build). A settle event dated after END (one payment is
# future-dated, 2026-09-18) is treated as settled on END, so the row is open through END-1 and gone from today's column like it is live.
OD_CTE="WITH RECURSIVE cal AS (SELECT DATE('$CUTOVER') d UNION ALL SELECT d + INTERVAL 1 DAY FROM cal WHERE d < DATE('$END')),
   ev AS (SELECT schedule_id, occurred_at, amount, event_type FROM crm_payment_events WHERE event_type IN ('schedule_settled','schedule_partial') AND schedule_id IS NOT NULL),
   se AS (SELECT schedule_id, LEAST(MIN(occurred_at), TIMESTAMP(DATE('$END'))) settled_at FROM ev WHERE event_type='schedule_settled' GROUP BY schedule_id),
   r AS (SELECT s.id, s.installment_id, s.schedule_date, s.schedule_amount, s.paid_amount, s.active, se.settled_at, (o.crm_active=1) act, COALESCE(o.crm_portfolio_manager_id,0) pm
         FROM crm_installment_schedules s JOIN orders o ON o.id=s.installment_id LEFT JOIN se ON se.schedule_id=s.id
         WHERE s.schedule_date < DATE('$END') AND (s.active=1 OR se.settled_at >= DATE('$CUTOVER'))),
   later AS (SELECT cal.d, e.schedule_id, SUM(e.amount) a FROM cal JOIN ev e ON e.occurred_at >= cal.d + INTERVAL 1 DAY GROUP BY cal.d, e.schedule_id),
   od AS (SELECT cal.d, r.installment_id, r.act, r.pm, DATEDIFF(cal.d, r.schedule_date) dpd, r.schedule_amount - r.paid_amount + COALESCE(l.a,0) outstanding
          FROM cal JOIN r ON r.schedule_date < cal.d AND (r.active=1 OR r.settled_at >= cal.d + INTERVAL 1 DAY)
          LEFT JOIN later l ON l.d=cal.d AND l.schedule_id=r.id)"
# schedule-row level: rows and amount per DPD bucket, split by whether the loan is active in the CRM (crm_active = 1)
Q "$OD_CTE SELECT d, act, $(BUCKET dpd) b, COUNT(*) rows_od, ROUND(SUM(outstanding),2) amt FROM od GROUP BY d, act, b ORDER BY d, act, b;" > "$S/coll_od_rows.tsv"
# loan level: each overdue loan once, in the bucket of its oldest overdue row, with its total overdue amount; by portfolio manager
Q "$OD_CTE, ln AS (SELECT d, act, pm, installment_id, MAX(dpd) dpd, SUM(outstanding) amt FROM od GROUP BY d, act, pm, installment_id)
   SELECT d, act, pm, $(BUCKET dpd) wb, COUNT(*) loans, ROUND(SUM(amt),2) amt FROM ln GROUP BY d, act, pm, wb ORDER BY d, act, pm, wb;" > "$S/coll_od_loans.tsv"

# ---- cash collected (flow) by payment_date: count, paying loans, amount and its parts (principal net of advance / advance /
# penalty; advance is a part of principal_part, so the three add up to amount) and by bank (crm_banks: 2 BOG, 1 TBC, 6 TBCPay)
PAYSEL="COUNT(*) n, COUNT(DISTINCT p.installment_id) loans, ROUND(SUM(p.amount),2) amt,
   ROUND(SUM(COALESCE(p.principal_part,0)-COALESCE(p.advance,0)),2) prin, ROUND(SUM(COALESCE(p.penalty_part,0)),2) pen, ROUND(SUM(COALESCE(p.advance,0)),2) adv,
   ROUND(SUM(CASE WHEN p.bank_id=2 THEN p.amount ELSE 0 END),2) bog, ROUND(SUM(CASE WHEN p.bank_id=1 THEN p.amount ELSE 0 END),2) tbc,
   ROUND(SUM(CASE WHEN p.bank_id=6 THEN p.amount ELSE 0 END),2) tbcpay, ROUND(SUM(CASE WHEN p.bank_id IN (1,2,6) THEN 0 ELSE p.amount END),2) other"
Q "SELECT p.payment_date d, $PAYSEL FROM crm_payments p WHERE $PAYOK AND p.payment_date BETWEEN '$CUTOVER' AND '$END' GROUP BY d ORDER BY d;" > "$S/coll_pay_day.tsv"
Q "SELECT DATE_FORMAT(p.payment_date,'%Y-%m') m, $PAYSEL FROM crm_payments p WHERE $PAYOK AND p.payment_date BETWEEN '$MSTART' AND '$END' GROUP BY m ORDER BY m;" > "$S/coll_pay_month.tsv"
# reversed payments by the day they were reversed (post-cutover only — the reversal trail starts with the CRM)
Q "SELECT DATE(p.reversed_at) d, COUNT(*) n, ROUND(SUM(p.amount),2) amt FROM crm_payments p WHERE p.amount < 100000 AND p.reversed_at IS NOT NULL AND DATE(p.reversed_at) BETWEEN '$CUTOVER' AND '$END' GROUP BY d ORDER BY d;" > "$S/coll_reversed.tsv"

# ---- scheduled dues by due date (schedule_date). Day file (due dates cutover -> END) also carries the due-date performance,
# knowable only for due dates since the cutover: on time = settled on or before the due date (settled before the cutover with
# no event, or a settle event dated <= due date), late = settle event after the due date, open = still unpaid today.
Q "WITH se AS (SELECT schedule_id, MIN(occurred_at) settled_at FROM crm_payment_events WHERE event_type='schedule_settled' AND schedule_id IS NOT NULL GROUP BY schedule_id)
   SELECT s.schedule_date d, COUNT(*) rows_due, ROUND(SUM(s.schedule_amount),2) amt, COUNT(DISTINCT s.installment_id) loans,
     SUM(s.active=0 AND (se.settled_at IS NULL OR DATE(se.settled_at) <= s.schedule_date)) ontime_rows, ROUND(SUM(CASE WHEN s.active=0 AND (se.settled_at IS NULL OR DATE(se.settled_at) <= s.schedule_date) THEN s.schedule_amount ELSE 0 END),2) ontime_amt,
     SUM(s.active=0 AND DATE(se.settled_at) > s.schedule_date) late_rows, ROUND(SUM(CASE WHEN s.active=0 AND DATE(se.settled_at) > s.schedule_date THEN s.schedule_amount ELSE 0 END),2) late_amt,
     SUM(s.active=1) open_rows, ROUND(SUM(CASE WHEN s.active=1 THEN s.schedule_amount - s.paid_amount ELSE 0 END),2) open_amt
   FROM crm_installment_schedules s LEFT JOIN se ON se.schedule_id=s.id WHERE s.schedule_date BETWEEN '$CUTOVER' AND '$END' GROUP BY d ORDER BY d;" > "$S/coll_due_day.tsv"
Q "SELECT DATE_FORMAT(s.schedule_date,'%Y-%m') m, COUNT(*) rows_due, ROUND(SUM(s.schedule_amount),2) amt, COUNT(DISTINCT s.installment_id) loans,
     SUM(s.active=1) open_rows, ROUND(SUM(CASE WHEN s.active=1 THEN s.schedule_amount - s.paid_amount ELSE 0 END),2) open_amt
   FROM crm_installment_schedules s WHERE s.schedule_date BETWEEN '$MSTART' AND '$END' GROUP BY m ORDER BY m;" > "$S/coll_due_month.tsv"

# ---- collections activity (flow, by the day it happened), long format: d, metric, n, amt — all tables are live since the cutover
Q "SELECT DATE(c.created_at) d, CONCAT('call_', c.outcome) metric, COUNT(*) n, 0 amt FROM crm_case_calls c WHERE c.created_at >= '$CUTOVER' AND DATE(c.created_at) <= '$END' GROUP BY d, metric
   UNION ALL SELECT DATE(c.created_at), CONCAT('chan_', COALESCE(c.channel,'none')), COUNT(*), 0 FROM crm_case_calls c WHERE c.created_at >= '$CUTOVER' AND DATE(c.created_at) <= '$END' GROUP BY 1, 2
   UNION ALL SELECT DATE(c.created_at), 'call_loans', COUNT(DISTINCT c.installment_id), 0 FROM crm_case_calls c WHERE c.created_at >= '$CUTOVER' AND DATE(c.created_at) <= '$END' GROUP BY 1
   UNION ALL SELECT DATE(a.created_at), CONCAT('status_', JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '\$.statusName'))), COUNT(*), 0 FROM crm_activity_log a WHERE a.action='collections.contact_status' AND a.created_at >= '$CUTOVER' AND DATE(a.created_at) <= '$END' GROUP BY 1, 2
   UNION ALL SELECT DATE(h.created_at), CONCAT('sms_', h.status), COUNT(*), 0 FROM crm_sms_history h WHERE h.created_at >= '$CUTOVER' AND DATE(h.created_at) <= '$END' GROUP BY 1, 2
   UNION ALL SELECT DATE(pr.created_at), 'promise_made', COUNT(*), ROUND(SUM(pr.amount),2) FROM crm_promises pr WHERE pr.created_at >= '$CUTOVER' AND DATE(pr.created_at) <= '$END' GROUP BY 1
   UNION ALL SELECT DATE(a.created_at), 'promise_kept', COUNT(*), 0 FROM crm_activity_log a WHERE a.action='collections.promise_settled' AND a.created_at >= '$CUTOVER' AND DATE(a.created_at) <= '$END' GROUP BY 1
   UNION ALL SELECT DATE(e.occurred_at), 'promise_broken', COUNT(*), 0 FROM crm_payment_events e WHERE e.event_type='promise_broken' AND e.occurred_at >= '$CUTOVER' AND DATE(e.occurred_at) <= '$END' GROUP BY 1
   UNION ALL SELECT DATE(rr.created_at), 'restructure_requested', COUNT(*), ROUND(SUM(rr.total_amount),2) FROM crm_restructure_requests rr WHERE rr.created_at >= '$CUTOVER' AND DATE(rr.created_at) <= '$END' GROUP BY 1
   UNION ALL SELECT DATE(rr.decided_at), CONCAT('restructure_', rr.status), COUNT(*), 0 FROM crm_restructure_requests rr WHERE rr.decided_at IS NOT NULL AND rr.decided_at >= '$CUTOVER' AND DATE(rr.decided_at) <= '$END' GROUP BY 1, 2
   UNION ALL SELECT DATE(pa.created_at), CONCAT('pm_assigned_', pa.role), COUNT(*), 0 FROM crm_portfolio_assignments pa WHERE pa.created_at >= '$CUTOVER' AND DATE(pa.created_at) <= '$END' GROUP BY 1, 2
   UNION ALL SELECT DATE(rm.reminder_date), 'reminder_due', COUNT(*), 0 FROM crm_reminders rm WHERE rm.reminder_date >= '$CUTOVER' AND DATE(rm.reminder_date) <= '$END' GROUP BY 1
   UNION ALL SELECT DATE(rm.created_at), 'reminder_created', COUNT(*), 0 FROM crm_reminders rm WHERE rm.created_at >= '$CUTOVER' AND DATE(rm.created_at) <= '$END' GROUP BY 1
   UNION ALL SELECT DATE(e.occurred_at), CONCAT('crm_', e.event_type), COUNT(*), 0 FROM crm_payment_events e WHERE e.event_type IN ('overdue_entered','overdue_cured','overdue_bucket_up','case_settled') AND e.occurred_at >= '$CUTOVER' AND DATE(e.occurred_at) <= '$END' GROUP BY 1, 2
   ORDER BY 1, 2;" > "$S/coll_activity.tsv"

# ---- today's snapshots: portfolio managers, CRM loan status, the CRM's customer payment-stats cache
Q "SELECT COALESCE(o.crm_portfolio_manager_id,0) pm, COALESCE(CONCAT(u.first_name,' ',u.last_name),'Unassigned') name, SUM(o.crm_active=1) active_loans,
     COUNT(DISTINCT CASE WHEN s.open_rows > 0 THEN o.id END) open_loans, ROUND(SUM(COALESCE(s.outstanding,0)),2) outstanding
   FROM orders o LEFT JOIN crm_users u ON u.id=o.crm_portfolio_manager_id
   LEFT JOIN (SELECT installment_id, SUM(active=1) open_rows, SUM(CASE WHEN active=1 THEN schedule_amount-paid_amount ELSE 0 END) outstanding FROM crm_installment_schedules GROUP BY installment_id) s ON s.installment_id=o.id
   WHERE o.crm_active=1 OR s.open_rows > 0 GROUP BY pm, name ORDER BY pm;" > "$S/coll_pm.tsv"
Q "SELECT COALESCE(o.crm_status_id,0) sid, COALESCE(st.name,'(no status)') name, COUNT(DISTINCT o.id) loans, ROUND(SUM(s.schedule_amount-s.paid_amount),2) outstanding,
     COUNT(DISTINCT CASE WHEN s.schedule_date < DATE('$END') THEN o.id END) od_loans, ROUND(SUM(CASE WHEN s.schedule_date < DATE('$END') THEN s.schedule_amount-s.paid_amount ELSE 0 END),2) od_amt
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

echo "END=$END"
wc -l "$S"/coll_*.tsv | sed "s#$S/##"
