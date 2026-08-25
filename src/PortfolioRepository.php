<?php

declare(strict_types=1);

namespace Volta\Funnel;

use DateTimeImmutable;
use PDO;

/**
 * Loan-portfolio analysis — a different domain from FunnelRepository (which tracks the
 * sales/application funnel). This repository looks at the current active book and its
 * history: who the customers are, how risk is distributed, which loans have closed and how,
 * and how much of the active book is overdue.
 *
 * Data-quality findings that shaped these queries (checked against real data before writing
 * any of this, 2026-08-25):
 *  - `instalments.OverDay` is 0 for every single active loan — not maintained, not usable.
 *    `Days_Age` is the real, populated aging field and is what delinquency() uses instead.
 *  - `Close_Type`: 0 = not a real closure (covers both currently-active loans AND the much
 *    larger set of rejected/never-activated applications — NOT itself a closure reason);
 *    1 = paid off (confirmed: avg remaining Debt ≈ 0 for this group); 2 = written off
 *    (confirmed: avg remaining Debt ≈ 735 GEL despite Active = 0, i.e. closed with money still
 *    owed). Only Close_Type IN (1, 2) represents a loan that was genuinely active and then
 *    closed — everything else is excluded from closedLoans().
 *  - `Close_Date` IS reliably populated (100%) for rows with Close_Type IN (1, 2) — an earlier
 *    session's note calling it "always NULL" was about something else / a different check;
 *    re-verified directly here, so month-by-month closure trends are safe to build.
 *  - `Risk_Status` has three real tiers (დაბალი/საშუალო/მაღალი) plus a small '0' placeholder
 *    bucket (not yet risk-scored).
 */
