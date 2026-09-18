<?php

declare(strict_types=1);

namespace Volta\Funnel;

use PDO;

/**
 * "For Sales" — CRM application-pipeline questions, one row per day, from HISTORY_START (the first day
 * crm_activity_log has real status-change events) through today:
 *   1. Unique applications received (orders.created_at).
 *   2. Applications that moved to committee (installment.status_change, to=8), broken out by sales
 *      manager x day for the committee tab's table.
 *   3. Sales (count + amount) by sales manager x day, same definition as the Daily Mail Amount Sold report.
 * Same day-range/zero-fill/status-code conventions as NewDbOps (see volta-analytics-new-db/pull_new.sh's
 * Operations section) — this is a small, standalone sibling, not part of that report.
 */
final class ForSalesReport
{
    public const HISTORY_START = '2026-09-01';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{apps: list<array{0:string,1:int}>, committee: list<array{0:string,1:int,2:int}>, committeeByManager: list<array{0:string,1:list<int>}>, salesByManager: list<array{0:string,1:list<array{0:int,1:float}>}>, generatedAt: string} */
    public function build(): array
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $start = self::HISTORY_START;
        $days = self::dayRange($start, $today);

        // ---- 1. unique applications by day
        $appsByDay = [];
        foreach ($this->q("SELECT DATE(created_at) d, COUNT(*) n FROM orders
            WHERE DATE(created_at) BETWEEN :start AND :end GROUP BY d", ['start' => $start, 'end' => $today]) as $r) {
            $appsByDay[(string) $r['d']] = (int) $r['n'];
        }
        $apps = [];
        foreach ($days as $d) {
            $apps[] = [$d, $appsByDay[$d] ?? 0];
        }

        // ---- 2. applications that moved to committee (status -> 8)
        $commByDay = [];
        foreach ($this->q("SELECT DATE(created_at) d, COUNT(DISTINCT entity_id) apps, COUNT(*) events
            FROM crm_activity_log WHERE action='installment.status_change' AND JSON_EXTRACT(metadata,'\$.to')=8
            AND DATE(created_at) BETWEEN :start AND :end GROUP BY d", ['start' => $start, 'end' => $today]) as $r) {
            $commByDay[(string) $r['d']] = [(int) $r['apps'], (int) $r['events']];
        }
        $committee = [];
        foreach ($days as $d) {
            [$a, $e] = $commByDay[$d] ?? [0, 0];
            $committee[] = [$d, $a, $e];
        }

        // ---- 2b. the same committee-bound applications, broken out by sales manager (orders.crm_sales_manager_id)
        // for the manager x date table. Unique per (day, manager) — an application that bounced back to committee
        // more than once on the same day still counts once, same rule as #2 above.
        $commByManager = [];
        foreach ($this->q("SELECT DATE(l.created_at) d,
                COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),'დაუნიშნავი') manager,
                COUNT(DISTINCT l.entity_id) apps
            FROM crm_activity_log l JOIN orders o ON o.id=l.entity_id LEFT JOIN crm_users u ON u.id=o.crm_sales_manager_id
            WHERE l.action='installment.status_change' AND JSON_EXTRACT(l.metadata,'\$.to')=8
            AND DATE(l.created_at) BETWEEN :start AND :end GROUP BY d, manager", ['start' => $start, 'end' => $today]) as $r) {
            $commByManager[(string) $r['manager']][(string) $r['d']] = (int) $r['apps'];
        }
        $managerTotals = [];
        foreach ($commByManager as $name => $byDay) {
            $managerTotals[$name] = array_sum($byDay);
        }
        arsort($managerTotals);
        $committeeByManager = [];
        foreach (array_keys($managerTotals) as $name) {
            $row = [];
            foreach ($days as $d) {
                $row[] = $commByManager[$name][$d] ?? 0;
            }
            $committeeByManager[] = [$name, $row];
        }

        // ---- 4. Sales by sales manager and day — count + amount, same definition and date key as the Daily
        // Mail "Amount Sold" report (volta-analytics-new-db/pull_new.sh, revised 2026-09-15): keyed to the day
        // the loan reaches Active status (Signed -> Active, crm_activity_log to=1), or crm_order_status=99
        // (single-payment, no activation event, keyed to created_at). Amount = the real installment schedule
        // total + advance whenever the schedule actually carries the financing markup (ground truth); falls
        // back to the computed estimate (site price * standard/Karcher markup, CEIL'd to the nearest 10) only
        // when the schedule is broken (= site price) and crm_creator_date >= 2026-09-01; otherwise site price.
        // MUST stay in sync with pull_new.sh's copy of this same CASE expression if that formula ever changes.
        $salesByManager = [];
        foreach ($this->q("WITH act AS (
                    SELECT entity_id, MIN(created_at) t FROM crm_activity_log
                    WHERE action='installment.status_change' AND JSON_EXTRACT(metadata,'\$.to')=1 GROUP BY entity_id
                    UNION ALL SELECT id, created_at FROM orders WHERE crm_order_status=99
                )
                SELECT DATE(act.t) d,
                    COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),'დაუნიშნავი') manager,
                    COUNT(*) deals,
                    SUM(CASE WHEN o.crm_order_status=99 THEN o.base_grand_total
                             WHEN s.tot IS NULL THEN o.base_grand_total
                             WHEN ABS(s.tot - o.base_grand_total) >= 0.01 THEN s.tot + COALESCE(o.crm_advance_amount,0)
                             WHEN o.crm_creator_date >= '2026-09-01' THEN CEIL((o.base_grand_total * (CASE WHEN EXISTS (
                                    SELECT 1 FROM order_items oi
                                    JOIN product_attribute_values pb ON pb.product_id=oi.product_id AND pb.attribute_id=25
                                    JOIN attribute_options ao ON ao.id=pb.integer_value AND ao.admin_name='KARCHER GEORGIA'
                                    WHERE oi.order_id=o.id
                                  ) THEN (1.15*1.05*1.20) ELSE (1.05*1.20) END)) / 10) * 10
                             WHEN s.tot + COALESCE(o.crm_advance_amount,0) <= o.base_grand_total + 0.01 THEN s.tot + COALESCE(o.crm_advance_amount,0)
                             ELSE s.tot END) amount
                FROM act JOIN orders o ON o.id=act.entity_id AND o.crm_active=1
                LEFT JOIN crm_users u ON u.id=o.crm_sales_manager_id
                LEFT JOIN (SELECT installment_id, SUM(schedule_amount) tot FROM crm_installment_schedules GROUP BY installment_id) s ON s.installment_id=o.id
                WHERE DATE(act.t) BETWEEN :start AND :end GROUP BY d, manager", ['start' => $start, 'end' => $today]) as $r) {
            $salesByManager[(string) $r['manager']][(string) $r['d']] = [(int) $r['deals'], round((float) $r['amount'], 2)];
        }
        $managerAmtTotals = [];
        foreach ($salesByManager as $name => $byDay) {
            $managerAmtTotals[$name] = array_sum(array_column($byDay, 1));
        }
        arsort($managerAmtTotals);
        $salesByManagerRows = [];
        foreach (array_keys($managerAmtTotals) as $name) {
            $row = [];
            foreach ($days as $d) {
                $row[] = $salesByManager[$name][$d] ?? [0, 0.0];
            }
            $salesByManagerRows[] = [$name, $row];
        }

        return [
            'apps' => $apps,
            'committee' => $committee,
            'committeeByManager' => $committeeByManager,
            'salesByManager' => $salesByManagerRows,
            'generatedAt' => (new \DateTimeImmutable('now'))->format('c'),
        ];
    }

    /** Swaps the data consts (APPS/COMMITTEE/COMMITTEE_BY_MANAGER/SALES_BY_MANAGER/GENERATED_AT) inside for-sales/for_sales.html for fresh data. */
    public static function applyToHtml(string $html, array $data): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $consts = [
            'APPS' => 'apps',
            'COMMITTEE' => 'committee',
            'COMMITTEE_BY_MANAGER' => 'committeeByManager',
            'SALES_BY_MANAGER' => 'salesByManager',
            'GENERATED_AT' => 'generatedAt',
        ];
        foreach ($consts as $const => $key) {
            // \r? before the line-end anchor: the shared working tree this runs against can pick up
            // CRLF line endings from a `git checkout`/`stash pop` (core.autocrlf=true on this Windows
            // machine) even though the committed blob itself is LF-only -- without it, a CRLF-tainted
            // working copy makes every replacement silently no-op (found 2026-09-18: a plain `php
            // bin/for_sales_dump.php` run reported success but left the file byte-for-byte unchanged).
            $html = preg_replace(
                '/^const ' . $const . ' = .*?;\r?$/ms',
                'const ' . $const . ' = ' . json_encode($data[$key], $flags) . ';',
                $html,
                1,
            );
        }
        return $html;
    }

    /** @return list<array<string,mixed>> */
    private function q(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return list<string> */
    private static function dayRange(string $start, string $end): array
    {
        $days = [];
        $d = new \DateTimeImmutable($start);
        $last = new \DateTimeImmutable($end);
        while ($d <= $last) {
            $days[] = $d->format('Y-m-d');
            $d = $d->modify('+1 day');
        }
        return $days;
    }
}
