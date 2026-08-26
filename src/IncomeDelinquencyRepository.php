<?php

declare(strict_types=1);

namespace Volta\Funnel;

use DateTimeImmutable;
use PDO;

/**
 * Income & Delinquency by product dimension — 4 tabs (Category/Subcategory/Brand/Product), each
 * showing two reports side by side, one column-group per calendar month plus Q1/Q2/Total summary
 * blocks (same idiom as Sales Monthly/Brand Analyze/Subcategory Analyze). Built 2026-08-26 per
 * the user's explicit instruction, both metric definitions confirmed with them before building
 * (via AskUserQuestion) rather than guessed:
 *
 *  - **Income = realized (actually collected) margin**, not invoiced/sale-time margin. Scoped to
 *    CLOSED loans only (`Close_Type IN (1, 2)`, keyed to `Close_Date`) — a loan that's still
 *    active hasn't finished paying, so its true realized income isn't known yet. For a loan that
 *    closed Paid Off (`Close_Type = 1`), 100% of its Full_Cost was collected (Debt ≈ 0, confirmed
 *    this session). For one Written Off (`Close_Type = 2`), only `Full_Cost - Debt` was actually
 *    collected before the rest was abandoned. Per-loan `collectionRatio = (Full_Cost - Debt) /
 *    Full_Cost` is computed once per loan, then applied proportionally to each of that loan's
 *    product lines (`ip.Final_Price * collectionRatio`) to get a per-line realized revenue figure
 *    — the same kind of proportional allocation this project already uses nowhere else, needed
 *    here specifically because Cogs lives at the line-item level but collection status lives at
 *    the loan level. This is a modeling simplification (assumes partial payment is collected
 *    evenly across a loan's product lines, not applied to one item before another) — documented
 *    here rather than treated as exact.
 *  - **Delinquency = write-off rate by product**: among closed loans, what share (by quantity
 *    and by GEL) ended up `Close_Type = 2` (written off/bad debt) vs `Close_Type = 1` (paid off
 *    in full), broken down by the same product dimension. Written-off GEL is the same
 *    proportional-Final_Price allocation of the loan's remaining Debt across its product lines;
 *    Paid-off GEL is just the line's own Final_Price (no allocation needed — fully collected).
 *
 * Both reports share one raw query per dimension (`rawIncomeDelinquencyRows()`, memoized) — same
 * "fetch once, aggregate in PHP" approach as `FunnelRepository::rawGroupedSales()` — since the
 * closed-loan-with-line-items dataset for this window is small (~1,300-1,400 rows for the whole
 * Jan-Aug 2026 window on this DB, confirmed before building), unlike the customer-demographics
 * reports that had to move aggregation into SQL because their underlying table was ~35,800 rows.
 */
