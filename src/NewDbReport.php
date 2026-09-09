<?php

declare(strict_types=1);

namespace Volta\Funnel;

use Throwable;

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
    /** first day of the CRM logistics module; the delivery series starts here. Population = orders with a crm_order_logistics row that are active or in status 11 = CRM "Signed" (contract signed, becomes Active a few hours later — the CRM counts them too) */
    private const LOGI_START = '2026-09-02';
    /** Sales – Pending Status counts applications submitted from this date on (pre-cutover migrated 'pending' applications are stale; the CRM's Pending figure includes them, this table does not — user's choice 2026-09-08) */
    private const PENDING_START = '2026-09-01';
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

    /** @return array{report: array, sales: array, logistics: array, mkt?: array, ops?: array, cust?: array, coll?: array, pf?: array} */
    public function build(string $end): array
    {
        $generatedAt = gmdate('Y-m-d H:i') . ' UTC';
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $report = $this->dailyMail($end);
        $report['cutover'] = self::CUTOVER;
        $report['generatedAt'] = $generatedAt;
        $sales = $this->salesAnalyze($end);
        $sales['cutover'] = self::CUTOVER;
        $sales['generatedAt'] = $generatedAt;
        $logistics = $this->logistics();
        $logistics['cutover'] = self::CUTOVER;
        $logistics['generatedAt'] = $generatedAt;
        $out = ['report' => $report, 'sales' => $sales, 'logistics' => $logistics];
        // The five newer groups (Marketing, Operations, Customers, Collections, Portfolio) live in their own classes —
        // src/NewDbMkt.php … — each the PHP twin of volta-analytics-new-db/build_<prefix>.js and returning exactly the
        // structure of <prefix>_data.json. A missing class file simply leaves that tab on the committed static numbers.
        $groups = [
            'mkt' => fn () => new NewDbMkt($this->pdo, $this->dir, $this->mappingPath),
            'ops' => fn () => new NewDbOps($this->pdo, $this->dir, $this->mappingPath),
            'cust' => fn () => new NewDbCust($this->pdo, $this->dir, $this->mappingPath),
            'coll' => fn () => new NewDbColl($this->pdo, $this->dir, $this->mappingPath),
            'pf' => fn () => new NewDbPf($this->pdo, $this->dir, $this->mappingPath),
        ];
        foreach ($groups as $key => $make) {
            try {
                $out[$key] = $make()->build($today);
            } catch (Throwable $e) {
                // one group failing (query timeout, schema change) must not take the whole page down: that tab keeps the
                // committed static numbers and the yellow note on the page says which group is stale
                $out['errors'][$key] = $e->getMessage();
            }
        }
        return $out;
    }

    /** JSON key in the build() result => class name (also the `const <KEY>_JSON` line the HTML carries, upper-cased) */
    public const GROUPS = ['mkt' => 'NewDbMkt', 'ops' => 'NewDbOps', 'cust' => 'NewDbCust', 'coll' => 'NewDbColl', 'pf' => 'NewDbPf'];

    // ------------------------------------------------------------------ Logistics Daily

    private const LOGI_STATUS_LABEL = [
        20 => 'დაწყებული / Started', 25 => 'მოძიება / Procuring', 30 => 'მზადაა მომწოდებელთან / Ready at vendor',
        35 => 'აღებულია მომწოდებლისგან / Collected', 40 => 'საწყობისკენ / To warehouse', 45 => 'საწყობშია / Warehouse',
        48 => 'ნაწილობრივ მზადაა / Partially ready', 50 => 'გასაგზავნად მზადაა / Ready to ship', 60 => 'გზაშია / Out for delivery',
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
        $stmt->execute(['cutover' => self::PENDING_START, 'today' => $today, 'cutover2' => self::PENDING_START]);
        $pending = ['dates' => [], 'upTo1' => [], 'oneTo5' => [], 'over5' => []];
        foreach ($stmt->fetchAll() as $r) {
            $pending['dates'][] = $r['d'];
            $pending['upTo1'][] = (int) $r['upTo1'];
            $pending['oneTo5'][] = (int) $r['oneTo5'];
            $pending['over5'][] = (int) $r['over5'];
        }

        $stmt = $this->pdo->prepare("WITH RECURSIVE cal AS (SELECT DATE(:cutover) d UNION ALL SELECT d + INTERVAL 1 DAY FROM cal WHERE d < DATE(:today)),
            pop AS (SELECT o.id, COALESCE(o.crm_creator_date, o.created_at) sale, GREATEST(COALESCE(o.crm_creator_date, o.created_at), l.created_at) entered, dl.delivered_at FROM orders o JOIN crm_order_logistics l ON l.order_id=o.id LEFT JOIN (SELECT order_id, MIN(created_at) delivered_at FROM crm_shipment_status_history WHERE entity_type=4 AND to_status IN (80,81) GROUP BY order_id) dl ON dl.order_id=o.id WHERE (o.crm_active=1 OR o.crm_order_status=11))
            SELECT cal.d,
              SUM(pop.entered < cal.d + INTERVAL 1 DAY AND (pop.delivered_at IS NULL OR pop.delivered_at >= cal.d + INTERVAL 1 DAY) AND DATEDIFF(cal.d, DATE(pop.sale)) <= 1) upTo1,
              SUM(pop.entered < cal.d + INTERVAL 1 DAY AND (pop.delivered_at IS NULL OR pop.delivered_at >= cal.d + INTERVAL 1 DAY) AND DATEDIFF(cal.d, DATE(pop.sale)) BETWEEN 2 AND 5) oneTo5,
              SUM(pop.entered < cal.d + INTERVAL 1 DAY AND (pop.delivered_at IS NULL OR pop.delivered_at >= cal.d + INTERVAL 1 DAY) AND DATEDIFF(cal.d, DATE(pop.sale)) > 5) over5,
              SUM(DATE(pop.delivered_at) = cal.d) delivered,
              ROUND(AVG(CASE WHEN DATE(pop.delivered_at) = cal.d THEN TIMESTAMPDIFF(HOUR, pop.sale, pop.delivered_at)/24 END),1) avgDays
            FROM cal LEFT JOIN pop ON pop.entered < cal.d + INTERVAL 1 DAY GROUP BY cal.d ORDER BY cal.d");
        $stmt->execute(['cutover' => $logiStart, 'today' => $today]);
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


        // goods type = mapping-sheet categoryEn of the product
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
        // Orders by City / Goods Type, by day — same population + entered/delivered rules as the Delivery Status table
        $stmt = $this->pdo->prepare("SELECT o.id order_id, GREATEST(COALESCE(o.crm_creator_date, o.created_at), l.created_at) entered, dl.delivered_at,
              CASE WHEN a.city IS NULL OR a.city='' THEN 'Without City' WHEN a.city='თბილისი' THEN 'Tbilisi' ELSE 'Other Cities' END city_grp,
              (SELECT oi.product_id FROM order_items oi WHERE oi.order_id=o.id ORDER BY oi.base_total DESC, oi.id LIMIT 1) top_product_id
            FROM orders o JOIN crm_order_logistics l ON l.order_id=o.id
            LEFT JOIN (SELECT order_id, MIN(city) city FROM addresses WHERE address_type='order_shipping' GROUP BY order_id) a ON a.order_id=o.id
            LEFT JOIN (SELECT order_id, MIN(created_at) delivered_at FROM crm_shipment_status_history WHERE entity_type=4 AND to_status IN (80,81) GROUP BY order_id) dl ON dl.order_id=o.id
            WHERE (o.crm_active=1 OR o.crm_order_status=11) ORDER BY o.id");
        $stmt->execute();
        $orders = [];
        foreach ($stmt->fetchAll() as $r) {
            $orders[] = ['entered' => (string) $r['entered'], 'delivered' => $r['delivered_at'] === null ? null : (string) $r['delivered_at'], 'city' => (string) $r['city_grp'], 'goods' => $goodsType((string) $r['top_product_id'])];
        }
        $seriesDates = $delivery['dates'];
        $seriesFor = static function (string $key, ?array $labels, string $title, string $headLabel, bool $sortByToday) use ($orders, $seriesDates): array {
            $zero = array_fill(0, count($seriesDates), 0);
            $groups = [];
            foreach ($labels ?? [] as $l) { $groups[$l] = ['nd' => $zero, 'all' => $zero]; }
            foreach ($orders as $o) {
                $k = $o[$key];
                $groups[$k] ??= ['nd' => $zero, 'all' => $zero];
                foreach ($seriesDates as $i => $d) {
                    $end = $d . ' 23:59:59.999';
                    if ($o['entered'] > $end) { continue; }
                    $groups[$k]['all'][$i]++;
                    if ($o['delivered'] === null || $o['delivered'] > $end) { $groups[$k]['nd'][$i]++; }
                }
            }
            $lastDay = end($seriesDates) ?: '';
            $month = substr((string) $lastDay, 0, 7);
            foreach ($orders as $o) {
                if (substr($o['entered'], 0, 7) === $month) { $groups[$o[$key]]['month'] = ($groups[$o[$key]]['month'] ?? 0) + 1; }
            }
            $rows = [];
            foreach ($groups as $label => $g) { $rows[] = ['label' => (string) $label, 'nd' => $g['nd'], 'all' => $g['all'], 'month' => $g['month'] ?? 0]; }
            if ($sortByToday) {
                usort($rows, static function ($a, $b) {
                    $ua = $a['label'] === 'Uncategorized' ? 1 : 0;
                    $ub = $b['label'] === 'Uncategorized' ? 1 : 0;
                    return $ua <=> $ub ?: end($b['all']) <=> end($a['all']);
                });
            }
            $sum = static function (string $k) use ($rows, $seriesDates): array {
                $out = [];
                foreach ($seriesDates as $i => $d) { $t = 0; foreach ($rows as $r) { $t += $r[$k][$i]; } $out[] = $t; }
                return $out;
            };
            $mk = static fn (string $k) => array_map(static fn ($r) => ['label' => $r['label'], 'vals' => $r[$k]], $rows);
            $ndTot = 0;
            $monthTot = 0;
            foreach ($rows as $r) { $ndTot += end($r['nd']); $monthTot += $r['month']; }
            $summaryRows = [];
            foreach ($rows as $r) {
                $ndLast = end($r['nd']);
                $summaryRows[] = ['label' => $r['label'], 'nd' => $ndLast, 'ndShare' => $ndTot ? self::num($ndLast / $ndTot) : 0, 'month' => $r['month'], 'monthShare' => $monthTot ? self::num($r['month'] / $monthTot) : 0];
            }
            $dash = "\u{2014}";
            return ['title' => $title, 'headLabel' => $headLabel, 'dates' => $seriesDates,
                'daily' => [
                    ['label' => 'Not Delivered Orders ' . $dash . ' by day', 'rows' => $mk('nd'), 'total' => $sum('nd')],
                    ['label' => 'ALL Orders in the logistics module ' . $dash . ' by day', 'rows' => $mk('all'), 'total' => $sum('all')],
                ],
                'summary' => ['lastDay' => $lastDay, 'month' => $month, 'rows' => $summaryRows,
                    'total' => ['label' => 'Total', 'nd' => $ndTot, 'ndShare' => $ndTot ? 1 : 0, 'month' => $monthTot, 'monthShare' => $monthTot ? 1 : 0]]];
        };
        $byCity = $seriesFor('city', ['Tbilisi', 'Other Cities', 'Without City'], 'Orders by City', 'City', false);
        $byGoods = $seriesFor('goods', null, 'Orders by Goods Type', 'Goods Type', true);

        $stmt = $this->pdo->prepare("SELECT o.id order_id, TRIM(CONCAT(COALESCE(o.customer_first_name,''),' ',COALESCE(o.customer_last_name,''))) customer, DATE(COALESCE(o.crm_creator_date,o.created_at)) waiting_from, l.logistics_status, COALESCE(a.city,'') city
            FROM orders o LEFT JOIN crm_order_logistics l ON l.order_id=o.id LEFT JOIN (SELECT order_id, MIN(city) city FROM addresses WHERE address_type='order_shipping' GROUP BY order_id) a ON a.order_id=o.id
            LEFT JOIN (SELECT order_id FROM crm_shipment_status_history WHERE entity_type=4 AND to_status IN (80,81) GROUP BY order_id) dl ON dl.order_id=o.id
            WHERE (o.crm_active=1 OR o.crm_order_status=11) AND l.id IS NOT NULL AND dl.order_id IS NULL ORDER BY COALESCE(o.crm_creator_date,o.created_at), o.id LIMIT 10");
        $stmt->execute();
        $openCases = [];
        foreach ($stmt->fetchAll() as $r) {
            $code = $r['logistics_status'];
            $openCases[] = [
                'customer' => $r['customer'], 'waitingFrom' => $r['waiting_from'],
                'status' => ($code === null || $code === '') ? 'ლოგისტიკა არ დაწყებულა / Not started' : (self::LOGI_STATUS_LABEL[(int) $code] ?? ('სტატუსი ' . $code)),
                'city' => $r['city'] !== '' ? $r['city'] : '–', 'orderNum' => (int) $r['order_id'],
            ];
        }

        // CRM status by day (orders / order lines / vendor collections) — same SQL as pull_new.sh logi_status.tsv
        $sections = [
            'orders' => ['entity' => 4, 'title' => 'Orders by logistics status', 'codes' => [20 => 'Started', 25 => 'Procuring', 30 => 'Ready at vendor', 35 => 'Collecting', 40 => 'Collected', 45 => 'At warehouse', 48 => 'Partially ready (mixed lines)', 50 => 'Ready to ship', 60 => 'Out for delivery', 80 => 'Delivered', 81 => 'Picked up']],
            'lines' => ['entity' => 1, 'title' => 'Order lines by fulfillment status', 'codes' => [20 => 'Awaiting procurement', 25 => 'Ordered from vendor', 30 => 'Ready at vendor', 35 => 'Collection scheduled', 40 => 'Collected', 45 => 'At warehouse', 50 => 'Ready for dispatch', 60 => 'Out for delivery', 80 => 'Delivered', 81 => 'Picked up']],
            'collections' => ['entity' => 2, 'title' => 'Vendor collections by status', 'codes' => [35 => 'Scheduled', 40 => 'Collected (on the way)', 45 => 'Received at warehouse']],
        ];
        $stmt = $this->pdo->prepare("WITH RECURSIVE cal AS (SELECT DATE(:start) d UNION ALL SELECT d + INTERVAL 1 DAY FROM cal WHERE d < DATE(:today)),
            ev AS (SELECT h.id, h.entity_type, h.entity_id, h.to_status, h.created_at FROM crm_shipment_status_history h JOIN orders o ON o.id=h.order_id AND (o.crm_active=1 OR o.crm_order_status=11) WHERE h.entity_type IN (1,2,4))
            SELECT e.entity_type, cal.d, e.to_status status, COUNT(*) n FROM cal JOIN ev e ON e.created_at < cal.d + INTERVAL 1 DAY
            LEFT JOIN ev e2 ON e2.entity_type=e.entity_type AND e2.entity_id=e.entity_id AND e2.created_at < cal.d + INTERVAL 1 DAY AND (e2.created_at > e.created_at OR (e2.created_at=e.created_at AND e2.id>e.id))
            WHERE e2.id IS NULL GROUP BY e.entity_type, cal.d, e.to_status ORDER BY e.entity_type, cal.d, e.to_status");
        $stmt->execute(['start' => $logiStart, 'today' => $today]);
        $raw = $stmt->fetchAll();
        $dates = [];
        foreach ($raw as $r) { $dates[$r['d']] = true; }
        $dates = array_keys($dates);
        sort($dates);
        $statusByDay = ['dates' => $dates];
        // memo row: module orders not yet activated (status 11, no 11->1 activation event by the end of day D)
        $stmt = $this->pdo->prepare("WITH RECURSIVE cal AS (SELECT DATE(:start) d UNION ALL SELECT d + INTERVAL 1 DAY FROM cal WHERE d < DATE(:today))
            SELECT cal.d, COUNT(*) n FROM cal JOIN crm_order_logistics l ON l.created_at < cal.d + INTERVAL 1 DAY JOIN orders o ON o.id=l.order_id AND (o.crm_active=1 OR o.crm_order_status=11)
            LEFT JOIN (SELECT entity_id, MIN(created_at) activated_at FROM crm_activity_log WHERE action='installment.status_change' AND JSON_EXTRACT(metadata,'$.to')=1 GROUP BY entity_id) act ON act.entity_id=o.id
            WHERE act.activated_at IS NULL OR act.activated_at >= cal.d + INTERVAL 1 DAY GROUP BY cal.d ORDER BY cal.d");
        $stmt->execute(['start' => $logiStart, 'today' => $today]);
        $notAct = [];
        foreach ($stmt->fetchAll() as $r) { $notAct[$r['d']] = (int) $r['n']; }
        $statusByDay['notActivated'] = array_map(static fn ($d) => $notAct[$d] ?? 0, $dates);
        foreach ($sections as $key => $sec) {
            $cell = [];
            $extra = [];
            foreach ($raw as $r) {
                if ((int) $r['entity_type'] !== $sec['entity']) { continue; }
                $cell[$r['d']][(int) $r['status']] = (int) $r['n'];
                if (!isset($sec['codes'][(int) $r['status']])) { $extra[(int) $r['status']] = 'Status ' . $r['status']; }
            }
            ksort($extra);
            $rows = [];
            foreach ($sec['codes'] + $extra as $code => $label) {
                $vals = [];
                foreach ($dates as $d) { $vals[] = $cell[$d][$code] ?? 0; }
                if (array_sum($vals) > 0) { $rows[] = ['code' => $code, 'label' => $label, 'vals' => $vals]; }
            }
            $total = [];
            foreach ($dates as $i => $d) { $t = 0; foreach ($rows as $row) { $t += $row['vals'][$i]; } $total[] = $t; }
            $statusByDay[$key] = ['title' => $sec['title'], 'rows' => $rows, 'total' => $total];
        }

        return ['pending' => $pending, 'delivery' => $delivery, 'byCity' => $byCity, 'byGoods' => $byGoods, 'openCases' => $openCases, 'statusByDay' => $statusByDay];
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

        // Deals Closed / Amount Sold — keyed to the day the loan reaches Active status (the Signed-to-Active
        // transition in crm_activity_log, metadata.to=1) -- the date key the CRM's own Sales performance page
        // uses (replaces the earlier crm_creator_date key, only possible from the cutover on). amount = full
        // installment amount (schedule total, plus the advance where the schedule was built net of it).
        $seg = self::SEG;
        $stmt = $this->pdo->prepare("WITH act AS (SELECT entity_id, MIN(created_at) t FROM crm_activity_log WHERE action='installment.status_change' AND JSON_EXTRACT(metadata,'\$.to')=1 GROUP BY entity_id)
              SELECT DATE(act.t) d, $seg seg, COUNT(*) deals,
                SUM(CASE WHEN s.tot IS NULL THEN o.base_grand_total
                         WHEN s.tot + COALESCE(o.crm_advance_amount,0) <= o.base_grand_total + 0.01 THEN s.tot + COALESCE(o.crm_advance_amount,0)
                         ELSE s.tot END) amount
              FROM act JOIN orders o ON o.id=act.entity_id AND o.crm_active=1
              LEFT JOIN (SELECT installment_id, SUM(schedule_amount) tot FROM crm_installment_schedules GROUP BY installment_id) s ON s.installment_id=o.id
              WHERE DATE(act.t) BETWEEN :cutover AND :end GROUP BY d, seg ORDER BY d, seg");
        $stmt->execute(['cutover' => self::CUTOVER, 'end' => $end]);
        foreach ($stmt->fetchAll() as $r) {
            $add($r['d'], $r['seg'], ['closed' => (int) $r['deals'], 'amount' => (float) $r['amount']]);
        }

        // Applications / Terms / Underwriting — application date (created_at)
        $stmt = $this->pdo->prepare("SELECT DATE(o.created_at) d, $seg seg, COUNT(*) applications, SUM(o.crm_underwriter_status_id IS NOT NULL) terms,
              SUM(o.crm_underwriter_status_id=16) uw
            FROM orders o WHERE DATE(o.created_at) BETWEEN :cutover AND :end GROUP BY d, seg ORDER BY d, seg");
        $stmt->execute(['cutover' => self::CUTOVER, 'end' => $end]);
        foreach ($stmt->fetchAll() as $r) {
            $add($r['d'], $r['seg'], ['applications' => (int) $r['applications'], 'terms' => (int) $r['terms'], 'uw' => (int) $r['uw']]);
        }

        // Downpayment Collected — REAL cash collected, keyed to the payment date: a crm_payments row whose
        // amount equals the order's crm_advance_amount (crm_payments' own `advance` column is a different,
        // much rarer flag, verified not to be the downpayment).
        $stmt = $this->pdo->prepare("SELECT DATE(p.payment_date) d, $seg seg, ROUND(SUM(p.amount),2) dp
              FROM crm_payments p JOIN orders o ON o.id=p.installment_id
              WHERE p.reversed_at IS NULL AND o.crm_advance_amount > 0 AND p.amount = o.crm_advance_amount
                AND DATE(p.payment_date) BETWEEN :cutover AND :end
              GROUP BY d, seg ORDER BY d, seg");
        $stmt->execute(['cutover' => self::CUTOVER, 'end' => $end]);
        foreach ($stmt->fetchAll() as $r) {
            $add($r['d'], $r['seg'], ['dp' => (float) $r['dp']]);
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

/**
 * Customers → Customers Analyze, computed live from VoltaStoreDB: the PHP twin of
 * volta-analytics-new-db/pull_cust.sh + build_cust.js. Output shape = exactly CUST_JSON (cust_data.json).
 *
 * Same definitions as the pull script (identity = personal_ID typed on the application, else the account's
 * id_number, else account id / e-mail; application = orders row by created_at; active loan = crm_active=1 and
 * not pending; loan = status 5/99, closed, or active), aggregates only — the identity never leaves the query.
 *
 * Performance: the pull script runs its BASE CTE chain six times (~8 s each on the RDS replica, ~50 s total).
 * Here the chain is materialised ONCE in a single statement whose UNION ALL branches produce every table:
 *   - the application-form fields are LONGTEXT; they are CAST to VARCHAR (same collation) so every CTE stays an
 *     in-memory temp table (the LONGTEXT/TEXT columns were the main cost);
 *   - the shipping city is pre-aggregated (sh) instead of joined row-by-row; custb is forced to materialise once
 *     (LIMIT) instead of being re-joined in every branch; the application rank and the running loan count share one
 *     streamed unbounded-preceding window (rank = COUNT(*) over it, prior loans = SUM(loan) over it minus the row's own)
 *     instead of ROW_NUMBER plus a buffered "1 PRECEDING" frame;
 *   - the session join buffer is raised (and restored) so the rating lookup's hash join does not spill;
 *   - the tables whose value sets are closed literals (gender/age/risk/rating and emp/soc/sal/src, by customer and by
 *     month) come out of two cross-product GROUP BYs and are split per dimension here; city and job title keep their
 *     own ordered branches because their utf8mb4_unicode_ci order cannot be reproduced in PHP;
 *   - new-vs-returning comes by day from MONTH_START and is summed to months here.
 * Every number and every row order equals the pull script's (validated row-by-row against the six original queries).
 */
final class NewDbCust
{
    public const CUTOVER = '2026-08-31';
    /** first month of the by-month series (earlier applications count in the customer totals, not in the series) */
    private const MONTH_START = '2024-01-01';
    /** first day of the by-day series */
    private const DAY_START = '2026-08-01';
    /** session join_buffer_size (bytes) while the customers statement runs; restored afterwards */
    private const JOIN_BUFFER = 8388608;

    private const TYPES = ['1:New', '2:Returning (earlier application only)', '3:Returning (previous loan)'];
    private const RISK_ORDER = ['Low' => 1, 'Medium' => 2, 'High' => 3];
    private const WORST = [0 => 'Not computed', 1 => 'Max 3 days late', 2 => '4 - 9 days late', 3 => '10 - 30 days late', 4 => '31 - 60 days late', 5 => '61 - 90 days late', 6 => '90+ days late'];
    /** dims whose values are closed literal sets (they come out of the cross-product branches), in the branch column order */
    private const DIMS_A = ['gender', 'age', 'risk', 'rating'];
    private const DIMS_B = ['emp', 'soc', 'sal', 'src'];
    private const MON_A = ['gender', 'age', 'risk'];
    private const MON_B = ['emp', 'soc', 'sal'];
    private const MON_C = ['src', 'city'];

    private const CITY_EN = ['თბილისი' => 'Tbilisi', 'ბათუმი' => 'Batumi', 'რუსთავი' => 'Rustavi', 'ქუთაისი' => 'Kutaisi', 'ზუგდიდი' => 'Zugdidi', 'გორი' => 'Gori', 'მცხეთა' => 'Mtskheta', 'ფოთი' => 'Poti', 'ქობულეთი' => 'Kobuleti', 'ოზურგეთი' => 'Ozurgeti', 'გარდაბანი' => 'Gardabani', 'ხაშური' => 'Khashuri', 'სენაკი' => 'Senaki', 'გურჯაანი' => 'Gurjaani', 'სამტრედია' => 'Samtredia', 'ბოლნისი' => 'Bolnisi', 'წყალტუბო' => 'Tskaltubo', 'საგარეჯო' => 'Sagarejo', 'კასპი' => 'Kaspi', 'ზესტაფონი' => 'Zestafoni', 'თელავი' => 'Telavi', 'დუშეთი' => 'Dusheti', 'ახალციხე' => 'Akhaltsikhe', 'მარნეული' => 'Marneuli', 'ქარელი' => 'Kareli', 'ლანჩხუთი' => 'Lanchkhuti', 'ჭიათურა' => 'Chiatura', 'ტყიბული' => 'Tkibuli', 'ხონი' => 'Khoni', 'ბორჯომი' => 'Borjomi', 'აბაშა' => 'Abasha', 'მარტვილი' => 'Martvili', 'წალენჯიხა' => 'Tsalenjikha', 'სიღნაღი' => 'Sighnaghi', 'ლაგოდეხი' => 'Lagodekhi', 'ყვარელი' => 'Kvareli', 'ახმეტა' => 'Akhmeta', 'ხობი' => 'Khobi', 'ვანი' => 'Vani', 'თერჯოლა' => 'Terjola', 'საჩხერე' => 'Sachkhere', 'ხარაგაული' => 'Kharagauli', 'ბაღდათი' => 'Baghdati', 'ონი' => 'Oni', 'ამბროლაური' => 'Ambrolauri', 'მესტია' => 'Mestia', 'ახალქალაქი' => 'Akhalkalaki', 'თეთრიწყარო' => 'Tetritskaro', 'წალკა' => 'Tsalka', 'დმანისი' => 'Dmanisi', 'ჩხოროწყუ' => 'Chkhorotsku', 'ცაგერი' => 'Tsageri', 'ლენტეხი' => 'Lentekhi', 'ადიგენი' => 'Adigeni', 'ასპინძა' => 'Aspindza', 'ნინოწმინდა' => 'Ninotsminda', 'თიანეთი' => 'Tianeti', 'ხელვაჩაური' => 'Khelvachauri', 'ქედა' => 'Keda', 'შუახევი' => 'Shuakhevi', 'ხულო' => 'Khulo', 'სურამი' => 'Surami', 'წნორი' => 'Tsnori'];
    private const FORM_NOTE = ' Present on ~95% of web applications March&ndash;August 2026; since the 31 Aug 2026 cutover the new form records it on only a minority of applications (~15% in September), so the latest month is thin.';
    /** dimension catalogue = DIMS in build_cust.js (labels/notes verbatim; 'label' = cityLabel, 'sort' = count | risk | null) */
    private const DIMS = [
        ['key' => 'gender', 'title' => 'Gender', 'head' => 'Gender', 'unspecified' => 'Unspecified', 'monthly' => true, 'label' => false, 'sort' => null,
            'def' => 'Gender as answered on the application form (<code>volta_application_data.gender</code>; migrated applications carry the old CRM value, web applications the form value); the customer account\'s gender is the fallback.'],
        ['key' => 'age', 'title' => 'Age', 'head' => 'Age band', 'unspecified' => 'No birth date', 'monthly' => true, 'label' => false, 'sort' => null,
            'def' => 'Age from the birth date on the application form (<code>birth_date</code>; the account\'s <code>date_of_birth</code> as fallback) &mdash; never from the ID number. Customer columns = age today; the by-month table = age on the application date. Ages outside 14&ndash;100 are treated as missing.'],
        ['key' => 'city', 'title' => 'Geography (city of the shipping address)', 'head' => 'City', 'unspecified' => 'No city', 'monthly' => true, 'label' => true, 'sort' => 'count',
            'def' => 'City of the order\'s shipping address (<code>addresses.city</code>, <code>address_type = order_shipping</code>) &mdash; the 15 most frequent cities, the rest grouped as Other cities. The new DB has no region field.'],
        ['key' => 'emp', 'title' => 'Employment status', 'head' => 'Employment', 'unspecified' => 'Not on the form', 'monthly' => true, 'label' => false, 'sort' => 'count',
            'def' => 'Employment status selected on the web application form (<code>employment_status</code>: private sector / self-employed / public sector / unemployed). The field exists only on applications submitted through the web form since 12 March 2026; migrated applications do not carry it, hence "Not on the form".' . self::FORM_NOTE],
        ['key' => 'soc', 'title' => 'Social status', 'head' => 'Social status', 'unspecified' => 'Not on the form', 'monthly' => true, 'label' => false, 'sort' => 'count',
            'def' => 'Social status selected on the web application form (<code>social_status</code>: housewife / student / pensioner; left empty = none of these). On the form since 12 March 2026, like employment status.' . self::FORM_NOTE],
        ['key' => 'sal', 'title' => 'Monthly income (declared salary, GEL)', 'head' => 'Salary band', 'unspecified' => 'Not on the form', 'monthly' => true, 'label' => false, 'sort' => null,
            'def' => 'Salary declared on the web application form (<code>salary</code>, a number since February 2026; for ~1,200 March&ndash;April 2026 applications a range picked from a list, mapped to the same bands). Self-declared, not verified. Migrated applications do not carry it.' . self::FORM_NOTE],
        ['key' => 'src', 'title' => 'How the customer heard about Volta', 'head' => 'Source', 'unspecified' => 'Not on the form', 'monthly' => true, 'label' => false, 'sort' => 'count',
            'def' => '"How did you hear about us" on the web application form (<code>about_us_source</code>), on the form since January 2026. "Used Volta before" is the applicant\'s own answer &mdash; compare it with the New / Returning tables, which are computed from the application history.' . self::FORM_NOTE],
        ['key' => 'risk', 'title' => 'Risk status (CRM)', 'head' => 'Risk status', 'unspecified' => 'Not rated', 'monthly' => true, 'label' => false, 'sort' => 'risk',
            'def' => 'CRM risk status of the loan (<code>orders.crm_risk_status</code>: დაბალი / low = Low, საშუალო / medium = Medium, მაღალი = High; 0 / empty = not rated). It is set when a loan is issued, so applications that never became a loan are mostly "Not rated". Customer columns use the status of the customer\'s active loan (else the latest rated loan).'],
        ['key' => 'pos', 'title' => 'Job title (free text, top 15)', 'head' => 'Job title', 'unspecified' => 'Not on the form', 'monthly' => false, 'label' => false, 'sort' => 'count',
            'def' => 'Job title typed on the application form (<code>position</code>, ~7,600 distinct raw values) &mdash; the 15 most frequent exact values, everything else under Other. Not clustered on purpose (spelling variants stay separate).'],
        ['key' => 'rating', 'title' => 'Customer rating (CRM profile, 1&ndash;5)', 'head' => 'Rating', 'unspecified' => 'Not rated', 'monthly' => false, 'label' => false, 'sort' => null,
            'def' => 'Star rating on the CRM customer profile (<code>crm_customer_profile.rating</code>, migrated from the old CRM; 0 = not rated), linked to the customer through the account or the ID number. Only a small part of the base is rated &mdash; see the coverage line.'],
    ];

    /** @var array<string,float> seconds per query of the last build() */
    public array $timings = [];

    public function __construct(private readonly PDO $pdo, private readonly string $dir, private readonly string $mappingPath)
    {
    }

    /**
     * @param string $end accepted for the common constructor/build signature; the series runs through TODAY, exactly
     *                    like `pull_cust.sh` with its default END (the page labels the last day column "today")
     */
    public function build(string $end): array
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $this->timings = [];

        // ---- one statement for every customer/application table. The default 256 KB join buffer makes the hash join of the
        // 25k-row rating lookup (rt) spill and run 2-3x slower: raise it for this statement only, then put it back (the PDO
        // connection is shared with the other report groups).
        $t = microtime(true);
        $joinBuffer = (int) $this->pdo->query('SELECT @@session.join_buffer_size')->fetchColumn();
        try {
            if ($joinBuffer < self::JOIN_BUFFER) {
                $this->pdo->exec('SET SESSION join_buffer_size = ' . self::JOIN_BUFFER);
            }
            $stmt = $this->prepareRepeated($this->customersSql(), ['monthStart' => self::MONTH_START, 'end' => $today, 'cutover' => self::CUTOVER]);
            $stmt->execute();
            $rows = $stmt->fetchAll();
        } finally {
            if ($joinBuffer < self::JOIN_BUFFER) {
                $this->pdo->exec('SET SESSION join_buffer_size = ' . $joinBuffer);
            }
        }
        $this->timings['customers'] = microtime(true) - $t;

        // dims: dim => list of ['val' => string ('NULL' for null, as the mysql CLI prints it), all, active, loan] in the pull script's order
        $dims = [];
        // month: dim => val => m => n ; months = every month in the series
        $month = [];
        $monthsSet = [];
        $nrDay = [];
        $hist = ['apps' => [], 'loans' => []];
        $sumCust = null;
        $sumApp = null;
        $addDim = static function (array &$dims, string $dim, ?string $val, array $r): void {
            $key = $val ?? 'NULL';
            if (!isset($dims[$dim][$key])) {
                $dims[$dim][$key] = ['val' => $key, 'all' => 0, 'active' => 0, 'loan' => 0];
            }
            $dims[$dim][$key]['all'] += (int) $r['n1'];
            $dims[$dim][$key]['active'] += (int) $r['n2'];
            $dims[$dim][$key]['loan'] += (int) $r['n3'];
        };
        $addMonth = static function (array &$month, string $dim, ?string $val, string $m, int $n): void {
            $key = $val ?? 'NULL';
            $month[$dim][$key][$m] = ($month[$dim][$key][$m] ?? 0) + $n;
        };
        foreach ($rows as $r) {
            switch ($r['kind']) {
                case 'dimsA':
                    foreach (self::DIMS_A as $i => $dim) { $addDim($dims, $dim, $r['k' . ($i + 1)], $r); }
                    break;
                case 'dimsB':
                    foreach (self::DIMS_B as $i => $dim) { $addDim($dims, $dim, $r['k' . ($i + 1)], $r); }
                    break;
                case 'city':
                case 'pos':
                    $addDim($dims, $r['kind'], $r['k1'], $r);
                    break;
                case 'monA':
                    $monthsSet[$r['k1']] = true;
                    foreach (self::MON_A as $i => $dim) { $addMonth($month, $dim, $r['k' . ($i + 2)], $r['k1'], (int) $r['n1']); }
                    break;
                case 'monB':
                    foreach (self::MON_B as $i => $dim) { $addMonth($month, $dim, $r['k' . ($i + 2)], $r['k1'], (int) $r['n1']); }
                    break;
                case 'monC':
                    foreach (self::MON_C as $i => $dim) { $addMonth($month, $dim, $r['k' . ($i + 2)], $r['k1'], (int) $r['n1']); }
                    break;
                case 'nr':
                    $nrDay[$r['k1']][$r['k2']] = ['n' => (int) $r['n1'], 'active_n' => (int) $r['n2'], 'loan_n' => (int) $r['n3']];
                    break;
                case 'hist':
                    foreach (['apps' => 'k1', 'loans' => 'k2'] as $kind => $col) {
                        $b = $r[$col];
                        $hist[$kind][$b] = ['n' => ($hist[$kind][$b]['n'] ?? 0) + (int) $r['n1'], 'active_c' => ($hist[$kind][$b]['active_c'] ?? 0) + (int) $r['n2']];
                    }
                    break;
                case 'cust':
                    $sumCust = $r;
                    break;
                case 'app':
                    $sumApp = $r;
                    break;
            }
        }
        if ($sumCust === null || $sumApp === null) {
            throw new \RuntimeException('customers statement returned no summary rows');
        }
        // the cross-product dims are re-ordered like the pull script's ORDER BY val (utf8mb4_unicode_ci: NULL first, then
        // case-insensitive) — their values are closed ASCII literal sets, decided at the first characters
        $cmp = static fn (array $a, array $b): int => ($a['val'] === 'NULL' ? 0 : 1) <=> ($b['val'] === 'NULL' ? 0 : 1) ?: strcasecmp($a['val'], $b['val']);
        foreach (array_merge(self::DIMS_A, self::DIMS_B) as $dim) {
            $list = array_values($dims[$dim] ?? []);
            usort($list, $cmp);
            $dims[$dim] = $list;
        }
        $dims['city'] = array_values($dims['city'] ?? []);
        $dims['pos'] = array_values($dims['pos'] ?? []);

        $months = array_keys($monthsSet);
        sort($months, SORT_STRING);
        $curMonth = $months[count($months) - 1] ?? null;

        // ---- summary (cust_summary.tsv)
        $summary = [
            'customers' => (int) $sumCust['n1'], 'activeCustomers' => (int) $sumCust['n2'], 'loanCustomers' => (int) $sumCust['n3'],
            'applications' => (int) $sumCust['n4'], 'loans' => (int) $sumCust['n5'],
            'customersBeforeSeries' => (int) $sumCust['n6'], 'appsBeforeSeries' => (int) $sumApp['n1'],
            'firstApp' => substr((string) $sumApp['k1'], 0, 10), 'lastApp' => substr((string) $sumApp['k2'], 0, 16),
            'appsWithoutPid' => (int) $sumApp['n4'], 'activeLoans' => (int) $sumApp['n2'], 'appsSinceCutover' => (int) $sumApp['n3'],
        ];

        // ---- customer-level tables + by-month tables (rowsFor / monthFor)
        $dimsOut = [];
        foreach (self::DIMS as $d) {
            $rf = $this->rowsFor($d, $dims[$d['key']] ?? [], $month[$d['key']] ?? [], $curMonth);
            $dimsOut[] = [
                'key' => $d['key'], 'title' => $d['title'], 'head' => $d['head'], 'def' => $d['def'], 'unspecified' => $d['unspecified'], 'monthly' => $d['monthly'],
                'rows' => $rf['rows'], 'total' => $rf['total'], 'coverage' => $rf['coverage'],
                'months' => $d['monthly'] ? $this->monthFor($d, $rf['rows'], $month[$d['key']] ?? [], $months) : null,
            ];
        }

        // ---- new vs returning: by month (summed from the days) and by day (from DAY_START)
        $nrMonth = [];
        $nrDayOnly = [];
        ksort($nrDay, SORT_STRING);
        foreach ($nrDay as $d => $types) {
            $m = substr($d, 0, 7);
            foreach ($types as $type => $v) {
                foreach (['n', 'active_n', 'loan_n'] as $c) { $nrMonth[$m][$type][$c] = ($nrMonth[$m][$type][$c] ?? 0) + $v[$c]; }
            }
            if ($d >= self::DAY_START) { $nrDayOnly[$d] = $types; }
        }
        $newret = ['month' => $this->series($nrMonth), 'day' => $this->series($nrDayOnly)];

        // ---- applications / loans per customer
        $histOut = [];
        foreach (['apps', 'loans'] as $kind) {
            $hrows = [];
            foreach ($hist[$kind] as $bucket => $v) { $hrows[] = ['label' => (string) $bucket, 'all' => $v['n'], 'active' => $v['active_c']]; }
            usort($hrows, static fn ($a, $b) => ((int) $a['label'] ?: 0) <=> ((int) $b['label'] ?: 0));
            $histOut[$kind] = ['rows' => $hrows, 'total' => ['all' => array_sum(array_column($hrows, 'all')), 'active' => array_sum(array_column($hrows, 'active'))]];
        }

        // ---- payment behaviour (crm_customer_payment_stats) — same SQL as pull_cust.sh
        $t = microtime(true);
        $payRaw = $this->pdo->query("SELECT 'worst' metric, CAST(COALESCE(worst_bucket,0) AS CHAR) val, COUNT(*) n FROM crm_customer_payment_stats GROUP BY 2
   UNION ALL SELECT 'overdue', CAST(currently_overdue AS CHAR), COUNT(*) FROM crm_customer_payment_stats GROUP BY 2
   UNION ALL SELECT 'dpd', CASE WHEN currently_overdue=0 OR current_dpd<=0 THEN '0:Not overdue' WHEN current_dpd<=7 THEN '1:1 - 7 days' WHEN current_dpd<=30 THEN '2:8 - 30 days' WHEN current_dpd<=60 THEN '3:31 - 60 days' WHEN current_dpd<=90 THEN '4:61 - 90 days' ELSE '5:90+ days' END, COUNT(*) FROM crm_customer_payment_stats GROUP BY 2
   UNION ALL SELECT 'ontime', CASE WHEN settled_count=0 THEN '9:No settled instalment yet' WHEN on_time_rate>=90 THEN '1:90%+' WHEN on_time_rate>=70 THEN '2:70 - 89%' WHEN on_time_rate>=50 THEN '3:50 - 69%' WHEN on_time_rate>=25 THEN '4:25 - 49%' ELSE '5:< 25%' END, COUNT(*) FROM crm_customer_payment_stats GROUP BY 2
   UNION ALL SELECT 'coverage', 'stats_rows', COUNT(*) FROM crm_customer_payment_stats
   UNION ALL SELECT 'coverage', 'active_customers_by_account', COUNT(DISTINCT customer_id) FROM orders WHERE crm_active=1 AND crm_order_status<>4 AND customer_id IS NOT NULL
   UNION ALL SELECT 'coverage', 'active_customers_with_stats', COUNT(DISTINCT o.customer_id) FROM orders o JOIN crm_customer_payment_stats p ON p.customer_id=o.customer_id WHERE o.crm_active=1 AND o.crm_order_status<>4
   UNION ALL SELECT 'coverage', 'computed_from', DATE_FORMAT(MIN(computed_at),'%Y-%m-%d') FROM crm_customer_payment_stats
   UNION ALL SELECT 'coverage', 'computed_to', DATE_FORMAT(MAX(computed_at),'%Y-%m-%d') FROM crm_customer_payment_stats")->fetchAll();
        $this->timings['paystats'] = microtime(true) - $t;
        $cov = [];
        foreach ($payRaw as $r) { if ($r['metric'] === 'coverage') { $cov[$r['val']] = $r['n']; } }
        $paystats = [
            'worst' => $this->payTable($payRaw, 'worst', static fn (string $v) => self::WORST[(int) $v] ?? ('Bucket ' . $v)),
            'dpd' => $this->payTable($payRaw, 'dpd', [self::class, 'strip']),
            'ontime' => $this->payTable($payRaw, 'ontime', [self::class, 'strip']),
            'coverage' => ['rows' => (int) $cov['stats_rows'], 'activeByAccount' => (int) $cov['active_customers_by_account'], 'activeWithStats' => (int) $cov['active_customers_with_stats'], 'from' => $cov['computed_from'], 'to' => $cov['computed_to']],
        ];

        return [
            'summary' => $summary, 'months' => $months, 'curMonth' => $curMonth, 'dims' => $dimsOut, 'newret' => $newret, 'hist' => $histOut, 'paystats' => $paystats,
            'cutover' => self::CUTOVER, 'dayStart' => $newret['day']['keys'][0] ?? null, 'generatedAt' => gmdate('Y-m-d H:i') . ' UTC',
        ];
    }

    // ------------------------------------------------------------------ Node build twins

    /** rowsFor() in build_cust.js: customer-level rows of one dimension, ordered, with totals and coverage */
    private function rowsFor(array $dim, array $rows, array $monthByVal, ?string $curMonth): array
    {
        $out = [];
        foreach ($rows as $r) {
            $isNull = $r['val'] === 'NULL';
            $out[] = [
                'raw' => $r['val'], 'label' => $this->label($dim, $r['val']), 'all' => $r['all'], 'active' => $r['active'], 'loan' => $r['loan'],
                'mtd' => $curMonth === null ? 0 : ($monthByVal[$r['val']][$curMonth] ?? 0),
                'unspecified' => $isNull, 'other' => $r['val'] === 'Other cities' || $r['val'] === 'Other',
            ];
        }
        // ordering: prefixed keys by prefix; counts descending; risk Low/Medium/High; Unspecified (and Other) always last
        usort($out, static function (array $a, array $b) use ($dim): int {
            $d = ((int) $a['unspecified'] <=> (int) $b['unspecified']) ?: ((int) $a['other'] <=> (int) $b['other']);
            if ($d !== 0) { return $d; }
            if ($dim['sort'] === 'risk') { return (self::RISK_ORDER[$a['raw']] ?? 0) <=> (self::RISK_ORDER[$b['raw']] ?? 0); }
            if ($dim['sort'] === 'count') { return $b['all'] <=> $a['all']; }
            return ((self::sortKey($a['raw']) ?? 0) <=> (self::sortKey($b['raw']) ?? 0)) ?: strcmp($a['label'], $b['label']);
        });
        $tot = static fn (string $k): int => array_sum(array_column($out, $k));
        $cov = static function (string $k) use ($out, $tot): int|float {
            $t = $tot($k);
            $u = 0;
            foreach ($out as $r) { if ($r['unspecified']) { $u += $r[$k]; } }
            return $t ? self::num(($t - $u) / $t) : 0;
        };
        return [
            'rows' => $out,
            'total' => ['all' => $tot('all'), 'active' => $tot('active'), 'loan' => $tot('loan'), 'mtd' => $tot('mtd')],
            'coverage' => ['all' => $cov('all'), 'active' => $cov('active'), 'mtd' => $cov('mtd')],
        ];
    }

    /** monthFor() in build_cust.js: applications by month for the dimension's values (rowsFor order), zero-only rows dropped */
    private function monthFor(array $dim, array $orderedRows, array $monthByVal, array $months): array
    {
        $kept = [];
        foreach ($orderedRows as $r) {
            $vals = [];
            $any = false;
            foreach ($months as $m) {
                $n = $monthByVal[$r['raw']][$m] ?? 0;
                $vals[] = $n;
                if ($n > 0) { $any = true; }
            }
            if ($any) { $kept[] = ['label' => $this->label($dim, $r['raw']), 'unspecified' => $r['raw'] === 'NULL', 'vals' => $vals]; }
        }
        $total = [];
        foreach ($months as $i => $m) {
            $t = 0;
            foreach ($kept as $r) { $t += $r['vals'][$i]; }
            $total[] = $t;
        }
        return ['rows' => $kept, 'total' => $total];
    }

    /** series() in build_cust.js; $raw = key => type => [n, active_n, loan_n] */
    private function series(array $raw): array
    {
        $keys = array_keys($raw);
        sort($keys, SORT_STRING);
        $rows = [];
        foreach (self::TYPES as $t) {
            $row = ['label' => self::strip($t), 'apps' => [], 'loans' => [], 'active' => []];
            foreach ($keys as $k) {
                $row['apps'][] = $raw[$k][$t]['n'] ?? 0;
                $row['loans'][] = $raw[$k][$t]['loan_n'] ?? 0;
                $row['active'][] = $raw[$k][$t]['active_n'] ?? 0;
            }
            $rows[] = $row;
        }
        $sum = static function (string $col) use ($rows, $keys): array {
            $out = [];
            foreach ($keys as $i => $k) { $s = 0; foreach ($rows as $r) { $s += $r[$col][$i]; } $out[] = $s; }
            return $out;
        };
        return ['keys' => array_values($keys), 'rows' => $rows, 'total' => ['apps' => $sum('apps'), 'loans' => $sum('loans'), 'active' => $sum('active')]];
    }

    /** payTable() in build_cust.js */
    private function payTable(array $payRaw, string $metric, callable $labelOf): array
    {
        $rows = [];
        foreach ($payRaw as $r) {
            if ($r['metric'] === $metric) { $rows[] = ['raw' => (string) $r['val'], 'label' => $labelOf((string) $r['val']), 'n' => (int) $r['n']]; }
        }
        $k = static fn (string $v): float => (float) (self::sortKey($v) ?? $v);
        usort($rows, static fn ($a, $b) => $k($a['raw']) <=> $k($b['raw']));
        return ['rows' => $rows, 'total' => array_sum(array_column($rows, 'n'))];
    }

    private function label(array $dim, string $raw): string
    {
        if ($raw === 'NULL') { return $dim['unspecified']; }
        $v = self::strip($raw);
        if ($dim['label']) { return isset(self::CITY_EN[$v]) ? self::CITY_EN[$v] . ' (' . $v . ')' : $v; }
        return $v;
    }

    /** '3:25 - 34' -> '25 - 34' (the prefix only fixes the sort order) */
    private static function strip(string $v): string
    {
        return preg_replace('/^\d+:/', '', $v) ?? $v;
    }

    private static function sortKey(string $v): ?int
    {
        return preg_match('/^(\d+):/', $v, $m) ? (int) $m[1] : null;
    }

    private static function num(float|int $v): float|int
    {
        if (is_float($v) && floor($v) === $v && abs($v) < 1e15) {
            return (int) $v;
        }
        return $v;
    }

    /** native prepares cannot reuse a named placeholder: every occurrence of :name becomes its own :pN, all bound */
    private function prepareRepeated(string $sql, array $params): \PDOStatement
    {
        $bound = [];
        $sql = preg_replace_callback('/:(' . implode('|', array_map('preg_quote', array_keys($params))) . ')\b/', static function (array $m) use (&$bound, $params): string {
            $k = 'p' . count($bound);
            $bound[$k] = $params[$m[1]];
            return ':' . $k;
        }, $sql);
        $stmt = $this->pdo->prepare($sql);
        foreach ($bound as $k => $v) { $stmt->bindValue($k, $v); }
        return $stmt;
    }

    // ------------------------------------------------------------------ SQL

    /** The pull script's BASE chain (restructured for speed, same results) + every output table as UNION ALL branches. */
    private function customersSql(): string
    {
        // application-form fields (LONGTEXT) as VARCHAR, keeping the column collation so comparisons/grouping are unchanged
        $fv = static fn (string $code, string $col, string $expr = 'v.field_value', string $extra = '', int $len = 255): string =>
            "MAX(CASE WHEN v.field_code='$code'$extra THEN CAST($expr AS CHAR($len)) COLLATE utf8mb4_unicode_ci END) $col";
        $age = static fn (string $at): string => "CASE WHEN dob IS NULL OR TIMESTAMPDIFF(YEAR,dob,$at) NOT BETWEEN 14 AND 100 THEN NULL
         WHEN TIMESTAMPDIFF(YEAR,dob,$at)<18 THEN '1:< 18' WHEN TIMESTAMPDIFF(YEAR,dob,$at)<25 THEN '2:18 - 24' WHEN TIMESTAMPDIFF(YEAR,dob,$at)<35 THEN '3:25 - 34'
         WHEN TIMESTAMPDIFF(YEAR,dob,$at)<45 THEN '4:35 - 44' WHEN TIMESTAMPDIFF(YEAR,dob,$at)<60 THEN '5:45 - 59' ELSE '6:60+' END";
        // latest application's value as in the pull script; GROUP_CONCAT would be TEXT (group_concat_max_len > 512): cast to
        // VARCHAR, city/pos keeping the column collation (the literal-valued columns have the connection collation either way)
        $latest = static fn (string $c, int $len = 64, string $coll = '', string $ord = 'created_at DESC, id DESC'): string =>
            "CAST(SUBSTRING_INDEX(GROUP_CONCAT($c ORDER BY $ord SEPARATOR '|'),'|',1) AS CHAR($len))$coll $c";
        $uc = ' COLLATE utf8mb4_unicode_ci';
        $nr = "CASE WHEN rn=1 THEN '1:New' WHEN prior_loans>0 THEN '3:Returning (previous loan)' ELSE '2:Returning (earlier application only)' END";
        $win = 'created_at>=:monthStart AND created_at < DATE(:end) + INTERVAL 1 DAY';
        // every key column of the union in one type/collation (its ORDER BY = the pull script's ORDER BY val)
        $k = static fn (string $x): string => "CAST($x AS CHAR) COLLATE utf8mb4_unicode_ci";
        $m = $k("DATE_FORMAT(created_at,'%Y-%m')");

        $base = "WITH
x AS (SELECT o.id, o.created_at, o.customer_id, o.customer_email, o.crm_active, o.crm_order_status, o.crm_close_type, o.crm_risk_status,
    {$fv('personal_ID', 'pid', 'v.field_value', " AND v.field_value<>''")},
    {$fv('gender', 'g')}, {$fv('birth_date', 'dob_raw')}, {$fv('employment_status', 'emp')}, {$fv('social_status', 'soc')}, {$fv('salary', 'sal')}, {$fv('about_us_source', 'src')},
    {$fv('position', 'pos', 'TRIM(v.field_value)', " AND TRIM(v.field_value)<>''", 500)}
  FROM orders o
  LEFT JOIN volta_application_data v ON v.order_id=o.id AND v.field_code IN ('personal_ID','gender','birth_date','employment_status','social_status','salary','about_us_source','position')
  GROUP BY o.id),
sh AS (SELECT order_id, MIN(NULLIF(NULLIF(TRIM(city),''),'N/A')) city FROM addresses WHERE address_type='order_shipping' GROUP BY order_id),
rt AS (SELECT c.id_number pid, MAX(pr.rating) rating FROM customers c JOIN crm_customer_profile pr ON pr.customer_id=c.id WHERE c.id_number IS NOT NULL AND c.id_number<>'' GROUP BY c.id_number),
app0 AS (SELECT x.id, x.created_at,
    COALESCE(x.pid, NULLIF(c.id_number,''), CONCAT('c',x.customer_id), CONCAT('e',NULLIF(x.customer_email,'')), CONCAT('o',x.id)) ident,
    (x.crm_active=1 AND x.crm_order_status<>4) act,
    (x.crm_order_status IN (5,99) OR x.crm_close_type IS NOT NULL OR (x.crm_active=1 AND x.crm_order_status<>4)) loan,
    CASE WHEN g IN ('მდედრ.','female','Female') OR (g IS NULL AND c.gender IN ('მდედრ.','Female')) THEN 'Female'
         WHEN g IN ('მამრ.','male','Male') OR (g IS NULL AND c.gender IN ('მამრ.','Male')) THEN 'Male' END gender,
    CASE WHEN dob_raw LIKE '____-__-__' THEN STR_TO_DATE(dob_raw,'%Y-%m-%d')
         WHEN dob_raw LIKE '__.__.____' THEN STR_TO_DATE(dob_raw,'%d.%m.%Y')
         WHEN dob_raw LIKE '__/__/____' THEN STR_TO_DATE(dob_raw,'%d/%m/%Y')
         WHEN c.date_of_birth > '1900-01-01' THEN c.date_of_birth END dob,
    CASE WHEN a.city='Tbilisi' THEN 'თბილისი' ELSE a.city END city,
    CASE WHEN emp='კერძო' THEN 'Private sector' WHEN emp='თვითდასაქმებული' THEN 'Self-employed' WHEN emp='საჯარო' THEN 'Public sector'
         WHEN emp='დაუსაქმებელი' THEN 'Unemployed' WHEN emp IS NOT NULL THEN 'Not specified' END emp,
    CASE WHEN soc='დიასახლისი' THEN 'Housewife' WHEN soc='სტუდენტი' THEN 'Student' WHEN soc='პენსიონერი' THEN 'Pensioner'
         WHEN soc IS NOT NULL THEN 'None of these' END soc,
    CASE WHEN sal REGEXP '^[0-9]+([.][0-9]+)?$' THEN
           CASE WHEN sal+0=0 THEN '0:0 (no salary)' WHEN sal+0<500 THEN '1:< 500' WHEN sal+0<1000 THEN '2:500 - 999' WHEN sal+0<2000 THEN '3:1,000 - 1,999'
                WHEN sal+0<3000 THEN '4:2,000 - 2,999' WHEN sal+0<5000 THEN '5:3,000 - 4,999' ELSE '6:5,000+' END
         WHEN sal='500-1000' THEN '2:500 - 999' WHEN sal='1000_2000' THEN '3:1,000 - 1,999' WHEN sal='2000-3000' THEN '4:2,000 - 2,999'
         WHEN sal IN ('3000-4000','4000-5000') THEN '5:3,000 - 4,999' WHEN sal='5000-მეტი' THEN '6:5,000+'
         WHEN sal IS NOT NULL AND sal<>'' THEN '7:Not specified' END sal,
    CASE WHEN src IN ('Facebook','Instagram','TikTok','Google') THEN src WHEN src='მეგობრის / ახლობლის რეკომენდაციით' THEN 'Friend / family recommendation'
         WHEN src='ადრეც ვსარგებლობდი ვოლტას განვადებით' THEN 'Used Volta before' WHEN src='გარე სარეკლამო ბანერით' THEN 'Outdoor banner'
         WHEN src IS NOT NULL AND src<>'' THEN 'Other' END src,
    CASE WHEN x.crm_risk_status IN ('საშუალო','medium','Medium') THEN 'Medium' WHEN x.crm_risk_status IN ('დაბალი','low','Low') THEN 'Low'
         WHEN x.crm_risk_status IN ('მაღალი','high','High') THEN 'High' END risk,
    x.pos, COALESCE(NULLIF(rt.rating,0), NULLIF(pr2.rating,0)) rating
  FROM x LEFT JOIN sh a ON a.order_id=x.id LEFT JOIN customers c ON c.id=x.customer_id
  LEFT JOIN rt ON rt.pid=COALESCE(x.pid, NULLIF(c.id_number,'')) LEFT JOIN crm_customer_profile pr2 ON pr2.customer_id=x.customer_id),
app AS (SELECT a.*, COUNT(*) OVER w rn, SUM(loan) OVER w - COALESCE(loan,0) prior_loans,
    {$age('created_at')} age
  FROM app0 a WINDOW w AS (PARTITION BY ident ORDER BY created_at, id ROWS UNBOUNDED PRECEDING)),
topc AS (SELECT city FROM app WHERE city IS NOT NULL GROUP BY city ORDER BY COUNT(*) DESC LIMIT 15),
topp AS (SELECT pos FROM app WHERE pos IS NOT NULL GROUP BY pos ORDER BY COUNT(*) DESC LIMIT 15),
cust AS (SELECT ident, MAX(act) act, MAX(loan) has_loan, COUNT(*) apps, SUM(loan) loans, MAX(rating) rating, MIN(created_at) first_at,
    {$latest('gender')}, {$latest('dob')}, {$latest('city', 255, $uc)}, {$latest('emp')}, {$latest('soc')}, {$latest('sal')}, {$latest('src')}, {$latest('pos', 500, $uc)},
    {$latest('risk', 64, '', 'act DESC, created_at DESC, id DESC')}
  FROM app GROUP BY ident),
custb AS (SELECT c.*, {$age('CURDATE()')} age,
    COALESCE(t.city, CASE WHEN c.city IS NULL THEN NULL ELSE 'Other cities' END) cityg,
    COALESCE(p.pos, CASE WHEN c.pos IS NULL THEN NULL ELSE 'Other' END) posg
  FROM cust c LEFT JOIN topc t ON t.city=c.city LEFT JOIN topp p ON p.pos=c.pos LIMIT 18446744073709551615)";

        $parts = [
            // 1) customer-level distribution per dimension (all / with active loan / ever had a loan)
            "SELECT 1 seq, 'dimsA' kind, {$k('gender')} k1, {$k('age')} k2, {$k('risk')} k3, {$k('rating')} k4, COUNT(*) n1, SUM(act) n2, SUM(has_loan) n3, NULL n4, NULL n5, NULL n6 FROM custb GROUP BY 3,4,5,6",
            "SELECT 1, 'dimsB', {$k('emp')}, {$k('soc')}, {$k('sal')}, {$k('src')}, COUNT(*), SUM(act), SUM(has_loan), NULL, NULL, NULL FROM custb GROUP BY 3,4,5,6",
            "SELECT 1, 'city', {$k('cityg')}, NULL, NULL, NULL, COUNT(*), SUM(act), SUM(has_loan), NULL, NULL, NULL FROM custb GROUP BY 3",
            "SELECT 1, 'pos', {$k('posg')}, NULL, NULL, NULL, COUNT(*), SUM(act), SUM(has_loan), NULL, NULL, NULL FROM custb GROUP BY 3",
            // 2) applications by calendar month per dimension (the application's own value; age at the application date)
            "SELECT 2, 'monA', $m, {$k('gender')}, {$k('age')}, {$k('risk')}, COUNT(*), NULL, NULL, NULL, NULL, NULL FROM app WHERE $win GROUP BY 3,4,5,6",
            "SELECT 2, 'monB', $m, {$k('emp')}, {$k('soc')}, {$k('sal')}, COUNT(*), NULL, NULL, NULL, NULL, NULL FROM app WHERE $win GROUP BY 3,4,5,6",
            "SELECT 2, 'monC', {$k("DATE_FORMAT(a.created_at,'%Y-%m')")}, {$k('a.src')}, {$k("COALESCE(t.city, CASE WHEN a.city IS NULL THEN NULL ELSE 'Other cities' END)")}, NULL, COUNT(*), NULL, NULL, NULL, NULL, NULL FROM app a LEFT JOIN topc t ON t.city=a.city WHERE a.created_at>=:monthStart AND a.created_at < DATE(:end) + INTERVAL 1 DAY GROUP BY 3,4,5",
            // 3) new vs returning applicants by day (months = sum of the days)
            "SELECT 3, 'nr', {$k("DATE_FORMAT(created_at,'%Y-%m-%d')")}, {$k($nr)}, NULL, NULL, COUNT(*), SUM(act), SUM(loan), NULL, NULL, NULL FROM app WHERE $win GROUP BY 3,4",
            // 4) applications / loans per customer
            "SELECT 4, 'hist', {$k("CASE WHEN apps>=5 THEN '5+' ELSE CAST(apps AS CHAR) END")}, {$k("CASE WHEN loans>=5 THEN '5+' ELSE CAST(loans AS CHAR) END")}, NULL, NULL, COUNT(*), SUM(act), NULL, NULL, NULL, NULL FROM cust GROUP BY 3,4",
            // 5) totals
            "SELECT 5, 'cust', NULL, NULL, NULL, NULL, COUNT(*), SUM(act), SUM(has_loan), SUM(apps), SUM(loans), SUM(first_at < :monthStart) FROM cust",
            "SELECT 5, 'app', {$k('MIN(created_at)')}, {$k('MAX(created_at)')}, NULL, NULL, SUM(created_at < :monthStart), SUM(act), SUM(created_at >= :cutover), SUM(ident LIKE 'c%' OR ident LIKE 'e%' OR ident LIKE 'o%'), NULL, NULL FROM app",
        ];
        return $base . "\n" . implode("\nUNION ALL ", $parts) . "\nORDER BY 1, 2, 3, 4, 5, 6";
    }
}

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

/**
 * Portfolio -> Portfolio Analyze for Volta_Analytics_New DB, computed live: the PHP twin of
 * volta-analytics-new-db/pull_pf.sh (SQL) + build_pf.js (aggregation), so build() returns exactly the structure of
 * pf_data.json / `const PF_JSON = …;` in deals_amount_migration.html.
 *
 * The loan book = every installment loan ever disbursed (crm_order_status 5, or 1 on rows migrated from the old CRM;
 * crm_active 1 = active, 0 = closed); kind = active | paid (crm_close_type 1) | wo (2) | norec (closed without a close
 * record); closed_at = a sane crm_close_date, else the last non-reversed crm_payments date. Stocks per day (CUTOVER..END)
 * and per month-end (MSTART..END, last point = END = MTD); balance at D = today's schedule balance + principal paid after
 * D (reversed payments and the 99,999,999.99 placeholder excluded).
 *
 * Performance: pull_pf.sh runs the book CTE in 7 separate statements (~1.2 s each on the RDS = 12.6 s in PHP). Here every
 * book-derived aggregate is one branch of a single UNION ALL statement over ONE `book` CTE (MySQL 8 materializes a CTE
 * referenced more than once exactly once), tagged by column `q`; the per-branch SQL and the row order (ORDER BY q, d, k
 * = each pull query's own ORDER BY) are unchanged, so the numbers are identical.
 *
 * MySQL 8.0.45 quirk (verified by bisecting, 2026-09-09): once a UNION branch reads the shared `book` with
 * `WHERE kind = 'active'`, a LATER branch that groups `book` by the raw `kind` column comes back split into ~2,100
 * bogus groups (the constant leaks into that GROUP BY). Grouping by the collated expression (positional `GROUP BY 3`)
 * is immune, and so is any branch placed before the active-only ones. Hence: whole-book branches first, `book_*`
 * (active-only) branches last, and the `close` / `quality` branches group by column position. Same groups, same numbers.
 *
 * END is the pull script's END (its default is today): the tab labels the last column "(today)", so pass today's date,
 * not yesterday's, to reproduce the Node pipeline.
 */
final class NewDbPf
{
    public const CUTOVER = '2026-08-31';
    /** month series / structure tables start here (2025 is the first year fully covered) */
    private const MSTART = '2025-01-01';

    private const SEG = "CASE WHEN EXISTS (SELECT 1 FROM order_items oi JOIN product_flat pf ON pf.product_id=oi.product_id AND pf.locale='ka_GE' WHERE oi.order_id=o.id AND (pf.name LIKE 'ტელეფონი%' OR pf.name LIKE 'ტელევიზორ%')) OR o.base_grand_total>2500 THEN 'A' ELSE 'B' END";

    /** the loan book CTEs (sched, pay, book) — verbatim from pull_pf.sh */
    private const BOOK = "sched AS (SELECT s.installment_id, SUM(s.schedule_amount) tot, SUM(s.schedule_amount - s.paid_amount) rem, SUM(DATEDIFF(s.schedule_date, DATE(o.crm_creator_date)) > 7) term
        FROM crm_installment_schedules s JOIN orders o ON o.id = s.installment_id GROUP BY s.installment_id),
 pay AS (SELECT installment_id, MAX(payment_date) last_pay FROM crm_payments WHERE reversed_at IS NULL GROUP BY installment_id),
 book AS (SELECT o.id, o.crm_creator_date disb, o.base_grand_total price, COALESCE(o.crm_advance_amount, 0) adv,
     CASE WHEN s.tot IS NULL THEN o.base_grand_total WHEN s.tot + COALESCE(o.crm_advance_amount, 0) <= o.base_grand_total + 0.01 THEN s.tot + COALESCE(o.crm_advance_amount, 0) ELSE s.tot END amount,
     COALESCE(s.rem, o.grand_total) rem, COALESCE(s.term, 0) term, o.crm_remaining_months rm, COALESCE(o.crm_risk_status, '') risk,
     CASE WHEN o.crm_active = 1 THEN 'active' WHEN o.crm_close_type = 2 THEN 'wo' WHEN o.crm_close_type = 1 THEN 'paid' ELSE 'norec' END kind,
     CASE WHEN o.crm_active = 1 THEN NULL WHEN o.crm_close_type IN (1, 2) AND o.crm_close_date >= o.crm_creator_date AND YEAR(o.crm_close_date) >= 2019 THEN o.crm_close_date
          ELSE COALESCE(p.last_pay, o.crm_close_date, o.crm_creator_date) END closed_at,
     " . self::SEG . " seg,
     CASE WHEN a.city IS NULL OR a.city = '' THEN 'Without City' WHEN a.city IN ('თბილისი', 'Tbilisi') THEN 'Tbilisi' ELSE 'Other Cities' END city,
     (SELECT oi.product_id FROM order_items oi WHERE oi.order_id = o.id ORDER BY oi.base_total DESC, oi.id LIMIT 1) top_product_id
   FROM orders o LEFT JOIN sched s ON s.installment_id = o.id LEFT JOIN pay p ON p.installment_id = o.id
   LEFT JOIN (SELECT order_id, MIN(city) city FROM addresses WHERE address_type = 'order_shipping' GROUP BY order_id) a ON a.order_id = o.id
   WHERE o.crm_order_status IN (1, 5) AND o.crm_active IN (0, 1) AND o.crm_creator_date IS NOT NULL)";

    /** the contract-amount band (1..6), used by the structure and active-book tables */
    private const AMT_BAND_SQL = "CASE WHEN amount < 500 THEN '1' WHEN amount < 1000 THEN '2' WHEN amount < 2000 THEN '3' WHEN amount < 3000 THEN '4' WHEN amount < 5000 THEN '5' ELSE '6' END";
    private const K = ' COLLATE utf8mb4_unicode_ci';

    private const AMT_BANDS = [1 => '< 500 GEL', 2 => '500 – 999 GEL', 3 => '1,000 – 1,999 GEL', 4 => '2,000 – 2,999 GEL', 5 => '3,000 – 4,999 GEL', 6 => '5,000+ GEL'];
    private const TERM_BANDS = ['1–3 months', '4–6 months', '7–9 months', '10 months', '11–12 months', '13+ months', 'No schedule'];
    private const AGE_BANDS = ['0–1 months', '2–3 months', '4–6 months', '7–9 months', '10–12 months', '13+ months'];
    private const RISK_ORDER = ['Low (დაბალი)', 'Medium (საშუალო)', 'High (მაღალი)', 'Not scored'];
    private const RM_ORDER = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11+', 'Unknown'];
    private const CITY_ORDER = ['Tbilisi', 'Other Cities', 'Without City'];
    private const SEG_LABEL = ['A' => 'Segment A (phone / TV or > 2,500 GEL)', 'B' => 'Segment B (other)'];
    private const SEG_ORDER = ['Segment A (phone / TV or > 2,500 GEL)', 'Segment B (other)'];
    private const KIND_LABEL = ['active' => 'Still active', 'paid' => 'Paid off (recorded close)', 'norec' => 'Paid off (no close record)', 'wo' => 'Written off'];

    /** @var array<string, float> seconds per query, filled by build() (name => seconds) */
    public array $timings = [];

    /** @var array<string, array{categoryGe:string,subcategoryGe:string,categoryEn:string,subcategoryEn:string,productEn:string}> */
    private array $mapping = [];

    public function __construct(private readonly PDO $pdo, private readonly string $dir, private readonly string $mappingPath)
    {
    }

    /** @param string $end the report END date (YYYY-MM-DD) = the last day of the day series / the MTD point of the month series */
    public function build(string $end): array
    {
        $this->timings = [];
        $cutover = self::CUTOVER;
        $mstart = self::MSTART;

        // ---- one statement, one `book`: every branch = one pull_pf.sh query, tagged by q; uniform columns
        //      q, d, k, n, amount, price, rem, act, c1..c5 (branch-specific extras, cast to CHAR)
        $K = self::K;
        $amtBand = self::AMT_BAND_SQL;
        $sql = "WITH RECURSIVE cald AS (SELECT DATE(:cutover) d UNION ALL SELECT d + INTERVAL 1 DAY FROM cald WHERE d < DATE(:endCal)),
 calm AS (SELECT LEAST(LAST_DAY(DATE(:mstart)), DATE(:end1)) d UNION ALL SELECT LEAST(LAST_DAY(d + INTERVAL 1 DAY), DATE(:end2)) FROM calm WHERE d < DATE(:end3)),
 cal AS (SELECT 'day' s, d FROM cald UNION ALL SELECT 'month' s, d FROM calm),
 " . self::BOOK . ",
 act AS (SELECT cal.s, cal.d, b.id, b.rem, b.amount, b.price FROM cal JOIN book b ON b.disb < cal.d + INTERVAL 1 DAY AND (b.closed_at IS NULL OR b.closed_at >= cal.d + INTERVAL 1 DAY)),
 pa AS (SELECT a.s, a.d, SUM(p.principal_part) paid_after FROM act a JOIN crm_payments p ON p.installment_id = a.id AND p.reversed_at IS NULL AND p.amount < 1000000 AND p.payment_date > a.d AND p.payment_date <= DATE(:endPa) GROUP BY a.s, a.d),
 m AS (SELECT b.*, DATE_FORMAT(b.disb, '%Y-%m') mo FROM book b WHERE b.disb >= :mstartM AND DATE(b.disb) <= :endM),
 a AS (SELECT b.* FROM book b WHERE b.kind = 'active')
 -- stocks at the end of each day (cutover .. END) and each month-end (MSTART .. END, last point = END = MTD)
 SELECT CONCAT('stock_', a.s) q, a.d d, ''$K k, COUNT(*) n, ROUND(SUM(a.amount), 2) amount, ROUND(SUM(a.price), 2) price, ROUND(SUM(a.rem), 2) rem, NULL act, CAST(ROUND(COALESCE(MAX(pa.paid_after), 0), 2) AS CHAR) c1, NULL c2, NULL c3, NULL c4, NULL c5
   FROM act a LEFT JOIN pa ON pa.s = a.s AND pa.d = a.d GROUP BY a.s, a.d
 -- flows by day (MSTART .. END): disbursed loans keyed to the disbursement date
 UNION ALL SELECT 'disb', DATE(b.disb), ''$K, COUNT(*), ROUND(SUM(b.amount), 2), ROUND(SUM(b.price), 2), NULL, NULL, CAST(SUM(b.term) AS CHAR), CAST(SUM(b.adv > 0) AS CHAR), CAST(ROUND(SUM(b.adv), 2) AS CHAR), CAST(SUM(b.seg = 'A') AS CHAR), NULL
   FROM book b WHERE b.disb >= :mstartD AND DATE(b.disb) <= :endD GROUP BY DATE(b.disb)
 -- closed loans keyed to closed_at, by kind
 UNION ALL SELECT 'close', DATE(b.closed_at), b.kind$K, COUNT(*), ROUND(SUM(b.amount), 2), ROUND(SUM(b.price), 2), ROUND(SUM(b.rem), 2), NULL, NULL, NULL, NULL, NULL, NULL
   FROM book b WHERE b.kind <> 'active' AND b.closed_at >= :mstartC AND DATE(b.closed_at) <= :endC GROUP BY 2, 3
 -- single-payment sales (crm_order_status 99) — memo row, not part of the loan book
 UNION ALL SELECT 'single', DATE(o.crm_creator_date), ''$K, COUNT(*), NULL, ROUND(SUM(o.base_grand_total), 2), NULL, NULL, NULL, NULL, NULL, NULL, NULL
   FROM orders o WHERE o.crm_order_status = 99 AND o.crm_creator_date >= :mstartS AND DATE(o.crm_creator_date) <= :endS GROUP BY DATE(o.crm_creator_date)
 -- data-quality memo (whole book): how each kind is recorded. Kept BEFORE the active-only branches and grouped by the
 -- collated expression (column 3), not the raw column: see the MySQL note in the class docblock
 UNION ALL SELECT 'quality', '', b.kind$K, COUNT(*), NULL, NULL, ROUND(SUM(b.rem), 2), NULL, CAST(SUM(b.rem <= 1) AS CHAR), CAST(MIN(DATE(b.disb)) AS CHAR), CAST(MAX(DATE(b.disb)) AS CHAR), CAST(MIN(DATE(b.closed_at)) AS CHAR), CAST(MAX(DATE(b.closed_at)) AS CHAR)
   FROM book b GROUP BY 3
 UNION ALL SELECT 'quality2', '', ''$K, NULL, NULL, NULL, NULL, NULL, CAST(SUM(o.crm_close_type IN (1,2) AND (o.crm_close_date < o.crm_creator_date OR YEAR(o.crm_close_date) < 2019)) AS CHAR), CAST(SUM(o.crm_active = 1 AND o.crm_order_status = 5 AND o.grand_total <= 0) AS CHAR), CAST(SUM(o.crm_order_status = 99) AS CHAR), CAST(SUM(o.crm_close_type IN (1,2) AND o.crm_close_date >= :cutoverQ) AS CHAR), NULL
   FROM orders o
 -- structure of the loans disbursed each month: dim, month, key, n, amount, rem (balance left today), act (still active)
 UNION ALL SELECT 'struct_term', mo, CAST(term AS CHAR)$K, COUNT(*), ROUND(SUM(amount), 2), NULL, ROUND(SUM(rem), 2), SUM(kind = 'active'), NULL, NULL, NULL, NULL, NULL FROM m GROUP BY 2, 3
 UNION ALL SELECT 'struct_amt', mo, $amtBand$K, COUNT(*), ROUND(SUM(amount), 2), NULL, ROUND(SUM(rem), 2), SUM(kind = 'active'), NULL, NULL, NULL, NULL, NULL FROM m GROUP BY 2, 3
 UNION ALL SELECT 'struct_seg', mo, seg$K, COUNT(*), ROUND(SUM(amount), 2), NULL, ROUND(SUM(rem), 2), SUM(kind = 'active'), NULL, NULL, NULL, NULL, NULL FROM m GROUP BY 2, 3
 UNION ALL SELECT 'struct_city', mo, city$K, COUNT(*), ROUND(SUM(amount), 2), NULL, ROUND(SUM(rem), 2), SUM(kind = 'active'), NULL, NULL, NULL, NULL, NULL FROM m GROUP BY 2, 3
 UNION ALL SELECT 'struct_risk', mo, risk$K, COUNT(*), ROUND(SUM(amount), 2), NULL, ROUND(SUM(rem), 2), SUM(kind = 'active'), NULL, NULL, NULL, NULL, NULL FROM m GROUP BY 2, 3
 UNION ALL SELECT 'struct_goods', mo, CAST(top_product_id AS CHAR)$K, COUNT(*), ROUND(SUM(amount), 2), NULL, ROUND(SUM(rem), 2), SUM(kind = 'active'), NULL, NULL, NULL, NULL, NULL FROM m GROUP BY 2, 3
 UNION ALL SELECT 'struct_kind', mo, kind$K, COUNT(*), ROUND(SUM(amount), 2), NULL, ROUND(SUM(rem), 2), SUM(kind = 'active'), NULL, NULL, NULL, NULL, NULL FROM m GROUP BY 2, 3
 -- the active book today: dim, key, n, amount, rem
 UNION ALL SELECT 'book_rm', '', CAST(COALESCE(rm, -1) AS CHAR)$K, COUNT(*), ROUND(SUM(amount), 2), NULL, ROUND(SUM(rem), 2), NULL, NULL, NULL, NULL, NULL, NULL FROM a GROUP BY 3
 UNION ALL SELECT 'book_age', '', CAST(TIMESTAMPDIFF(MONTH, disb, DATE(:endAge)) AS CHAR)$K, COUNT(*), ROUND(SUM(amount), 2), NULL, ROUND(SUM(rem), 2), NULL, NULL, NULL, NULL, NULL, NULL FROM a GROUP BY 3
 UNION ALL SELECT 'book_risk', '', risk$K, COUNT(*), ROUND(SUM(amount), 2), NULL, ROUND(SUM(rem), 2), NULL, NULL, NULL, NULL, NULL, NULL FROM a GROUP BY 3
 UNION ALL SELECT 'book_term', '', CAST(term AS CHAR)$K, COUNT(*), ROUND(SUM(amount), 2), NULL, ROUND(SUM(rem), 2), NULL, NULL, NULL, NULL, NULL, NULL FROM a GROUP BY 3
 UNION ALL SELECT 'book_amt', '', $amtBand$K, COUNT(*), ROUND(SUM(amount), 2), NULL, ROUND(SUM(rem), 2), NULL, NULL, NULL, NULL, NULL, NULL FROM a GROUP BY 3
 UNION ALL SELECT 'book_seg', '', seg$K, COUNT(*), ROUND(SUM(amount), 2), NULL, ROUND(SUM(rem), 2), NULL, NULL, NULL, NULL, NULL, NULL FROM a GROUP BY 3
 UNION ALL SELECT 'book_city', '', city$K, COUNT(*), ROUND(SUM(amount), 2), NULL, ROUND(SUM(rem), 2), NULL, NULL, NULL, NULL, NULL, NULL FROM a GROUP BY 3
 UNION ALL SELECT 'book_goods', '', CAST(top_product_id AS CHAR)$K, COUNT(*), ROUND(SUM(amount), 2), NULL, ROUND(SUM(rem), 2), NULL, NULL, NULL, NULL, NULL, NULL FROM a GROUP BY 3
 ORDER BY q, d, k";
        $all = $this->q('book_all', $sql, ['cutover' => $cutover, 'endCal' => $end, 'mstart' => $mstart, 'end1' => $end, 'end2' => $end, 'end3' => $end, 'endPa' => $end,
            'mstartM' => $mstart, 'endM' => $end, 'mstartD' => $mstart, 'endD' => $end, 'mstartC' => $mstart, 'endC' => $end, 'mstartS' => $mstart, 'endS' => $end, 'endAge' => $end, 'cutoverQ' => $cutover]);
        $byQ = [];
        foreach ($all as $r) { $byQ[$r['q']][] = $r; }
        $pick = static fn (string $q): array => $byQ[$q] ?? [];

        // ---- stocks: n, rem_now (rem), contract (amount), price, paid_after (c1)
        $stockOf = static function (array $rows): array {
            $o = ['dates' => [], 'active' => [], 'balance' => [], 'contract' => [], 'price' => []];
            foreach ($rows as $r) {
                $o['dates'][] = (string) $r['d'];
                $o['active'][] = (int) $r['n'];
                $o['balance'][] = self::r2((float) $r['rem'] + (float) $r['c1']);
                $o['contract'][] = self::num((float) $r['amount']);
                $o['price'][] = self::num((float) $r['price']);
            }
            return $o;
        };
        $stockDay = $stockOf($pick('stock_day'));
        $stockMonth = $stockOf($pick('stock_month'));
        $stockMonth['months'] = array_map(static fn ($d) => substr($d, 0, 7), $stockMonth['dates']);
        $END = (string) end($stockDay['dates']);
        $MSTART = $stockMonth['months'][0];
        $dayDates = $stockDay['dates'];
        $months = $stockMonth['months'];

        // ---- flows: day extracts (MSTART .. END) rolled up to the day series (cutover .. END) and the month series
        $disbRows = $pick('disb');     // d, n, amount, price, term_sum (c1), adv_n (c2), adv_sum (c3), seg_a (c4)
        $closeRows = $pick('close');   // d, kind (k), n, amount, price, rem
        $singleRows = $pick('single'); // d, n, price
        $flowsFor = static function (array $keys, callable $keyOf) use ($disbRows, $closeRows, $singleRows): array {
            $z = array_fill(0, count($keys), 0);
            $idx = array_flip($keys);
            $f = ['keys' => $keys,
                'disb' => ['n' => $z, 'amount' => $z, 'price' => $z, 'adv' => $z, 'termSum' => $z, 'advN' => $z, 'segA' => $z],
                'single' => ['n' => $z, 'price' => $z],
                'close' => ['paid' => ['n' => $z, 'amount' => $z], 'norec' => ['n' => $z, 'amount' => $z], 'wo' => ['n' => $z, 'amount' => $z, 'rem' => $z]]];
            foreach ($disbRows as $r) {
                $i = $idx[$keyOf((string) $r['d'])] ?? null;
                if ($i === null) { continue; }
                $f['disb']['n'][$i] += (int) $r['n'];
                $f['disb']['amount'][$i] += (float) $r['amount'];
                $f['disb']['price'][$i] += (float) $r['price'];
                $f['disb']['adv'][$i] += (float) $r['c3'];
                $f['disb']['termSum'][$i] += (int) $r['c1'];
                $f['disb']['advN'][$i] += (int) $r['c2'];
                $f['disb']['segA'][$i] += (int) $r['c4'];
            }
            foreach ($singleRows as $r) {
                $i = $idx[$keyOf((string) $r['d'])] ?? null;
                if ($i === null) { continue; }
                $f['single']['n'][$i] += (int) $r['n'];
                $f['single']['price'][$i] += (float) $r['price'];
            }
            foreach ($closeRows as $r) {
                $i = $idx[$keyOf((string) $r['d'])] ?? null;
                if ($i === null || !isset($f['close'][$r['k']])) { continue; }
                $c = &$f['close'][$r['k']];
                $c['n'][$i] += (int) $r['n'];
                $c['amount'][$i] += (float) $r['amount'];
                if (isset($c['rem'])) { $c['rem'][$i] += (float) $r['rem']; }
                unset($c);
            }
            $r2 = static fn (array $a): array => array_map([self::class, 'r2'], $a);
            foreach (['disb', 'single'] as $g) { foreach ($f[$g] as $k => $a) { $f[$g][$k] = $r2($a); } }
            foreach ($f['close'] as $kind => $g) { foreach ($g as $k => $a) { $f['close'][$kind][$k] = $r2($a); } }
            return $f;
        };
        $flowDay = $flowsFor($dayDates, static fn (string $d) => $d);
        $flowMonth = $flowsFor($months, static fn (string $d) => substr($d, 0, 7));

        // ---- label rules shared by the structure tables and the current-book tables
        $goodsType = $this->goodsTypeClassifier();
        $risk = static function ($raw): string {
            $s = strtolower(trim((string) $raw));
            if ($s === 'დაბალი' || $s === 'low') { return 'Low (დაბალი)'; }
            if ($s === 'საშუალო' || $s === 'medium') { return 'Medium (საშუალო)'; }
            if ($s === 'მაღალი' || $s === 'high') { return 'High (მაღალი)'; }
            return 'Not scored';
        };
        $termBand = static function ($t): string {
            $t = (int) $t;
            if (!$t) { return 'No schedule'; }
            if ($t <= 3) { return '1–3 months'; }
            if ($t <= 6) { return '4–6 months'; }
            if ($t <= 9) { return '7–9 months'; }
            if ($t === 10) { return '10 months'; }
            if ($t <= 12) { return '11–12 months'; }
            return '13+ months';
        };
        $ageBand = static function ($a): string {
            $a = (int) $a;
            if ($a <= 1) { return '0–1 months'; }
            if ($a <= 3) { return '2–3 months'; }
            if ($a <= 6) { return '4–6 months'; }
            if ($a <= 9) { return '7–9 months'; }
            if ($a <= 12) { return '10–12 months'; }
            return '13+ months';
        };
        $rmLabel = static function ($k): string {
            $k = (int) $k;
            if ($k < 0) { return 'Unknown'; }
            if ($k >= 11) { return '11+'; }
            return (string) $k;
        };
        $amtLabel = static fn ($k) => self::AMT_BANDS[(int) $k] ?? 'Unknown';
        $segLabel = static fn ($k) => self::SEG_LABEL[(string) $k] ?? (string) $k;
        $ident = static fn ($k) => (string) $k;

        // ---- structure of the loans disbursed each month: one table per dimension, rows = bands, columns = months (+ totals)
        //      rows: mo (d), k, n, amount, rem, act — in (mo, k) order like pull_pf.sh, so first-appearance order (goods) is the same
        $structTable = static function (string $dim, callable $labelOf, ?array $order, string $title, string $headLabel) use ($pick, $months): array {
            $zero = array_fill(0, count($months), 0);
            $mIdx = array_flip($months);
            $rows = [];
            $get = static function (string $l) use (&$rows, $zero): void {
                if (!isset($rows[$l])) { $rows[$l] = ['label' => $l, 'n' => $zero, 'amount' => $zero, 'act' => $zero]; }
            };
            foreach ($order ?? [] as $l) { $get($l); }
            foreach ($pick('struct_' . $dim) as $r) {
                $i = $mIdx[$r['d']] ?? null;
                if ($i === null) { continue; }
                $l = $labelOf($r['k'] ?? '');
                $get($l);
                $rows[$l]['n'][$i] += (int) $r['n'];
                $rows[$l]['amount'][$i] += (float) $r['amount'];
                $rows[$l]['act'][$i] += (int) $r['act'];
            }
            $out = [];
            foreach ($rows as $g) {
                $out[] = ['label' => (string) $g['label'], 'n' => $g['n'], 'amount' => array_map([self::class, 'r2'], $g['amount']), 'act' => $g['act'], 'total' => array_sum($g['n'])];
            }
            if ($order === null) {
                usort($out, static fn ($a, $b) => (($a['label'] === 'Uncategorized') <=> ($b['label'] === 'Uncategorized')) ?: $b['total'] <=> $a['total']);
            }
            $out = array_values(array_filter($out, static fn ($g) => $g['total'] > 0));
            $tn = [];
            $ta = [];
            foreach ($months as $i => $m) {
                $n = 0;
                $a = 0.0;
                foreach ($out as $g) { $n += $g['n'][$i]; $a += $g['amount'][$i]; }
                $tn[] = $n;
                $ta[] = self::r2($a);
            }
            return ['title' => $title, 'headLabel' => $headLabel, 'rows' => $out, 'total' => ['n' => $tn, 'amount' => $ta]];
        };
        $struct = [
            'term' => $structTable('term', $termBand, self::TERM_BANDS, 'Loans by term (months in the payment schedule)', 'Term'),
            'amt' => $structTable('amt', $amtLabel, array_values(self::AMT_BANDS), 'Loans by contract amount', 'Contract amount'),
            'seg' => $structTable('seg', $segLabel, self::SEG_ORDER, 'Loans by segment', 'Segment'),
            'goods' => $structTable('goods', $goodsType, null, 'Loans by goods type (highest-value product line)', 'Goods Type'),
            'city' => $structTable('city', $ident, self::CITY_ORDER, 'Loans by city (shipping address)', 'City'),
            'risk' => $structTable('risk', $risk, self::RISK_ORDER, 'Loans by risk status (crm_risk_status)', 'Risk status'),
        ];
        // vintage: per disbursement month, how the loans stand today
        $by = [];
        foreach ($months as $m) { $by[$m] = ['disb' => 0, 'amount' => 0.0, 'active' => 0, 'paid' => 0, 'norec' => 0, 'wo' => 0, 'rem' => 0.0, 'remActive' => 0.0]; }
        foreach ($pick('struct_kind') as $r) {
            if (!isset($by[$r['d']])) { continue; }
            $g = &$by[$r['d']];
            $g['disb'] += (int) $r['n'];
            $g['amount'] += (float) $r['amount'];
            $g[(string) $r['k']] = ($g[(string) $r['k']] ?? 0) + (int) $r['n'];
            $g['rem'] += (float) $r['rem'];
            if ($r['k'] === 'active') { $g['remActive'] += (float) $r['rem']; }
            unset($g);
        }
        $vintage = [];
        foreach ($months as $m) {
            $g = $by[$m];
            $vintage[] = array_merge(['month' => $m], $g, ['amount' => self::r2($g['amount']), 'rem' => self::r2($g['rem']), 'remActive' => self::r2($g['remActive'])]);
        }

        // ---- the active book today (as of END): rows k, n, amount, rem — in k order like pull_pf.sh
        $bookTable = static function (string $dim, callable $labelOf, ?array $order, string $title, string $headLabel) use ($pick): array {
            $rows = [];
            $get = static function (string $l) use (&$rows): void {
                if (!isset($rows[$l])) { $rows[$l] = ['label' => $l, 'n' => 0, 'amount' => 0.0, 'rem' => 0.0]; }
            };
            foreach ($order ?? [] as $l) { $get($l); }
            foreach ($pick('book_' . $dim) as $r) {
                $l = $labelOf($r['k'] ?? '');
                $get($l);
                $rows[$l]['n'] += (int) $r['n'];
                $rows[$l]['amount'] += (float) $r['amount'];
                $rows[$l]['rem'] += (float) $r['rem'];
            }
            $out = [];
            foreach ($rows as $g) {
                $out[] = ['label' => (string) $g['label'], 'n' => $g['n'], 'amount' => self::r2($g['amount']), 'rem' => self::r2($g['rem'])];
            }
            if ($order === null) {
                usort($out, static fn ($a, $b) => (($a['label'] === 'Uncategorized') <=> ($b['label'] === 'Uncategorized')) ?: $b['n'] <=> $a['n']);
            }
            $out = array_values(array_filter($out, static fn ($g) => $g['n'] > 0));
            $tn = 0;
            $ta = 0.0;
            $tr = 0.0;
            foreach ($out as $g) { $tn += $g['n']; $ta += $g['amount']; $tr += $g['rem']; }
            return ['title' => $title, 'headLabel' => $headLabel, 'rows' => $out, 'total' => ['n' => $tn, 'amount' => self::r2($ta), 'rem' => self::r2($tr)]];
        };
        $book = [
            'rm' => $bookTable('rm', $rmLabel, self::RM_ORDER, 'Active loans by months remaining (crm_remaining_months)', 'Months remaining'),
            'age' => $bookTable('age', $ageBand, self::AGE_BANDS, 'Active loans by age (months since disbursement)', 'Loan age'),
            'risk' => $bookTable('risk', $risk, self::RISK_ORDER, 'Active loans by risk status', 'Risk status'),
            'term' => $bookTable('term', $termBand, self::TERM_BANDS, 'Active loans by term', 'Term'),
            'amt' => $bookTable('amt', $amtLabel, array_values(self::AMT_BANDS), 'Active loans by contract amount', 'Contract amount'),
            'seg' => $bookTable('seg', $segLabel, self::SEG_ORDER, 'Active loans by segment', 'Segment'),
            'city' => $bookTable('city', $ident, self::CITY_ORDER, 'Active loans by city', 'City'),
            'goods' => $bookTable('goods', $goodsType, null, 'Active loans by goods type', 'Goods Type'),
        ];

        // ---- data-quality memo: kind (k), n, rem, fully_paid (c1), first_disb (c2), last_disb (c3), first_close (c4), last_close (c5)
        $kinds = [];
        foreach ($pick('quality') as $r) {
            $kinds[] = ['kind' => (string) $r['k'], 'label' => self::KIND_LABEL[$r['k']] ?? (string) $r['k'], 'n' => (int) $r['n'], 'rem' => self::num((float) $r['rem']), 'fullyPaid' => (int) $r['c1'],
                'firstDisb' => (string) $r['c2'], 'lastDisb' => (string) $r['c3'], 'firstClose' => $r['c4'] === null ? null : (string) $r['c4'], 'lastClose' => $r['c5'] === null ? null : (string) $r['c5']];
        }
        $q = $pick('quality2')[0] ?? [];
        $intOrNull = static fn ($v) => ($v === null || $v === '') ? null : (int) $v;
        $quality = ['kinds' => $kinds, 'badCloseDates' => $intOrNull($q['c1'] ?? null), 'activeZeroBalance' => $intOrNull($q['c2'] ?? null), 'singleAll' => $intOrNull($q['c3'] ?? null), 'closesSinceCutover' => $intOrNull($q['c4'] ?? null)];

        return ['cutover' => $cutover, 'mstart' => $MSTART, 'end' => $END, 'stockDay' => $stockDay, 'stockMonth' => $stockMonth, 'flowDay' => $flowDay, 'flowMonth' => $flowMonth,
            'struct' => $struct, 'vintage' => $vintage, 'book' => $book, 'quality' => $quality, 'generatedAt' => gmdate('Y-m-d H:i') . ' UTC'];
    }

    // ------------------------------------------------------------------ helpers

    /** prepared query, timed into $this->timings[$name] */
    private function q(string $name, string $sql, array $params = []): array
    {
        $t = microtime(true);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $this->timings[$name] = round(microtime(true) - $t, 3);
        return $rows;
    }

    /** goods type = mapping-sheet categoryEn over the new-DB category tree (categories / category_translations / product_categories), same as NewDbReport::logistics() */
    private function goodsTypeClassifier(): callable
    {
        $this->loadMapping();
        $cats = [];
        foreach ($this->q('categories', "SELECT c.id, c.parent_id, ct.name FROM categories c LEFT JOIN category_translations ct ON ct.category_id=c.id AND ct.locale='ka_GE'") as $r) {
            $cats[(string) $r['id']] = ['parent' => (string) ($r['parent_id'] ?? ''), 'name' => trim((string) ($r['name'] ?? ''))];
        }
        $depth = static function (string $id) use ($cats): int {
            $d = 0;
            $c = $cats[$id] ?? null;
            while ($c && $c['parent'] !== '' && isset($cats[$c['parent']])) { $d++; $c = $cats[$c['parent']]; }
            return $d;
        };
        $productCats = [];
        foreach ($this->q('product_categories', 'SELECT product_id, category_id FROM product_categories ORDER BY product_id, category_id') as $r) {
            $productCats[(string) $r['product_id']][] = (string) $r['category_id'];
        }
        return function ($productId) use ($productCats, $cats, $depth): string {
            $ids = $productCats[(string) $productId] ?? [];
            usort($ids, static fn ($a, $b) => $depth($a) <=> $depth($b));
            $names = [];
            foreach ($ids as $id) { $n = $cats[$id]['name'] ?? ''; if ($n !== '' && $n !== 'none') { $names[] = $n; } }
            $s = self::label(($this->lookup(implode(',', $names)) ?? [])['categoryEn'] ?? null);
            return $s ?? 'Uncategorized';
        };
    }

    private function loadMapping(): void
    {
        $raw = json_decode((string) file_get_contents($this->mappingPath), true, 512, JSON_THROW_ON_ERROR);
        $this->mapping = [];
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

    /** JS-compatible number: whole floats become ints so json_encode prints 6734, not 6734.0 */
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
}
