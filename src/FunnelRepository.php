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

        // Amount Sold: financed principal for active/disbursed deals, keyed to Order_Date.
        $stmtAmount = $this->pdo->prepare(<<<SQL
            SELECT SUM(i.Initial_Amount) AS amount_sold
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
}
