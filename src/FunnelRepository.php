<?php

declare(strict_types=1);

namespace Volta\Funnel;

use DateTimeImmutable;
use PDO;

/**
 * All queries against `instalments`, mirroring the definitions worked out for the
 * Volta Funnel Dashboard. See README.md for the full explanation of each metric.
 *
 * Two different date fields are used on purpose:
 *  - Applications / Terms Approved / Underwriting Approved / Deals Closed / Downpayment
 *    Collected are keyed to Aplication_Date (when the client applied).
 *  - Amount Sold is keyed to Order_Date (when the loan was actually issued/disbursed) —
 *    a loan applied for on one day but issued the next counts toward the day it was issued.
 *
 * Amount Sold = SUM(Full_Cost), i.e. Initial_Amount + First_Payment — the full sale price of
 * the product, not just the financed principal. Downpayment Collected is still reported
 * separately (it's cash collected upfront), so it's already included inside Amount Sold —
 * the two are not meant to be added together.
 */
final class FunnelRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function segmentMetrics(DateTimeImmutable $from, DateTimeImmutable $to, Segment $segment): SegmentMetrics
    {
        $segmentCondition = $segment->sqlCondition();
        $params = [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ];

        // Applications / Terms Approved / Underwriting Approved / Deals Closed / Downpayment Collected.
        // Excludes unassigned "lead" placeholder rows (Product_ID = 1, no real product chosen yet).
        // Downpayment Collected counts every First_Payment regardless of underwriting/active status —
        // once collected it is not refunded, so it counts even on a rejected or still-pending deal.
        $stmt = $this->pdo->prepare(<<<SQL
            SELECT
                COUNT(*) AS applications,
                SUM(i.UnderWriter_Status_ID != 0) AS terms_approved,
                SUM(i.UnderWriter_Status_ID = 16) AS uw_approved,
                SUM(i.Active = 1) AS deals_closed,
                SUM(i.First_Payment) AS dp_collected
            FROM instalments i
            LEFT JOIN products p ON p.Product_ID = i.Product_ID
            WHERE i.Aplication_Date >= :from AND i.Aplication_Date < :to
              AND i.Product_ID > 1
              AND {$segmentCondition}
            SQL);
        $stmt->execute($params);
        $row = $stmt->fetch();

        // Amount Sold: full product price (Initial_Amount + First_Payment) for active/disbursed
        // deals, keyed to Order_Date — the actual sale value, not just the financed principal.
        $stmtAmount = $this->pdo->prepare(<<<SQL
            SELECT SUM(i.Full_Cost) AS amount_sold
            FROM instalments i
            LEFT JOIN products p ON p.Product_ID = i.Product_ID
            WHERE i.Order_Date >= :from AND i.Order_Date < :to
              AND i.Product_ID > 1
              AND i.Active = 1
              AND {$segmentCondition}
            SQL);
        $stmtAmount->execute($params);
        $amountRow = $stmtAmount->fetch();

        return new SegmentMetrics(
            applications: (int) $row['applications'],
            termsApproved: (int) $row['terms_approved'],
            underwritingApproved: (int) $row['uw_approved'],
            dealsClosed: (int) $row['deals_closed'],
            amountSold: round((float) ($amountRow['amount_sold'] ?? 0.0), 2),
            downpaymentCollected: round((float) ($row['dp_collected'] ?? 0.0), 2),
        );
    }

    /**
     * Same shape as segmentMetrics(), but grouped by calendar day and split by segment —
     * one entry per day from $from through the day before $to, each with an 'A' and 'B'
     * SegmentMetrics-shaped array. Used to pivot the full Section A/B/C report layout across
     * many periods (one column pair per day) instead of just Yesterday/MTD.
     *
     * @return array<string, array{A: array, B: array}> keyed by 'Y-m-d'
     */
    public function dailySegmentStats(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->periodSegmentStats($from, $to, '%Y-%m-%d');
    }

    /**
     * Same as dailySegmentStats() but grouped by calendar month ('Y-m') instead of day —
     * used for the MTD Statistics tab (one column pair per month). For the current,
     * still-in-progress month this naturally yields the MTD-to-date figures; for a
     * completed past month it yields that month's final total.
     *
     * @return array<string, array{A: array, B: array}> keyed by 'Y-m'
     */
    public function monthlySegmentStats(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->periodSegmentStats($from, $to, '%Y-%m');
    }

    /**
     * Shared implementation: one CASE-WHEN pass per date field (Aplication_Date for
     * applications/terms/uw/closed/dp, Order_Date for amount) computes both segments' figures
     * together, grouped by the given DATE_FORMAT pattern — two queries total regardless of how
     * many periods come out, rather than one query per period.
     */
    private function periodSegmentStats(DateTimeImmutable $from, DateTimeImmutable $to, string $dateFormat): array
    {
        $segA = Segment::A->sqlCondition();
        $params = [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ];

        $stmt = $this->pdo->prepare(<<<SQL
            SELECT
                DATE_FORMAT(i.Aplication_Date, '{$dateFormat}') AS period,
                SUM(CASE WHEN {$segA} THEN 1 ELSE 0 END) AS a_applications,
                SUM(CASE WHEN {$segA} THEN (i.UnderWriter_Status_ID != 0) ELSE 0 END) AS a_terms,
                SUM(CASE WHEN {$segA} THEN (i.UnderWriter_Status_ID = 16) ELSE 0 END) AS a_uw,
                SUM(CASE WHEN {$segA} THEN (i.Active = 1) ELSE 0 END) AS a_closed,
                SUM(CASE WHEN {$segA} THEN i.First_Payment ELSE 0 END) AS a_dp,
                SUM(CASE WHEN NOT ({$segA}) THEN 1 ELSE 0 END) AS b_applications,
                SUM(CASE WHEN NOT ({$segA}) THEN (i.UnderWriter_Status_ID != 0) ELSE 0 END) AS b_terms,
                SUM(CASE WHEN NOT ({$segA}) THEN (i.UnderWriter_Status_ID = 16) ELSE 0 END) AS b_uw,
                SUM(CASE WHEN NOT ({$segA}) THEN (i.Active = 1) ELSE 0 END) AS b_closed,
                SUM(CASE WHEN NOT ({$segA}) THEN i.First_Payment ELSE 0 END) AS b_dp
            FROM instalments i
            LEFT JOIN products p ON p.Product_ID = i.Product_ID
            WHERE i.Aplication_Date >= :from AND i.Aplication_Date < :to
              AND i.Product_ID > 1
            GROUP BY DATE_FORMAT(i.Aplication_Date, '{$dateFormat}')
            SQL);
        $stmt->execute($params);
        $byApplicationDate = [];
        foreach ($stmt->fetchAll() as $row) {
            $byApplicationDate[$row['period']] = $row;
        }

        $stmtAmount = $this->pdo->prepare(<<<SQL
            SELECT
                DATE_FORMAT(i.Order_Date, '{$dateFormat}') AS period,
                SUM(CASE WHEN {$segA} THEN i.Full_Cost ELSE 0 END) AS a_amount,
                SUM(CASE WHEN NOT ({$segA}) THEN i.Full_Cost ELSE 0 END) AS b_amount
            FROM instalments i
            LEFT JOIN products p ON p.Product_ID = i.Product_ID
            WHERE i.Order_Date >= :from AND i.Order_Date < :to
              AND i.Product_ID > 1
              AND i.Active = 1
            GROUP BY DATE_FORMAT(i.Order_Date, '{$dateFormat}')
            SQL);
        $stmtAmount->execute($params);
        $byOrderDate = [];
        foreach ($stmtAmount->fetchAll() as $row) {
            $byOrderDate[$row['period']] = $row;
        }

        $periods = [];
        $allKeys = array_unique(array_merge(array_keys($byApplicationDate), array_keys($byOrderDate)));
        sort($allKeys);
        foreach ($allKeys as $key) {
            $appRow = $byApplicationDate[$key] ?? null;
            $amtRow = $byOrderDate[$key] ?? null;
            $periods[$key] = [
                'A' => [
                    'applications' => (int) ($appRow['a_applications'] ?? 0),
                    'terms' => (int) ($appRow['a_terms'] ?? 0),
                    'uw' => (int) ($appRow['a_uw'] ?? 0),
                    'closed' => (int) ($appRow['a_closed'] ?? 0),
                    'amount' => round((float) ($amtRow['a_amount'] ?? 0.0), 2),
                    'dp' => round((float) ($appRow['a_dp'] ?? 0.0), 2),
                ],
                'B' => [
                    'applications' => (int) ($appRow['b_applications'] ?? 0),
                    'terms' => (int) ($appRow['b_terms'] ?? 0),
                    'uw' => (int) ($appRow['b_uw'] ?? 0),
                    'closed' => (int) ($appRow['b_closed'] ?? 0),
                    'amount' => round((float) ($amtRow['b_amount'] ?? 0.0), 2),
                    'dp' => round((float) ($appRow['b_dp'] ?? 0.0), 2),
                ],
            ];
        }

        return $periods;
    }

    /**
     * Sales Monthly tab: Sales / COGS / Margin / Qty per product bucket, one column-group
     * per calendar month, plus Q1/Q2 quarterly summary blocks (with each bucket's share of
     * that quarter's total Sales) and an overall Total block (share of the whole window).
     * Mirrors the business's own "Monthly" report sheet's per-product section as closely as
     * the DB allows — see ProductClassifier for why raw Category_Name needs translating, and
     * see its class docblock for the exact-match validation against the business's own
     * numbers (Smartphones, Jan 2026: 53010 / 28967 / 34).
     *
     * Order_Status = 5 is the "real sale" filter — confirmed by reverse-engineering against
     * 34 known case IDs from the business's own report; Active is NOT the right filter here
     * (varies freely across genuine sales, since a sale can still be active=0 later e.g. paid
     * off/closed). Sales = SUM(Final_Price), Cogs = SUM(Start_Price) — both raw, unmodified
     * from what's stored per line item, matching the business's own report's own methodology
     * exactly (their own Cogs column is not garbage-cleaned either).
     */
    public function salesMonthlyStats(DateTimeImmutable $from, DateTimeImmutable $to, ProductClassifier $classifier): array
    {
        $rawRows = $this->rawGroupedSales($from, $to, 'pc.Category_Name');
        $report = $this->buildBucketedReport($rawRows, fn (?string $raw) => $classifier->classify($raw), 'Uncategorized');
        $report['garbage'] = $this->cogsGarbageStats($from, $to);
        return $report;
    }

    /**
     * Brand Analyze tab: same shape as salesMonthlyStats(), grouped by `product_brands.Brand_Name`
     * instead of product category. Brand_ID's FK link is 100% populated (unlike Category_ID),
     * but the *value* itself is sometimes a placeholder for "no real brand" in three different
     * spellings found in the data — 'none', 'N/A', 'ბრენდის გარეშე' ("without brand") — all
     * three are consolidated into one "No Brand" bucket rather than shown as three separate
     * near-empty rows.
     */
    public function brandMonthlyStats(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rawRows = $this->rawGroupedSales($from, $to, 'pb.Brand_Name', 'LEFT JOIN product_brands pb ON pb.Brand_ID = p.Brand_ID');
        $noBrandValues = ['none', 'n/a', 'ბრენდის გარეშე', ''];
        $classify = function (?string $raw) use ($noBrandValues): ?string {
            $name = trim((string) $raw);
            if ($name === '' || in_array(strtolower($name), $noBrandValues, true)) {
                return null;
            }
            return $name;
        };
        $report = $this->buildBucketedReport($rawRows, $classify, 'No Brand');
        $report['garbage'] = $this->cogsGarbageStats($from, $to);
        return $report;
    }

    /**
     * Subcategory Analyze tab: same shape as salesMonthlyStats(), grouped by the mapping
     * sheet's broader Subcategory(EN) bucket (e.g. "Small Kitchen Appliances", which combines
     * Air Fryer/Blender/Toaster/etc. from the Product-level grouping) instead of the specific
     * Product(EN) bucket. Same ProductClassifier / mapping table as salesMonthlyStats() — just
     * a different field of the same lookup entry.
     */
    public function subcategoryMonthlyStats(DateTimeImmutable $from, DateTimeImmutable $to, ProductClassifier $classifier): array
    {
        $rawRows = $this->rawGroupedSales($from, $to, 'pc.Category_Name');
        $report = $this->buildBucketedReport($rawRows, fn (?string $raw) => $classifier->classifySubcategory($raw), 'Uncategorized');
        $report['garbage'] = $this->cogsGarbageStats($from, $to);
        return $report;
    }

    /**
     * Shared raw query for the three *MonthlyStats() methods above — one row per
     * (calendar month, raw grouping value), Order_Status = 5 filter, Sales/Cogs/Qty summed.
     * $groupExpr is a trusted SQL expression (column reference), never user input.
     */
    private function rawGroupedSales(DateTimeImmutable $from, DateTimeImmutable $to, string $groupExpr, string $extraJoin = ''): array
    {
        $params = [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ];
        $stmt = $this->pdo->prepare(<<<SQL
            SELECT
                DATE_FORMAT(i.Order_Date, '%Y-%m') AS period,
                {$groupExpr} AS raw_key,
                SUM(ip.Final_Price) AS sales,
                SUM(ip.Start_Price) AS cogs,
                COUNT(*) AS qty
            FROM instalment_products ip
            JOIN instalments i ON i.Instalment_ID = ip.Instalment_ID
            JOIN products p ON p.Product_ID = ip.Product_ID
            LEFT JOIN product_category pc ON pc.Category_ID = p.Category_ID
            {$extraJoin}
            WHERE i.Order_Date >= :from AND i.Order_Date < :to
              AND i.Order_Status = 5
            GROUP BY period, {$groupExpr}
            SQL);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Turns raw (period, raw_key, sales, cogs, qty) rows into the bucketed report shape shared
     * by Sales Monthly / Brand Analyze / Subcategory Analyze: $classify maps a raw_key to a
     * display bucket name (or null, which gets folded into $fallbackBucket — "Uncategorized" /
     * "No Brand" — and always sorted last regardless of size, since it's a data-quality signal,
     * not a real product/brand/category line).
     *
     * Adds Q1 (Jan-Mar) and Q2 (Apr-Jun) quarterly summary blocks — only for whichever of those
     * months are actually present in the queried window — plus an overall Total block, each
     * carrying that bucket's "share" (fraction of that block's grand-total Sales, 0..1) so the
     * UI can show e.g. "Smartphones = 23% of Q1 sales" the way the business's own report does.
     *
     * @return array{
     *   periods: string[], q1Periods: string[], q2Periods: string[],
     *   rows: array<int, array{bucket: string, byPeriod: array<string, array{sales: float, cogs: float, qty: int}>, q1: array{sales: float, cogs: float, qty: int, share: float}, q2: array{sales: float, cogs: float, qty: int, share: float}, total: array{sales: float, cogs: float, qty: int, share: float}}>,
     *   grandTotal: array{sales: float, cogs: float, qty: int}, grandQ1: array{sales: float, cogs: float, qty: int}, grandQ2: array{sales: float, cogs: float, qty: int},
     *   uncategorized: array{count: int, sales: float},
     * }
     */
    private function buildBucketedReport(array $rawRows, callable $classify, string $fallbackBucket): array
    {
        $periodsSeen = [];
        $byBucket = [];
        $fallbackCount = 0;
        $fallbackSales = 0.0;

        foreach ($rawRows as $row) {
            $period = $row['period'];
            $periodsSeen[$period] = true;
            $sales = round((float) $row['sales'], 2);
            $cogs = round((float) $row['cogs'], 2);
            $qty = (int) $row['qty'];

            $bucket = $classify($row['raw_key']);
            if ($bucket === null) {
                $fallbackCount += $qty;
                $fallbackSales += $sales;
                $bucket = $fallbackBucket;
            }

            $byBucket[$bucket][$period] ??= ['sales' => 0.0, 'cogs' => 0.0, 'qty' => 0];
            $byBucket[$bucket][$period]['sales'] += $sales;
            $byBucket[$bucket][$period]['cogs'] += $cogs;
            $byBucket[$bucket][$period]['qty'] += $qty;
        }

        $periods = array_keys($periodsSeen);
        sort($periods);
        $q1Periods = array_values(array_filter($periods, static fn ($p) => in_array(substr($p, 5, 2), ['01', '02', '03'], true)));
        $q2Periods = array_values(array_filter($periods, static fn ($p) => in_array(substr($p, 5, 2), ['04', '05', '06'], true)));

        $sumCells = static function (array $cells): array {
            $out = ['sales' => 0.0, 'cogs' => 0.0, 'qty' => 0];
            foreach ($cells as $c) {
                $out['sales'] += $c['sales'];
                $out['cogs'] += $c['cogs'];
                $out['qty'] += $c['qty'];
            }
            return $out;
        };

        $rows = [];
        $grandTotal = ['sales' => 0.0, 'cogs' => 0.0, 'qty' => 0];
        $grandQ1 = ['sales' => 0.0, 'cogs' => 0.0, 'qty' => 0];
        $grandQ2 = ['sales' => 0.0, 'cogs' => 0.0, 'qty' => 0];

        foreach ($byBucket as $bucket => $byPeriod) {
            foreach ($periods as $period) {
                $byPeriod[$period] ??= ['sales' => 0.0, 'cogs' => 0.0, 'qty' => 0];
            }
            $q1 = $sumCells(array_map(static fn ($p) => $byPeriod[$p], $q1Periods));
            $q2 = $sumCells(array_map(static fn ($p) => $byPeriod[$p], $q2Periods));
            $total = $sumCells($byPeriod);

            $rows[] = ['bucket' => $bucket, 'byPeriod' => $byPeriod, 'q1' => $q1, 'q2' => $q2, 'total' => $total];
            $grandTotal['sales'] += $total['sales']; $grandTotal['cogs'] += $total['cogs']; $grandTotal['qty'] += $total['qty'];
            $grandQ1['sales'] += $q1['sales']; $grandQ1['cogs'] += $q1['cogs']; $grandQ1['qty'] += $q1['qty'];
            $grandQ2['sales'] += $q2['sales']; $grandQ2['cogs'] += $q2['cogs']; $grandQ2['qty'] += $q2['qty'];
        }

        foreach ($rows as &$r) {
            $r['q1']['share'] = $grandQ1['sales'] > 0 ? round($r['q1']['sales'] / $grandQ1['sales'], 4) : 0.0;
            $r['q2']['share'] = $grandQ2['sales'] > 0 ? round($r['q2']['sales'] / $grandQ2['sales'], 4) : 0.0;
            $r['total']['share'] = $grandTotal['sales'] > 0 ? round($r['total']['sales'] / $grandTotal['sales'], 4) : 0.0;
        }
        unset($r);

        usort($rows, static function ($a, $b) use ($fallbackBucket) {
            if ($a['bucket'] === $fallbackBucket) return 1;
            if ($b['bucket'] === $fallbackBucket) return -1;
            return $b['total']['sales'] <=> $a['total']['sales'];
        });

        return [
            'periods' => $periods,
            'q1Periods' => $q1Periods,
            'q2Periods' => $q2Periods,
            'rows' => $rows,
            'grandTotal' => $grandTotal,
            'grandQ1' => $grandQ1,
            'grandQ2' => $grandQ2,
            'uncategorized' => ['count' => $fallbackCount, 'sales' => round($fallbackSales, 2)],
        ];
    }

    /**
     * Informational only — does NOT affect the Cogs sums above (which intentionally stay
     * raw, matching the business's own report). Flags how much of the window's COGS data
     * is placeholder/junk, per the two-part rule worked out with the business:
     *  1) literal repdigit placeholders (1, 11, 111, ...) — staff enters these because the
     *     system won't save the line without *some* value, then comes back later with the
     *     real cost;
     *  2) statistical outliers — a line's Start_Price under 0.5x or over 2x that same
     *     Product_ID's own clean (repdigit-excluded) median within this window.
     * See volta_sales_monthly memory for how these two thresholds were calibrated against
     * real data (not guessed) — repdigits alone account for ~38% of all-time nonzero
     * Start_Price entries; the 0.5x/2x band was chosen because 99.8% of clean values fall
     * within it.
     *
     * @return array{count: int, salesAffected: float, total: int}
     */
    private function cogsGarbageStats(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $params = [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ];
        $stmt = $this->pdo->prepare(<<<SQL
            SELECT ip.Product_ID AS product_id, ip.Start_Price AS start_price, ip.Final_Price AS final_price
            FROM instalment_products ip
            JOIN instalments i ON i.Instalment_ID = ip.Instalment_ID
            WHERE i.Order_Date >= :from AND i.Order_Date < :to
              AND i.Order_Status = 5
            SQL);
        $stmt->execute($params);
        $lines = $stmt->fetchAll();

        $isRepdigit = static fn (float $v): bool => $v > 0 && preg_match('/^1+$/', (string) (int) round($v)) === 1;

        $cleanByProduct = [];
        foreach ($lines as $line) {
            $sp = (float) $line['start_price'];
            if ($sp > 0 && !$isRepdigit($sp)) {
                $cleanByProduct[$line['product_id']][] = $sp;
            }
        }
        $medianByProduct = [];
        foreach ($cleanByProduct as $pid => $values) {
            sort($values);
            $n = count($values);
            $mid = intdiv($n, 2);
            $medianByProduct[$pid] = $n % 2 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
        }

        $garbageCount = 0;
        $garbageSales = 0.0;
        foreach ($lines as $line) {
            $sp = (float) $line['start_price'];
            $med = $medianByProduct[$line['product_id']] ?? null;
            $isGarbage = $sp <= 0 || $isRepdigit($sp) || ($med !== null && ($sp < 0.5 * $med || $sp > 2 * $med));
            if ($isGarbage) {
                $garbageCount++;
                $garbageSales += (float) $line['final_price'];
            }
        }

        return ['count' => $garbageCount, 'salesAffected' => round($garbageSales, 2), 'total' => count($lines)];
    }
}
