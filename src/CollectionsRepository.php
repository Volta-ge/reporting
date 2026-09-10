<?php

declare(strict_types=1);

namespace Volta\Funnel;

use PDO;

/**
 * Collection tab — a partial port of the separate "Volta Collections & Portfolio" system at
 * collections.volta.ge (its own login-gated app, not part of this project). Added 2026-08-31
 * after the user shared screenshots of its 6 sub-tabs (Overview, Portfolio Trend, Delinquency
 * Buckets, Financials, Vintage, Worklist) and asked for the same tabs here, fed from this
 * project's own myvolta.info connection instead.
 *
 * **Only 3 of the 6 sub-tabs are built** — Overview (KPIs only), Delinquency Buckets, and
 * Financials. All three are point-in-time / current-state, computed directly from
 * `instalments.Days_Age` (already the project's established DPD field — confirmed against
 * `Next_Payment_Date` to mean "days since the loan's next-due installment," i.e. exactly the DPD
 * the source site buckets on) plus `Debt`/`Penalty`/`Full_Cost`.
 *
 * **Deliberately NOT built**: Portfolio Trend (397-day daily history), Vintage (cohort triangle
 * by age), and Delinquency Buckets' monthly-collection-yield-by-bucket table. All three need to
 * know what was due/outstanding as of *past* dates, and `instalment_shedules` — the only table
 * with per-installment due dates — turns out not to reliably retain history: comparing two real
 * loans, one still-active partially-paid loan had its already-paid schedule rows deleted (only 1
 * of what must have been many rows remained), while a fully-closed loan still had all 10 of its
 * original rows. That inconsistency means the table can't be trusted as a complete historical
 * record without loan-by-loan verification, which isn't feasible at portfolio scale — so building
 * those 3 items now would risk shipping silently wrong numbers in a collections tool, which is
 * worse than not having them. User agreed explicitly (2026-08-31) to ship the 3 reliable tabs now
 * and revisit the historical ones separately, once/if a trustworthy historical source is found
 * (candidates not yet explored: the `payments` table's Debt_Old/Debt_New chain reconstructs
 * *balance* history reliably since every row is a real event, but not DPD-bucket history, which
 * needs due-dates as well as balances).
 *
 * **Two different bucket schemes, matching the source site exactly, both derived from the same
 * `Days_Age` field**:
 * - Customer-level (Overview KPIs, Delinquency Buckets tab): Due today / 1-3 / 4-10 / 11-30 /
 *   31-60 / 61-90 / 90+. A customer can hold several active loans; they're placed in the bucket
 *   of their WORST (highest Days_Age) loan, and their *entire* balance across all active loans is
 *   attributed to that one bucket — matching the source site's own stated rule ("a customer sits
 *   in exactly one bucket... and their whole outstanding debt sits with them"). "Due today"
 *   (Days_Age = 0) is shown but excluded from "% of overdue" and from the Overview
 *   overdue-balance/overdue-customers KPIs — confirmed by reconciling the source site's own
 *   numbers: its bucket balances summed to exactly its Overview "Overdue Balance" KPI plus the
 *   "Due today" bucket's balance, nothing else.
 * - Loan-level (Financials' "CFO reconciliation view"): 0 current / 1-4 / 5-29 / 30-59 / 60-89 /
 *   90+ — coarser, different boundaries, one row per loan (not per customer). The source site
 *   states explicitly the two schemes "agree on totals only, never bucket by bucket" — same here.
 *
 * **Real data-quality bug found and worked around while building this**: `payments.Amount` has
 * exactly one sentinel row in the whole ~103k-row table, Amount = 99999999.99 (Payment_ID
 * 105016, dated 2026-08-31) — clearly a placeholder/error, not a real payment. It's excluded from
 * every aggregate here (`Amount < 100000`). Found by a "collected today" total coming out at 100
 * million GEL and tracing the single outlier row, not by assuming the data was clean.
 */
