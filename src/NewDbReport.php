<?php

declare(strict_types=1);

namespace Volta\Funnel;

use PDO;

/**
 * Volta_Analytics_New DB, computed live: the same numbers the Node pipeline in volta-analytics-new-db/
 * produces (merge.js + build_report_data.js + build_sales.js), but built in PHP on request so the page is
 * always current. Hybrid source, per metric family:
 *   - before CUTOVER (2026-08-31): the frozen old-DB extracts (volta-analytics-new-db/old_*.tsv), never re-queried;
 *   - from CUTOVER on: VoltaStoreDB, queried here (the same SQL as volta-analytics-new-db/pull_new.sh).
 * Output shape = exactly REPORT_JSON / SALES_JSON as embedded in deals_amount_migration.html, so the same
 * static file doubles as the template (index.php swaps the two `const ... = {...};` lines).
 */
final class NewDbReport
{
    public const CUTOVER = '2026-08-31';
    /** first day of the CRM logistics module; the delivery tables start here (earlier orders were fulfilled outside it) */
    private const LOGI_START = '2026-09-02';
    private const SERIES_START = '2026-01-01';
    private const DAILY_STATS_FROM = '2026-06-01';
    private const OLD_BUCKET_END = '2026-08-30';

    private const SEG = "CASE WHEN EXISTS (SELECT 1 FROM order_items oi JOIN product_flat pf ON pf.product_id=oi.product_id AND pf.locale='ka_GE' WHERE oi.order_id=o.id AND (pf.name LIKE 'ტელეფონი%' OR pf.name LIKE 'ტელევიზორ%')) OR o.base_grand_total>2500 THEN 'A' ELSE 'B' END";
    private const METRICS = ['closed', 'amount', 'applications', 'terms', 'uw', 'dp'];
    private const NO_BRAND = ['none', 'n/a', 'ბრენდის გარეშე', ''];

    /** @var array<string, array{categoryGe:string,subcategoryGe:string,categoryEn:string,subcategoryEn:string,productEn:string}> */
    private array $mapping = [];
    /** @var array<string,string> lower-cased old-DB brand -> its spelling */
    private array $oldBrandBySlug = [];

    public function __construct(private readonly PDO $pdo, private readonly string $dir, private readonly string $mappingPath)
    {
    }

    /** @return array{report: array, sales: array} */
    public function build(string $end): array
    {
        $generatedAt = gmdate('Y-m-d H:i') . ' UTC';
        $report = $this->dailyMail($end);
        $report['cutover'] = self::CUTOVER;
        $report['generatedAt'] = $generatedAt;
        $sales = $this->salesAnalyze($end);
        $sales['cutover'] = self::CUTOVER;
        $sales['generatedAt'] = $generatedAt;
        $logistics = $this->logistics();
        $logistics['cutover'] = self::CUTOVER;
        $logistics['generatedAt'] = $generatedAt;
        return ['report' => $report, 'sales' => $sales, 'logistics' => $logistics];
    }

    // ------------------------------------------------------------------ Logistics Daily

    private const LOGI_STATUS_LABEL = [
        20 => 'დაწყებული / Started', 25 => 'მოძიება / Procuring', 30 => 'მზადაა მომწოდებელთან / Ready at vendor',
        35 => 'აღებულია მომწოდებლისგან / Collected', 40 => 'საწყობისკენ / To warehouse', 45 => 'საწყობშია / Warehouse',
        48 => 'სტატუსი 48', 50 => 'გასაგზავნად მზადაა / Ready to ship', 60 => 'გზაშია / Out for delivery',
        80 => 'მიტანილი / Delivered', 81 => 'გატანილი / Picked up',
    ];

    /** Same series/snapshots as volta-analytics-new-db/build_logistics.js (SQL identical to pull_new.sh); through today. */
    private function logistics(): array
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $cutover = self::CUTOVER;
        $logiStart = self::LOGI_START;
        $numOrNull = static fn ($v) => ($v === null || $v === '') ? null : self::num((float) $v);