final class IncomeDelinquencyRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @var array<string, array> */
    private array $rawRowsCache = [];

    private function rawIncomeDelinquencyRows(DateTimeImmutable $from, DateTimeImmutable $to, string $groupExpr, string $extraJoin = ''): array
    {
        $cacheKey = $from->format('Y-m-d H:i:s') . '|' . $to->format('Y-m-d H:i:s') . '|' . $groupExpr . '|' . $extraJoin;
        if (isset($this->rawRowsCache[$cacheKey])) {
            return $this->rawRowsCache[$cacheKey];
        }

        $params = [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ];
        $stmt = $this->pdo->prepare(<<<SQL
            SELECT
                DATE_FORMAT(i.Close_Date, '%Y-%m') AS period,
                {$groupExpr} AS raw_key,
                i.Instalment_ID AS instalment_id,
                i.Close_Type AS close_type,
                i.Full_Cost AS full_cost,
                i.Debt AS debt,
                ip.Final_Price AS final_price,
                ip.Start_Price AS start_price
            FROM instalment_products ip
            JOIN instalments i ON i.Instalment_ID = ip.Instalment_ID
            JOIN products p ON p.Product_ID = ip.Product_ID
            LEFT JOIN product_category pc ON pc.Category_ID = p.Category_ID
            {$extraJoin}
            WHERE i.Close_Type IN (1, 2)
              AND i.Close_Date >= :from AND i.Close_Date < :to
            SQL);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->rawRowsCache[$cacheKey] = $rows;
        return $rows;
    }

    /**
     * Turns raw per-line rows into the bucketed income+delinquency report shape, one row per
     * $classify() bucket, with the same periods/Q1/Q2/Total structure as
     * FunnelRepository::buildBucketedReport(). $classify maps a raw_key to a display bucket name
     * (or null → $fallbackBucket, sorted last).
     */
    private function buildReport(array $rawRows, callable $classify, string $fallbackBucket): array
    {
        // Per-loan total Final_Price, needed to proportionally allocate that loan's remaining
        // Debt (written-off case) across its product lines.
        $loanTotalFinalPrice = [];
        foreach ($rawRows as $row) {
            $loanTotalFinalPrice[$row['instalment_id']] = ($loanTotalFinalPrice[$row['instalment_id']] ?? 0.0) + (float) $row['final_price'];
        }

        $emptyCell = ['revenue' => 0.0, 'cogs' => 0.0, 'qty' => 0, 'paidQty' => 0, 'paidAmt' => 0.0, 'writtenQty' => 0, 'writtenAmt' => 0.0];
        $periodsSeen = [];
        $byBucket = [];

        foreach ($rawRows as $row) {
            $period = $row['period'];
            $periodsSeen[$period] = true;

            $bucket = $classify($row['raw_key']) ?? $fallbackBucket;
            $byBucket[$bucket][$period] ??= $emptyCell;
            $cell = &$byBucket[$bucket][$period];

            $fullCost = (float) $row['full_cost'];
            $debt = (float) $row['debt'];
            $finalPrice = (float) $row['final_price'];
            $collectionRatio = $fullCost > 0 ? ($fullCost - $debt) / $fullCost : 0.0;

            $cell['revenue'] += $finalPrice * $collectionRatio;
            $cell['cogs'] += (float) $row['start_price'];
            $cell['qty']++;

            if ((int) $row['close_type'] === 1) {
                $cell['paidQty']++;
                $cell['paidAmt'] += $finalPrice;
            } else {
                $cell['writtenQty']++;
                $loanTotal = $loanTotalFinalPrice[$row['instalment_id']] ?? 0.0;
                $lineShare = $loanTotal > 0 ? $finalPrice / $loanTotal : 0.0;
                $cell['writtenAmt'] += $debt * $lineShare;
            }
            unset($cell);
        }

        $periods = array_keys($periodsSeen);
        sort($periods);
        $q1Periods = array_values(array_filter($periods, static fn ($p) => in_array(substr($p, 5, 2), ['01', '02', '03'], true)));
        $q2Periods = array_values(array_filter($periods, static fn ($p) => in_array(substr($p, 5, 2), ['04', '05', '06'], true)));

        $sumCells = static function (array $cells) use ($emptyCell): array {
            $out = $emptyCell;
            foreach ($cells as $c) {
                foreach ($out as $k => $v) {
                    $out[$k] += $c[$k];
                }
            }
            return $out;
        };
        $finalize = static function (array $cell): array {
            $cell['margin'] = $cell['revenue'] - $cell['cogs'];
            $cell['marginPct'] = $cell['revenue'] > 0 ? round(($cell['revenue'] - $cell['cogs']) / $cell['revenue'], 4) : null;
            $closedQty = $cell['paidQty'] + $cell['writtenQty'];
            $cell['writeoffRateQty'] = $closedQty > 0 ? round($cell['writtenQty'] / $closedQty, 4) : 0.0;
            $closedAmt = $cell['paidAmt'] + $cell['writtenAmt'];
            $cell['writeoffRateAmt'] = $closedAmt > 0 ? round($cell['writtenAmt'] / $closedAmt, 4) : 0.0;
            $cell['revenue'] = round($cell['revenue'], 2);
            $cell['cogs'] = round($cell['cogs'], 2);
            $cell['paidAmt'] = round($cell['paidAmt'], 2);
            $cell['writtenAmt'] = round($cell['writtenAmt'], 2);
            return $cell;
        };

        $rows = [];
        $grandTotal = $emptyCell;
        $grandQ1 = $emptyCell;
        $grandQ2 = $emptyCell;

        foreach ($byBucket as $bucket => $byPeriod) {
            foreach ($periods as $period) {
                $byPeriod[$period] ??= $emptyCell;
            }
            $q1 = $sumCells(array_map(static fn ($p) => $byPeriod[$p], $q1Periods));
            $q2 = $sumCells(array_map(static fn ($p) => $byPeriod[$p], $q2Periods));
            $total = $sumCells($byPeriod);

            foreach ($periods as $period) {
                $byPeriod[$period] = $finalize($byPeriod[$period]);
            }

            $rows[] = [
                'bucket' => $bucket,
                'byPeriod' => $byPeriod,
                'q1' => $finalize($q1),
                'q2' => $finalize($q2),
                'total' => $finalize($total),
            ];

            foreach ($grandTotal as $k => $v) {
                $grandTotal[$k] += $total[$k];
                $grandQ1[$k] += $q1[$k];
                $grandQ2[$k] += $q2[$k];
            }
        }

        usort($rows, static function ($a, $b) use ($fallbackBucket) {
            if ($a['bucket'] === $fallbackBucket) return 1;
            if ($b['bucket'] === $fallbackBucket) return -1;
            return $b['total']['revenue'] <=> $a['total']['revenue'];
        });

        foreach ($rows as &$r) {
            $r['total']['share'] = $grandTotal['revenue'] > 0 ? round($r['total']['revenue'] / $grandTotal['revenue'], 4) : 0.0;
        }
        unset($r);

        return [
            'periods' => $periods,
            'q1Periods' => $q1Periods,
            'q2Periods' => $q2Periods,
            'rows' => $rows,
            'grandTotal' => $finalize($grandTotal),
            'grandQ1' => $finalize($grandQ1),
            'grandQ2' => $finalize($grandQ2),
        ];
    }

    public function categoryReport(DateTimeImmutable $from, DateTimeImmutable $to, ProductClassifier $classifier): array
    {
        $rawRows = $this->rawIncomeDelinquencyRows($from, $to, 'pc.Category_Name');
        return $this->buildReport($rawRows, fn (?string $raw) => $classifier->classifyCategory($raw), 'Uncategorized');
    }

    public function subcategoryReport(DateTimeImmutable $from, DateTimeImmutable $to, ProductClassifier $classifier): array
    {
        $rawRows = $this->rawIncomeDelinquencyRows($from, $to, 'pc.Category_Name');
        return $this->buildReport($rawRows, fn (?string $raw) => $classifier->classifySubcategory($raw), 'Uncategorized');
    }

    public function productReport(DateTimeImmutable $from, DateTimeImmutable $to, ProductClassifier $classifier): array
    {
        $rawRows = $this->rawIncomeDelinquencyRows($from, $to, 'pc.Category_Name');
        return $this->buildReport($rawRows, fn (?string $raw) => $classifier->classify($raw), 'Uncategorized');
    }

    public function brandReport(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rawRows = $this->rawIncomeDelinquencyRows($from, $to, 'pb.Brand_Name', 'LEFT JOIN product_brands pb ON pb.Brand_ID = p.Brand_ID');
        $noBrandValues = ['none', 'n/a', 'ბრენდის გარეშე', ''];
        $classify = function (?string $raw) use ($noBrandValues): ?string {
            $name = trim((string) $raw);
            if ($name === '' || in_array(strtolower($name), $noBrandValues, true)) {
                return null;
            }
            return $name;
        };
        return $this->buildReport($rawRows, $classify, 'No Brand');
    }
}
