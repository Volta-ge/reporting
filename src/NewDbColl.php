<?php

declare(strict_types=1);

namespace Volta\Funnel;

use PDO;

/**
 * Collections Analyze (COLL_JSON) computed live: the PHP twin of volta-analytics-new-db/pull_coll.sh (same SQL, except the
 * overdue reconstruction, which runs over the day calendar in PHP from two bounded extracts — see SQL_OD_R)
 * + build_coll.js (aggregation), returning exactly the structure of coll_data.json.
 *   - day series: CUTOVER .. today — the pull script's END defaults to today and the page labels the last column "(today)",
 *     so every query here runs with END = today (the $end argument is accepted for the common constructor/build signature);
 *   - month series: 2023-01 .. the current month (crm_installment_schedules covers every loan from 2023 on).
 * Numbers follow the Node build: TSV `+x` = (float), `r2` = Math.round(v*100)/100, whole floats become ints on output.
 */
final class NewDbColl
{
    public const CUTOVER = '2026-08-31';
    private const MSTART = '2023-01';
    private const MSTART_DAY = '2023-01-01';

    private const BUCKETS = [[1, '1–3 days'], [2, '4–10 days'], [3, '11–30 days'], [4, '31–60 days'], [5, '61–90 days'], [6, '90+ days']];
    private const CPS_LABEL = [0 => 'Never overdue / not scored', 1 => '1–3 days', 2 => '4–10 days', 3 => '11–30 days', 4 => '31–60 days', 5 => '61–90 days', 6 => '90+ days'];
    private const PAYK = ['n', 'loans', 'amt', 'prin', 'pen', 'adv', 'bog', 'tbc', 'tbcpay', 'other'];
    private const DUEK = ['rows_due', 'amt', 'loans', 'ontime_rows', 'ontime_amt', 'late_rows', 'late_amt', 'open_rows', 'open_amt'];

    // ---- SQL from pull_coll.sh; {C} = CUTOVER, {E} = END (today), {M} = MSTART_DAY (each occurrence becomes its own placeholder)

    /** payment guards: one 99,999,999.99 sentinel row, reversed payments excluded from "collected" */
    private const PAYOK = 'p.amount < 100000 AND p.reversed_at IS NULL';

    private const PAYSEL = <<<'SQL'
COUNT(*) n, COUNT(DISTINCT p.installment_id) loans, ROUND(SUM(p.amount),2) amt,
   ROUND(SUM(COALESCE(p.principal_part,0)-COALESCE(p.advance,0)),2) prin, ROUND(SUM(COALESCE(p.penalty_part,0)),2) pen, ROUND(SUM(COALESCE(p.advance,0)),2) adv,
   ROUND(SUM(CASE WHEN p.bank_id=2 THEN p.amount ELSE 0 END),2) bog, ROUND(SUM(CASE WHEN p.bank_id=1 THEN p.amount ELSE 0 END),2) tbc,
   ROUND(SUM(CASE WHEN p.bank_id=6 THEN p.amount ELSE 0 END),2) tbcpay, ROUND(SUM(CASE WHEN p.bank_id IN (1,2,6) THEN 0 ELSE p.amount END),2) other
SQL;

    /**
     * Overdue portfolio at the end of each day (stock), reconstructed from the schedule + the post-cutover settlement events.
     * pull_coll.sh does it in SQL (its OD_CTE): a day calendar × the schedule rows (`od`) plus a day calendar × the event
     * trail (`later`) — cost grows with every day the window gains (quadratic in the event trail: ~3 s at 10 days, and the
     * window runs cutover → today for good). Same reconstruction here, in PHP, from two bounded extracts:
     *   R  = the schedule rows that can be overdue on some day of the window: schedule_date < END and either still open
     *        today (active = 1) or settled since the cutover AND later than due date + 1 day. A row is open at the end of an
     *        overdue day D iff schedule_date < D < settle day, so a row settled on or before due date + 1 has no such D and
     *        contributes nothing — the extra bound removes only rows the CTE joins to nothing. Settle day = MIN(settle
     *        event), capped at END (a future-dated settle event counts as settled on END, like the CTE).
     *   EV = the settle/partial events of those rows, summed per (schedule, event day), for event days after the cutover
     *        (the CTE's `later` counts events with occurred_at >= D + 1 day, i.e. event day > D >= cutover).
     * Then, exactly as the CTE: DPD = D - schedule_date, outstanding(D) = schedule_amount - paid_amount + Σ events with
     * event day > D, row buckets per (D, act, bucket), loan buckets per (D, act, pm, bucket of the loan's MAX DPD).
     * Amounts are DECIMAL(12,2) and are summed as integer cents, so the group totals equal MySQL's ROUND(SUM(),2) exactly.
     */
    private const SQL_OD_R = <<<'SQL'
WITH ev AS (SELECT schedule_id, occurred_at, amount, event_type FROM crm_payment_events WHERE event_type IN ('schedule_settled','schedule_partial') AND schedule_id IS NOT NULL),
   se AS (SELECT schedule_id, LEAST(MIN(occurred_at), TIMESTAMP(DATE({E}))) settled_at FROM ev WHERE event_type='schedule_settled' GROUP BY schedule_id)
SELECT s.id, s.installment_id, s.schedule_date, s.schedule_amount - s.paid_amount base, s.active, DATE(se.settled_at) settle_day, (o.crm_active=1) act, COALESCE(o.crm_portfolio_manager_id,0) pm
   FROM crm_installment_schedules s JOIN orders o ON o.id=s.installment_id LEFT JOIN se ON se.schedule_id=s.id
   WHERE s.schedule_date < DATE({E}) AND (s.active=1 OR (se.settled_at >= DATE({C}) AND DATE(se.settled_at) > s.schedule_date + INTERVAL 1 DAY))
   ORDER BY s.id
SQL;

