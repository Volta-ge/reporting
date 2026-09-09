#!/usr/bin/env bash
# Operations (Applications + Committee) extracts for Volta_Analytics_New DB from VoltaStoreDB.
#   usage: bash pull_ops.sh [YYYY-MM-DD]      (needs ./config.sh — see config.example.sh)
# END = last day of the day series (default: today — the last column is "(today)"); the month series always runs
# from MONTH_START. Everything here is an aggregate (no row dumps, no customer data); build_ops.js turns the TSVs
# into ops_data.json and injects them into the HTML.
# Dates are DATE() of the raw UTC timestamps, the same convention as every other tab (it reproduces the CRM's own
# Applications count for Sep 1-7 exactly: 987).
set -euo pipefail
S="$(cd "$(dirname "$0")" && pwd)"; source "$S/config.sh"
END="${1:-$(date +%F)}"
CUTOVER='2026-08-31'      # bulk migration day; the status-change log starts 2026-09-01 08:23 UTC
MONTH_START='2026-01-01'  # month series start (application rows exist for these months, status history does not)
export MYSQL_PWD="$NEWDB_PWD"
Q() { "$MYSQL_BIN" -h "$NEWDB_HOST" -P 3306 -u "$NEWDB_USER" -D "$NEWDB_NAME" --default-character-set=utf8mb4 --connect-timeout=20 -e "$1"; }

# 1. Applications by application day x CURRENT crm_order_status x current crm_underwriter_status_id (state, not history).
#    Feeds: Applications by current status (day/month), the funnel by application date, underwriting outcome by application date.
Q "SELECT DATE(o.created_at) d, o.crm_order_status st, COALESCE(o.crm_underwriter_status_id,0) uw, COUNT(*) n
   FROM orders o WHERE DATE(o.created_at) BETWEEN '$MONTH_START' AND '$END' GROUP BY d, st, uw ORDER BY d, st, uw;" > "$S/ops_apps_state.tsv"

# 2. Status-change events (flow) by day: from -> to, events and distinct applications. Source: crm_activity_log,
#    action 'installment.status_change', JSON metadata {from,to}. Real history from 2026-09-01 only.
Q "SELECT DATE(l.created_at) d, JSON_EXTRACT(l.metadata,'\$.from') f, JSON_EXTRACT(l.metadata,'\$.to') t, COUNT(*) n, COUNT(DISTINCT l.entity_id) apps
   FROM crm_activity_log l WHERE l.action='installment.status_change' AND DATE(l.created_at) BETWEEN '$CUTOVER' AND '$END'
   GROUP BY d, f, t ORDER BY d, f, t;" > "$S/ops_flow.tsv"

# 3. Stock at the end of each day: applications whose LAST status-change event up to the end of D put them in status 8
#    (at committee) or 15 (returned for clarification). Today's figure equals the live orders.crm_order_status count (verified 4/4, 7/7).
Q "WITH RECURSIVE cal AS (SELECT DATE('$CUTOVER') d UNION ALL SELECT d + INTERVAL 1 DAY FROM cal WHERE d < DATE('$END')),
   ev AS (SELECT id, entity_id, JSON_EXTRACT(metadata,'\$.to') t, created_at FROM crm_activity_log WHERE action='installment.status_change')
   SELECT cal.d, e.t status, COUNT(*) n FROM cal JOIN ev e ON e.created_at < cal.d + INTERVAL 1 DAY
   LEFT JOIN ev e2 ON e2.entity_id=e.entity_id AND e2.created_at < cal.d + INTERVAL 1 DAY AND (e2.created_at > e.created_at OR (e2.created_at=e.created_at AND e2.id>e.id))
   WHERE e2.id IS NULL AND e.t IN (8,15) GROUP BY cal.d, e.t ORDER BY cal.d, e.t;" > "$S/ops_queue.tsv"

# 4. Committee decisions per decider: every status change OUT of status 8 (at committee), by day, actor and outcome.
#    Decider = the log's actor (crm_users.id; role 6 = Underwriter). orders.crm_underwriter_id is NOT used: the CRM also
#    stamps it with whichever sales manager rejects a case before committee ('Underwriter assigned by deciding the case').
Q "SELECT DATE(l.created_at) d, l.actor_id, COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''), l.actor_name) actor, JSON_EXTRACT(l.metadata,'\$.to') t, COUNT(*) n
   FROM crm_activity_log l LEFT JOIN crm_users u ON u.id=l.actor_id
   WHERE l.action='installment.status_change' AND JSON_EXTRACT(l.metadata,'\$.from')=8 AND DATE(l.created_at) BETWEEN '$CUTOVER' AND '$END'
   GROUP BY d, l.actor_id, actor, t ORDER BY d, l.actor_id, t;" > "$S/ops_uw.tsv"

# 5. Rejection reasons (status -> 6) by day and by the stage the case was rejected from; the reason is the text after
#    'მიზეზი: ' in the log summary (also stored in metadata.reason / orders.crm_reason). Free text is trimmed to 80 chars;
#    the build keeps the standard labels and buckets everything else as Other.
Q "SELECT DATE(l.created_at) d, JSON_EXTRACT(l.metadata,'\$.from') f,
     LEFT(TRIM(REPLACE(REPLACE(REPLACE(CASE WHEN l.summary LIKE '%მიზეზი: %' THEN SUBSTRING_INDEX(l.summary,'მიზეზი: ',-1) ELSE '' END, CHAR(10),' '), CHAR(13),' '), CHAR(9),' ')), 80) reason, COUNT(*) n
   FROM crm_activity_log l WHERE l.action='installment.status_change' AND JSON_EXTRACT(l.metadata,'\$.to')=6 AND DATE(l.created_at) BETWEEN '$CUTOVER' AND '$END'
   GROUP BY d, f, reason ORDER BY d, f, n DESC;" > "$S/ops_reasons.tsv"

echo "END=$END"
wc -l "$S/ops_apps_state.tsv" "$S/ops_flow.tsv" "$S/ops_queue.tsv" "$S/ops_uw.tsv" "$S/ops_reasons.tsv" | sed "s#$S/##"
