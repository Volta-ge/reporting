<?php

declare(strict_types=1);

namespace Volta\Funnel;

use PDO;

/**
 * Marketing -> Leads for Volta_Analytics_New DB, computed live: the PHP twin of
 * volta-analytics-new-db/pull_mkt.sh + build_mkt.js. Output shape = exactly MKT_JSON (mkt_data.json).
 * Source: VoltaStoreDB `volta_leads` (daily aggregates only, no PII); the lead -> application conversion ties
 * leads to `orders` by phone number via `addresses` (order_billing) / `customers` (phone or volta_leads.customer_id).
 * Every series is keyed to the lead's creation day (DATE(volta_leads.created_at)); day tables run
 * DAY_START .. today, month tables first lead month .. current month (MTD) — the pull script's END defaults to
 * today and the Node build takes "today" from the last calendar day, so the calendar here ends today as well.
 */
final class NewDbMkt
{
    public const CUTOVER = '2026-08-31';
    /** first row in volta_leads (the lead-capture form went live that day) */
    private const LEADS_START = '2026-03-19';
    private const DAY_START = '2026-08-01';

    private const DAY_EXTRA_HEADS = ['Share {last}', 'Last 7 days', 'Share 7 days'];
    private const MONTH_EXTRA_HEADS = ['Share {last}', 'Total', 'Share total'];
    private const CITY_ORDER = ['Tbilisi', 'Batumi', 'Kutaisi', 'Rustavi', 'Gori', 'Zugdidi', 'Other Cities', 'Without City'];
    private const STEP_LABEL = [1 => 'Step 1 (contact details)', 2 => 'Step 2', 3 => 'Step 3 (form completed)'];
    private const MEMO_DEF = [['assigned', 'of which assigned to a sales manager'], ['repeat_', 'of which repeat submissions (phone already sent an earlier lead)']];
    private const CONV_COLS = [['leads', 'Leads created'], ['matched', 'Phone matched to an order or customer (any date)'], ['before_', 'of which already a customer (order before the lead)'],
        ['apps', 'Applications after the lead (any time)'], ['apps30', 'Applications within 30 days of the lead'], ['deals', 'Loans issued after the lead']];

    /** @var array<string, float> per-query wall time in seconds (name => s), filled by build() */
    private array $timings = [];

    /** @var list<string> calendar days LEADS_START .. today */
    private array $allDays = [];
    /** @var list<string> allDays >= DAY_START */
    private array $days = [];
    /** @var list<string> distinct months of allDays */
    private array $months = [];

    public function __construct(private readonly PDO $pdo, private readonly string $dir, private readonly string $mappingPath)
    {
    }

    /** @return array<string, float> seconds per query, in execution order */
    public function timings(): array
    {
        return $this->timings;
    }

