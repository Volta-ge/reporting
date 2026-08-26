<?php

declare(strict_types=1);

namespace Volta\Funnel;

use PDO;

/**
 * Ex Customers — individual-row report, the only one in this project. Built 2026-08-26 per an
 * explicit user request for per-customer contact details (name, PID, phone, email) and a
 * payment-quality grade for former customers, for outreach/collections purposes. Every other
 * report in this project deliberately stays aggregate-only to avoid exposing PII (see
 * volta_portfolio_analysis memory's Customer Analysis section) — this one is a documented,
 * explicit exception because the user is the data controller asking for their own customers'
 * contact details for a legitimate business purpose, not a default to repeat elsewhere.
 *
 * **"Ex Customer" definition**: a `Customer_ID` with at least one genuinely closed loan
 * (`Close_Type IN (1, 2)`, `Product_ID > 1`) and NO currently active loan (`Active = 1`) — i.e.
 * someone who really was a customer but has nothing open with the business right now. A
 * customer with one closed loan and one still-active loan is NOT an ex customer (still
 * current). 3,210 such customers as of 2026-08-26.
 *
 * **Payment-quality grade (A–E)**, since the user asked for this criterion but left B/C/D to be
 * defined: per customer, `collectionRate = SUM(Full_Cost − Debt) / SUM(Full_Cost)` across all
 * their closed loans (weighted by loan size, not a simple per-loan average — same collection-rate
 * concept already used and validated in IncomeDelinquencyRepository). Band cutoffs were chosen by
 * looking at the real distribution before picking round numbers, not guessed blind:
 *   A (≥98% collected)  — 2,930 customers (91.3%) — paid in full, or near enough (rounding/small
 *                          penalty adjustments explain values fractionally over 100%).
 *   B (80–98% collected) —    24 customers (0.7%)  — mostly paid, small balance written off.
 *   C (50–80% collected) —    60 customers (1.9%)  — paid about half.
 *   D (1–50% collected)  —   122 customers (3.8%)  — paid very little.
 *   E (<1% collected)    —    74 customers (2.3%)  — collected essentially nothing (includes a
 *                          handful of negative rates, i.e. Debt ended up above Full_Cost).
 *
 * Dataset is small (3,210 rows) — confirmed safe to fetch in full and grade in PHP, unlike the
 * customer-demographics reports that had to move to SQL-side aggregation (see
 * feedback_aggregate_serverside_and_time_live memory).
 */
