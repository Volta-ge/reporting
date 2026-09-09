<?php

declare(strict_types=1);

namespace Volta\Funnel;

use PDO;

/**
 * Volta_Analytics_New DB — Operations group (Applications / Committee), computed live from VoltaStoreDB.
 * PHP twin of volta-analytics-new-db/pull_ops.sh (SQL, identical) + build_ops.js (aggregation, identical):
 * build() returns exactly the structure of ops_data.json (= the `const OPS_JSON = …;` line in the dashboard HTML).
 *
 * Calendar: like pull_ops.sh with no argument, the day series runs CUTOVER .. today (the last column is "(today)");
 * the $end argument is accepted for signature parity with NewDbReport but is not used, exactly as logistics() does.
 * Everything is aggregated in SQL (GROUP BY day/status); the biggest result set is ~1.5k rows.
 */
final class NewDbOps
{
    public const CUTOVER = '2026-08-31';        // migration day: application rows carry real dates from here
    public const HISTORY_START = '2026-09-01';  // first status-change event in crm_activity_log (2026-09-01 08:23 UTC)
    public const MONTH_START = '2026-01';       // month series start (application rows exist for these months, status history does not)

    private const DASH = "\u{2013}";   // –
    private const ARROW = "\u{2192}";  // →

    /** [code, label, crm wording / basis, verified] — same list and order as build_ops.js STATUS */
    private const STATUS = [
        [4,  'Pending',                               'ახალი / Pending (CRM Applications page)', true],
        [7,  'In processing',                         'დამუშავების პროცესი', true],
        [8,  'At committee',                          'კომიტეტზე გაგზავნა', true],
        [15, 'Clarification needed',                  'დასაზუსტებელია საკითხი', true],
        [16, 'Approved by committee',                 'დამტკიცებულია', true],
        [17, 'Disbursement in process',               'გაცემის პროცესი', true],
        [9,  'Approved ' . self::DASH . ' invoice & contract draft sent', 'დამტკიცებული, გაიგზავნა ინვოისი და ხელშეკრულების ნიმუში', true],
        [10, 'Contract sent for signing',             'ხელშეკრულება გაიგზავნა ხელმოსაწერად', true],
        [11, 'Signed',                                'ხელმოწერილია', true],
        [5,  'Active',                                'მიმდინარე (log code 1 = activation)', true],
        [1,  'Active (legacy code, migrated loans)',  'migrated 2019-2024 loans, all closed', true],
        [99, 'Single payment',                        'ერთიანი გადახდა', true],
        [6,  'Rejected',                              'უარი განვადებაზე', true],
        [12, 'Customer declined',                     'კლიენტმა უარი განაცხადა განვადებაზე', true],
        [13, 'Expired',                               'განვადების განაცხადს ვადა ამოეწურა', true],
        [14, 'Status 14 (unverified)',                'no CRM wording found; migrated rows only', false],
        [3,  'Status 3 (unverified)',                 'no CRM wording found; migrated rows only', false],
    ];

    /** in the log, to=1 is the activation event (the order is then stored as crm_order_status 5 = Active) */
    private const FLOW_LABEL = [1 => 'Active (activated; stored as status 5)'];

    /** [Georgian CRM wording, English gloss] — same list as build_ops.js REASONS (exact-match keys) */
    private const REASONS = [
        ['შეუსაბამო მონაცემები', 'Inconsistent data'], ['დასაფარია მიმდინარე', 'Existing loan must be repaid first'], ['გადახდისუუნარო', 'Insolvent'],
        ['მოვალეთა რეესტრი', "Debtors' registry"], ['ხიშნიკი', 'ხიშნიკი'], ['დუბლირებული განაცხადი', 'Duplicate application'],
        ['პროდუქციის არ ქონა', 'Product unavailable'], ['პროდუქტის არ ქონა', 'Product unavailable'], ['ვერ ვუკავშირდები', 'Cannot reach the customer'], ['შეუსაბამო მონაცემები.', 'Inconsistent data'],
    ];