    /**
     * @param string $end report END date (yesterday, YYYY-MM-DD) — accepted for signature parity; the Leads calendar
     *                    runs through today exactly like pull_mkt.sh's default END / build_mkt.js's `today`.
     */
    public function build(string $end): array
    {
        $this->timings = [];
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $arrow = "\u{2192}";

        // ---- (d) conversion first: it is calendar-driven and defines allDays / days / months
        $conv = $this->query('conv', "WITH RECURSIVE cal AS (SELECT DATE(:start) d UNION ALL SELECT d + INTERVAL 1 DAY FROM cal WHERE d < DATE(:end)),
            lo AS (SELECT l.id lead_id, o.id order_id FROM volta_leads l JOIN customers c ON c.phone=l.telephone OR c.id=l.customer_id JOIN orders o ON o.customer_id=c.id
                   UNION SELECT l.id, a.order_id FROM volta_leads l JOIN addresses a ON a.phone=l.telephone AND a.address_type='order_billing' AND a.order_id IS NOT NULL),
            m AS (SELECT l.id lead_id, MIN(CASE WHEN o.created_at>=l.created_at THEN o.created_at END) first_after,
                         MIN(CASE WHEN o.created_at>=l.created_at AND (o.crm_order_status IN (5,99) OR o.crm_active=1) THEN o.created_at END) first_deal,
                         SUM(o.created_at<l.created_at) before_n
                  FROM volta_leads l JOIN lo ON lo.lead_id=l.id JOIN orders o ON o.id=lo.order_id GROUP BY l.id)
            SELECT cal.d, COUNT(l.id) leads, SUM(m.lead_id IS NOT NULL) matched, SUM(m.first_after IS NOT NULL) apps,
              SUM(m.first_after IS NOT NULL AND m.first_after < l.created_at + INTERVAL 30 DAY) apps30, SUM(m.first_deal IS NOT NULL) deals, SUM(m.before_n>0) before_
            FROM cal LEFT JOIN volta_leads l ON DATE(l.created_at)=cal.d LEFT JOIN m ON m.lead_id=l.id GROUP BY cal.d ORDER BY cal.d",
            ['start' => self::LEADS_START, 'end' => $today]);
        if (!$conv) {
            throw new \RuntimeException('conversion extract is empty');
        }
        $this->allDays = array_map(static fn ($r) => (string) $r['d'], $conv);
        $leadsStart = $this->allDays[0];
        $today = $this->allDays[array_key_last($this->allDays)];
        $this->days = array_values(array_filter($this->allDays, static fn ($d) => $d >= self::DAY_START));
        $months = [];
        foreach ($this->allDays as $d) { $months[substr($d, 0, 7)] = true; }
        $this->months = array_keys($months);
        sort($this->months, SORT_STRING);
        $mtd = $this->months[array_key_last($this->months)];

        // ---- status catalogue (CRM marketing statuses, id order; volta_leads.status stores the lower-cased name)
        $statusCat = [];
        foreach ($this->query('statuses', 'SELECT id, name, active FROM crm_marketing_statuses ORDER BY id') as $r) {
            $statusCat[] = ['key' => self::lower((string) $r['name']), 'label' => (string) $r['name']];
        }

        // ---- (a) new leads per day by CURRENT status + memo counts (assigned / repeat submissions)
        // repeat_ in pull_mkt.sh is a correlated EXISTS (an earlier lead with the same phone: created_at <, or = and id <);
        // volta_leads.telephone has no index so that scans 8.5k x 8.5k rows (~42 s live). ROW_NUMBER() over the same
        // ordering is the set-identical rewrite (verified row-for-row on live data, 0.2 s); telephone is NOT NULL.
        $statusRaw = $this->query('status', "SELECT DATE(l.created_at) d, LOWER(l.status) status, COUNT(*) n, SUM(l.crm_sales_manager_id IS NOT NULL) assigned, SUM(l.rn > 1) repeat_
            FROM (SELECT id, status, created_at, crm_sales_manager_id, ROW_NUMBER() OVER (PARTITION BY telephone ORDER BY created_at, id) rn FROM volta_leads WHERE created_at IS NOT NULL) l
            WHERE DATE(l.created_at) BETWEEN :start AND :end GROUP BY d, status ORDER BY d, status",
            ['start' => self::LEADS_START, 'end' => $today]);
        $statusLabel = static function (string $k) use ($statusCat): string {
            foreach ($statusCat as $c) { if ($c['key'] === $k) { return $c['label']; } }
            return 'Status "' . $k . '"';
        };
        // Node keys the status by the TSV text: a NULL status prints as 'NULL' (key 'NULL', label 'Status "NULL"')
        $st = $this->seriesOf(array_map(static fn ($r) => ['d' => (string) $r['d'], 'key' => $r['status'] === null ? 'NULL' : (string) $r['status'], 'n' => self::intOf($r['n'])], $statusRaw),
            array_map(static fn ($c) => $c['key'], $statusCat), $statusLabel);
        $memo = [];
        foreach (self::MEMO_DEF as [$col, $label]) {
            $s = $this->seriesOf(array_map(static fn ($r) => ['d' => (string) $r['d'], 'key' => 'x', 'n' => self::intOf($r[$col])], $statusRaw), ['x'], static fn () => $label);
            $memo[] = ['label' => $label, 'day' => $s['dayRows'][0]['vals'], 'month' => $s['monthRows'][0]['vals']];
        }
        $statusPack = function (array $rows, bool $isDay) use ($memo): array {
            $tot = self::sumRows($rows, $isDay ? count($this->days) : count($this->months));
            $p = self::withExtras($rows, $tot, $isDay);
            $p['memos'] = [];
            foreach ($memo as $m) {
                $vals = $isDay ? $m['day'] : $m['month'];
                $p['memos'][] = ['label' => $m['label'], 'vals' => $vals, 'ex' => self::extras($vals, $tot, $isDay)];
            }
            return $p;
        };
        $status = ['title' => 'New leads by status (current status of the lead)', 'headLabel' => 'Status', 'day' => $statusPack($st['dayRows'], true), 'month' => $statusPack($st['monthRows'], false)];

        // ---- (b) new leads per day by the last form step reached
        $stepRaw = $this->query('step', 'SELECT DATE(created_at) d, last_step step, COUNT(*) n FROM volta_leads WHERE DATE(created_at) BETWEEN :start AND :end GROUP BY d, step ORDER BY d, step',
            ['start' => self::LEADS_START, 'end' => $today]);
        $stepLabel = static fn (string $k) => self::STEP_LABEL[(int) $k] ?? ('Step ' . $k);
        // Node keys the step by the TSV text: NULL prints as 'NULL' and labels 'Step NULL'
        $stepKey = static fn ($v) => $v === null ? 'NULL' : (string) $v;
        $sp = $this->seriesOf(array_map(static fn ($r) => ['d' => (string) $r['d'], 'key' => $stepKey($r['step']), 'n' => self::intOf($r['n'])], $stepRaw), ['1', '2', '3'], $stepLabel);
        $step = ['title' => 'New leads by form step reached', 'headLabel' => 'Last step',
            'day' => self::withExtras($sp['dayRows'], self::sumRows($sp['dayRows'], count($this->days)), true),
            'month' => self::withExtras($sp['monthRows'], self::sumRows($sp['monthRows'], count($this->months)), false)];

        // ---- (c) new leads per day by city group (free-text city, Georgian and Latin spellings folded together)
        $cityRaw = $this->query('city', "SELECT DATE(created_at) d,
              CASE WHEN city IS NULL OR TRIM(city)='' THEN 'Without City'
                   WHEN LOWER(TRIM(city)) IN ('თბილისი','tbilisi','t''bilisi','tbilisi ') THEN 'Tbilisi'
                   WHEN LOWER(TRIM(city)) IN ('ბათუმი','batumi') THEN 'Batumi'
                   WHEN LOWER(TRIM(city)) IN ('ქუთაისი','kutaisi','qutaisi') THEN 'Kutaisi'
                   WHEN LOWER(TRIM(city)) IN ('რუსთავი','rustavi') THEN 'Rustavi'
                   WHEN LOWER(TRIM(city)) IN ('გორი','gori') THEN 'Gori'
                   WHEN LOWER(TRIM(city)) IN ('ზუგდიდი','zugdidi') THEN 'Zugdidi'
                   ELSE 'Other Cities' END city_grp, COUNT(*) n
            FROM volta_leads WHERE DATE(created_at) BETWEEN :start AND :end GROUP BY d, city_grp ORDER BY d, city_grp",
            ['start' => self::LEADS_START, 'end' => $today]);
        $ct = $this->seriesOf(array_map(static fn ($r) => ['d' => (string) $r['d'], 'key' => (string) $r['city_grp'], 'n' => self::intOf($r['n'])], $cityRaw), self::CITY_ORDER, static fn (string $k) => $k);
        $city = ['title' => 'New leads by city', 'headLabel' => 'City',
            'day' => self::withExtras($ct['dayRows'], self::sumRows($ct['dayRows'], count($this->days)), true),
            'month' => self::withExtras($ct['monthRows'], self::sumRows($ct['monthRows'], count($this->months)), false)];

        // ---- (d) lead -> application conversion (counts + ratio rows)
        $convSeries = [];
        foreach (self::CONV_COLS as [$col, $label]) {
            $s = $this->seriesOf(array_map(static fn ($r) => ['d' => (string) $r['d'], 'key' => 'x', 'n' => self::intOf($r[$col])], $conv), ['x'], static fn () => $label);
            $convSeries[$col] = ['label' => $label, 'day' => $s['dayRows'][0]['vals'], 'month' => $s['monthRows'][0]['vals']];
        }
        $convPack = static function (bool $isDay) use ($convSeries, $arrow): array {
            $v = static fn (string $col) => $isDay ? $convSeries[$col]['day'] : $convSeries[$col]['month'];
            $leads = $v('leads');
            $agg = static fn (array $vals) => $isDay ? self::last7($vals) : self::total($vals);
            $nRow = static fn (string $col) => ['label' => $convSeries[$col]['label'], 'kind' => 'n', 'vals' => $v($col), 'ex' => [['n' => $agg($v($col))]]];
            $pRow = static function (string $label, string $col) use ($v, $leads, $agg): array {
                $c = $v($col);
                $vals = [];
                foreach ($leads as $i => $l) { $vals[] = self::sh($c[$i], $l); }
                return ['label' => $label, 'kind' => 'pct', 'vals' => $vals, 'ex' => [['pct' => self::sh($agg($c), $agg($leads))]]];
            };
            return ['rows' => [$nRow('leads'), $nRow('matched'), $nRow('before_'), $nRow('apps'), $pRow('Lead ' . $arrow . ' application rate (any time)', 'apps'),
                $nRow('apps30'), $pRow('Lead ' . $arrow . ' application rate (30 days)', 'apps30'), $nRow('deals'), $pRow('Lead ' . $arrow . ' loan rate', 'deals')],
                'extraHeads' => $isDay ? ['Last 7 days'] : ['Total']];
        };
        $convT = ['title' => 'Lead ' . $arrow . ' application conversion (by lead creation date)', 'headLabel' => 'Metric', 'day' => $convPack(true), 'month' => $convPack(false)];

        return ['days' => $this->days, 'months' => $this->months, 'mtd' => $mtd, 'today' => $today, 'dayStart' => self::DAY_START, 'leadsStart' => $leadsStart, 'cutover' => self::CUTOVER,
            'dayExtraHeads' => self::DAY_EXTRA_HEADS, 'monthExtraHeads' => self::MONTH_EXTRA_HEADS, 'status' => $status, 'step' => $step, 'city' => $city, 'conv' => $convT,
            'generatedAt' => gmdate('Y-m-d H:i') . ' UTC'];
    }