final class ExCustomerRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{summary: array{byGrade: array<int, array{grade: string, count: int, share: float, totalPurchased: float, totalWrittenOff: float}>, grandTotal: array{count: int, totalPurchased: float, totalWrittenOff: float}}, rows: array<int, array{customerId: int, name: string, pid: string, phone: string, email: string, city: string, grade: string, collectionRate: float, loanCount: int, totalPurchased: float, totalWrittenOff: float, lastCloseDate: ?string, products: string}>}
     */
    public function exCustomers(ProductClassifier $classifier): array
    {
        // Per-customer aggregate across their closed loans (Close_Type IN (1,2)) — the basis for
        // both the grade and the "how much did they buy / how much got written off" columns.
        $aggStmt = $this->pdo->query(<<<'SQL'
            SELECT
                i.Customer_ID AS customer_id,
                SUM(i.Full_Cost) AS total_full_cost,
                SUM(i.Debt) AS total_debt,
                COUNT(*) AS loan_count,
                MAX(i.Close_Date) AS last_close_date
            FROM instalments i
            WHERE i.Product_ID > 1
              AND i.Close_Type IN (1, 2)
              AND i.Customer_ID NOT IN (SELECT Customer_ID FROM instalments WHERE Active = 1 AND Product_ID > 1)
            GROUP BY i.Customer_ID
            SQL);
        $agg = $aggStmt->fetchAll(PDO::FETCH_ASSOC);

        // Product names purchased, deduped per customer — scoped to the same closed-loan set.
        // ProductClassifier::classify() takes the raw product_category.Category_Name (the same
        // join used throughout this project, e.g. IncomeDelinquencyRepository), NOT the product
        // Model code directly — translates to the business's own Product(EN) bucket names (e.g.
        // "Smartphones") for readability; falls back to the raw Model string when unclassified
        // (no category link, or a category the mapping sheet doesn't cover) rather than dropping
        // the purchase entirely.
        $modelStmt = $this->pdo->query(<<<'SQL'
            SELECT DISTINCT i.Customer_ID AS customer_id, p.Model AS model, pc.Category_Name AS category_name
            FROM instalments i
            JOIN instalment_products ip ON ip.Instalment_ID = i.Instalment_ID
            JOIN products p ON p.Product_ID = ip.Product_ID
            LEFT JOIN product_category pc ON pc.Category_ID = p.Category_ID
            WHERE i.Product_ID > 1
              AND i.Close_Type IN (1, 2)
              AND i.Customer_ID NOT IN (SELECT Customer_ID FROM instalments WHERE Active = 1 AND Product_ID > 1)
            SQL);
        $productsByCustomer = [];
        foreach ($modelStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $label = $classifier->classify($row['category_name']) ?? $row['model'];
            $productsByCustomer[(int) $row['customer_id']][$label] = true;
        }

        // Contact details — join coverage confirmed 100% (every ex-customer has a customers row).
        $contactStmt = $this->pdo->query(<<<'SQL'
            SELECT Customer_ID AS customer_id, FullName AS name, PID AS pid, Mobile AS mobile,
                   Phone1 AS phone1, Email AS email, COALESCE(NULLIF(Fact_City, ''), City) AS city
            FROM customers
            WHERE Customer_ID IN (
                SELECT DISTINCT i.Customer_ID FROM instalments i
                WHERE i.Product_ID > 1 AND i.Close_Type IN (1, 2)
                  AND i.Customer_ID NOT IN (SELECT Customer_ID FROM instalments WHERE Active = 1 AND Product_ID > 1)
            )
            SQL);
        $contactByCustomer = [];
        foreach ($contactStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $contactByCustomer[(int) $row['customer_id']] = $row;
        }

        $rows = [];
        $byGrade = ['A' => [], 'B' => [], 'C' => [], 'D' => [], 'E' => []];
        foreach ($agg as $a) {
            $customerId = (int) $a['customer_id'];
            $fullCost = (float) $a['total_full_cost'];
            $debt = (float) $a['total_debt'];
            if ($fullCost <= 0) {
                continue;
            }
            $rate = ($fullCost - $debt) / $fullCost;
            $grade = self::gradeForRate($rate);

            $contact = $contactByCustomer[$customerId] ?? null;
            $phone = trim((string) ($contact['mobile'] ?? '')) !== ''
                ? $contact['mobile']
                : trim((string) ($contact['phone1'] ?? ''));

            $products = isset($productsByCustomer[$customerId])
                ? implode(', ', array_keys($productsByCustomer[$customerId]))
                : '';

            $row = [
                'customerId' => $customerId,
                'name' => $contact['name'] ?? '',
                'pid' => $contact['pid'] ?? '',
                'phone' => $phone,
                'email' => $contact['email'] ?? '',
                'city' => $contact['city'] ?? '',
                'grade' => $grade,
                'collectionRate' => round($rate, 4),
                'loanCount' => (int) $a['loan_count'],
                'totalPurchased' => round($fullCost, 2),
                'totalWrittenOff' => round(max($debt, 0.0), 2),
                'lastCloseDate' => $a['last_close_date'] !== null ? substr((string) $a['last_close_date'], 0, 10) : null,
                'products' => $products,
            ];
            $rows[] = $row;
            $byGrade[$grade][] = $row;
        }

        // Rows sorted worst-payer-first (E→A) — the population most worth a collections/win-back
        // look first, then by total purchased descending within a grade (biggest accounts first).
        $gradeOrder = ['E' => 0, 'D' => 1, 'C' => 2, 'B' => 3, 'A' => 4];
        usort($rows, static function ($a, $b) use ($gradeOrder) {
            $g = $gradeOrder[$a['grade']] <=> $gradeOrder[$b['grade']];
            return $g !== 0 ? $g : ($b['totalPurchased'] <=> $a['totalPurchased']);
        });

        $grandTotal = ['count' => 0, 'totalPurchased' => 0.0, 'totalWrittenOff' => 0.0];
        $summaryByGrade = [];
        foreach (['A', 'B', 'C', 'D', 'E'] as $grade) {
            $count = count($byGrade[$grade]);
            $totalPurchased = array_sum(array_column($byGrade[$grade], 'totalPurchased'));
            $totalWrittenOff = array_sum(array_column($byGrade[$grade], 'totalWrittenOff'));
            $summaryByGrade[] = [
                'grade' => $grade,
                'count' => $count,
                'totalPurchased' => round($totalPurchased, 2),
                'totalWrittenOff' => round($totalWrittenOff, 2),
            ];
            $grandTotal['count'] += $count;
            $grandTotal['totalPurchased'] += $totalPurchased;
            $grandTotal['totalWrittenOff'] += $totalWrittenOff;
        }
        foreach ($summaryByGrade as &$s) {
            $s['share'] = $grandTotal['count'] > 0 ? round($s['count'] / $grandTotal['count'], 4) : 0.0;
        }
        unset($s);
        $grandTotal['totalPurchased'] = round($grandTotal['totalPurchased'], 2);
        $grandTotal['totalWrittenOff'] = round($grandTotal['totalWrittenOff'], 2);

        return [
            'summary' => ['byGrade' => $summaryByGrade, 'grandTotal' => $grandTotal],
            'rows' => $rows,
        ];
    }

    /**
     * The other side of "inactive customers" — customers with NO active loan and NO genuinely
     * closed loan either (i.e. every application they ever submitted was rejected, refused,
     * expired, or otherwise never became a real disbursed loan). Added 2026-08-26 after the user
     * compared this tab's 3,210 against Customer Analysis's own Total (35,894) minus Active
     * (6,126) = 29,768 and asked where the other ~26,558 went — they were never excluded on
     * purpose, just out of scope for a *payment-quality* grade, since someone who never actually
     * borrowed has no collection history to grade. Kept as an aggregate-only breakdown (no PII)
     * rather than added to the individual-row list, since there's no meaningful A–E grade for
     * them and doubling the PII row count wasn't asked for — see the memory note on this
     * decision (asked the user directly rather than assuming either way).
     *
     * Bucketed by each customer's MOST RECENT application's `Order_Status` label (not every
     * application they ever made — a customer can have several rejected attempts over time; the
     * latest one is the most representative "why they're not a customer now"). 26,558 customers,
     * reconciling exactly against 29,768 total inactive − 3,210 graded ex-customers.
     */
    public function neverBorrowedByStatus(): array
    {
        $rows = $this->pdo->query(<<<SQL
            SELECT os.Order_Status AS status_label, COUNT(*) AS n
            FROM (
                SELECT i.Customer_ID,
                       SUBSTRING_INDEX(GROUP_CONCAT(i.Order_Status ORDER BY i.Aplication_Date DESC), ',', 1) AS latest_status
                FROM instalments i
                WHERE i.Product_ID > 1
                  AND i.Customer_ID NOT IN (SELECT Customer_ID FROM instalments WHERE Active = 1 AND Product_ID > 1)
                  AND i.Customer_ID NOT IN (SELECT Customer_ID FROM instalments WHERE Close_Type IN (1, 2) AND Product_ID > 1)
                GROUP BY i.Customer_ID
            ) t
            LEFT JOIN order_statuses os ON os.Order_Status_ID = t.latest_status
            GROUP BY os.Order_Status
            SQL)->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        $total = 0;
        foreach ($rows as $r) {
            $status = trim((string) $r['status_label']) === '' ? 'Unspecified' : $r['status_label'];
            $result[] = ['status' => $status, 'count' => (int) $r['n']];
            $total += (int) $r['n'];
        }
        usort($result, static function ($a, $b) {
            if ($a['status'] === 'Unspecified') return 1;
            if ($b['status'] === 'Unspecified') return -1;
            return $b['count'] <=> $a['count'];
        });
        foreach ($result as &$r) {
            $r['share'] = $total > 0 ? round($r['count'] / $total, 4) : 0.0;
        }
        unset($r);

        return ['rows' => $result, 'total' => $total];
    }

    private static function gradeForRate(float $rate): string
    {
        if ($rate >= 0.98) {
            return 'A';
        }
        if ($rate >= 0.80) {
            return 'B';
        }
        if ($rate >= 0.50) {
            return 'C';
        }
        if ($rate >= 0.01) {
            return 'D';
        }
        return 'E';
    }
}