final class CollectionsRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    private const CUSTOMER_BUCKET_CASE = <<<'SQL'
        CASE
            WHEN maxage = 0 THEN 'Due today'
            WHEN maxage BETWEEN 1 AND 3 THEN 'DPD 1-3'
            WHEN maxage BETWEEN 4 AND 10 THEN 'DPD 4-10'
            WHEN maxage BETWEEN 11 AND 30 THEN 'DPD 11-30'
            WHEN maxage BETWEEN 31 AND 60 THEN 'DPD 31-60'
            WHEN maxage BETWEEN 61 AND 90 THEN 'DPD 61-90'
            ELSE 'DPD 90+'
        END
        SQL;

    /** @var array<string, array{stage: string, order: int}> */
    private const CUSTOMER_BUCKET_META = [
        'Due today' => ['stage' => 'Pre-collection', 'order' => 0],
        'DPD 1-3' => ['stage' => 'Processing window', 'order' => 1],
        'DPD 4-10' => ['stage' => 'Early warning', 'order' => 2],
        'DPD 11-30' => ['stage' => 'Active collection', 'order' => 3],
        'DPD 31-60' => ['stage' => 'Hard collection', 'order' => 4],
        'DPD 61-90' => ['stage' => 'Pre-legal', 'order' => 5],
        'DPD 90+' => ['stage' => 'Legal / recovery', 'order' => 6],
    ];

    /**
     * @return array{openLoanBook: float, loanCount: int, customerCount: int, penalties: float, totalOpenDebt: float, overdueBalance: float, overdueCustomers: int, collectedToday: float}
     */
    public function overviewKpis(): array
    {
        $book = $this->pdo->query(<<<'SQL'
            SELECT COUNT(*) AS loans, COUNT(DISTINCT Customer_ID) AS customers,
                   SUM(Debt) AS open_book, SUM(Penalty) AS penalties
            FROM instalments WHERE Active = 1 AND Product_ID > 1
            SQL)->fetch(PDO::FETCH_ASSOC);

        $overdue = $this->pdo->query(<<<'SQL'
            SELECT SUM(Debt + Penalty) AS overdue_balance, COUNT(DISTINCT Customer_ID) AS overdue_customers
            FROM instalments WHERE Active = 1 AND Product_ID > 1 AND Days_Age >= 1
            SQL)->fetch(PDO::FETCH_ASSOC);

        $collectedToday = $this->pdo->query(<<<'SQL'
            SELECT SUM(p.Amount) AS total
            FROM payments p
            JOIN instalments i ON i.Instalment_ID = p.Instalment_ID
            WHERE p.Payment_Date = CURDATE() AND i.Product_ID > 1 AND p.Amount < 100000
            SQL)->fetchColumn();

        $openBook = (float) $book['open_book'];
        $penalties = (float) $book['penalties'];

        return [
            'openLoanBook' => round($openBook, 2),
            'loanCount' => (int) $book['loans'],
            'customerCount' => (int) $book['customers'],
            'penalties' => round($penalties, 2),
            'totalOpenDebt' => round($openBook + $penalties, 2),
            'overdueBalance' => round((float) $overdue['overdue_balance'], 2),
            'overdueCustomers' => (int) $overdue['overdue_customers'],
            'collectedToday' => round((float) $collectedToday, 2),
        ];
    }

    /**
     * @return array{rows: array<int, array{bucket: string, stage: string, customers: int, balance: float, pctOfOverdue: ?float}>, chart: array<int, array{bucket: string, balance: float}>}
     */
    public function delinquencyBucketsDistribution(): array
    {
        $sql = 'SELECT ' . self::CUSTOMER_BUCKET_CASE . ' AS bucket, COUNT(*) AS customers, SUM(bal) AS balance
            FROM (
                SELECT Customer_ID, MAX(Days_Age) AS maxage, SUM(Debt + Penalty) AS bal
                FROM instalments
                WHERE Active = 1 AND Product_ID > 1 AND Days_Age >= 0
                GROUP BY Customer_ID
            ) t
            GROUP BY bucket';
        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $byBucket = [];
        foreach ($rows as $r) {
            $byBucket[$r['bucket']] = ['customers' => (int) $r['customers'], 'balance' => round((float) $r['balance'], 2)];
        }

        // "% of overdue" excludes Due today from both the numerator and denominator — it isn't
        // actually delinquent yet, same convention as the Overview KPIs above.
        $overdueTotal = 0.0;
        foreach (self::CUSTOMER_BUCKET_META as $bucket => $meta) {
            if ($bucket === 'Due today') {
                continue;
            }
            $overdueTotal += $byBucket[$bucket]['balance'] ?? 0.0;
        }

        $result = [];
        foreach (self::CUSTOMER_BUCKET_META as $bucket => $meta) {
            $customers = $byBucket[$bucket]['customers'] ?? 0;
            $balance = $byBucket[$bucket]['balance'] ?? 0.0;
            $result[] = [
                'bucket' => $bucket,
                'stage' => $meta['stage'],
                'customers' => $customers,
                'balance' => $balance,
                'pctOfOverdue' => $bucket === 'Due today' || $overdueTotal <= 0
                    ? null
                    : round($balance / $overdueTotal, 4),
            ];
        }

        return [
            'rows' => $result,
            'chart' => array_map(static fn ($r) => ['bucket' => $r['bucket'], 'balance' => $r['balance']], $result),
        ];
    }

    /**
     * @return array{kpis: array{originalDebt: float, repaidToDate: float, repaidShare: float, openLoanBook: float, penaltiesAccrued: float, totalOpenDebt: float, activeLoans: int}, collectionsByMonth: array<int, array{month: string, principal: float, penalty: float}>, loanLevelBuckets: array<int, array{bucket: string, loans: int, pctLoans: float, principal: float, penalties: float, totalDebt: float, pctOfDebt: float}>}
     */
    public function financials(): array
    {
        $book = $this->pdo->query(<<<'SQL'
            SELECT COUNT(*) AS loans, SUM(Full_Cost) AS orig_debt, SUM(Full_Cost - Debt) AS repaid,
                   SUM(Debt) AS open_book, SUM(Penalty) AS penalties
            FROM instalments WHERE Active = 1 AND Product_ID > 1
            SQL)->fetch(PDO::FETCH_ASSOC);

        $origDebt = (float) $book['orig_debt'];
        $repaid = (float) $book['repaid'];
        $openBook = (float) $book['open_book'];
        $penalties = (float) $book['penalties'];

        // Last 13 calendar months (matches the source site's window), principal/penalty split.
        // Company-wide cash collected — not scoped to currently-active loans, since a loan can be
        // fully paid off and closed within the window and its cash still counts. The one
        // Amount=99999999.99 sentinel row (see class docblock) is excluded.
        $monthly = $this->pdo->query(<<<'SQL'
            SELECT DATE_FORMAT(p.Payment_Date, '%Y-%m') AS ym,
                   SUM(p.pDebet) AS principal, SUM(p.pPenalty) AS penalty
            FROM payments p
            JOIN instalments i ON i.Instalment_ID = p.Instalment_ID
            WHERE i.Product_ID > 1 AND p.Amount < 100000
              AND p.Payment_Date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 12 MONTH), '%Y-%m-01')
            GROUP BY ym
            ORDER BY ym
            SQL)->fetchAll(PDO::FETCH_ASSOC);
        $collectionsByMonth = array_map(static fn ($r) => [
            'month' => $r['ym'],
            'principal' => round((float) $r['principal'], 2),
            'penalty' => round((float) $r['penalty'], 2),
        ], $monthly);

        $bucketSql = <<<'SQL'
            SELECT
                CASE
                    WHEN Days_Age <= 0 THEN '0 current'
                    WHEN Days_Age BETWEEN 1 AND 4 THEN '1-4'
                    WHEN Days_Age BETWEEN 5 AND 29 THEN '5-29'
                    WHEN Days_Age BETWEEN 30 AND 59 THEN '30-59'
                    WHEN Days_Age BETWEEN 60 AND 89 THEN '60-89'
                    ELSE '90+'
                END AS bucket,
                COUNT(*) AS loans, SUM(Debt) AS principal, SUM(Penalty) AS penalties, SUM(Debt + Penalty) AS total_debt
            FROM instalments WHERE Active = 1 AND Product_ID > 1
            GROUP BY bucket
            SQL;
        $bucketRows = $this->pdo->query($bucketSql)->fetchAll(PDO::FETCH_ASSOC);

        $order = ['0 current' => 0, '1-4' => 1, '5-29' => 2, '30-59' => 3, '60-89' => 4, '90+' => 5];
        usort($bucketRows, static fn ($a, $b) => $order[$a['bucket']] <=> $order[$b['bucket']]);

        $totalLoans = (int) $book['loans'];
        $totalDebtAll = $openBook + $penalties;
        $loanLevelBuckets = [];
        foreach ($bucketRows as $r) {
            $loanLevelBuckets[] = [
                'bucket' => $r['bucket'],
                'loans' => (int) $r['loans'],
                'pctLoans' => $totalLoans > 0 ? round(((int) $r['loans']) / $totalLoans, 4) : 0.0,
                'principal' => round((float) $r['principal'], 2),
                'penalties' => round((float) $r['penalties'], 2),
                'totalDebt' => round((float) $r['total_debt'], 2),
                'pctOfDebt' => $totalDebtAll > 0 ? round(((float) $r['total_debt']) / $totalDebtAll, 4) : 0.0,
            ];
        }

        return [
            'kpis' => [
                'originalDebt' => round($origDebt, 2),
                'repaidToDate' => round($repaid, 2),
                'repaidShare' => $origDebt > 0 ? round($repaid / $origDebt, 4) : 0.0,
                'openLoanBook' => round($openBook, 2),
                'penaltiesAccrued' => round($penalties, 2),
                'totalOpenDebt' => round($openBook + $penalties, 2),
                'activeLoans' => $totalLoans,
            ],
            'collectionsByMonth' => $collectionsByMonth,
            'loanLevelBuckets' => $loanLevelBuckets,
        ];
    }
}