    // ------------------------------------------------------------------ series helpers (build_mkt.js twins)

    /**
     * Generic day/month series builder. recs = [{d, key, n}]; row order = keys, then any extra keys sorted (JS .sort()).
     * @param list<array{d:string,key:string,n:int}> $recs
     * @param list<string> $keys
     * @return array{order: list<string>, dayRows: list<array>, monthRows: list<array>}
     */
    private function seriesOf(array $recs, array $keys, callable $labelOf): array
    {
        $byKey = [];
        foreach ($recs as $r) {
            $byKey[$r['key']][$r['d']] = ($byKey[$r['key']][$r['d']] ?? 0) + $r['n'];
        }
        $extra = [];
        foreach (array_keys($byKey) as $k) {
            $k = (string) $k;
            if (!in_array($k, $keys, true)) { $extra[] = $k; }
        }
        sort($extra, SORT_STRING);
        $order = array_merge($keys, $extra);
        $dayRows = [];
        $monthRows = [];
        foreach ($order as $k) {
            $dayVals = [];
            foreach ($this->days as $d) { $dayVals[] = $byKey[$k][$d] ?? 0; }
            $monthVals = [];
            foreach ($this->months as $m) {
                $t = 0;
                foreach ($this->allDays as $d) { if (substr($d, 0, 7) === $m) { $t += $byKey[$k][$d] ?? 0; } }
                $monthVals[] = $t;
            }
            $dayRows[] = ['key' => $k, 'label' => $labelOf($k), 'vals' => $dayVals];
            $monthRows[] = ['key' => $k, 'label' => $labelOf($k), 'vals' => $monthVals];
        }
        return ['order' => $order, 'dayRows' => $dayRows, 'monthRows' => $monthRows];
    }

