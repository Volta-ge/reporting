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
 *  - Rejection/decision reason lives on `instalments.Reason` — no separate "committee" table
 *    exists. The admin panel's own Status filter (Approved/Rejected/"Client Refused"/Expired/
 *    Not Responded) does NOT map to `order_statuses`' own ID numbering the way its labels would
 *    suggest — confirmed the hard way: `order_statuses` labels `Order_Status = 16` as
 *    "დამტკიცებულია" (Approved), but that code has ~0 rows at any moment (a fleeting transitional
 *    state) while the panel's real "Approved" filter, direct-checked against 3 Instalment_IDs
 *    from a live screenshot, is actually `Order_Status = 5` (labelled "ინვოისის გაგზავნა" in the
 *    lookup table) — the SAME status already used everywhere else in this project as the
 *    "real sale" filter (see [[volta-sales-monthly]]). So in this business's workflow, "Approved"
 *    effectively means the application became a real, invoiced sale. `Order_Status = 5` rows have
 *    Reason populated on essentially none of them (11,360 of 11,361 blank) — no reason is needed
 *    to explain a successful approval, unlike the other four outcomes. `6/12/13/14` were verified
 *    against real Instalment_IDs too (Rejected: 163758/163764) and their `order_statuses` labels
 *    do line up with real data for those — but given the Approved mismatch, treat every one of
 *    these Order_Status mappings as "verified against real IDs," never "trusted from the lookup
 *    table's label text alone."
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
     * Age (from BirthDay) x Gender breakdown, same customer scope as customerAnalysis().
     * Buckets match the layout the business asked for: "<18" (labelled ">18" on their own
     * template — read in context as "under 18" since it's followed by "18-25", not "over 18"),
     * 18-25, 25-35, 35-45, 45-60, "60+". Customers with a missing/blank BirthDay get their own
     * "Unspecified" row rather than being dropped or guessed into a bucket. Share % is computed
     * within each gender column (that bucket's count ÷ that gender's total), not against the
     * grand total — answers "how is each gender's age distributed", which is what a per-gender
     * breakdown is for.
     *
     * This and the four reports below (Workshop/Workpos/Comment/FactAddress) originally fetched
     * every scoped customer row (~35,800) into PHP for regex/substring processing. On this DB's
     * connection that took ~26s for the shared fetch ALONE (confirmed by timing each query
     * variant — a derived-table join, a direct join, and an EXISTS subquery all took the same
     * ~30s, and even a bare `SELECT * FROM customers` with no join took 42s for 44k rows — so
     * it's row-transfer-bound on this connection, not a query-plan problem) — with five reports
     * doing this per page load, real production hit PHP's 30s `max_execution_time` and fataled
     * (caught by the user's own next "refresh Daily Report" request, not by this session's own
     * verification, which happened to run on a faster connection when first built). Rewritten to
     * aggregate server-side (`GROUP BY`/`COUNT(DISTINCT Customer_ID)`) like every *other* method
     * in this class already does, so only a handful of summary rows cross the network — same
     * fix category as `FunnelRepository`'s `cogsGarbageStats()` memoization, but here the fix is
     * "don't transfer 35,800 rows to do what SQL can aggregate", not just "don't ask twice".
     * MySQL 5.7 has no `REGEXP_SUBSTR` (that's 8.0+), so `customerIncomeAnalysis()` below is a
     * hybrid: buckets pure-numeric Comments in SQL, and only pulls the free-text minority
     * (~14% of rows) to PHP for first-number regex extraction.
     */
    public function customerAgeGenderAnalysis(): array
    {
        $rows = $this->pdo->query(<<<SQL
            SELECT
                CASE
                    WHEN c.BirthDay IS NULL OR c.BirthDay = '0000-00-00' THEN 'Unspecified'
                    WHEN TIMESTAMPDIFF(YEAR, c.BirthDay, CURDATE()) < 18 THEN '<18'
                    WHEN TIMESTAMPDIFF(YEAR, c.BirthDay, CURDATE()) < 25 THEN '18-25'
                    WHEN TIMESTAMPDIFF(YEAR, c.BirthDay, CURDATE()) < 35 THEN '25-35'
                    WHEN TIMESTAMPDIFF(YEAR, c.BirthDay, CURDATE()) < 45 THEN '35-45'
                    WHEN TIMESTAMPDIFF(YEAR, c.BirthDay, CURDATE()) < 60 THEN '45-60'
                    ELSE '60+'
                END AS bucket,
                CASE WHEN c.Gender IN ('მდედრ.', 'მამრ.') THEN c.Gender ELSE 'N/A' END AS gender,
                COUNT(DISTINCT c.Customer_ID) AS n
            FROM customers c
            JOIN instalments i ON i.Customer_ID = c.Customer_ID AND i.Product_ID > 1
            GROUP BY bucket, gender
            SQL)->fetchAll(PDO::FETCH_ASSOC);

        $buckets = ['<18', '18-25', '25-35', '35-45', '45-60', '60+', 'Unspecified'];
        $genders = ['მდედრ.', 'მამრ.', 'N/A'];
        $grid = [];
        foreach ($buckets as $b) {
            foreach ($genders as $g) {
                $grid[$b][$g] = 0;
            }
        }
        // MySQL occasionally returns more than one group for what should be a single collapsed
        // 'N/A' gender bucket (a real quirk on this server, checked directly) — sum defensively
        // by key instead of assuming one row per (bucket, gender) pair.
        foreach ($rows as $r) {
            $grid[$r['bucket']][$r['gender']] += (int) $r['n'];
        }

        $genderTotals = [];
        foreach ($genders as $g) {
            $genderTotals[$g] = array_sum(array_column($grid, $g));
        }
        $grandTotal = array_sum($genderTotals);

        $result = [];
        foreach ($buckets as $b) {
            $row = ['bucket' => $b];
            foreach ($genders as $g) {
                $n = $grid[$b][$g];
                $row[$g] = ['n' => $n, 'share' => $genderTotals[$g] > 0 ? round($n / $genderTotals[$g], 4) : 0.0];
            }
            $row['total'] = [
                'n' => array_sum($grid[$b]),
                'share' => $grandTotal > 0 ? round(array_sum($grid[$b]) / $grandTotal, 4) : 0.0,
            ];
            $result[] = $row;
        }

        return [
            'genders' => $genders,
            'genderTotals' => $genderTotals,
            'grandTotal' => $grandTotal,
            'rows' => $result,
        ];
    }

    /**
     * Shared helper for Workshop / Workpos: both are free-text-ish fields on `customers` where
     * the useful value sometimes has a leading ",", top N distinct raw values + "Other" (the
     * long tail) + "Unspecified" (blank). This is a "most common exact strings" report, not
     * semantic clustering — typo/spelling variants (e.g. "თვითდასაქმებული" vs "თვით
     * დასაქმებული") are NOT merged, since guessing which strings mean the same thing risks being
     * wrong; the footer note discloses this explicitly. All aggregated server-side (see class-
     * level note above on why) — only ever transfers ~20 summary rows plus one totals row.
     */
    private function groupedTextFieldReport(string $column, int $topN): array
    {
        $raw = "COALESCE(c.{$column}, '')";
        $expr = "CASE WHEN {$raw} LIKE ',%' THEN TRIM(SUBSTRING({$raw}, 2)) ELSE TRIM({$raw}) END";

        $top = $this->pdo->query(<<<SQL
            SELECT {$expr} AS label, COUNT(DISTINCT c.Customer_ID) AS n
            FROM customers c
            JOIN instalments i ON i.Customer_ID = c.Customer_ID AND i.Product_ID > 1
            WHERE {$expr} <> ''
            GROUP BY label
            ORDER BY n DESC
            LIMIT {$topN}
            SQL)->fetchAll(PDO::FETCH_ASSOC);

        $totals = $this->pdo->query(<<<SQL
            SELECT
                COUNT(DISTINCT c.Customer_ID) AS total,
                COUNT(DISTINCT CASE WHEN {$expr} = '' THEN c.Customer_ID END) AS blankN,
                COUNT(DISTINCT CASE WHEN {$expr} <> '' THEN {$expr} END) AS distinctValues,
                COUNT(DISTINCT CASE WHEN {$expr} <> '' THEN c.Customer_ID END) AS nonBlankCustomers
            FROM customers c
            JOIN instalments i ON i.Customer_ID = c.Customer_ID AND i.Product_ID > 1
            SQL)->fetch(PDO::FETCH_ASSOC);

        $total = (int) $totals['total'];
        $topTotal = array_sum(array_column($top, 'n'));
        $otherCount = (int) $totals['nonBlankCustomers'] - $topTotal;
        $unspecified = (int) $totals['blankN'];

        $result = [];
        foreach ($top as $r) {
            $n = (int) $r['n'];
            $result[] = ['label' => $r['label'], 'n' => $n, 'share' => $total > 0 ? round($n / $total, 4) : 0.0];
        }
        if ($otherCount > 0) {
            $result[] = ['label' => 'Other', 'n' => $otherCount, 'share' => $total > 0 ? round($otherCount / $total, 4) : 0.0];
        }
        $result[] = ['label' => 'Unspecified', 'n' => $unspecified, 'share' => $total > 0 ? round($unspecified / $total, 4) : 0.0];

        return ['rows' => $result, 'total' => $total, 'distinctValues' => (int) $totals['distinctValues']];
    }

    public function customerWorkshopAnalysis(): array
    {
        return $this->groupedTextFieldReport('Workshop', 20);
    }

    public function customerWorkposAnalysis(): array
    {
        return $this->groupedTextFieldReport('Workpos', 20);
    }

    /**
     * `customers.Comment` is not a free-text note field despite the name — it's where staff
     * record the customer's reported income/compensation (values like "1500", "1000_2000",
     * "1200 ლარი თიბისი ბანკში", "დღეში 100 ლ"), confirmed by inspecting real values before
     * building this (57.7% pure numeric, 3.6% "X_Y" range, 10.1% free text with an embedded
     * number, 28.7% blank). Bucketed by the FIRST number found in the text — no attempt to
     * normalize daily-wage phrasing ("დღეში" = "per day") to a monthly figure, since that would
     * be guessing at a conversion; the footer note discloses this as a known limitation rather
     * than silently mixing daily and monthly figures into the same buckets.
     *
     * Hybrid SQL/PHP: pure-numeric Comments (the majority) are bucketed server-side via
     * `CAST(... AS UNSIGNED)` — MySQL 5.7 on this DB has no `REGEXP_SUBSTR` to extract a number
     * from "1200 ლარი..." server-side, so only the free-text minority (~14% of rows) is pulled
     * to PHP for regex extraction, not all ~35,800.
     */
    public function customerIncomeAnalysis(): array
    {
        $buckets = [
            '0-500' => [0, 500], '500-1000' => [500, 1000], '1000-1500' => [1000, 1500],
            '1500-2000' => [1500, 2000], '2000-3000' => [2000, 3000], '3000-5000' => [3000, 5000],
            '5000+' => [5000, PHP_INT_MAX],
        ];
        $counts = array_fill_keys(array_keys($buckets), 0);

        $bucketCase = '';
        foreach ($buckets as $label => [, $hi]) {
            if ($hi === PHP_INT_MAX) {
                continue;
            }
            $bucketCase .= "WHEN CAST(c.Comment AS UNSIGNED) < {$hi} THEN '{$label}' ";
        }
        $numericRows = $this->pdo->query(<<<SQL
            SELECT
                CASE {$bucketCase} ELSE '5000+' END AS bucket,
                COUNT(DISTINCT c.Customer_ID) AS n
            FROM customers c
            JOIN instalments i ON i.Customer_ID = c.Customer_ID AND i.Product_ID > 1
            WHERE c.Comment REGEXP '^[0-9]+$'
            GROUP BY bucket
            SQL)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($numericRows as $r) {
            $counts[$r['bucket']] += (int) $r['n'];
        }

        $total = (int) $this->pdo->query(
            'SELECT COUNT(DISTINCT c.Customer_ID) AS n FROM customers c JOIN instalments i ON i.Customer_ID = c.Customer_ID AND i.Product_ID > 1'
        )->fetch()['n'];
        $unspecified = (int) $this->pdo->query(<<<SQL
            SELECT COUNT(DISTINCT c.Customer_ID) AS n
            FROM customers c
            JOIN instalments i ON i.Customer_ID = c.Customer_ID AND i.Product_ID > 1
            WHERE c.Comment IS NULL OR c.Comment = ''
            SQL)->fetch()['n'];

        // The free-text minority: non-blank, not pure-numeric — pulled to PHP for regex only.
        // Grouped (with a customer count per distinct string), not a flat row list — several
        // customers often share the exact same phrasing (e.g. "დღეში 100 ლარი"), and a flat
        // `SELECT DISTINCT Comment` would collapse them into one row and silently undercount.
        $messyRows = $this->pdo->query(<<<SQL
            SELECT c.Comment, COUNT(DISTINCT c.Customer_ID) AS n
            FROM customers c
            JOIN instalments i ON i.Customer_ID = c.Customer_ID AND i.Product_ID > 1
            WHERE c.Comment IS NOT NULL AND c.Comment <> '' AND c.Comment NOT REGEXP '^[0-9]+$'
            GROUP BY c.Comment
            SQL)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($messyRows as $r) {
            $v = trim((string) $r['Comment']);
            $n = (int) $r['n'];
            if ($v === '' || !preg_match('/(\d+)/', $v, $m)) {
                $unspecified += $n;
                continue;
            }
            $amount = (int) $m[1];
            foreach ($buckets as $label => [$lo, $hi]) {
                if ($amount >= $lo && $amount < $hi) {
                    $counts[$label] += $n;
                    break;
                }
            }
        }

        $result = [];
        foreach ($counts as $label => $n) {
            $result[] = ['label' => $label, 'n' => $n, 'share' => $total > 0 ? round($n / $total, 4) : 0.0];
        }
        $result[] = ['label' => 'Unspecified', 'n' => $unspecified, 'share' => $total > 0 ? round($unspecified / $total, 4) : 0.0];

        return ['rows' => $result, 'total' => $total];
    }

    /**
     * FactAddress district decode for Tbilisi residents. Real Tbilisi addresses in this DB
     * follow no single consistent format — sometimes "თბილისი, <district>, <street+details>"
     * (district cleanly in its own comma segment), sometimes "თბილისი, <street+details>" with
     * the district (if mentioned at all) embedded inside the street text, sometimes no district
     * mentioned at all. Approach: scan each address for a substring match against a canonical
     * list of real Tbilisi districts/microrayons — the list itself was built empirically from
     * this database's own comma-segment-2 values (not from outside knowledge), checked longest-
     * name-first so e.g. "ზემო ფონიჭალა" matches before the more generic "ფონიჭალა". Measured
     * match rate on real data: ~46-47% — the rest go into "ვერ დადგინდა" ("Could not be
     * determined") rather than guessed at. This is inherently best-effort text parsing, not a
     * real data field, and the footer note says so.
     *
     * The matching itself runs as one big SQL `CASE`/`LIKE` expression (server-side), not a PHP
     * loop over every fetched address — see class-level note above on why.
     */
    public function customerDistrictAnalysis(): array
    {
        static $districts = [
            'ზემო ფონიჭალა', 'ქვემო ფონიჭალა', 'დიდი დიღომი', 'დიღმის მასივი', 'ვარკეთილის ზემო პლატო',
            'ვარკეთილი', 'გლდანი', 'საბურთალო', 'ნაძალადევი', 'თემქა', 'ისანი', 'სანზონა', 'სამგორი',
            'მუხიანი', 'ავჭალა', 'ჩუღურეთი', 'ავლაბარი', 'ვაზისუბანი', 'ვაზისუბნის', 'მთაწმინდა', 'ვაკე',
            'ორთაჭალა', 'დიდუბე', 'ვერა', 'ვაშლიჯვარი', 'ზაჰესი', 'ლილო', 'აფრიკა', 'სოლოლაკი', 'კუკია',
            'გლდანულა', 'ბაგები', 'წყნეთი', 'ორხევი', 'ტაბახმელა', 'ოქროყანა', 'კრწანისი', 'დიღომი',
            'ნუცუბიძის', 'ლოტკინი', 'ნახალოვკა', 'მოსკოვის გამზირი',
        ];
        usort($districts, static fn ($a, $b) => strlen($b) <=> strlen($a));

        $whenClauses = '';
        foreach ($districts as $d) {
            $esc = str_replace("'", "''", $d);
            $whenClauses .= "WHEN c.FactAddress LIKE '%{$esc}%' THEN '{$esc}' ";
        }

        $rows = $this->pdo->query(<<<SQL
            SELECT
                CASE {$whenClauses} ELSE 'ვერ დადგინდა' END AS district,
                COUNT(DISTINCT c.Customer_ID) AS n
            FROM customers c
            JOIN instalments i ON i.Customer_ID = c.Customer_ID AND i.Product_ID > 1
            WHERE c.FactAddress LIKE '%თბილისი%'
            GROUP BY district
            SQL)->fetchAll(PDO::FETCH_ASSOC);

        $undetermined = 0;
        $counts = [];
        foreach ($rows as $r) {
            if ($r['district'] === 'ვერ დადგინდა') {
                $undetermined += (int) $r['n'];
            } else {
                $counts[$r['district']] = ($counts[$r['district']] ?? 0) + (int) $r['n'];
            }
        }
        arsort($counts);

        $total = $undetermined + array_sum($counts);
        $result = [];
        foreach ($counts as $label => $n) {
            $result[] = ['label' => $label, 'n' => $n, 'share' => $total > 0 ? round($n / $total, 4) : 0.0];
        }
        $result[] = ['label' => 'ვერ დადგინდა', 'n' => $undetermined, 'share' => $total > 0 ? round($undetermined / $total, 4) : 0.0];

        return ['rows' => $result, 'total' => $total];
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

    /**
     * Reason breakdown for one committee/admin-panel outcome status, month-by-month, keyed to
     * Aplication_Date (same as the rest of the funnel's application-stage metrics). Blank Reason
     * is its own "Unspecified" row rather than dropped — a real gap, not guessed at. Used for
     * Order_Status IN (5, 6, 12, 13, 14).
     *
     * Deliberately does NOT filter `Product_ID > 1`, unlike every sales-funnel query in
     * FunnelRepository — verified against the business's own committee export
     * (ექსპორტი_კომიტეტი.xlsx, 2026-08-25): 775 Rejected / 228 Client Refused / 5 Not Responding
     * rows have `Product_ID <= 1` AND a real, meaningful Reason value (e.g. "შეუსაბამო
     * მონაცემები") — these are genuine committee-reviewed applications, not blank leads. Adding
     * the `Product_ID > 1` filter here (copied from the funnel reports without re-checking)
     * silently undercounted every one of these tables until caught by the user cross-checking
     * against a raw CRM export. `Product_ID > 1` still makes sense for the sales-funnel reports,
     * where it excludes leads that never became real product interest — it just doesn't apply to
     * this committee-outcome domain, where `Product_ID` can legitimately be unset even for a
     * fully-reviewed application.
     */
    public function reasonsByStatusMonthly(DateTimeImmutable $from, DateTimeImmutable $to, int $orderStatus): array
    {
        $rows = $this->pdo->prepare(<<<SQL
            SELECT
                DATE_FORMAT(Aplication_Date, '%Y-%m') AS period,
                Reason AS reason,
                COUNT(*) AS n
            FROM instalments
            WHERE Order_Status = :orderStatus
              AND Aplication_Date >= :from AND Aplication_Date < :to
            GROUP BY period, reason
            SQL);
        $rows->execute([
            'orderStatus' => $orderStatus,
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ]);

        $periodsSeen = [];
        $byReason = [];
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $period = $r['period'];
            $periodsSeen[$period] = true;
            $reason = trim((string) $r['reason']) === '' ? 'Unspecified' : $r['reason'];
            $byReason[$reason][$period] = ($byReason[$reason][$period] ?? 0) + (int) $r['n'];
        }

        $periods = array_keys($periodsSeen);
        sort($periods);

        $result = [];
        $grandTotal = 0;
        foreach ($byReason as $reason => $byPeriod) {
            $total = 0;
            $filledByPeriod = [];
            foreach ($periods as $period) {
                $n = $byPeriod[$period] ?? 0;
                $filledByPeriod[$period] = $n;
                $total += $n;
            }
            $result[] = ['reason' => $reason, 'byPeriod' => $filledByPeriod, 'total' => $total];
            $grandTotal += $total;
        }

        // Sort by total descending — "Unspecified" always last, same convention as
        // FunnelRepository's Sales Monthly "Uncategorized" row.
        usort($result, function ($a, $b) {
            if ($a['reason'] === 'Unspecified') return 1;
            if ($b['reason'] === 'Unspecified') return -1;
            return $b['total'] <=> $a['total'];
        });

        foreach ($result as &$r) {
            $r['share'] = $grandTotal > 0 ? round($r['total'] / $grandTotal, 4) : 0.0;
        }
        unset($r);

        return ['periods' => $periods, 'rows' => $result, 'grandTotal' => $grandTotal];
    }

    /**
     * Application Statuses tab: every `Order_Status` an application has ever carried, one row per
     * month, keyed to `Aplication_Date` — the full-lifecycle counterpart to
     * reasonsByStatusMonthly() above, which only covers the 5 committee-outcome statuses. Built
     * 2026-08-26 per the user sharing a screenshot of the external admin panel's own "Applications"
     * Status filter and asking for a by-month status breakdown in the same style as Committee
     * Statuses.
     *
     * Bucketed by `order_statuses.Order_Status` (the label text), not the raw numeric code —
     * two codes share the literal label "ინვოისის გაგზავნა" (IDs 5 and 9; ID 5 is overwhelmingly
     * the one actually used, ID 9 a rare/legacy duplicate — same pattern as ID 16 vs ID 5 for
     * "Approved" found earlier this session, see volta_portfolio_analysis memory) — grouping by
     * label merges them into one meaningful row instead of splitting a single business concept
     * across two near-identical rows. Two raw codes (1 and 99) have no `order_statuses` row at all
     * (small counts — 79 and 1 respectively in the Jan–Aug 2026 window) and fall into
     * "Unspecified" alongside any future code that's similarly missing a label, same convention as
     * reasonsByStatusMonthly()'s blank-Reason handling.
     *
     * No `Product_ID > 1` filter, same reasoning as reasonsByStatusMonthly() — this is the
     * application-lifecycle domain, not the sales-funnel domain.
     */
    public function applicationStatusesMonthly(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = $this->pdo->prepare(<<<SQL
            SELECT
                DATE_FORMAT(i.Aplication_Date, '%Y-%m') AS period,
                os.Order_Status AS status_label,
                COUNT(*) AS n
            FROM instalments i
            LEFT JOIN order_statuses os ON os.Order_Status_ID = i.Order_Status
            WHERE i.Aplication_Date >= :from AND i.Aplication_Date < :to
            GROUP BY period, status_label
            SQL);
        $rows->execute([
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ]);

        $periodsSeen = [];
        $byStatus = [];
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $period = $r['period'];
            $periodsSeen[$period] = true;
            $status = trim((string) $r['status_label']) === '' ? 'Unspecified' : $r['status_label'];
            $byStatus[$status][$period] = ($byStatus[$status][$period] ?? 0) + (int) $r['n'];
        }

        $periods = array_keys($periodsSeen);
        sort($periods);

        $result = [];
        $grandTotal = 0;
        foreach ($byStatus as $status => $byPeriod) {
            $total = 0;
            $filledByPeriod = [];
            foreach ($periods as $period) {
                $n = $byPeriod[$period] ?? 0;
                $filledByPeriod[$period] = $n;
                $total += $n;
            }
            $result[] = ['status' => $status, 'byPeriod' => $filledByPeriod, 'total' => $total];
            $grandTotal += $total;
        }

        usort($result, function ($a, $b) {
            if ($a['status'] === 'Unspecified') return 1;
            if ($b['status'] === 'Unspecified') return -1;
            return $b['total'] <=> $a['total'];
        });

        foreach ($result as &$r) {
            $r['share'] = $grandTotal > 0 ? round($r['total'] / $grandTotal, 4) : 0.0;
        }
        unset($r);

        return ['periods' => $periods, 'rows' => $result, 'grandTotal' => $grandTotal];
    }

    /**
     * Marketing / Leads tab: every `Lead_Status_ID` a lead has carried, one row per month, keyed
     * to `Lead_Create_Date` — the lead-stage counterpart to applicationStatusesMonthly() above.
     * Built 2026-08-26 per the user sharing a screenshot of the external admin panel's own
     * "Leads" list Status filter and asking for a by-month breakdown in the same style as
     * Committee Statuses.
     *
     * There are actually TWO places lead status data could come from — investigated both before
     * picking one:
     *  - The standalone `leads` table (14,828 rows, own `Lead_Status_ID` column): in the
     *    Jan–Aug 2026 window this is 92% `Lead_Status_ID = 0` (no label) and the rest `1`
     *    (Pending) — essentially just an untriaged intake log, none of the later-stage statuses
     *    (Processing/Not Responding/No Longer Interested/Duplicate/Expired/Application Created)
     *    ever show up there.
     *  - `instalments.Lead_Status_ID` / `Lead_Create_Date` / `Lead_Reason`: same
     *    `lead_statuses` lookup table, but here the full status lifecycle is actually populated
     *    (e.g. 6,440 "შექმნილია განაცხადი" / Application Created, 3,872 "აღარ არის
     *    დაინტერესებული" / No Longer Interested, in the Jan–Aug 2026 window) — this is where a
     *    lead's status actually gets updated once someone works it, which only happens after the
     *    lead is pulled into `instalments` (as a `Lead = 1` row). Confirmed by matching the full
     *    distribution against the same `lead_statuses` labels shown in the admin panel's own
     *    Status filter dropdown.
     * Used `instalments` for that reason — it's the one that actually reflects worked-lead
     * outcomes, not just raw intake volume. No `Product_ID > 1` filter, same reasoning as
     * applicationStatusesMonthly() — a lead legitimately has no product selected yet.
     */
    public function leadStatusesMonthly(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = $this->pdo->prepare(<<<SQL
            SELECT
                DATE_FORMAT(i.Lead_Create_Date, '%Y-%m') AS period,
                ls.Lid_Status AS status_label,
                COUNT(*) AS n
            FROM instalments i
            LEFT JOIN lead_statuses ls ON ls.Lid_Status_ID = i.Lead_Status_ID
            WHERE i.Lead_Create_Date >= :from AND i.Lead_Create_Date < :to
            GROUP BY period, status_label
            SQL);
        $rows->execute([
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ]);

        $periodsSeen = [];
        $byStatus = [];
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $period = $r['period'];
            $periodsSeen[$period] = true;
            $status = trim((string) $r['status_label']) === '' ? 'Unspecified' : $r['status_label'];
            $byStatus[$status][$period] = ($byStatus[$status][$period] ?? 0) + (int) $r['n'];
        }

        $periods = array_keys($periodsSeen);
        sort($periods);

        $result = [];
        $grandTotal = 0;
        foreach ($byStatus as $status => $byPeriod) {
            $total = 0;
            $filledByPeriod = [];
            foreach ($periods as $period) {
                $n = $byPeriod[$period] ?? 0;
                $filledByPeriod[$period] = $n;
                $total += $n;
            }
            $result[] = ['status' => $status, 'byPeriod' => $filledByPeriod, 'total' => $total];
            $grandTotal += $total;
        }

        usort($result, function ($a, $b) {
            if ($a['status'] === 'Unspecified') return 1;
            if ($b['status'] === 'Unspecified') return -1;
            return $b['total'] <=> $a['total'];
        });

        foreach ($result as &$r) {
            $r['share'] = $grandTotal > 0 ? round($r['total'] / $grandTotal, 4) : 0.0;
        }
        unset($r);

        return ['periods' => $periods, 'rows' => $result, 'grandTotal' => $grandTotal];
    }
}