    private const SQL_OD_EV = <<<'SQL'
WITH ev AS (SELECT schedule_id, occurred_at, amount, event_type FROM crm_payment_events WHERE event_type IN ('schedule_settled','schedule_partial') AND schedule_id IS NOT NULL),
   se AS (SELECT schedule_id, LEAST(MIN(occurred_at), TIMESTAMP(DATE({E}))) settled_at FROM ev WHERE event_type='schedule_settled' GROUP BY schedule_id),
   r AS (SELECT s.id FROM crm_installment_schedules s JOIN orders o ON o.id=s.installment_id LEFT JOIN se ON se.schedule_id=s.id
         WHERE s.schedule_date < DATE({E}) AND (s.active=1 OR (se.settled_at >= DATE({C}) AND DATE(se.settled_at) > s.schedule_date + INTERVAL 1 DAY)))
SELECT e.schedule_id, DATE(e.occurred_at) ed, SUM(e.amount) a FROM ev e JOIN r ON r.id=e.schedule_id
   WHERE e.occurred_at >= DATE({C}) + INTERVAL 1 DAY GROUP BY e.schedule_id, ed ORDER BY e.schedule_id, ed
SQL;

    private const SQL_DUE_DAY = <<<'SQL'
WITH se AS (SELECT schedule_id, MIN(occurred_at) settled_at FROM crm_payment_events WHERE event_type='schedule_settled' AND schedule_id IS NOT NULL GROUP BY schedule_id)
   SELECT s.schedule_date d, COUNT(*) rows_due, ROUND(SUM(s.schedule_amount),2) amt, COUNT(DISTINCT s.installment_id) loans,
     SUM(s.active=0 AND (se.settled_at IS NULL OR DATE(se.settled_at) <= s.schedule_date)) ontime_rows, ROUND(SUM(CASE WHEN s.active=0 AND (se.settled_at IS NULL OR DATE(se.settled_at) <= s.schedule_date) THEN s.schedule_amount ELSE 0 END),2) ontime_amt,
     SUM(s.active=0 AND DATE(se.settled_at) > s.schedule_date) late_rows, ROUND(SUM(CASE WHEN s.active=0 AND DATE(se.settled_at) > s.schedule_date THEN s.schedule_amount ELSE 0 END),2) late_amt,
     SUM(s.active=1) open_rows, ROUND(SUM(CASE WHEN s.active=1 THEN s.schedule_amount - s.paid_amount ELSE 0 END),2) open_amt
   FROM crm_installment_schedules s LEFT JOIN se ON se.schedule_id=s.id WHERE s.schedule_date BETWEEN {C} AND {E} GROUP BY d ORDER BY d
SQL;

    private const SQL_DUE_MONTH = <<<'SQL'
SELECT DATE_FORMAT(s.schedule_date,'%Y-%m') m, COUNT(*) rows_due, ROUND(SUM(s.schedule_amount),2) amt, COUNT(DISTINCT s.installment_id) loans,
     SUM(s.active=1) open_rows, ROUND(SUM(CASE WHEN s.active=1 THEN s.schedule_amount - s.paid_amount ELSE 0 END),2) open_amt
   FROM crm_installment_schedules s WHERE s.schedule_date BETWEEN {M} AND {E} GROUP BY m ORDER BY m
SQL;