        $stmt = $this->pdo->prepare("WITH RECURSIVE cal AS (SELECT DATE(:cutover) d UNION ALL SELECT d + INTERVAL 1 DAY FROM cal WHERE d < DATE(:today))
            SELECT cal.d, SUM(DATEDIFF(cal.d, DATE(o.created_at)) <= 1) upTo1, SUM(DATEDIFF(cal.d, DATE(o.created_at)) BETWEEN 2 AND 5) oneTo5, SUM(DATEDIFF(cal.d, DATE(o.created_at)) > 5) over5
            FROM cal JOIN orders o ON o.created_at >= :cutover2 AND o.created_at < cal.d + INTERVAL 1 DAY
            LEFT JOIN (SELECT entity_id, MIN(created_at) left_at FROM crm_activity_log WHERE action='installment.status_change' AND JSON_EXTRACT(metadata,'$.from')=4 GROUP BY entity_id) x ON x.entity_id=o.id
            WHERE (x.entity_id IS NOT NULL OR o.crm_order_status=4) AND (x.left_at IS NULL OR x.left_at >= cal.d + INTERVAL 1 DAY)
            GROUP BY cal.d ORDER BY cal.d");
        $stmt->execute(['cutover' => $cutover, 'today' => $today, 'cutover2' => $cutover]);
        $pending = ['dates' => [], 'upTo1' => [], 'oneTo5' => [], 'over5' => []];
        foreach ($stmt->fetchAll() as $r) {
            $pending['dates'][] = $r['d'];
            $pending['upTo1'][] = (int) $r['upTo1'];
            $pending['oneTo5'][] = (int) $r['oneTo5'];
            $pending['over5'][] = (int) $r['over5'];
        }

        $stmt = $this->pdo->prepare("WITH RECURSIVE cal AS (SELECT DATE(:cutover) d UNION ALL SELECT d + INTERVAL 1 DAY FROM cal WHERE d < DATE(:today)),
            pop AS (SELECT o.id, COALESCE(o.crm_creator_date, o.created_at) sale, dl.delivered_at FROM orders o LEFT JOIN (SELECT order_id, MIN(created_at) delivered_at FROM crm_shipment_status_history WHERE entity_type=4 AND to_status IN (80,81) GROUP BY order_id) dl ON dl.order_id=o.id WHERE o.crm_active=1 AND o.crm_creator_date >= :start2)
            SELECT cal.d,
              SUM(pop.sale < cal.d + INTERVAL 1 DAY AND (pop.delivered_at IS NULL OR pop.delivered_at >= cal.d + INTERVAL 1 DAY) AND DATEDIFF(cal.d, DATE(pop.sale)) <= 1) upTo1,
              SUM(pop.sale < cal.d + INTERVAL 1 DAY AND (pop.delivered_at IS NULL OR pop.delivered_at >= cal.d + INTERVAL 1 DAY) AND DATEDIFF(cal.d, DATE(pop.sale)) BETWEEN 2 AND 5) oneTo5,
              SUM(pop.sale < cal.d + INTERVAL 1 DAY AND (pop.delivered_at IS NULL OR pop.delivered_at >= cal.d + INTERVAL 1 DAY) AND DATEDIFF(cal.d, DATE(pop.sale)) > 5) over5,
              SUM(DATE(pop.delivered_at) = cal.d) delivered,
              ROUND(AVG(CASE WHEN DATE(pop.delivered_at) = cal.d THEN TIMESTAMPDIFF(HOUR, pop.sale, pop.delivered_at)/24 END),1) avgDays
            FROM cal LEFT JOIN pop ON pop.sale < cal.d + INTERVAL 1 DAY GROUP BY cal.d ORDER BY cal.d");
        $stmt->execute(['cutover' => $logiStart, 'today' => $today, 'start2' => $logiStart]);
        $delivery = ['dates' => [], 'upTo1' => [], 'oneTo5' => [], 'over5' => [], 'onHold' => [], 'delivered' => [], 'avgDeliveryTime' => []];
        foreach ($stmt->fetchAll() as $r) {
            $delivery['dates'][] = $r['d'];
            $delivery['upTo1'][] = (int) $r['upTo1'];
            $delivery['oneTo5'][] = (int) $r['oneTo5'];
            $delivery['over5'][] = (int) $r['over5'];
            $delivery['onHold'][] = null;
            $delivery['delivered'][] = (int) $r['delivered'];
            $delivery['avgDeliveryTime'][] = $numOrNull($r['avgDays']);
        }

        $stmt = $this->pdo->prepare("SELECT CASE WHEN a.city IS NULL OR a.city='' THEN 'Without City' WHEN a.city='თბილისი' THEN 'Tbilisi' ELSE 'Other Cities' END grp, SUM(dl.order_id IS NULL) notDelivered, COUNT(*) allOrders
            FROM orders o LEFT JOIN (SELECT order_id, MIN(city) city FROM addresses WHERE address_type='order_shipping' GROUP BY order_id) a ON a.order_id=o.id
            LEFT JOIN (SELECT order_id FROM crm_shipment_status_history WHERE entity_type=4 AND to_status IN (80,81) GROUP BY order_id) dl ON dl.order_id=o.id
            WHERE o.crm_active=1 AND o.crm_creator_date >= :start GROUP BY grp");
        $stmt->execute(['start' => $logiStart]);
        $cityMap = [];
        foreach ($stmt->fetchAll() as $r) {
            $cityMap[$r['grp']] = ['notDelivered' => (int) $r['notDelivered'], 'all' => (int) $r['allOrders']];
        }
        $cityRows = [];
        $totND = 0;
        $totAll = 0;
        foreach (['Tbilisi', 'Other Cities', 'Without City'] as $label) {
            $c = $cityMap[$label] ?? ['notDelivered' => 0, 'all' => 0];
            $cityRows[] = ['label' => $label, 'notDelivered' => $c['notDelivered'], 'all' => $c['all']];
            $totND += $c['notDelivered'];
            $totAll += $c['all'];
        }
        foreach ($cityRows as &$cr) {
            $cr['share'] = $totND ? self::num($cr['notDelivered'] / $totND) : 0;
        }
        unset($cr);
        $byCity = ['title' => 'Orders by City', 'headLabel' => 'City', 'rows' => $cityRows, 'total' => ['label' => 'Total', 'notDelivered' => $totND, 'all' => $totAll, 'share' => $totND ? 1 : 0]];

        // goods type = mapping-sheet categoryEn of each line's product
        $this->loadMapping();
        $cats = [];
        foreach ($this->pdo->query("SELECT c.id, c.parent_id, ct.name FROM categories c LEFT JOIN category_translations ct ON ct.category_id=c.id AND ct.locale='ka_GE'")->fetchAll() as $r) {
            $cats[(string) $r['id']] = ['parent' => (string) ($r['parent_id'] ?? ''), 'name' => trim((string) ($r['name'] ?? ''))];
        }
        $depth = static function (string $id) use ($cats): int {
            $d = 0;
            $c = $cats[$id] ?? null;
            while ($c && $c['parent'] !== '' && isset($cats[$c['parent']])) { $d++; $c = $cats[$c['parent']]; }
            return $d;
        };
        $productCats = [];
        foreach ($this->pdo->query('SELECT product_id, category_id FROM product_categories ORDER BY product_id, category_id')->fetchAll() as $r) {
            $productCats[(string) $r['product_id']][] = (string) $r['category_id'];
        }
        $goodsType = function (string $productId) use ($productCats, $cats, $depth): string {
            $ids = $productCats[$productId] ?? [];
            usort($ids, static fn ($a, $b) => $depth($a) <=> $depth($b));
            $names = [];
            foreach ($ids as $id) { $n = $cats[$id]['name'] ?? ''; if ($n !== '' && $n !== 'none') { $names[] = $n; } }
            $s = self::label(($this->lookup(implode(',', $names)) ?? [])['categoryEn'] ?? null);
            return $s ?? 'Uncategorized';
        };
        $stmt = $this->pdo->prepare("SELECT o.id order_id, oi.product_id, (dl.order_id IS NULL) not_delivered FROM orders o JOIN order_items oi ON oi.order_id=o.id
            LEFT JOIN (SELECT order_id FROM crm_shipment_status_history WHERE entity_type=4 AND to_status IN (80,81) GROUP BY order_id) dl ON dl.order_id=o.id
            WHERE o.crm_active=1 AND o.crm_creator_date >= :start ORDER BY o.id, oi.id");
        $stmt->execute(['start' => $logiStart]);
        $goods = [];
        foreach ($stmt->fetchAll() as $l) {
            $g = $goodsType((string) $l['product_id']);
            $goods[$g] ??= ['notDelivered' => 0, 'all' => 0];
            $goods[$g]['all']++;
            if ((int) $l['not_delivered'] === 1) { $goods[$g]['notDelivered']++; }
        }
        $goodsRows = [];
        foreach (self::jsKeyOrder($goods) as $label) {
            $goodsRows[] = ['label' => (string) $label, 'notDelivered' => $goods[$label]['notDelivered'], 'all' => $goods[$label]['all']];
        }
        usort($goodsRows, static function ($a, $b) {
            $ua = $a['label'] === 'Uncategorized' ? 1 : 0;
            $ub = $b['label'] === 'Uncategorized' ? 1 : 0;
            return $ua <=> $ub ?: $b['all'] <=> $a['all'];
        });
        $gND = array_sum(array_column($goodsRows, 'notDelivered'));
        $gAll = array_sum(array_column($goodsRows, 'all'));
        foreach ($goodsRows as &$gr) {
            $gr['share'] = $gND ? self::num($gr['notDelivered'] / $gND) : 0;
        }
        unset($gr);
        $byGoods = ['title' => 'Orders by Goods Type', 'headLabel' => 'Goods Type', 'rows' => $goodsRows, 'total' => ['label' => 'Total', 'notDelivered' => $gND, 'all' => $gAll, 'share' => $gND ? 1 : 0]];

        $stmt = $this->pdo->prepare("SELECT o.id order_id, TRIM(CONCAT(COALESCE(o.customer_first_name,''),' ',COALESCE(o.customer_last_name,''))) customer, DATE(COALESCE(o.crm_creator_date,o.created_at)) waiting_from, l.logistics_status, COALESCE(a.city,'') city
            FROM orders o LEFT JOIN crm_order_logistics l ON l.order_id=o.id LEFT JOIN (SELECT order_id, MIN(city) city FROM addresses WHERE address_type='order_shipping' GROUP BY order_id) a ON a.order_id=o.id
            LEFT JOIN (SELECT order_id FROM crm_shipment_status_history WHERE entity_type=4 AND to_status IN (80,81) GROUP BY order_id) dl ON dl.order_id=o.id
            WHERE o.crm_active=1 AND o.crm_creator_date >= :start AND dl.order_id IS NULL ORDER BY COALESCE(o.crm_creator_date,o.created_at), o.id LIMIT 10");
        $stmt->execute(['start' => $logiStart]);
        $openCases = [];
        foreach ($stmt->fetchAll() as $r) {
            $code = $r['logistics_status'];
            $openCases[] = [
                'customer' => $r['customer'], 'waitingFrom' => $r['waiting_from'],
                'status' => ($code === null || $code === '') ? 'ლოგისტიკა არ დაწყებულა / Not started' : (self::LOGI_STATUS_LABEL[(int) $code] ?? ('სტატუსი ' . $code)),
                'city' => $r['city'] !== '' ? $r['city'] : '–', 'orderNum' => (int) $r['order_id'],
            ];
        }

        return ['pending' => $pending, 'delivery' => $delivery, 'byCity' => $byCity, 'byGoods' => $byGoods, 'openCases' => $openCases];
    }

    // ------------------------------------------------------------------ helpers

    /** @return list<array<string,string>> */
    private function tsv(string $file): array
    {
        $content = file_get_contents($this->dir . '/' . $file);
        if ($content === false) {
            throw new \RuntimeException("missing extract: $file");
        }
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        $lines = array_values(array_filter(preg_split('/\r?\n/', $content), static fn ($l) => $l !== ''));
        $header = explode("\t", array_shift($lines));
        $rows = [];
        foreach ($lines as $line) {
            $cols = explode("\t", $line);
            $row = [];
            foreach ($header as $i => $h) {
                $row[$h] = $cols[$i] ?? '';
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /** JS-compatible number: whole floats become ints so json_encode prints 6734, not 6734.0 */
    /** mbstring is not guaranteed on the server; brand names are Latin, Georgian has no case */
    private static function lower(string $s): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s);
    }

    /** Keys in JavaScript object order: integer-like keys first (ascending), then the rest in insertion order — keeps tie-breaks identical to the Node pipeline. */
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

    private static function num(float|int $v): float|int
    {
        if (is_float($v) && floor($v) === $v && abs($v) < 1e15) {
            return (int) $v;
        }
        return $v;
    }

    private static function r2(float|int $v): float|int
    {
        return self::num(round((float) $v, 2));
    }

    private static function r4(float $v): float|int
    {
        return self::num(round($v, 4));
    }

    // ------------------------------------------------------------------ Daily Mail

    private static function emptySeg(): array
    {
        return ['closed' => 0, 'amount' => 0, 'applications' => 0, 'terms' => 0, 'uw' => 0, 'dp' => 0];
    }

    private function dailyMail(string $end): array
    {
        $byDay = [];
        $day = static function (string $d) use (&$byDay): array {
            if (!isset($byDay[$d])) {
                $byDay[$d] = ['d' => $d, 'source' => $d >= self::CUTOVER ? 'new' : 'old', 'A' => self::emptySeg(), 'B' => self::emptySeg()];
            }
            return $byDay[$d];
        };
        $add = static function (string $d, string $seg, array $vals) use (&$byDay, $day): void {
            $day($d);
            foreach ($vals as $k => $v) {
                $byDay[$d][$seg][$k] += $v;
            }
        };

        foreach ($this->tsv('old_daily_seg.tsv') as $r) {
            if ($r['d'] >= self::CUTOVER) { throw new \RuntimeException('old-DB row past cutover: ' . $r['d']); }
            $add($r['d'], $r['seg'], ['closed' => (int) $r['deals'], 'amount' => (float) $r['amount']]);
        }
        foreach ($this->tsv('old_daily_apps.tsv') as $r) {
            if ($r['d'] >= self::CUTOVER) { throw new \RuntimeException('old-DB row past cutover: ' . $r['d']); }
            $add($r['d'], $r['seg'], ['applications' => (int) $r['applications'], 'terms' => (int) $r['terms'], 'uw' => (int) $r['uw'], 'dp' => (float) $r['dp']]);
        }

        // Deals Closed / Amount Sold — order date, active only; amount = full installment amount (schedule
        // total, plus the advance where the schedule was built net of it), price kept for the Segment rule.
        $seg = self::SEG;
        $stmt = $this->pdo->prepare("SELECT DATE(o.crm_creator_date) d, $seg seg, COUNT(*) deals,
              SUM(CASE WHEN s.tot IS NULL THEN o.base_grand_total
                       WHEN s.tot + COALESCE(o.crm_advance_amount,0) <= o.base_grand_total + 0.01 THEN s.tot + COALESCE(o.crm_advance_amount,0)
                       ELSE s.tot END) amount
            FROM orders o LEFT JOIN (SELECT installment_id, SUM(schedule_amount) tot FROM crm_installment_schedules GROUP BY installment_id) s ON s.installment_id=o.id
            WHERE o.crm_active=1 AND DATE(o.crm_creator_date) BETWEEN :cutover AND :end GROUP BY d, seg ORDER BY d, seg");
        $stmt->execute(['cutover' => self::CUTOVER, 'end' => $end]);
        foreach ($stmt->fetchAll() as $r) {
            $add($r['d'], $r['seg'], ['closed' => (int) $r['deals'], 'amount' => (float) $r['amount']]);
        }

        // Applications / Terms / Underwriting / Downpayment — application date (created_at)
        $stmt = $this->pdo->prepare("SELECT DATE(o.created_at) d, $seg seg, COUNT(*) applications, SUM(o.crm_underwriter_status_id IS NOT NULL) terms,
              SUM(o.crm_underwriter_status_id=16) uw, ROUND(SUM(COALESCE(o.crm_advance_amount,0)),2) dp
            FROM orders o WHERE DATE(o.created_at) BETWEEN :cutover AND :end GROUP BY d, seg ORDER BY d, seg");
        $stmt->execute(['cutover' => self::CUTOVER, 'end' => $end]);
        foreach ($stmt->fetchAll() as $r) {
            $add($r['d'], $r['seg'], ['applications' => (int) $r['applications'], 'terms' => (int) $r['terms'], 'uw' => (int) $r['uw'], 'dp' => (float) $r['dp']]);
        }

        // full daily series, zeros for missing days
        $series = [];
        for ($dt = new \DateTimeImmutable(self::SERIES_START); $dt->format('Y-m-d') <= $end; $dt = $dt->modify('+1 day')) {
            $d = $dt->format('Y-m-d');
            $row = $byDay[$d] ?? ['d' => $d, 'source' => $d >= self::CUTOVER ? 'new' : 'old', 'A' => self::emptySeg(), 'B' => self::emptySeg()];
            foreach (['A', 'B'] as $s) {
                $row[$s]['amount'] = self::r2($row[$s]['amount']);
                $row[$s]['dp'] = self::r2($row[$s]['dp']);
            }
            $series[] = $row;
        }

        // monthly rollups
        $byMonth = [];
        foreach ($series as $r) {
            $m = substr($r['d'], 0, 7);
            if (!isset($byMonth[$m])) {
                $byMonth[$m] = ['m' => $m, 'sources' => [], 'A' => self::emptySeg(), 'B' => self::emptySeg()];
            }
            $byMonth[$m]['sources'][$r['source']] = true;
            foreach (['A', 'B'] as $s) {
                foreach (self::METRICS as $k) {
                    $byMonth[$m][$s][$k] += $r[$s][$k];
                }
            }
        }
        $monthlyStatsObj = [];
        foreach ($byMonth as $m => $b) {
            foreach (['A', 'B'] as $s) {
                $b[$s]['amount'] = self::r2($b[$s]['amount']);
                $b[$s]['dp'] = self::r2($b[$s]['dp']);
            }
            $monthlyStatsObj[$m] = ['A' => $b['A'], 'B' => $b['B'], 'source' => count($b['sources']) > 1 ? 'mixed' : array_key_first($b['sources'])];
        }

        $last = $series[count($series) - 1];
        $lastMonth = substr($last['d'], 0, 7);
        $mtdRows = array_values(array_filter($series, static fn ($r) => substr($r['d'], 0, 7) === $lastMonth));
        $sum = static function (array $rows): array {
            $acc = ['A' => [], 'B' => []];
            foreach (['A', 'B'] as $s) {
                foreach (self::METRICS as $k) {
                    $acc[$s][$k] = self::r2(array_sum(array_map(static fn ($r) => $r[$s][$k], $rows)));
                }
            }
            return $acc;
        };
        $mtd = $sum($mtdRows);

        $dailyStatsObj = [];
        foreach ($series as $r) {
            if ($r['d'] >= self::DAILY_STATS_FROM) {
                $dailyStatsObj[$r['d']] = ['A' => $r['A'], 'B' => $r['B'], 'source' => $r['source']];
            }
        }

        return [
            'reportData' => [
                'yest' => ['date' => $last['d'], 'A' => $last['A'], 'B' => $last['B']],
                'mtd' => ['start' => $mtdRows[0]['d'], 'end' => $last['d'], 'A' => $mtd['A'], 'B' => $mtd['B']],
            ],
            'dailyStatsObj' => $dailyStatsObj,
            'monthlyStatsObj' => $monthlyStatsObj,
        ];
    }

    // ------------------------------------------------------------------ Sales Analyze

    private function loadMapping(): void
    {
        $raw = json_decode((string) file_get_contents($this->mappingPath), true, 512, JSON_THROW_ON_ERROR);
        foreach ($raw as $k => $v) {
            $this->mapping[trim((string) $k)] = $v;
        }
    }

    private function lookup(?string $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        $name = trim($raw);
        if ($name === '' || $name === 'none') {
            return null;
        }
        if (isset($this->mapping[$name])) {
            return $this->mapping[$name];
        }
        $parts = array_values(array_filter(array_map('trim', explode(',', $name)), static fn ($p) => $p !== ''));
        foreach (array_reverse($parts) as $part) {
            if (isset($this->mapping[$part])) {
                return $this->mapping[$part];
            }
        }
        return null;
    }

    private static function label(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));
        return ($s === '' || strtolower($s) === 'none') ? null : $s;
    }

    private function classifyProduct(?string $raw): ?string { return self::label(($this->lookup($raw) ?? [])['productEn'] ?? null); }
    private function classifySubcategory(?string $raw): ?string { return self::label(($this->lookup($raw) ?? [])['subcategoryEn'] ?? null); }

    private function classifyBrand(?string $raw): ?string
    {
        $n = trim((string) $raw);
        if ($n === '' || in_array(self::lower($n), self::NO_BRAND, true)) {
            return null;
        }
        return $this->oldBrandBySlug[self::lower($n)] ?? $n;
    }

    private static function emptyCell(): array
    {
        return ['sales' => 0.0, 'cogs' => 0.0, 'qty' => 0, 'cogsUnknownSales' => 0.0];
    }

    private static function addCell(array &$a, array $b): void
    {
        $a['sales'] += $b['sales'];
        $a['cogs'] += $b['cogs'];
        $a['qty'] += $b['qty'];
        $a['cogsUnknownSales'] += $b['cogsUnknownSales'];
    }

    private static function finish(array $c): array
    {
        return ['sales' => self::r2($c['sales']), 'cogs' => self::r2($c['cogs']), 'qty' => (int) $c['qty'], 'cogsUnknownSales' => self::r2($c['cogsUnknownSales'])];
    }

    private function buildBucketedReport(array $rows, callable $classify, string $fallbackBucket): array
    {
        $periodsSeen = [];
        $byBucket = [];
        $fallbackCount = 0;
        $fallbackSales = 0.0;
        foreach ($rows as $row) {
            $periodsSeen[$row['period']] = true;
            $bucket = $classify($row);
            if ($bucket === null) {
                $fallbackCount += $row['qty'];
                $fallbackSales += $row['sales'];
                $bucket = $fallbackBucket;
            }
            if (!isset($byBucket[$bucket][$row['period']])) {
                $byBucket[$bucket][$row['period']] = self::emptyCell();
            }
            self::addCell($byBucket[$bucket][$row['period']], $row);
        }
        $periods = array_keys($periodsSeen);
        sort($periods);
        $q1Periods = array_values(array_filter($periods, static fn ($p) => in_array(substr($p, 5, 2), ['01', '02', '03'], true)));
        $q2Periods = array_values(array_filter($periods, static fn ($p) => in_array(substr($p, 5, 2), ['04', '05', '06'], true)));
        $sumCells = static function (array $cells): array {
            $out = self::emptyCell();
            foreach ($cells as $c) {
                self::addCell($out, $c);
            }
            return $out;
        };

        $out = [];
        $grandTotal = self::emptyCell();
        $grandQ1 = self::emptyCell();
        $grandQ2 = self::emptyCell();
        foreach (self::jsKeyOrder($byBucket) as $bucket) {
            $byPeriod = $byBucket[$bucket];
            foreach ($periods as $p) {
                $byPeriod[$p] ??= self::emptyCell();
            }
            $q1 = $sumCells(array_map(static fn ($p) => $byPeriod[$p], $q1Periods));
            $q2 = $sumCells(array_map(static fn ($p) => $byPeriod[$p], $q2Periods));
            $total = $sumCells(array_map(static fn ($p) => $byPeriod[$p], $periods));
            self::addCell($grandTotal, $total);
            self::addCell($grandQ1, $q1);
            self::addCell($grandQ2, $q2);
            $bp = [];
            foreach ($periods as $p) {
                $bp[$p] = self::finish($byPeriod[$p]);
            }
            $out[] = ['bucket' => (string) $bucket, 'byPeriod' => $bp, 'q1' => self::finish($q1), 'q2' => self::finish($q2), 'total' => self::finish($total)];
        }
        foreach ($out as &$r) {
            $r['q1']['share'] = $grandQ1['sales'] > 0 ? self::r4($r['q1']['sales'] / $grandQ1['sales']) : 0;
            $r['q2']['share'] = $grandQ2['sales'] > 0 ? self::r4($r['q2']['sales'] / $grandQ2['sales']) : 0;
            $r['total']['share'] = $grandTotal['sales'] > 0 ? self::r4($r['total']['sales'] / $grandTotal['sales']) : 0;
        }
        unset($r);
        usort($out, static function ($a, $b) use ($fallbackBucket) {
            if ($a['bucket'] === $fallbackBucket) { return 1; }
            if ($b['bucket'] === $fallbackBucket) { return -1; }
            return $b['total']['sales'] <=> $a['total']['sales'];
        });
        return [
            'periods' => $periods, 'q1Periods' => $q1Periods, 'q2Periods' => $q2Periods, 'rows' => $out,
            'grandTotal' => self::finish($grandTotal), 'grandQ1' => self::finish($grandQ1), 'grandQ2' => self::finish($grandQ2),
            'uncategorized' => ['count' => $fallbackCount, 'sales' => self::r2($fallbackSales)],
        ];
    }

    private static function isRepdigit(float $v): bool
    {
        return $v > 0 && preg_match('/^1+$/', (string) round($v)) === 1;
    }

    /** @param list<array{product_id:string,start_price:float,final_price:float,deal_type:string}> $lines */
    private static function garbageFor(array $lines): array
    {
        $clean = [];
        foreach ($lines as $l) {
            if ($l['start_price'] > 0 && !self::isRepdigit($l['start_price'])) {
                $clean[$l['product_id']][] = $l['start_price'];
            }
        }
        $median = [];
        foreach ($clean as $pid => $vals) {
            sort($vals);
            $n = count($vals);
            $m = intdiv($n, 2);
            $median[$pid] = $n % 2 ? $vals[$m] : ($vals[$m - 1] + $vals[$m]) / 2;
        }
        $count = 0;
        $salesAffected = 0.0;
        foreach ($lines as $l) {
            $med = $median[$l['product_id']] ?? null;
            $g = $l['start_price'] <= 0 || self::isRepdigit($l['start_price']) || ($med !== null && ($l['start_price'] < 0.5 * $med || $l['start_price'] > 2 * $med));
            if ($g) {
                $count++;
                $salesAffected += $l['final_price'];
            }
        }
        return ['count' => $count, 'salesAffected' => self::r2($salesAffected), 'total' => count($lines)];
    }

    private function salesAnalyze(string $end): array
    {
        $this->loadMapping();

        // category tree + product links (new DB)
        $cats = [];
        foreach ($this->pdo->query("SELECT c.id, c.parent_id, ct.name FROM categories c LEFT JOIN category_translations ct ON ct.category_id=c.id AND ct.locale='ka_GE'")->fetchAll() as $r) {
            $cats[(string) $r['id']] = ['parent' => (string) ($r['parent_id'] ?? ''), 'name' => trim((string) ($r['name'] ?? ''))];
        }
        $depth = static function (string $id) use ($cats): int {
            $d = 0;
            $c = $cats[$id] ?? null;
            while ($c && $c['parent'] !== '' && isset($cats[$c['parent']])) {
                $d++;
                $c = $cats[$c['parent']];
            }
            return $d;
        };
        $productCats = [];
        foreach ($this->pdo->query('SELECT product_id, category_id FROM product_categories ORDER BY product_id, category_id')->fetchAll() as $r) {
            $productCats[(string) $r['product_id']][] = (string) $r['category_id'];
        }
        $rawCategory = static function (string $productId) use ($productCats, $cats, $depth): string {
            $ids = $productCats[$productId] ?? [];
            usort($ids, static fn ($a, $b) => $depth($a) <=> $depth($b));
            $names = [];
            foreach ($ids as $id) {
                $n = $cats[$id]['name'] ?? '';
                if ($n !== '' && $n !== 'none') {
                    $names[] = $n;
                }
            }
            return implode(',', $names);
        };

        // old-DB frozen rows
        $oldGrouped = [];
        foreach ($this->tsv('old_sales_grouped.tsv') as $r) {
            if ($r['period'] > '2026-08') { throw new \RuntimeException('old sales row past cutover month'); }
            $oldGrouped[] = ['period' => $r['period'], 'category' => $r['category'], 'brand' => $r['brand'], 'deal_type' => $r['deal_type'],
                'sales' => (float) $r['sales'], 'cogs' => (float) $r['cogs'], 'qty' => (int) $r['qty'], 'cogsUnknownSales' => 0.0];
            $b = trim($r['brand']);
            if ($b !== '' && !in_array(self::lower($b), self::NO_BRAND, true)) {
                $this->oldBrandBySlug[self::lower($b)] ??= $b;
            }
        }

        // new-DB line items from the cutover
        $stmt = $this->pdo->prepare("SELECT DATE_FORMAT(COALESCE(o.crm_creator_date,o.created_at),'%Y-%m') period, DATE(COALESCE(o.crm_creator_date,o.created_at)) d, oi.product_id, oi.qty_ordered qty, oi.base_total sales,
              CASE WHEN o.crm_order_status=99 THEN 'single' ELSE 'installment' END deal_type, COALESCE(ao.admin_name,'') brand
            FROM orders o JOIN order_items oi ON oi.order_id=o.id
            LEFT JOIN product_attribute_values pb ON pb.product_id=oi.product_id AND pb.attribute_id=25
            LEFT JOIN attribute_options ao ON ao.id=pb.integer_value
            WHERE o.crm_order_status IN (5,99) AND COALESCE(o.crm_creator_date,o.created_at) >= :cutover AND DATE(COALESCE(o.crm_creator_date,o.created_at)) <= :end
            ORDER BY d, o.id, oi.id");
        $stmt->execute(['cutover' => self::CUTOVER, 'end' => $end]);
        $newGrouped = [];
        foreach ($stmt->fetchAll() as $r) {
            $newGrouped[] = ['period' => $r['period'], 'category' => $rawCategory((string) $r['product_id']), 'brand' => (string) $r['brand'], 'deal_type' => $r['deal_type'],
                'sales' => (float) $r['sales'], 'cogs' => 0.0, 'qty' => (int) $r['qty'], 'cogsUnknownSales' => (float) $r['sales']];
        }
        $rawRows = array_merge($oldGrouped, $newGrouped);

        // COGS data-quality stats — old lines only (the new DB has no cost data at all)
        $oldLines = array_map(static fn ($r) => ['product_id' => $r['product_id'], 'start_price' => (float) $r['start_price'], 'final_price' => (float) $r['final_price'], 'deal_type' => $r['deal_type']], $this->tsv('old_sales_lines.tsv'));
        $garbage = [];
        $cogsUnknown = [];
        foreach (['all', 'installment', 'single'] as $dt) {
            $garbage[$dt] = self::garbageFor($dt === 'all' ? $oldLines : array_values(array_filter($oldLines, static fn ($l) => $l['deal_type'] === $dt)));
            $rows = $dt === 'all' ? $newGrouped : array_values(array_filter($newGrouped, static fn ($r) => $r['deal_type'] === $dt));
            $cogsUnknown[$dt] = ['count' => array_sum(array_column($rows, 'qty')), 'sales' => self::r2(array_sum(array_column($rows, 'sales')))];
        }

        $threeWay = function (callable $classify, string $fallback) use ($rawRows, $garbage, $cogsUnknown): array {
            $result = [];
            foreach (['all', 'installment', 'single'] as $dt) {
                $rows = $dt === 'all' ? $rawRows : array_values(array_filter($rawRows, static fn ($r) => $r['deal_type'] === $dt));
                $rep = $this->buildBucketedReport($rows, $classify, $fallback);
                $rep['garbage'] = $garbage[$dt];
                $rep['cogsUnknown'] = $cogsUnknown[$dt];
                $result[$dt] = $rep;
            }
            return $result;
        };
        $salesMonthlyStats = $threeWay(fn ($r) => $this->classifyProduct($r['category']), 'Uncategorized');
        $subcategoryStats = $threeWay(fn ($r) => $this->classifySubcategory($r['category']), 'Uncategorized');
        $brandStats = $threeWay(fn ($r) => $this->classifyBrand($r['brand']), 'No Brand');

        // Category / Brand
        $categoryBrand = function (array $rows): array {
            $byCategory = [];
            foreach ($rows as $row) {
                $category = $this->classifyProduct($row['category']) ?? 'Uncategorized';
                $brand = $this->classifyBrand($row['brand']) ?? 'No Brand';
                $m = substr($row['period'], 5, 2);
                $quarter = in_array($m, ['01', '02', '03'], true) ? 'q1' : (in_array($m, ['04', '05', '06'], true) ? 'q2' : null);
                if (!isset($byCategory[$category][$brand])) {
                    $byCategory[$category][$brand] = ['q1' => self::emptyCell(), 'q2' => self::emptyCell(), 'total' => self::emptyCell()];
                }
                if ($quarter !== null) {
                    self::addCell($byCategory[$category][$brand][$quarter], $row);
                }
                self::addCell($byCategory[$category][$brand]['total'], $row);
            }
            $categories = [];
            foreach (self::jsKeyOrder($byCategory) as $category) {
                $brands = $byCategory[$category];
                $brandRows = [];
                $catTotal = self::emptyCell();
                foreach (self::jsKeyOrder($brands) as $brand) {
                    $per = $brands[$brand];
                    $known = $per['total']['sales'] - $per['total']['cogsUnknownSales'];
                    $margin = $known > 0 ? ($known - $per['total']['cogs']) / $known : null;
                    $brandRows[] = ['brand' => (string) $brand, 'q1' => self::finish($per['q1']), 'q2' => self::finish($per['q2']), 'total' => self::finish($per['total']), 'margin' => $margin];
                    self::addCell($catTotal, $per['total']);
                }
                usort($brandRows, static fn ($a, $b) => $b['total']['sales'] <=> $a['total']['sales']);
                foreach ($brandRows as &$br) {
                    $br['share'] = $catTotal['sales'] > 0 ? self::r4($br['total']['sales'] / $catTotal['sales']) : 0;
                }
                unset($br);
                $knownCat = $catTotal['sales'] - $catTotal['cogsUnknownSales'];
                $categories[] = ['category' => (string) $category, 'brands' => $brandRows, 'total' => self::finish($catTotal), 'margin' => $knownCat > 0 ? ($knownCat - $catTotal['cogs']) / $knownCat : null];
            }
            usort($categories, static function ($a, $b) {
                if ($a['category'] === 'Uncategorized') { return 1; }
                if ($b['category'] === 'Uncategorized') { return -1; }
                return $b['total']['sales'] <=> $a['total']['sales'];
            });
            return ['categories' => $categories];
        };
        $categoryBrandBreakdown = [];
        foreach (['all', 'installment', 'single'] as $dt) {
            $categoryBrandBreakdown[$dt] = $categoryBrand($dt === 'all' ? $rawRows : array_values(array_filter($rawRows, static fn ($r) => $r['deal_type'] === $dt)));
        }

        return [
            'salesMonthlyStats' => $salesMonthlyStats,
            'brandStats' => $brandStats,
            'subcategoryStats' => $subcategoryStats,
            'categoryBrandBreakdown' => $categoryBrandBreakdown,
        ];
    }
}