    /** @return list<int> column sums over the rows' vals */
    private static function sumRows(array $rows, int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $t = 0;
            foreach ($rows as $r) { $t += $r['vals'][$i]; }
            $out[] = $t;
        }
        return $out;
    }

    private static function last7(array $vals): int
    {
        return (int) array_sum(array_slice($vals, -7));
    }

    private static function total(array $vals): int
    {
        return (int) array_sum($vals);
    }

    private static function sh(int|float $a, int|float $b): int|float
    {
        return $b ? self::num($a / $b) : 0;
    }

    /**
     * Trailing share columns: day tables -> share of the last day, last-7-days count, share of the last 7 days;
     * month tables -> share of the MTD month, all-time total, share of the all-time total.
     */
    private static function extras(array $vals, array $tot, bool $isDay): array
    {
        $last = $vals[array_key_last($vals)];
        $tlast = $tot[array_key_last($tot)];
        return $isDay
            ? [['pct' => self::sh($last, $tlast)], ['n' => self::last7($vals)], ['pct' => self::sh(self::last7($vals), self::last7($tot))]]
            : [['pct' => self::sh($last, $tlast)], ['n' => self::total($vals)], ['pct' => self::sh(self::total($vals), self::total($tot))]];
    }

    /** @return array{rows: list<array>, total: array} */
    private static function withExtras(array $rows, array $tot, bool $isDay): array
    {
        $out = [];
        foreach ($rows as $r) {
            $r['ex'] = self::extras($r['vals'], $tot, $isDay);
            $out[] = $r;
        }
        return ['rows' => $out, 'total' => ['vals' => $tot, 'ex' => self::extras($tot, $tot, $isDay)]];
    }

    // ------------------------------------------------------------------ helpers

    /** prepared + timed query; @return list<array<string,mixed>> */
    private function query(string $name, string $sql, array $params = []): array
    {
        $t = microtime(true);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $this->timings[$name] = microtime(true) - $t;
        return $rows;
    }

    /** Node `num(v)`: '' / NULL / undefined -> 0, else +v (COUNT arrives as int, SUM as a decimal string) */
    private static function intOf(mixed $v): int
    {
        if ($v === null || $v === '' || $v === 'NULL') { return 0; }
        return (int) $v;
    }

    /** mbstring is not guaranteed on the server; status names are Latin, Georgian has no case */
    private static function lower(string $s): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s);
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