    /** rejection stage labels by the status the case was rejected from (JS object: integer keys ascending = sort order) */
    private const STAGE = [
        4 => 'from Pending (4) ' . self::DASH . ' screened out by sales', 7 => 'from In processing (7)', 8 => 'at committee (8)', 9 => 'after approval (9)',
        10 => 'after approval (10)', 15 => 'after clarification request (15)', 16 => 'after approval (16)', 17 => 'after approval (17)',
    ];

    /** @var array<string,float> per-query wall time in seconds (name => s), filled by build() */
    private array $timings = [];

    public function __construct(private readonly PDO $pdo, private readonly string $dir, private readonly string $mappingPath)
    {
    }

    /** @return array<string,float> */
    public function timings(): array
    {
        return $this->timings;
    }

    /** Same structure as volta-analytics-new-db/ops_data.json. $end is unused (series run through today, like pull_ops.sh). */
    public function build(string $end): array
    {
        $this->timings = [];
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $cutover = self::CUTOVER;
        $monthStartDay = self::MONTH_START . '-01';

        // ---- 1. applications by application day x CURRENT crm_order_status x current crm_underwriter_status_id (state)
        $state = [];
        foreach ($this->q('apps_state', "SELECT DATE(o.created_at) d, o.crm_order_status st, COALESCE(o.crm_underwriter_status_id,0) uw, COUNT(*) n
   FROM orders o WHERE DATE(o.created_at) BETWEEN :start AND :end GROUP BY d, st, uw ORDER BY d, st, uw", ['start' => $monthStartDay, 'end' => $today]) as $r) {
            $state[] = ['d' => (string) $r['d'], 'm' => substr((string) $r['d'], 0, 7), 'st' => (int) $r['st'], 'uw' => (int) $r['uw'], 'n' => (int) $r['n']];
        }

        // ---- 2. status-change events (flow) by day: from -> to (crm_activity_log, action 'installment.status_change')
        $flow = [];
        foreach ($this->q('flow', "SELECT DATE(l.created_at) d, JSON_EXTRACT(l.metadata,'$.from') f, JSON_EXTRACT(l.metadata,'$.to') t, COUNT(*) n, COUNT(DISTINCT l.entity_id) apps
   FROM crm_activity_log l WHERE l.action='installment.status_change' AND DATE(l.created_at) BETWEEN :cutover AND :end
   GROUP BY d, f, t ORDER BY d, f, t", ['cutover' => $cutover, 'end' => $today]) as $r) {
            $flow[] = ['d' => (string) $r['d'], 'm' => substr((string) $r['d'], 0, 7), 'f' => (int) $r['f'], 't' => (int) $r['t'], 'n' => (int) $r['n'], 'apps' => (int) $r['apps']];
        }

        // ---- 3. stock at the end of each day: applications whose LAST status-change event up to the end of D put them in 8 / 15.
        //    Same result as pull_ops.sh (verified row-for-row), but the "no later event for this application" test uses
        //    LEAD() over each application's events instead of the per-calendar-day self-join, which took 20-34 s on RDS
        //    (production has a 30 s limit); this form runs in ~0.2 s. Ordering (created_at, id) is the pull script's.
        $queueRows = $this->q('queue', "WITH RECURSIVE cal AS (SELECT DATE(:cutover) d UNION ALL SELECT d + INTERVAL 1 DAY FROM cal WHERE d < DATE(:end)),
   ev AS (SELECT entity_id, JSON_EXTRACT(metadata,'$.to') t, created_at, LEAD(created_at) OVER (PARTITION BY entity_id ORDER BY created_at, id) next_at
          FROM crm_activity_log WHERE action='installment.status_change')
   SELECT cal.d, e.t status, COUNT(*) n FROM cal JOIN ev e ON e.created_at < cal.d + INTERVAL 1 DAY AND (e.next_at IS NULL OR e.next_at >= cal.d + INTERVAL 1 DAY)
   WHERE e.t IN (8,15) GROUP BY cal.d, e.t ORDER BY cal.d, e.t", ['cutover' => $cutover, 'end' => $today]);

        // ---- 4. committee decisions per decider: every status change OUT of status 8, by day, actor and outcome
        $uw = [];
        foreach ($this->q('uw', "SELECT DATE(l.created_at) d, l.actor_id, COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''), l.actor_name) actor, JSON_EXTRACT(l.metadata,'$.to') t, COUNT(*) n
   FROM crm_activity_log l LEFT JOIN crm_users u ON u.id=l.actor_id
   WHERE l.action='installment.status_change' AND JSON_EXTRACT(l.metadata,'$.from')=8 AND DATE(l.created_at) BETWEEN :cutover AND :end
   GROUP BY d, l.actor_id, actor, t ORDER BY d, l.actor_id, t", ['cutover' => $cutover, 'end' => $today]) as $r) {
            // the mysql CLI prints SQL NULL as the string "NULL" (Node keeps it as the name); PDO gives null
            $uw[] = ['d' => (string) $r['d'], 'm' => substr((string) $r['d'], 0, 7), 'id' => (int) $r['actor_id'], 'name' => $r['actor'] === null ? 'NULL' : (string) $r['actor'], 't' => (int) $r['t'], 'n' => (int) $r['n']];
        }

        // ---- 5. rejection reasons (status -> 6) by day and by the stage the case was rejected from
        $reasons = [];
        foreach ($this->q('reasons', "SELECT DATE(l.created_at) d, JSON_EXTRACT(l.metadata,'$.from') f,
     LEFT(TRIM(REPLACE(REPLACE(REPLACE(CASE WHEN l.summary LIKE '%მიზეზი: %' THEN SUBSTRING_INDEX(l.summary,'მიზეზი: ',-1) ELSE '' END, CHAR(10),' '), CHAR(13),' '), CHAR(9),' ')), 80) reason, COUNT(*) n
   FROM crm_activity_log l WHERE l.action='installment.status_change' AND JSON_EXTRACT(l.metadata,'$.to')=6 AND DATE(l.created_at) BETWEEN :cutover AND :end
   GROUP BY d, f, reason ORDER BY d, f, n DESC", ['cutover' => $cutover, 'end' => $today]) as $r) {
            $reasons[] = ['d' => (string) $r['d'], 'm' => substr((string) $r['d'], 0, 7), 'f' => (int) $r['f'], 'key' => self::reasonKey((string) ($r['reason'] ?? '')), 'n' => (int) $r['n']];
        }

        // ---- calendars (END = last day in the queue extract, i.e. today; CUTOVER if the extract is empty)
        $endDay = $cutover;
        foreach ($queueRows as $r) { if ((string) $r['d'] > $endDay) { $endDay = (string) $r['d']; } }
        $days = self::dayRange($cutover, $endDay);
        $hdays = self::dayRange(self::HISTORY_START, $endDay);
        $months = self::monthRange(self::MONTH_START, substr($endDay, 0, 7));
        $hmonths = self::monthRange(substr(self::HISTORY_START, 0, 7), substr($endDay, 0, 7));
        $di = array_flip($days);
        $hdi = array_flip($hdays);
        $mi = array_flip($months);
        $hmi = array_flip($hmonths);

        // ---- 1. applications by application date x current status (state)
        $appsByStatus = ['day' => $this->statusSeries($state, $days, $di, 'd', []), 'month' => $this->statusSeries($state, $months, $mi, 'm', [])];

        // funnel by application date (state): where the applications submitted in that period stand today
        $funnelDef = [
            ['apps',     'Applications submitted',                        static fn (array $r): bool => true],
            ['approved', 'Approved by committee (underwriting status 16)', static fn (array $r): bool => $r['uw'] === 16],
            ['signed',   'Signed or Active (status 11 / 5 / 1)',           static fn (array $r): bool => in_array($r['st'], [11, 5, 1], true)],
            ['active',   'of which Active (status 5 / 1)',                 static fn (array $r): bool => in_array($r['st'], [5, 1], true)],
            ['open',     'Still in process (4 / 7 / 8 / 15 / 16 / 17 / 9 / 10)', static fn (array $r): bool => in_array($r['st'], [4, 7, 8, 15, 16, 17, 9, 10], true)],
            ['rejected', 'Rejected (status 6)',                            static fn (array $r): bool => $r['st'] === 6],
            ['declined', 'Customer declined (status 12)',                  static fn (array $r): bool => $r['st'] === 12],
            ['expired',  'Expired (status 13)',                            static fn (array $r): bool => $r['st'] === 13],
            ['other',    'Other (single payment 99 / unverified 3, 14)',   static fn (array $r): bool => in_array($r['st'], [99, 3, 14], true)],
        ];
        $funnel = ['day' => self::keyedSeries($funnelDef, $state, $days, $di, 'd'), 'month' => self::keyedSeries($funnelDef, $state, $months, $mi, 'm')];

        // underwriting outcome by application date (state of crm_underwriter_status_id today)
        $uwDef = [
            ['apps', 'Applications submitted', static fn (array $r): bool => true],
            ['uw16', 'Approved (16)', static fn (array $r): bool => $r['uw'] === 16],
            ['uw6',  'Rejected (6) ' . self::DASH . ' at committee or before it', static fn (array $r): bool => $r['uw'] === 6],
            ['uw15', 'Clarification needed (15)', static fn (array $r): bool => $r['uw'] === 15],
            ['uw0',  'No underwriting decision yet', static fn (array $r): bool => $r['uw'] === 0],
        ];
        $uwOutcome = ['day' => self::keyedSeries($uwDef, $state, $days, $di, 'd'), 'month' => self::keyedSeries($uwDef, $state, $months, $mi, 'm')];

        // ---- 2. flow: status-change events by day (rows keyed by the status entered)
        $flowRows = array_map(static fn (array $r): array => ['d' => $r['d'], 'm' => $r['m'], 'st' => $r['t'], 'uw' => 0, 'n' => $r['n']], $flow);
        $flowByStatus = ['day' => $this->statusSeries($flowRows, $hdays, $hdi, 'd', self::FLOW_LABEL), 'month' => $this->statusSeries($flowRows, $hmonths, $hmi, 'm', self::FLOW_LABEL)];

        // ---- 3. committee decisions (events out of status 8) + queue stock
        $queue = [];
        foreach ($queueRows as $r) { $queue[(string) $r['status']][(string) $r['d']] = (int) $r['n']; }
        $lastDayInMonth = static function (string $m) use ($hdays): string {
            $last = '';
            foreach ($hdays as $d) { if (substr($d, 0, 7) === $m) { $last = $d; } }
            return $last;
        };
        $decisions = [
            'day' => self::decisionSeries($flow, $queue, $hdays, $hdi, 'd', static fn (string $k): string => $k),
            'month' => self::decisionSeries($flow, $queue, $hmonths, $hmi, 'm', $lastDayInMonth),
        ];

        // ---- 4. decisions per underwriter (actor of the events out of status 8)
        $actors = [];   // Map(id -> name): first-insertion position, last name wins
        foreach ($uw as $r) {
            if (!isset($actors[$r['id']])) { $actors[$r['id']] = ['id' => $r['id'], 'name' => $r['name'], 'n' => 0]; }
            $actors[$r['id']]['name'] = $r['name'];
        }
        foreach ($uw as $r) { $actors[$r['id']]['n'] += $r['n']; }
        $actors = array_values($actors);
        usort($actors, static fn (array $a, array $b): int => $b['n'] <=> $a['n']);
        $perUwRows = [];
        foreach ($actors as $a) {
            $vals = [];
            foreach ($hdays as $d) {
                $t = 0;
                foreach ($uw as $x) { if ($x['id'] === $a['id'] && $x['d'] === $d) { $t += $x['n']; } }
                $vals[] = $t;
            }
            $perUwRows[] = ['label' => $a['name'], 'vals' => $vals];
        }
        $perUwDay = ['keys' => $hdays, 'rows' => $perUwRows, 'total' => self::sumRows($perUwRows, count($hdays))];
        $cellsFor = static function (array $rows) use ($hmonths): array {
            $out = [];
            foreach ($hmonths as $m) {
                $s = [16 => 0, 15 => 0, 6 => 0];
                foreach ($rows as $x) { if ($x['m'] === $m && isset($s[$x['t']])) { $s[$x['t']] += $x['n']; } }
                $ap = $s[16]; $ret = $s[15]; $rej = $s[6]; $all = $ap + $ret + $rej;
                $out[] = ['dec' => $all, 'ap' => $ap, 'ret' => $ret, 'rej' => $rej, 'rate' => $all ? self::num($ap / $all) : null];
            }
            return $out;
        };
        $perUwMonthRows = [];
        foreach ($actors as $a) {
            $perUwMonthRows[] = ['label' => $a['name'], 'cells' => $cellsFor(array_values(array_filter($uw, static fn (array $x): bool => $x['id'] === $a['id'])))];
        }
        $perUwMonth = ['keys' => $hmonths, 'rows' => $perUwMonthRows, 'total' => ['label' => 'Total', 'cells' => $cellsFor($uw)]];

        // ---- 5. rejection reasons (-> 6): committee (from 8 / 15) by reason; all rejections by stage
        $committee = static fn (array $r): bool => $r['f'] === 8 || $r['f'] === 15;
        $committeeReasons = ['day' => self::reasonSeries($reasons, $hdays, $hdi, 'd', $committee), 'month' => self::reasonSeries($reasons, $hmonths, $hmi, 'm', $committee)];
        $rejectionsByStage = ['day' => self::stageSeries($reasons, $hdays, $hdi, 'd'), 'month' => self::stageSeries($reasons, $hmonths, $hmi, 'm')];

        $legend = [];
        foreach (self::STATUS as [$code, $lab, $crm, $verified]) { $legend[] = ['code' => $code, 'label' => $lab, 'crm' => $crm, 'verified' => $verified]; }

        return [
            'cutover' => $cutover, 'historyStart' => self::HISTORY_START, 'monthStart' => self::MONTH_START, 'end' => $endDay,
            'statusLegend' => $legend,
            'appsByStatus' => $appsByStatus, 'funnel' => $funnel, 'uwOutcome' => $uwOutcome, 'flowByStatus' => $flowByStatus, 'decisions' => $decisions,
            'perUwDay' => $perUwDay, 'perUwMonth' => $perUwMonth, 'committeeReasons' => $committeeReasons, 'rejectionsByStage' => $rejectionsByStage,
            'generatedAt' => gmdate('Y-m-d H:i') . ' UTC',
        ];
    }

    // ------------------------------------------------------------------ series builders (build_ops.js twins)

    /**
     * statusSeries / flowSeries: rows per status code in STATUS order (only codes present), then unknown codes ascending.
     * @param list<array{d:string,m:string,st:int,n:int}> $rows
     * @param array<int,string> $labelOverride
     */
    private function statusSeries(array $rows, array $keys, array $ki, string $keyOf, array $labelOverride): array
    {
        $zero = array_fill(0, count($keys), 0);
        $by = [];
        foreach ($rows as $r) {
            $k = $r[$keyOf];
            if (!isset($ki[$k])) { continue; }
            $by[$r['st']] ??= $zero;
            $by[$r['st']][$ki[$k]] += $r['n'];
        }
        $known = [];
        foreach (self::STATUS as [$code, $lab]) { $known[$code] = $lab; }
        $out = [];
        foreach (self::STATUS as [$code]) {
            if (isset($by[$code])) { $out[] = ['code' => $code, 'label' => $labelOverride[$code] ?? $known[$code], 'vals' => $by[$code]]; }
        }
        $extra = [];
        foreach ($by as $code => $vals) { if (!isset($known[$code])) { $extra[$code] = $vals; } }
        ksort($extra);   // Object.keys(): integer-like keys ascending
        foreach ($extra as $code => $vals) { $out[] = ['code' => $code, 'label' => 'Status ' . $code . ' (unverified)', 'vals' => $vals]; }
        return ['keys' => $keys, 'rows' => $out, 'total' => self::sumRows($out, count($keys))];
    }

    /** funnelSeries / uwSeries: fixed row list [key, label, test], each row sums the state rows that pass its test */
    private static function keyedSeries(array $def, array $state, array $keys, array $ki, string $keyOf): array
    {
        $zero = array_fill(0, count($keys), 0);
        $rows = [];
        foreach ($def as [$key, $lab]) { $rows[] = ['key' => $key, 'label' => $lab, 'vals' => $zero]; }
        foreach ($state as $r) {
            $k = $r[$keyOf];
            if (!isset($ki[$k])) { continue; }
            foreach ($def as $j => [, , $test]) { if ($test($r)) { $rows[$j]['vals'][$ki[$k]] += $r['n']; } }
        }
        return ['keys' => $keys, 'rows' => $rows];
    }

    /** @param callable(string):string $lastDayOfKey */
    private static function decisionSeries(array $flow, array $queue, array $keys, array $ki, string $keyOf, callable $lastDayOfKey): array
    {
        $n = count($keys);
        $pick = static function (?int $f, ?int $t) use ($flow, $keys, $ki, $keyOf, $n): array {
            $v = array_fill(0, $n, 0);
            foreach ($flow as $r) {
                $k = $r[$keyOf];
                if (isset($ki[$k]) && ($f === null || $r['f'] === $f) && ($t === null || $r['t'] === $t)) { $v[$ki[$k]] += $r['n']; }
            }
            return $v;
        };
        $approved = $pick(8, 16);
        $returned = $pick(8, 15);
        $rejected = $pick(8, 6);
        $rows = [
            ['key' => 'approved', 'label' => 'Approved (8 ' . self::ARROW . ' 16)', 'vals' => $approved],
            ['key' => 'returned', 'label' => 'Returned for clarification (8 ' . self::ARROW . ' 15)', 'vals' => $returned],
            ['key' => 'rejected', 'label' => 'Rejected (8 ' . self::ARROW . ' 6)', 'vals' => $rejected],
        ];
        $total = self::sumRows($rows, $n);
        $stock = static function (string $st) use ($keys, $queue, $lastDayOfKey): array {
            $out = [];
            foreach ($keys as $k) { $out[] = $queue[$st][$lastDayOfKey($k)] ?? 0; }
            return $out;
        };
        $rate = [];
        foreach ($total as $i => $t) { $rate[] = $t ? self::num($approved[$i] / $t) : null; }
        $memo = [
            ['key' => 'rate', 'label' => 'Approval rate (approved / decisions)', 'vals' => $rate, 'fmt' => 'pct'],
            ['key' => 'sent', 'label' => 'Sent to committee (' . self::ARROW . ' 8, all)', 'vals' => $pick(null, 8)],
            ['key' => 'resent', 'label' => 'of which resubmitted after clarification (15 ' . self::ARROW . ' 8)', 'vals' => $pick(15, 8)],
            ['key' => 'queue8', 'label' => 'At committee at end of period (stock, status 8)', 'vals' => $stock('8')],
            ['key' => 'queue15', 'label' => 'Awaiting clarification at end of period (stock, status 15)', 'vals' => $stock('15')],
        ];
        return ['keys' => $keys, 'rows' => $rows, 'total' => $total, 'memo' => $memo];
    }

    /** @param callable(array):bool $filter */
    private static function reasonSeries(array $reasons, array $keys, array $ki, string $keyOf, callable $filter): array
    {
        $zero = array_fill(0, count($keys), 0);
        $by = [];   // label -> vals, insertion order (labels are never integer-like)
        foreach ($reasons as $r) {
            $k = $r[$keyOf];
            if (!isset($ki[$k]) || !$filter($r)) { continue; }
            $by[$r['key']] ??= $zero;
            $by[$r['key']][$ki[$k]] += $r['n'];
        }
        $rows = [];
        foreach ($by as $lab => $vals) { $rows[] = ['label' => (string) $lab, 'vals' => $vals]; }
        $tail = static fn (string $l): int => (str_starts_with($l, 'Other') || str_starts_with($l, '(no')) ? 1 : 0;
        usort($rows, static fn (array $a, array $b): int => ($tail($a['label']) <=> $tail($b['label'])) ?: (array_sum($b['vals']) <=> array_sum($a['vals'])));
        return ['keys' => $keys, 'rows' => $rows, 'total' => self::sumRows($rows, count($keys))];
    }

    private static function stageSeries(array $reasons, array $keys, array $ki, string $keyOf): array
    {
        $zero = array_fill(0, count($keys), 0);
        $by = [];
        foreach ($reasons as $r) {
            $k = $r[$keyOf];
            if (!isset($ki[$k])) { continue; }
            $lab = self::STAGE[$r['f']] ?? ('from status ' . $r['f']);
            $by[$lab] ??= $zero;
            $by[$lab][$ki[$k]] += $r['n'];
        }
        $order = array_values(self::STAGE);   // Object.values(STAGE): integer keys ascending
        $rank = static function (string $l) use ($order): int { $i = array_search($l, $order, true); return $i === false ? 99 : $i + 1; };
        $rows = [];
        foreach ($by as $lab => $vals) { $rows[] = ['label' => (string) $lab, 'vals' => $vals]; }
        usort($rows, static fn (array $a, array $b): int => $rank($a['label']) <=> $rank($b['label']));
        return ['keys' => $keys, 'rows' => $rows, 'total' => self::sumRows($rows, count($keys))];
    }

    // ------------------------------------------------------------------ helpers

    /** runs a prepared statement, records its wall time under $name, returns all rows */
    private function q(string $name, string $sql, array $params): array
    {
        $t = microtime(true);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $this->timings[$name] = microtime(true) - $t;
        return $rows;
    }

    /** build_ops.js reasonKey: JS-trimmed text; '' -> no reason; exact standard label -> 'ka / en' (or 'ka' if identical); else Other */
    private static function reasonKey(string $txt): string
    {
        $t = self::jsTrim($txt);
        if ($t === '') { return '(no reason recorded)'; }
        foreach (self::REASONS as [$ka, $en]) {
            if ($ka === $t) { return $ka === $en ? $ka : $ka . ' / ' . $en; }
        }
        return 'Other (free text)';
    }

    /** String.prototype.trim(): strips the JS WhiteSpace + LineTerminator set at both ends (no mbstring needed) */
    private static function jsTrim(string $s): string
    {
        $ws = '[\x{9}-\x{D}\x{20}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+';
        $out = preg_replace('/^' . $ws . '|' . $ws . '$/u', '', $s);
        return $out ?? trim($s);
    }

    /** @return list<string> */
    private static function dayRange(string $a, string $b): array
    {
        $out = [];
        $d = new \DateTimeImmutable($a . ' 00:00:00', new \DateTimeZone('UTC'));
        for ($s = $a; $s <= $b; $d = $d->modify('+1 day'), $s = $d->format('Y-m-d')) { $out[] = $s; }
        return $out;
    }

    /** @return list<string> */
    private static function monthRange(string $a, string $b): array
    {
        $out = [];
        [$y, $m] = array_map('intval', explode('-', $a));
        [$by, $bm] = array_map('intval', explode('-', $b));
        while ($y < $by || ($y === $by && $m <= $bm)) {
            $out[] = $y . '-' . str_pad((string) $m, 2, '0', STR_PAD_LEFT);
            $m++;
            if ($m > 12) { $m = 1; $y++; }
        }
        return $out;
    }

    /** @param list<array{vals:list<int>}> $rows */
    private static function sumRows(array $rows, int $n): array
    {
        $out = array_fill(0, $n, 0);
        foreach ($rows as $r) { foreach ($r['vals'] as $i => $v) { $out[$i] += $v; } }
        return $out;
    }

    /** JS-compatible number: whole floats become ints so json_encode prints 1, not 1.0 */
    private static function num(float|int $v): float|int
    {
        if (is_float($v) && floor($v) === $v && abs($v) < 1e15) {
            return (int) $v;
        }
        return $v;
    }
}