    /** collections activity (flow, by the day it happened), long format: d, metric, n, amt */
    private const SQL_ACTIVITY = <<<'SQL'
SELECT DATE(c.created_at) d, CONCAT('call_', c.outcome) metric, COUNT(*) n, 0 amt FROM crm_case_calls c WHERE c.created_at >= {C} AND DATE(c.created_at) <= {E} GROUP BY d, metric
   UNION ALL SELECT DATE(c.created_at), CONCAT('chan_', COALESCE(c.channel,'none')), COUNT(*), 0 FROM crm_case_calls c WHERE c.created_at >= {C} AND DATE(c.created_at) <= {E} GROUP BY 1, 2
   UNION ALL SELECT DATE(c.created_at), 'call_loans', COUNT(DISTINCT c.installment_id), 0 FROM crm_case_calls c WHERE c.created_at >= {C} AND DATE(c.created_at) <= {E} GROUP BY 1
   UNION ALL SELECT DATE(a.created_at), CONCAT('status_', JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.statusName'))), COUNT(*), 0 FROM crm_activity_log a WHERE a.action='collections.contact_status' AND a.created_at >= {C} AND DATE(a.created_at) <= {E} GROUP BY 1, 2
   UNION ALL SELECT DATE(h.created_at), CONCAT('sms_', h.status), COUNT(*), 0 FROM crm_sms_history h WHERE h.created_at >= {C} AND DATE(h.created_at) <= {E} GROUP BY 1, 2
   UNION ALL SELECT DATE(pr.created_at), 'promise_made', COUNT(*), ROUND(SUM(pr.amount),2) FROM crm_promises pr WHERE pr.created_at >= {C} AND DATE(pr.created_at) <= {E} GROUP BY 1
   UNION ALL SELECT DATE(a.created_at), 'promise_kept', COUNT(*), 0 FROM crm_activity_log a WHERE a.action='collections.promise_settled' AND a.created_at >= {C} AND DATE(a.created_at) <= {E} GROUP BY 1
   UNION ALL SELECT DATE(e.occurred_at), 'promise_broken', COUNT(*), 0 FROM crm_payment_events e WHERE e.event_type='promise_broken' AND e.occurred_at >= {C} AND DATE(e.occurred_at) <= {E} GROUP BY 1
   UNION ALL SELECT DATE(rr.created_at), 'restructure_requested', COUNT(*), ROUND(SUM(rr.total_amount),2) FROM crm_restructure_requests rr WHERE rr.created_at >= {C} AND DATE(rr.created_at) <= {E} GROUP BY 1
   UNION ALL SELECT DATE(rr.decided_at), CONCAT('restructure_', rr.status), COUNT(*), 0 FROM crm_restructure_requests rr WHERE rr.decided_at IS NOT NULL AND rr.decided_at >= {C} AND DATE(rr.decided_at) <= {E} GROUP BY 1, 2
   UNION ALL SELECT DATE(pa.created_at), CONCAT('pm_assigned_', pa.role), COUNT(*), 0 FROM crm_portfolio_assignments pa WHERE pa.created_at >= {C} AND DATE(pa.created_at) <= {E} GROUP BY 1, 2
   UNION ALL SELECT DATE(rm.reminder_date), 'reminder_due', COUNT(*), 0 FROM crm_reminders rm WHERE rm.reminder_date >= {C} AND DATE(rm.reminder_date) <= {E} GROUP BY 1
   UNION ALL SELECT DATE(rm.created_at), 'reminder_created', COUNT(*), 0 FROM crm_reminders rm WHERE rm.created_at >= {C} AND DATE(rm.created_at) <= {E} GROUP BY 1
   UNION ALL SELECT DATE(e.occurred_at), CONCAT('crm_', e.event_type), COUNT(*), 0 FROM crm_payment_events e WHERE e.event_type IN ('overdue_entered','overdue_cured','overdue_bucket_up','case_settled') AND e.occurred_at >= {C} AND DATE(e.occurred_at) <= {E} GROUP BY 1, 2
   ORDER BY 1, 2
SQL;

    private const SQL_PM = <<<'SQL'
SELECT COALESCE(o.crm_portfolio_manager_id,0) pm, COALESCE(CONCAT(u.first_name,' ',u.last_name),'Unassigned') name, SUM(o.crm_active=1) active_loans,
     COUNT(DISTINCT CASE WHEN s.open_rows > 0 THEN o.id END) open_loans, ROUND(SUM(COALESCE(s.outstanding,0)),2) outstanding
   FROM orders o LEFT JOIN crm_users u ON u.id=o.crm_portfolio_manager_id
   LEFT JOIN (SELECT installment_id, SUM(active=1) open_rows, SUM(CASE WHEN active=1 THEN schedule_amount-paid_amount ELSE 0 END) outstanding FROM crm_installment_schedules GROUP BY installment_id) s ON s.installment_id=o.id
   WHERE o.crm_active=1 OR s.open_rows > 0 GROUP BY pm, name ORDER BY pm
SQL;

    private const SQL_STATUS = <<<'SQL'
SELECT COALESCE(o.crm_status_id,0) sid, COALESCE(st.name,'(no status)') name, COUNT(DISTINCT o.id) loans, ROUND(SUM(s.schedule_amount-s.paid_amount),2) outstanding,
     COUNT(DISTINCT CASE WHEN s.schedule_date < DATE({E}) THEN o.id END) od_loans, ROUND(SUM(CASE WHEN s.schedule_date < DATE({E}) THEN s.schedule_amount-s.paid_amount ELSE 0 END),2) od_amt
   FROM orders o JOIN crm_installment_schedules s ON s.installment_id=o.id AND s.active=1 LEFT JOIN crm_installment_statuses st ON st.id=o.crm_status_id
   GROUP BY sid, name ORDER BY loans DESC
SQL;

    private const SQL_CPS = <<<'SQL'
SELECT COALESCE(worst_bucket,0) wb, COUNT(*) customers, SUM(currently_overdue=1) cur_od, ROUND(AVG(on_time_rate),1) on_time_rate, ROUND(AVG(avg_days_late),1) avg_days_late,
     SUM(promises_made) promises_made, SUM(promises_kept) promises_kept, SUM(promises_broken) promises_broken, ROUND(SUM(total_paid),2) total_paid, ROUND(SUM(total_penalty_paid),2) penalty_paid, MAX(computed_at) computed_at
   FROM crm_customer_payment_stats GROUP BY wb ORDER BY wb
SQL;

    private const SQL_META = <<<'SQL'
SELECT 'crm_promises' t, COUNT(*) n FROM crm_promises UNION ALL SELECT 'crm_case_calls', COUNT(*) FROM crm_case_calls UNION ALL SELECT 'crm_call_interviews', COUNT(*) FROM crm_call_interviews
   UNION ALL SELECT 'crm_collection_items', COUNT(*) FROM crm_collection_items UNION ALL SELECT 'crm_portfolio_assignments', COUNT(*) FROM crm_portfolio_assignments UNION ALL SELECT 'crm_loan_marks', COUNT(*) FROM crm_loan_marks
   UNION ALL SELECT 'crm_loan_comments', COUNT(*) FROM crm_loan_comments UNION ALL SELECT 'crm_tasks', COUNT(*) FROM crm_tasks UNION ALL SELECT 'crm_loans', COUNT(*) FROM crm_loans UNION ALL SELECT 'crm_charges', COUNT(*) FROM crm_charges
   UNION ALL SELECT 'crm_restructure_requests', COUNT(*) FROM crm_restructure_requests UNION ALL SELECT 'crm_reminders', COUNT(*) FROM crm_reminders UNION ALL SELECT 'crm_sms_history', COUNT(*) FROM crm_sms_history
   UNION ALL SELECT 'crm_customer_payment_stats', COUNT(*) FROM crm_customer_payment_stats UNION ALL SELECT 'crm_payment_events', COUNT(*) FROM crm_payment_events UNION ALL SELECT 'crm_payments', COUNT(*) FROM crm_payments
   UNION ALL SELECT 'crm_installment_schedules', COUNT(*) FROM crm_installment_schedules
SQL;

    /** @var array<string,float> seconds per query, in execution order (filled by build()) */
    private array $timings = [];

    public function __construct(private readonly PDO $pdo, private readonly string $dir, private readonly string $mappingPath)
    {
    }

    /** @return array<string,float> query name => seconds, from the last build() */
    public function timings(): array
    {
        return $this->timings;
    }

    /** Exactly the structure of coll_data.json (see build_coll.js). $end is unused: the series run through today, as pull_coll.sh does by default. */
    public function build(string $end): array
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $tok = ['C' => self::CUTOVER, 'E' => $today, 'M' => self::MSTART_DAY];
        $this->timings = [];

        // ---- overdue portfolio: the coll_od_rows.tsv / coll_od_loans.tsv rows (see SQL_OD_R), reconstructed over the day calendar in PHP
        $odR = $this->run('od_r', self::SQL_OD_R, $tok);
        $odEv = $this->run('od_ev', self::SQL_OD_EV, $tok);
        $t = microtime(true);
        [$odRows, $odLoans] = self::reconstructOverdue($odR, $odEv, self::CUTOVER, $today);
        $this->timings['od_php'] = microtime(true) - $t;
        unset($odR, $odEv);
        $payDayRows = $this->run('pay_day', 'SELECT p.payment_date d, ' . self::PAYSEL . ' FROM crm_payments p WHERE ' . self::PAYOK . ' AND p.payment_date BETWEEN {C} AND {E} GROUP BY d ORDER BY d', $tok);
        $payMonthRows = $this->run('pay_month', "SELECT DATE_FORMAT(p.payment_date,'%Y-%m') m, " . self::PAYSEL . ' FROM crm_payments p WHERE ' . self::PAYOK . ' AND p.payment_date BETWEEN {M} AND {E} GROUP BY m ORDER BY m', $tok);
        $reversedRows = $this->run('reversed', 'SELECT DATE(p.reversed_at) d, COUNT(*) n, ROUND(SUM(p.amount),2) amt FROM crm_payments p WHERE p.amount < 100000 AND p.reversed_at IS NOT NULL AND DATE(p.reversed_at) BETWEEN {C} AND {E} GROUP BY d ORDER BY d', $tok);
        $dueDayRows = $this->run('due_day', self::SQL_DUE_DAY, $tok);
        $dueMonthRows = $this->run('due_month', self::SQL_DUE_MONTH, $tok);
        $actRows = $this->run('activity', self::SQL_ACTIVITY, $tok);
        $pmRows = $this->run('pm', self::SQL_PM, $tok);
        $statusRows = $this->run('status', self::SQL_STATUS, $tok);
        $cpsRows = $this->run('cps', self::SQL_CPS, $tok);
        $metaRows = $this->run('meta', self::SQL_META, $tok);

        // ---- calendars: days = cutover .. last day in the overdue series (today); months = MSTART .. current month
        $daysSet = [];
        foreach ($odRows as $r) { $daysSet[(string) $r['d']] = true; }
        $days = array_keys($daysSet);
        sort($days, SORT_STRING);
        if ($days === []) { throw new \RuntimeException('Collections: the overdue series is empty'); }
        $todayD = (string) end($days);
        $n = count($days);
        $months = [];
        for ($m = self::MSTART; strcmp($m, substr($todayD, 0, 7)) <= 0;) {
            $months[] = $m;
            [$y, $mo] = array_map('intval', explode('-', $m));
            $mo++;
            if ($mo > 12) { $mo = 1; $y++; }
            $m = $y . '-' . str_pad((string) $mo, 2, '0', STR_PAD_LEFT);
        }
        $zeros = static fn (int $len): array => array_fill(0, $len, 0.0);
        $dIdx = array_flip($days);
        $mIdx = array_flip($months);

        // ---- overdue portfolio (stock, end of day)
        $od = ['days' => $days, 'buckets' => array_map(static fn ($b) => $b[1], self::BUCKETS),
            'rowsByBucket' => array_map(static fn ($b) => $zeros($n), self::BUCKETS), 'amtByBucket' => array_map(static fn ($b) => $zeros($n), self::BUCKETS),
            'rows' => $zeros($n), 'amt' => $zeros($n), 'amtActive' => $zeros($n), 'amtLegacy' => $zeros($n), 'rowsActive' => $zeros($n), 'rowsLegacy' => $zeros($n),
            'loansByWb' => array_map(static fn ($b) => $zeros($n), self::BUCKETS), 'loans' => $zeros($n), 'loansActive' => $zeros($n), 'loansLegacy' => $zeros($n), 'pm' => []];
        foreach ($odRows as $r) {
            $i = $dIdx[(string) $r['d']];
            $b = (int) $r['b'] - 1;
            $rows = self::jn($r['rows_od']);
            $amt = self::jn($r['amt']);
            $act = (string) $r['act'] === '1';
            $od['rowsByBucket'][$b][$i] += $rows;
            $od['amtByBucket'][$b][$i] += $amt;
            $od['rows'][$i] += $rows;
            $od['amt'][$i] += $amt;
            if ($act) { $od['amtActive'][$i] += $amt; $od['rowsActive'][$i] += $rows; } else { $od['amtLegacy'][$i] += $amt; $od['rowsLegacy'][$i] += $rows; }
        }
        $pmName = [];
        foreach ($pmRows as $p) { $pmName[(string) $p['pm']] = (string) $p['name']; }
        foreach ($odLoans as $r) {
            $i = $dIdx[(string) $r['d']];
            $b = (int) $r['wb'] - 1;
            $loans = self::jn($r['loans']);
            $amt = self::jn($r['amt']);
            $act = (string) $r['act'] === '1';
            $od['loansByWb'][$b][$i] += $loans;
            $od['loans'][$i] += $loans;
            if ($act) { $od['loansActive'][$i] += $loans; } else { $od['loansLegacy'][$i] += $loans; }
            $pmKey = (string) $r['pm'];
            $od['pm'][$pmKey] ??= ['name' => $pmName[$pmKey] ?? ('PM ' . $pmKey), 'loans' => $zeros($n), 'amt' => $zeros($n), 'amt90' => $zeros($n), 'loans90' => $zeros($n)];
            $od['pm'][$pmKey]['loans'][$i] += $loans;
            $od['pm'][$pmKey]['amt'][$i] += $amt;
            if ((int) $r['wb'] === 6) { $od['pm'][$pmKey]['amt90'][$i] += $amt; $od['pm'][$pmKey]['loans90'][$i] += $loans; }
        }
        foreach (['rows', 'amt', 'amtActive', 'amtLegacy', 'rowsActive', 'rowsLegacy', 'loans', 'loansActive', 'loansLegacy'] as $k) { $od[$k] = array_map([self::class, 'r2'], $od[$k]); }
        foreach ($od['amtByBucket'] as &$bk) { $bk = array_map([self::class, 'r2'], $bk); }
        unset($bk);
        $pmOrdered = [];
        foreach (self::jsKeyOrder($od['pm']) as $k) {
            $p = $od['pm'][$k];
            $p['amt'] = array_map([self::class, 'r2'], $p['amt']);
            $p['amt90'] = array_map([self::class, 'r2'], $p['amt90']);
            $pmOrdered[(string) $k] = $p;
        }
        $od['pm'] = $pmOrdered;
        // month-end view of the same stock: the last day of each calendar month present in the day series
        $monthEnds = [];
        $seen = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            $m = substr($days[$i], 0, 7);
            if (!isset($seen[$m])) { $seen[$m] = true; array_unshift($monthEnds, ['m' => $m, 'd' => $days[$i], 'i' => $i]); }
        }
        $od['monthEnds'] = $monthEnds;

