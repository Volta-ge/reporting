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
 *  - Applications / Terms Approved / Underwriting Approved / Downpayment Collected are keyed
 *    to Aplication_Date (when the client applied).
 *  - Deals Closed and Amount Sold are keyed to Order_Date (when the loan was actually
 *    issued/disbursed) — a loan applied for on one day but issued the next counts toward the
 *    day it was issued, for both figures.
 *
 * Amount Sold = SUM(Full_Cost), i.e. Initial_Amount + First_Payment — the full sale price of
 * the product, not just the financed principal. Downpayment Collected is still reported
 * separately (it's cash collected upfront), so it's already included inside Amount Sold —
 * the two are not meant to be added together.
 *
 * Deals Closed changed from Aplication_Date to Order_Date on 2026-08-25, per a business
 * decision (Shai, in reply to a report explaining the original logic): counting by
 * disbursement date "aligns the number of closed deals with the daily sales amount (GEL),
 * which is already reported based on the date of sale" — and "in the long run, these timing
 * differences should naturally balance out" (i.e. a deal applied for on one day and closed
 * the next was previously "missing" from that day's Deals Closed and only appeared on its
 * application day, undercounting same-day-close activity relative to Amount Sold).
 */
final class FunnelRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * The six funnel figures for one period out of a dailySegmentStats()/monthlySegmentStats()
     * result, as `['A' => [...], 'B' => [...]]`.
     *
     * The Yesterday and MTD headline figures are not queried separately: DateHelper gives the
     * yesterday, month-to-date and daily/monthly windows the same exclusive end (today 00:00),
     * so the current-month row of monthlySegmentStats() *is* month-to-date and the yesterday
     * row of dailySegmentStats() *is* yesterday — same date fields, same filters, same six
     * sums. Reading them from those results instead of re-running four two-query
     * segmentMetrics() lookups drops 8 of the page's round trips against a slow remote DB.
     *
     * A period with no rows in either underlying query is absent from the result entirely
     * (see periodSegmentStats()), which means a genuine zero — hence the zero-filled fallback.
     *
     * @param array<string, array{A: array, B: array}> $periodStats
     * @return array{A: array{applications: int, terms: int, uw: int, closed: int, amount: float, dp: float}, B: array{applications: int, terms: int, uw: int, closed: int, amount: float, dp: float}}
     */
    public static function periodFigures(array $periodStats, string $periodKey): array
    {
        $zero = ['applications' => 0, 'terms' => 0, 'uw' => 0, 'closed' => 0, 'amount' => 0.0, 'dp' => 0.0];

        return $periodStats[$periodKey] ?? ['A' => $zero, 'B' => $zero];
    }

    /**
     * Same shape as segmentMetrics(), but grouped by calendar day and split by segment —
     * one entry per day from $from through the day before $to, each with an 'A' and 'B'
     * six-figure array. Used to pivot the full Section A/B/C report layout across
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
     * applications/terms/uw/dp, Order_Date for closed/amount) computes both segments' figures
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
                SUM(CASE WHEN {$segA} THEN i.First_Payment ELSE 0 END) AS a_dp,
                SUM(CASE WHEN NOT ({$segA}) THEN 1 ELSE 0 END) AS b_applications,
                SUM(CASE WHEN NOT ({$segA}) THEN (i.UnderWriter_Status_ID != 0) ELSE 0 END) AS b_terms,
                SUM(CASE WHEN NOT ({$segA}) THEN (i.UnderWriter_Status_ID = 16) ELSE 0 END) AS b_uw,
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

        // Deals Closed and Amount Sold both keyed to Order_Date, both restricted to Active = 1 —
        // see the class docblock for why (2026-08-25 business decision to align Deals Closed
        // with how Amount Sold already counts by disbursement date, not application date).
        $stmtAmount = $this->pdo->prepare(<<<SQL
            SELECT
                DATE_FORMAT(i.Order_Date, '{$dateFormat}') AS period,
                SUM(CASE WHEN {$segA} THEN 1 ELSE 0 END) AS a_closed,
                SUM(CASE WHEN {$segA} THEN i.Full_Cost ELSE 0 END) AS a_amount,
                SUM(CASE WHEN NOT ({$segA}) THEN 1 ELSE 0 END) AS b_closed,
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
                    'closed' => (int) ($amtRow['a_closed'] ?? 0),
                    'amount' => round((float) ($amtRow['a_amount'] ?? 0.0), 2),
                    'dp' => round((float) ($appRow['a_dp'] ?? 0.0), 2),
                ],
                'B' => [
                    'applications' => (int) ($appRow['b_applications'] ?? 0),
                    'terms' => (int) ($appRow['b_terms'] ?? 0),
                    'uw' => (int) ($appRow['b_uw'] ?? 0),
                    'closed' => (int) ($amtRow['b_closed'] ?? 0),
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
     * Order_Status = 5 is the "real sale" filter for installment deals — confirmed by
     * reverse-engineering against 34 known case IDs from the business's own report; Active is
     * NOT the right filter here (varies freely across genuine sales, since a sale can still be
     * active=0 later e.g. paid off/closed). Sales = SUM(Final_Price), Cogs = SUM(Start_Price) —
     * both raw, unmodified from what's stored per line item, matching the business's own
     * report's own methodology exactly (their own Cogs column is not garbage-cleaned either).
     *
     * Single-payment ("ერთიანი გადახდა") sales are a separate deal type entirely, confirmed
     * against real Instalment_IDs from two admin-panel screenshots (2026-08-25):
     * `Type_Of_Sales = 99` (installment deals are always `0`), and they use `Order_Status IN
     * (1, 3)` instead of 5 — this business's committee/invoice pipeline doesn't apply to a
     * same-day full-payment sale. Their `Order_Date` is frequently NULL (unlike installment
     * deals, where it's always populated) — `COALESCE(Order_Date, Aplication_Date)` is used as
     * the effective sale-month date for both deal types, since Aplication_Date is the only
     * date reliably populated for single-payment rows and, for a one-step cash sale, is
     * effectively the transaction date anyway. Each of the three *MonthlyStats() methods below
     * returns `['all' => ..., 'installment' => ..., 'single' => ...]` — all three built from one
     * DB fetch (see rawSalesRows()/cogsGarbageStats()), not three separate queries.
     */
    public function salesMonthlyStats(DateTimeImmutable $from, DateTimeImmutable $to, ProductClassifier $classifier): array
    {
        $rawRows = $this->salesRowsFor($from, $to, 'category');
        $garbage = $this->cogsGarbageStats($from, $to);
        $result = [];
        foreach (['all', 'installment', 'single'] as $dealType) {
            $filtered = $dealType === 'all' ? $rawRows : array_filter($rawRows, fn ($r) => $r['deal_type'] === $dealType);
            $report = $this->buildBucketedReport($filtered, fn (?string $raw) => $classifier->classify($raw), 'Uncategorized');
            $report['garbage'] = $garbage[$dealType];
            $result[$dealType] = $report;
        }
        return $result;
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
        $rawRows = $this->salesRowsFor($from, $to, 'brand');
        $noBrandValues = ['none', 'n/a', 'ბრენდის გარეშე', ''];
        $classify = function (?string $raw) use ($noBrandValues): ?string {
            $name = trim((string) $raw);
            if ($name === '' || in_array(strtolower($name), $noBrandValues, true)) {
                return null;
            }
            return $name;
        };
        $garbage = $this->cogsGarbageStats($from, $to);
        $result = [];
        foreach (['all', 'installment', 'single'] as $dealType) {
            $filtered = $dealType === 'all' ? $rawRows : array_filter($rawRows, fn ($r) => $r['deal_type'] === $dealType);
            $report = $this->buildBucketedReport($filtered, $classify, 'No Brand');
            $report['garbage'] = $garbage[$dealType];
            $result[$dealType] = $report;
        }
        return $result;
    }

    /**
     * Category/Brand tab: one block per product category, brands broken down within it —
     * mirrors the exact layout of the business's own reference report's "Top 4 — Brands" sheet
     * (Brand | Q1 Sales | Q2 Sales | <period> Sales | Share % | COGS | PR Mrg % | Q-ty, one
     * category title bar + header + brand rows + total row per category), just built for every
     * category found in the window instead of a hand-picked top 4, and using "Total Sales" for
     * the third sales column instead of "H1 Sales" since this report's window runs Jan through
     * yesterday (matching every other tab here), not a fixed first-half cutoff — Q1/Q2 are still
     * broken out as their own columns for the same at-a-glance quarterly comparison the
     * reference sheet gives, they just don't bound the total anymore. COGS/Margin/Qty are always
     * for the whole window (not per-quarter), matching the reference sheet's own formulas
     * exactly (its COGS/Margin/Qty columns are SUMIFS/COUNTIFS with no EOM date filter, only
     * Q1/Q2 Sales are date-bounded). Same All/Installment/Single-Payment deal-type split as
     * Sales Monthly/Brand Analyze/Subcategory Analyze (`['all' => ..., 'installment' => ...,
     * 'single' => ...]`) — the reference sheet itself has no such distinction, but this project's
     * own convention across every other sales tab does, so kept consistent rather than being the
     * one report without it. Uses the exact same `rawSalesRows()` infra as Brand Analyze —
     * the two share a single category+brand fetch, and the `CHAR(1)`-joined composite key is
     * assembled in PHP from that result's own category/brand columns, so it's one query, not
     * one query per category, and all three deal-type variants are built from that one fetch
     * (PHP-side filtering), not three separate queries.
     */
    public function categoryBrandBreakdown(DateTimeImmutable $from, DateTimeImmutable $to, ProductClassifier $classifier): array
    {
        $rawRows = $this->salesRowsFor($from, $to, 'catbrand');

        $result = [];
        foreach (['all', 'installment', 'single'] as $dealType) {
            $filtered = $dealType === 'all' ? $rawRows : array_filter($rawRows, fn ($r) => $r['deal_type'] === $dealType);
            $result[$dealType] = $this->buildCategoryBrandReport($filtered, $classifier);
        }
        return $result;
    }

    private function buildCategoryBrandReport(array $rawRows, ProductClassifier $classifier): array
    {
        $noBrandValues = ['none', 'n/a', 'ბრენდის გარეშე', ''];
        $emptyPeriod = ['sales' => 0.0, 'cogs' => 0.0, 'qty' => 0];
        $byCategory = [];

        foreach ($rawRows as $row) {
            [$rawCategory, $rawBrand] = explode("\x01", $row['raw_key'], 2);
            $category = $classifier->classify($rawCategory) ?? 'Uncategorized';
            $brandName = trim($rawBrand);
            $brand = ($brandName === '' || in_array(strtolower($brandName), $noBrandValues, true)) ? 'No Brand' : $brandName;

            $month = substr($row['period'], 5, 2);
            $quarter = in_array($month, ['01', '02', '03'], true) ? 'q1' : (in_array($month, ['04', '05', '06'], true) ? 'q2' : null);

            $sales = round((float) $row['sales'], 2);
            $cogs = round((float) $row['cogs'], 2);
            $qty = (int) $row['qty'];

            $byCategory[$category][$brand] ??= ['q1' => $emptyPeriod, 'q2' => $emptyPeriod, 'total' => $emptyPeriod];
            $entry = &$byCategory[$category][$brand];
            if ($quarter !== null) {
                $entry[$quarter]['sales'] += $sales;
                $entry[$quarter]['cogs'] += $cogs;
                $entry[$quarter]['qty'] += $qty;
            }
            $entry['total']['sales'] += $sales;
            $entry['total']['cogs'] += $cogs;
            $entry['total']['qty'] += $qty;
            unset($entry);
        }

        $categories = [];
        foreach ($byCategory as $categoryName => $brands) {
            $brandRows = [];
            $catTotal = ['sales' => 0.0, 'cogs' => 0.0, 'qty' => 0];
            foreach ($brands as $brandName => $periods) {
                $margin = $periods['total']['sales'] > 0
                    ? ($periods['total']['sales'] - $periods['total']['cogs']) / $periods['total']['sales']
                    : null;
                $brandRows[] = [
                    'brand' => $brandName,
                    'q1' => $periods['q1'],
                    'q2' => $periods['q2'],
                    'total' => $periods['total'],
                    'margin' => $margin,
                ];
                $catTotal['sales'] += $periods['total']['sales'];
                $catTotal['cogs'] += $periods['total']['cogs'];
                $catTotal['qty'] += $periods['total']['qty'];
            }
            usort($brandRows, static fn ($a, $b) => $b['total']['sales'] <=> $a['total']['sales']);
            foreach ($brandRows as &$br) {
                $br['share'] = $catTotal['sales'] > 0 ? round($br['total']['sales'] / $catTotal['sales'], 4) : 0.0;
            }
            unset($br);
            $catMargin = $catTotal['sales'] > 0 ? ($catTotal['sales'] - $catTotal['cogs']) / $catTotal['sales'] : null;
            $categories[] = ['category' => $categoryName, 'brands' => $brandRows, 'total' => $catTotal, 'margin' => $catMargin];
        }
        usort($categories, static function ($a, $b) {
            if ($a['category'] === 'Uncategorized') {
                return 1;
            }
            if ($b['category'] === 'Uncategorized') {
                return -1;
            }
            return $b['total']['sales'] <=> $a['total']['sales'];
        });

        return ['categories' => $categories];
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
        $rawRows = $this->salesRowsFor($from, $to, 'category');
        $garbage = $this->cogsGarbageStats($from, $to);
        $result = [];
        foreach (['all', 'installment', 'single'] as $dealType) {
            $filtered = $dealType === 'all' ? $rawRows : array_filter($rawRows, fn ($r) => $r['deal_type'] === $dealType);
            $report = $this->buildBucketedReport($filtered, fn (?string $raw) => $classifier->classifySubcategory($raw), 'Uncategorized');
            $report['garbage'] = $garbage[$dealType];
            $result[$dealType] = $report;
        }
        return $result;
    }

    /** @var array<string, array> */
    private array $rawGroupedSalesCache = [];

    /**
     * Shared raw query for the four sales reports above. All three groupings they need —
     * by product category, by brand, and by the two together — come back from a single
     * round trip as one UNION ALL, tagged with `grouping_key`, instead of the three separate
     * queries this used to issue against a slow remote DB.
     *
     * Each branch keeps its own `GROUP BY`, so every Sales/Cogs figure is still summed by the
     * database exactly as before, at exactly the grain its report consumes. That is deliberate:
     * re-deriving the coarser groupings from the finest one in PHP would re-add the same money
     * in a different order, and IEEE-754 addition is not associative — a one-ULP drift is
     * enough to flip a figure that lands on `.50` when the dashboard rounds it to whole GEL.
     *
     * The date filter deliberately spells out the two COALESCE branches rather than wrapping
     * the column in a function: `COALESCE(i.Order_Date, ...) >= :from` cannot use an index on
     * Order_Date, this form can. It is exactly equivalent — a NULL Order_Date takes the
     * Aplication_Date branch, and a non-NULL one (including a '0000-00-00' zero date, which
     * COALESCE does not treat as NULL either) is compared on its own value, as before.
     *
     * Memoized for the request only — not a cache between page loads.
     */
    private function rawSalesRows(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $cacheKey = $from->format('Y-m-d H:i:s') . '|' . $to->format('Y-m-d H:i:s');
        if (isset($this->rawGroupedSalesCache[$cacheKey])) {
            return $this->rawGroupedSalesCache[$cacheKey];
        }

        // Every occurrence gets its own placeholder name: Database sets ATTR_EMULATE_PREPARES
        // to false, and PDO cannot bind one named placeholder to several positions in a native
        // prepared statement.
        $branch = static function (string $tag, string $groupExpr, string $extraJoin, int $i): string {
            return <<<SQL
                SELECT
                    '{$tag}' AS grouping_key,
                    DATE_FORMAT(COALESCE(i.Order_Date, i.Aplication_Date), '%Y-%m') AS period,
                    {$groupExpr} AS raw_key,
                    CASE WHEN i.Type_Of_Sales = 99 THEN 'single' ELSE 'installment' END AS deal_type,
                    SUM(ip.Final_Price) AS sales,
                    SUM(ip.Start_Price) AS cogs,
                    COUNT(*) AS qty
                FROM instalment_products ip
                JOIN instalments i ON i.Instalment_ID = ip.Instalment_ID
                JOIN products p ON p.Product_ID = ip.Product_ID
                LEFT JOIN product_category pc ON pc.Category_ID = p.Category_ID
                {$extraJoin}
                WHERE (
                        (i.Order_Date IS NOT NULL AND i.Order_Date >= :fromA{$i} AND i.Order_Date < :toA{$i})
                     OR (i.Order_Date IS NULL AND i.Aplication_Date >= :fromB{$i} AND i.Aplication_Date < :toB{$i})
                      )
                  AND (i.Order_Status = 5 OR (i.Order_Status IN (1, 3) AND i.Type_Of_Sales = 99))
                GROUP BY period, {$groupExpr}, deal_type
                SQL;
        };

        $brandJoin = 'LEFT JOIN product_brands pb ON pb.Brand_ID = p.Brand_ID';
        $sql = implode("\nUNION ALL\n", [
            $branch('category', 'pc.Category_Name', '', 1),
            $branch('brand', 'pb.Brand_Name', $brandJoin, 2),
            $branch('catbrand', "CONCAT(COALESCE(pc.Category_Name, ''), CHAR(1), COALESCE(pb.Brand_Name, ''))", $brandJoin, 3),
        ]);

        $params = [];
        foreach ([1, 2, 3] as $i) {
            $params["fromA{$i}"] = $from->format('Y-m-d H:i:s');
            $params["toA{$i}"] = $to->format('Y-m-d H:i:s');
            $params["fromB{$i}"] = $from->format('Y-m-d H:i:s');
            $params["toB{$i}"] = $to->format('Y-m-d H:i:s');
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $this->rawGroupedSalesCache[$cacheKey] = $rows;

        return $rows;
    }

    /** The rows of one UNION ALL branch of rawSalesRows(), in the order the DB returned them. */
    private function salesRowsFor(DateTimeImmutable $from, DateTimeImmutable $to, string $groupingKey): array
    {
        return array_values(array_filter(
            $this->rawSalesRows($from, $to),
            static fn (array $r) => $r['grouping_key'] === $groupingKey,
        ));
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
     * @return array{all: array{count: int, salesAffected: float, total: int}, installment: array{count: int, salesAffected: float, total: int}, single: array{count: int, salesAffected: float, total: int}}
     */
    /** @var array<string, array{all: array{count: int, salesAffected: float, total: int}, installment: array{count: int, salesAffected: float, total: int}, single: array{count: int, salesAffected: float, total: int}}> */
    private array $garbageStatsCache = [];

    private function cogsGarbageStats(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        // Memoized — Sales Monthly / Brand Analyze / Subcategory Analyze all call this for the
        // same window on the same request, and the underlying query pulls every line item in
        // the window (tens of thousands of rows over a slow link to myvolta.info); running it
        // three times pushed a single page load past PHP's default 30s execution limit.
        $cacheKey = $from->format('Y-m-d H:i:s') . '|' . $to->format('Y-m-d H:i:s');
        if (isset($this->garbageStatsCache[$cacheKey])) {
            return $this->garbageStatsCache[$cacheKey];
        }

        // Distinct placeholder names per occurrence — see rawSalesRows().
        $params = [
            'fromA' => $from->format('Y-m-d H:i:s'),
            'toA' => $to->format('Y-m-d H:i:s'),
            'fromB' => $from->format('Y-m-d H:i:s'),
            'toB' => $to->format('Y-m-d H:i:s'),
        ];
        $stmt = $this->pdo->prepare(<<<SQL
            SELECT
                ip.Product_ID AS product_id, ip.Start_Price AS start_price, ip.Final_Price AS final_price,
                CASE WHEN i.Type_Of_Sales = 99 THEN 'single' ELSE 'installment' END AS deal_type
            FROM instalment_products ip
            JOIN instalments i ON i.Instalment_ID = ip.Instalment_ID
            WHERE (
                    (i.Order_Date IS NOT NULL AND i.Order_Date >= :fromA AND i.Order_Date < :toA)
                 OR (i.Order_Date IS NULL AND i.Aplication_Date >= :fromB AND i.Aplication_Date < :toB)
                  )
              AND (i.Order_Status = 5 OR (i.Order_Status IN (1, 3) AND i.Type_Of_Sales = 99))
            SQL);
        $stmt->execute($params);
        $allLines = $stmt->fetchAll();

        $isRepdigit = static fn (float $v): bool => $v > 0 && preg_match('/^1+$/', (string) (int) round($v)) === 1;

        $computeForLines = function (array $lines) use ($isRepdigit): array {
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
        };

        $installmentLines = array_filter($allLines, fn ($l) => $l['deal_type'] === 'installment');
        $singleLines = array_filter($allLines, fn ($l) => $l['deal_type'] === 'single');

        $result = [
            'all' => $computeForLines($allLines),
            'installment' => $computeForLines($installmentLines),
            'single' => $computeForLines($singleLines),
        ];
        $this->garbageStatsCache[$cacheKey] = $result;
        return $result;
    }
}