final class PortfolioRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Customer Analysis tab: aggregate customer-level stats, scoped to customers who have at
     * least one real application (Product_ID > 1) — never exposes individual customer PII,
     * only counts/segments. "New" = exactly one loan ever; "Repeat" = 2+.
     */
    public function customerAnalysis(): array
    {
        $totalCustomers = (int) $this->pdo->query(
            'SELECT COUNT(DISTINCT Customer_ID) AS n FROM instalments WHERE Product_ID > 1'
        )->fetch()['n'];

        $activeCustomers = (int) $this->pdo->query(
            'SELECT COUNT(DISTINCT Customer_ID) AS n FROM instalments WHERE Active = 1'
        )->fetch()['n'];

        $loansPerCustomer = $this->pdo->query(<<<SQL
            SELECT loans, COUNT(*) AS n_customers FROM (
                SELECT Customer_ID, COUNT(*) AS loans
                FROM instalments WHERE Product_ID > 1
                GROUP BY Customer_ID
            ) t
            GROUP BY loans ORDER BY loans
            SQL)->fetchAll();

        $newCustomers = 0;
        $repeatCustomers = 0;
        $distribution = []; // '1','2','3','4','5+'
        foreach ($loansPerCustomer as $row) {
            $loans = (int) $row['loans'];
            $n = (int) $row['n_customers'];
            if ($loans === 1) {
                $newCustomers += $n;
            } else {
                $repeatCustomers += $n;
            }
            $key = $loans >= 5 ? '5+' : (string) $loans;
            $distribution[$key] = ($distribution[$key] ?? 0) + $n;
        }
        // stable order
        $orderedDistribution = [];
        foreach (['1', '2', '3', '4', '5+'] as $key) {
            $orderedDistribution[$key] = $distribution[$key] ?? 0;
        }

        $byCity = $this->pdo->query(<<<SQL
            SELECT c.City AS label, COUNT(DISTINCT c.Customer_ID) AS n
            FROM customers c
            JOIN instalments i ON i.Customer_ID = c.Customer_ID AND i.Product_ID > 1
            GROUP BY c.City ORDER BY n DESC
            SQL)->fetchAll(PDO::FETCH_ASSOC);

        $byGender = $this->pdo->query(<<<SQL
            SELECT
                CASE WHEN c.Gender IN ('მდედრ.', 'მამრ.') THEN c.Gender ELSE 'N/A' END AS label,
                COUNT(DISTINCT c.Customer_ID) AS n
            FROM customers c
            JOIN instalments i ON i.Customer_ID = c.Customer_ID AND i.Product_ID > 1
            GROUP BY label ORDER BY n DESC
            SQL)->fetchAll(PDO::FETCH_ASSOC);

        return [
            'totalCustomers' => $totalCustomers,
            'activeCustomers' => $activeCustomers,
            'newCustomers' => $newCustomers,
            'repeatCustomers' => $repeatCustomers,
            'loansPerCustomer' => $orderedDistribution,
            'byCity' => array_map(fn ($r) => ['label' => (string) $r['label'], 'n' => (int) $r['n']], $byCity),
            'byGender' => array_map(fn ($r) => ['label' => (string) $r['label'], 'n' => (int) $r['n']], $byGender),
        ];
    }

    /**
     * Risk Segmentation tab: current active portfolio (Active = 1) broken down by Risk_Status.
     */
    public function riskSegmentation(): array
    {
        $rows = $this->pdo->query(<<<SQL
            SELECT
                CASE WHEN Risk_Status IN ('დაბალი', 'საშუალო', 'მაღალი') THEN Risk_Status ELSE 'Not Scored' END AS label,
                COUNT(*) AS n,
                SUM(Debt) AS debt,
                SUM(Penalty) AS penalty
            FROM instalments
            WHERE Active = 1
            GROUP BY label
            SQL)->fetchAll(PDO::FETCH_ASSOC);

        $totalN = 0;
        $totalDebt = 0.0;
        foreach ($rows as $r) {
            $totalN += (int) $r['n'];
            $totalDebt += (float) $r['debt'];
        }

        $result = [];
        foreach ($rows as $r) {
            $result[] = [
                'label' => $r['label'],
                'n' => (int) $r['n'],
                'debt' => round((float) $r['debt'], 2),
                'penalty' => round((float) $r['penalty'], 2),
                'share' => $totalN > 0 ? round((int) $r['n'] / $totalN, 4) : 0.0,
            ];
        }
        // Sort: დაბალი, საშუალო, მაღალი, Not Scored — fixed risk-ascending order, not by size.
        $order = ['დაბალი' => 0, 'საშუალო' => 1, 'მაღალი' => 2, 'Not Scored' => 3];
        usort($result, fn ($a, $b) => ($order[$a['label']] ?? 9) <=> ($order[$b['label']] ?? 9));

        return [
            'rows' => $result,
            'total' => ['n' => $totalN, 'debt' => round($totalDebt, 2)],
        ];
    }

    /**
     * Closed Loans Analysis tab: month-by-month trend of loans that actually closed
     * (Close_Type 1 = paid off, 2 = written off), keyed to Close_Date. Only loans that were
     * genuinely active and then closed — see class docblock for why Close_Type = 0 is excluded.
     */
    public function closedLoansMonthly(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = $this->pdo->prepare(<<<SQL
            SELECT
                DATE_FORMAT(Close_Date, '%Y-%m') AS period,
                SUM(CASE WHEN Close_Type = 1 THEN 1 ELSE 0 END) AS paid_off_n,
                SUM(CASE WHEN Close_Type = 1 THEN Full_Cost ELSE 0 END) AS paid_off_amount,
                SUM(CASE WHEN Close_Type = 2 THEN 1 ELSE 0 END) AS written_off_n,
                SUM(CASE WHEN Close_Type = 2 THEN Debt ELSE 0 END) AS written_off_debt
            FROM instalments
            WHERE Close_Type IN (1, 2)
              AND Close_Date >= :from AND Close_Date < :to
            GROUP BY period
            ORDER BY period
            SQL);
        $rows->execute(['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')]);
        $periods = $rows->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        $totalPaidOffN = 0;
        $totalPaidOffAmount = 0.0;
        $totalWrittenOffN = 0;
        $totalWrittenOffDebt = 0.0;
        foreach ($periods as $r) {
            $result[$r['period']] = [
                'paidOffN' => (int) $r['paid_off_n'],
                'paidOffAmount' => round((float) $r['paid_off_amount'], 2),
                'writtenOffN' => (int) $r['written_off_n'],
                'writtenOffDebt' => round((float) $r['written_off_debt'], 2),
            ];
            $totalPaidOffN += (int) $r['paid_off_n'];
            $totalPaidOffAmount += (float) $r['paid_off_amount'];
            $totalWrittenOffN += (int) $r['written_off_n'];
            $totalWrittenOffDebt += (float) $r['written_off_debt'];
        }

        return [
            'periods' => $result,
            'total' => [
                'paidOffN' => $totalPaidOffN,
                'paidOffAmount' => round($totalPaidOffAmount, 2),
                'writtenOffN' => $totalWrittenOffN,
                'writtenOffDebt' => round($totalWrittenOffDebt, 2),
            ],
        ];
    }

    /**
     * Overdue / Delinquency Analysis (Portfolio at Risk) tab: current active portfolio broken
     * down by Days_Age bucket. Point-in-time snapshot, same as Logistics Daily's Not-Delivered
     * table — can't be reconstructed for a past date, only captured going forward.
     */
    public function delinquencyAnalysis(): array
    {
        $rows = $this->pdo->query(<<<SQL
            SELECT
                CASE
                    WHEN Days_Age IS NULL OR Days_Age <= 0 THEN 'Current'
                    WHEN Days_Age BETWEEN 1 AND 30 THEN '1-30'
                    WHEN Days_Age BETWEEN 31 AND 60 THEN '31-60'
                    WHEN Days_Age BETWEEN 61 AND 90 THEN '61-90'
                    ELSE '90+'
                END AS bucket,
                COUNT(*) AS n,
                SUM(Debt) AS debt,
                SUM(Penalty) AS penalty
            FROM instalments
            WHERE Active = 1
            GROUP BY bucket
            SQL)->fetchAll(PDO::FETCH_ASSOC);

        $byBucket = [];
        $totalN = 0;
        $totalDebt = 0.0;
        foreach ($rows as $r) {
            $byBucket[$r['bucket']] = [
                'n' => (int) $r['n'],
                'debt' => round((float) $r['debt'], 2),
                'penalty' => round((float) $r['penalty'], 2),
            ];
            $totalN += (int) $r['n'];
            $totalDebt += (float) $r['debt'];
        }

        $order = ['Current', '1-30', '31-60', '61-90', '90+'];
        $ordered = [];
        foreach ($order as $bucket) {
            $ordered[$bucket] = $byBucket[$bucket] ?? ['n' => 0, 'debt' => 0.0, 'penalty' => 0.0];
        }

        // PAR (Portfolio at Risk) 30/60/90 = share of total outstanding debt that is at least
        // that many days overdue — the standard lending-industry delinquency metric.
        $par = fn (array $buckets) => $totalDebt > 0
            ? round(array_sum(array_map(fn ($b) => $ordered[$b]['debt'], $buckets)) / $totalDebt, 4)
            : 0.0;

        return [
            'buckets' => $ordered,
            'total' => ['n' => $totalN, 'debt' => round($totalDebt, 2)],
            'par30' => $par(['31-60', '61-90', '90+']),
            'par60' => $par(['61-90', '90+']),
            'par90' => $par(['90+']),
        ];
    }
}