        // ---- cash collected (flow) by payment date, day + month, with scheduled dues on the same key
        $paySeries = static function (array $rows, array $keys, array $idx, string $keyName) use ($zeros): array {
            $s = [];
            foreach (self::PAYK as $k) { $s[$k] = $zeros(count($keys)); }
            foreach ($rows as $r) {
                $key = (string) $r[$keyName];
                if (!isset($idx[$key])) { continue; }
                $i = $idx[$key];
                foreach (self::PAYK as $k) { $s[$k][$i] += self::jn($r[$k]); }
            }
            foreach (self::PAYK as $k) { $s[$k] = array_map([self::class, 'r2'], $s[$k]); }
            return $s;
        };
        $payDay = $paySeries($payDayRows, $days, $dIdx, 'd');
        $payMonth = $paySeries($payMonthRows, $months, $mIdx, 'm');
        $dueSeries = static function (array $rows, array $keys, array $idx, string $keyName) use ($zeros): array {
            $s = [];
            foreach (self::DUEK as $k) { $s[$k] = $zeros(count($keys)); }
            foreach ($rows as $r) {
                $key = (string) $r[$keyName];
                if (!isset($idx[$key])) { continue; }
                $i = $idx[$key];
                foreach (self::DUEK as $k) { if (array_key_exists($k, $r)) { $s[$k][$i] += self::jn($r[$k]); } }
            }
            foreach (self::DUEK as $k) { $s[$k] = array_map([self::class, 'r2'], $s[$k]); }
            return $s;
        };
        $dueDay = $dueSeries($dueDayRows, $days, $dIdx, 'd');
        $dueMonth = $dueSeries($dueMonthRows, $months, $mIdx, 'm');
        // due-date performance is only knowable for due dates since the cutover: month view = the day rows summed per month
        $perfMonths = array_values(array_unique(array_map(static fn ($d) => substr($d, 0, 7), $days)));
        $pIdx = array_flip($perfMonths);
        $perfMonth = [];
        foreach (self::DUEK as $k) { $perfMonth[$k] = $zeros(count($perfMonths)); }
        foreach ($dueDayRows as $r) {
            $m = substr((string) $r['d'], 0, 7);
            if (!isset($pIdx[$m])) { continue; }
            $i = $pIdx[$m];
            foreach (self::DUEK as $k) { $perfMonth[$k][$i] += self::jn($r[$k]); }
        }
        foreach (self::DUEK as $k) { $perfMonth[$k] = array_map([self::class, 'r2'], $perfMonth[$k]); }
        $reversed = ['n' => $zeros($n), 'amt' => $zeros($n), 'nMonth' => $zeros(count($months)), 'amtMonth' => $zeros(count($months))];
        foreach ($reversedRows as $r) {
            $d = (string) $r['d'];
            $m = substr($d, 0, 7);
            if (isset($dIdx[$d])) { $reversed['n'][$dIdx[$d]] += self::jn($r['n']); $reversed['amt'][$dIdx[$d]] += self::jn($r['amt']); }
            if (isset($mIdx[$m])) { $reversed['nMonth'][$mIdx[$m]] += self::jn($r['n']); $reversed['amtMonth'][$mIdx[$m]] += self::jn($r['amt']); }
        }

