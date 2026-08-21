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
}