        // ---- collections activity (flow), long format -> metric series by day and by month (months present in the day window)
        $metrics = [];
        foreach ($actRows as $r) {
            $metric = (string) $r['metric'];
            $metrics[$metric] ??= ['day' => $zeros($n), 'month' => $zeros(count($perfMonths)), 'amtDay' => $zeros($n), 'amtMonth' => $zeros(count($perfMonths))];
            $d = (string) $r['d'];
            $m = substr($d, 0, 7);
            if (!isset($dIdx[$d])) { continue; }
            $i = $dIdx[$d];
            $metrics[$metric]['day'][$i] += self::jn($r['n']);
            $metrics[$metric]['amtDay'][$i] += self::jn($r['amt']);
            if (isset($pIdx[$m])) { $metrics[$metric]['month'][$pIdx[$m]] += self::jn($r['n']); $metrics[$metric]['amtMonth'][$pIdx[$m]] += self::jn($r['amt']); }
        }
        $activity = ['days' => $days, 'months' => $perfMonths, 'metrics' => $metrics];

        // ---- today's snapshots
        $todayI = $n - 1;
        $pm = [];
        foreach ($pmRows as $p) {
            $k = (string) $p['pm'];
            $o = $od['pm'][$k] ?? null;
            $pm[] = ['pm' => $k, 'name' => (string) $p['name'], 'activeLoans' => self::jn($p['active_loans']), 'openLoans' => self::jn($p['open_loans']), 'outstanding' => self::jn($p['outstanding']),
                'odLoans' => $o ? $o['loans'][$todayI] : 0, 'odAmt' => $o ? $o['amt'][$todayI] : 0, 'od90Amt' => $o ? $o['amt90'][$todayI] : 0];
        }
        $status = [];
        foreach ($statusRows as $r) {
            $status[] = ['id' => self::jn($r['sid']), 'name' => (string) $r['name'], 'loans' => self::jn($r['loans']), 'outstanding' => self::jn($r['outstanding']), 'odLoans' => self::jn($r['od_loans']), 'odAmt' => self::jn($r['od_amt'])];
        }
        $cps = [];
        foreach ($cpsRows as $r) {
            $wb = (int) $r['wb'];
            $cps[] = ['wb' => self::jn($r['wb']), 'label' => self::CPS_LABEL[$wb] ?? ('Bucket ' . $r['wb']), 'customers' => self::jn($r['customers']), 'curOd' => self::jn($r['cur_od']),
                'onTimeRate' => self::numOrNull($r['on_time_rate']), 'avgDaysLate' => self::numOrNull($r['avg_days_late']),
                'promisesMade' => self::jn($r['promises_made']), 'promisesKept' => self::jn($r['promises_kept']), 'promisesBroken' => self::jn($r['promises_broken']),
                'totalPaid' => self::jn($r['total_paid']), 'penaltyPaid' => self::jn($r['penalty_paid']), 'computedAt' => $r['computed_at'] === null ? null : (string) $r['computed_at']];
        }
        $meta = [];
        foreach ($metaRows as $r) { $meta[(string) $r['t']] = self::jn($r['n']); }

        $payload = ['cutover' => self::CUTOVER, 'mstart' => self::MSTART, 'today' => $todayD, 'days' => $days, 'months' => $months, 'od' => $od,
            'payDay' => $payDay, 'payMonth' => $payMonth, 'dueDay' => $dueDay, 'dueMonth' => $dueMonth, 'perfMonths' => $perfMonths, 'perfMonth' => $perfMonth,
            'reversed' => $reversed, 'activity' => $activity, 'pm' => $pm, 'status' => $status, 'cps' => $cps, 'meta' => $meta,
            'generatedAt' => gmdate('Y-m-d H:i') . ' UTC'];
        $payload = self::normalize($payload);
        // keyed-by-id maps must encode as JSON objects even when their keys happen to be 0..n-1 (or empty)
        $payload['od']['pm'] = (object) $payload['od']['pm'];
        $payload['activity']['metrics'] = (object) $payload['activity']['metrics'];
        return $payload;
    }

    // ------------------------------------------------------------------ helpers

    /** Prepared query with {C}/{E}/{M} tokens expanded to distinct placeholders (native prepares refuse a repeated name); timed. */
    private function run(string $name, string $sql, array $tokens): array
    {
        $params = [];
        $i = 0;
        $sql = preg_replace_callback('/\{([CEM])\}/', static function (array $m) use (&$params, &$i, $tokens): string {
            $k = 'p' . (++$i);
            $params[$k] = $tokens[$m[1]];
            return ':' . $k;
        }, $sql);
        $t = microtime(true);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $this->timings[$name] = microtime(true) - $t;
        return $rows;
    }

    /** DPD buckets = the CRM's own dpd_bucket codes (1 = 1-3 days, 2 = 4-10, 3 = 11-30, 4 = 31-60, 5 = 61-90, 6 = 90+) — pull_coll.sh's BUCKET() */
    private static function bucketOf(int $dpd): int
    {
        return $dpd <= 3 ? 1 : ($dpd <= 10 ? 2 : ($dpd <= 30 ? 3 : ($dpd <= 60 ? 4 : ($dpd <= 90 ? 5 : 6))));
    }

    /** Exact integer cents of a DECIMAL(…,2) string ("-27.00" -> -2700); NULL -> 0 (what COALESCE(SUM(),0) gives) */
    private static function cents(mixed $s): int
    {
        if ($s === null || $s === '') { return 0; }
        $s = (string) $s;
        $neg = str_starts_with($s, '-');
        if ($neg) { $s = substr($s, 1); }
        [$int, $frac] = array_pad(explode('.', $s, 2), 2, '');
        if (!ctype_digit($int === '' ? '0' : $int) || ($frac !== '' && !ctype_digit($frac)) || rtrim(substr($frac, 2), '0') !== '') {
            throw new \RuntimeException("Collections: not a 2-decimal amount: $s");
        }
        $v = (int) $int * 100 + (int) substr(str_pad($frac, 2, '0'), 0, 2);
        return $neg ? -$v : $v;
    }

    /** Days since the epoch of a YYYY-MM-DD date (so DATEDIFF = plain subtraction) */
    private static function dayNum(string $d): int
    {
        static $cache = [];
        return $cache[$d] ??= intdiv((new \DateTimeImmutable($d . ' 00:00:00', new \DateTimeZone('UTC')))->getTimestamp(), 86400);
    }

    /**
     * The overdue reconstruction described at SQL_OD_R: returns [coll_od_rows rows, coll_od_loans rows], each in the pull
     * script's order (d, act, b) / (d, act, pm, wb) with the same columns, amounts as numbers (= Node's `+r.amt`).
     * @return array{0: list<array>, 1: list<array>}
     */
    private static function reconstructOverdue(array $rRows, array $evRows, string $cutover, string $end): array
    {
        $d0 = self::dayNum($cutover);
        $d1 = self::dayNum($end);
        $ev = [];   // schedule id => list of [event day, cents], ascending by day
        foreach ($evRows as $e) { $ev[(int) $e['schedule_id']][] = [self::dayNum((string) $e['ed']), self::cents($e['a'])]; }
        $rows = [];   // day => act => bucket => [count, cents]
        $loans = [];  // day => act => pm => installment => [max dpd, cents]
        foreach ($rRows as $r) {
            $sd = self::dayNum((string) $r['schedule_date']);
            $active = (int) $r['active'] === 1;
            $settle = $r['settle_day'] === null ? null : self::dayNum((string) $r['settle_day']);
            $from = max($d0, $sd + 1);                                   // overdue: schedule_date < D
            $to = $active ? $d1 : ($settle === null ? -1 : min($d1, $settle - 1));   // open at the end of D: active, or settle day > D
            if ($from > $to) { continue; }
            $base = self::cents($r['base']);
            $act = (int) $r['act'];
            $pm = (int) $r['pm'];
            $inst = (int) $r['installment_id'];
            $events = $ev[(int) $r['id']] ?? [];
            for ($d = $from; $d <= $to; $d++) {
                $later = 0;
                foreach ($events as [$ed, $a]) { if ($ed > $d) { $later += $a; } }
                $out = $base + $later;
                $dpd = $d - $sd;
                $b = self::bucketOf($dpd);
                $cell = &$rows[$d][$act][$b];
                $cell ??= [0, 0];
                $cell[0]++;
                $cell[1] += $out;
                unset($cell);
                $ln = &$loans[$d][$act][$pm][$inst];
                $ln ??= [0, 0];
                if ($dpd > $ln[0]) { $ln[0] = $dpd; }
                $ln[1] += $out;
                unset($ln);
            }
        }
        $odRows = [];
        ksort($rows);
        foreach ($rows as $d => $byAct) {
            $ds = gmdate('Y-m-d', $d * 86400);
            ksort($byAct);
            foreach ($byAct as $act => $byB) {
                ksort($byB);
                foreach ($byB as $b => [$cnt, $c]) { $odRows[] = ['d' => $ds, 'act' => (string) $act, 'b' => $b, 'rows_od' => $cnt, 'amt' => $c / 100]; }
            }
        }
        $odLoans = [];
        ksort($loans);
        foreach ($loans as $d => $byAct) {
            $ds = gmdate('Y-m-d', $d * 86400);
            ksort($byAct);
            foreach ($byAct as $act => $byPm) {
                ksort($byPm);
                foreach ($byPm as $pm => $byInst) {
                    $g = [];   // wb => [count, cents]
                    foreach ($byInst as [$dpd, $c]) {
                        $wb = self::bucketOf($dpd);
                        $g[$wb] ??= [0, 0];
                        $g[$wb][0]++;
                        $g[$wb][1] += $c;
                    }
                    ksort($g);
                    foreach ($g as $wb => [$cnt, $c]) { $odLoans[] = ['d' => $ds, 'act' => (string) $act, 'pm' => $pm, 'wb' => $wb, 'loans' => $cnt, 'amt' => $c / 100]; }
                }
            }
        }
        return [$odRows, $odLoans];
    }

    /** Node's `+x` on a TSV cell: a number, or NaN for a SQL NULL (which JSON.stringify emits as null — see normalize()). */
    private static function jn(mixed $v): float
    {
        return ($v === null || $v === '') ? NAN : (float) $v;
    }

    /** Node's num(): null for a SQL NULL, else the number */
    private static function numOrNull(mixed $v): float|int|null
    {
        return ($v === null || $v === '') ? null : self::num((float) $v);
    }

    /** JS Math.round: nearest integer, halves toward +infinity (PHP's round() pre-rounds and rounds halves away from zero) */
    private static function jsRound(float $x): float
    {
        $f = floor($x);
        return ($x - $f) >= 0.5 ? $f + 1 : $f;
    }

    /** Node's r2 = Math.round(v * 100) / 100 */
    private static function r2(float|int $v): float
    {
        return self::jsRound((float) $v * 100) / 100;
    }

    /** JS-compatible number: whole floats become ints so json_encode prints 6734, not 6734.0 */
    private static function num(float|int $v): float|int
    {
        if (is_float($v) && floor($v) === $v && abs($v) < 1e15) {
            return (int) $v;
        }
        return $v;
    }

    /** Output normalisation: whole floats -> int, NaN/Inf -> null (what JSON.stringify does), recursively. */
    private static function normalize(mixed $v): mixed
    {
        if (is_array($v)) {
            foreach ($v as $k => $x) { $v[$k] = self::normalize($x); }
            return $v;
        }
        if (is_float($v)) {
            if (is_nan($v) || is_infinite($v)) { return null; }
            return self::num($v);
        }
        return $v;
    }

    /** Keys in JavaScript object order: integer-like keys first (ascending), then the rest in insertion order. */
    private static function jsKeyOrder(array $assoc): array
    {
        $ints = [];
        $others = [];
        foreach (array_keys($assoc) as $k) {
            if (is_int($k) && $k >= 0) { $ints[] = $k; } elseif (is_string($k) && preg_match('/^(0|[1-9][0-9]*)$/', $k) && (int) $k < 4294967295) { $ints[] = $k; } else { $others[] = $k; }
        }
        usort($ints, static fn ($a, $b) => (int) $a <=> (int) $b);
        return array_merge($ints, $others);
    }
}
