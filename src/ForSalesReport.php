<?php

declare(strict_types=1);

namespace Volta\Funnel;

use PDO;

/**
 * "For Sales" — three CRM application-pipeline questions, one row per day, from HISTORY_START (the first
 * day crm_activity_log has real status-change events) through today:
 *   1. Unique applications received (orders.created_at).
 *   2. Applications that moved to committee (installment.status_change, to=8).
 *   3. Applications that left "Signed" (status 11) for another status, broken out by destination.
 * Same day-range/zero-fill/status-code conventions as NewDbOps (see volta-analytics-new-db/pull_new.sh's
 * Operations section) — this is a small, standalone sibling, not part of that report.
 */
final class ForSalesReport
{
    public const HISTORY_START = '2026-09-01';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{apps: list<array{0:string,1:int}>, committee: list<array{0:string,1:int,2:int}>, signed: list<array{0:string,1:int,2:int,3:int,4:int}>, generatedAt: string} */
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

        // ---- 3. applications that left "Signed" (status 11) for another status, by destination
        // Active = to 1 (activation event) or 5 (rare: logged straight to the stored Active code);
        // Rejected = to 6; Customer declined = to 12; everything else (13 expired, etc.) -> "other".
        // A self-transition (to 11, seen once as a same-day correction) is not a real status change and is excluded.
        $signedByDay = [];
        foreach ($this->q("SELECT DATE(created_at) d, JSON_EXTRACT(metadata,'\$.to') t, COUNT(DISTINCT entity_id) n
            FROM crm_activity_log WHERE action='installment.status_change' AND JSON_EXTRACT(metadata,'\$.from')=11
            AND JSON_EXTRACT(metadata,'\$.to')<>11 AND DATE(created_at) BETWEEN :start AND :end
            GROUP BY d, t", ['start' => $start, 'end' => $today]) as $r) {
            $d = (string) $r['d'];
            $t = (int) $r['t'];
            $n = (int) $r['n'];
            $signedByDay[$d] ??= [0, 0, 0, 0];
            if ($t === 1 || $t === 5) {
                $signedByDay[$d][0] += $n;
            } elseif ($t === 6) {
                $signedByDay[$d][1] += $n;
            } elseif ($t === 12) {
                $signedByDay[$d][2] += $n;
            } else {
                $signedByDay[$d][3] += $n;
            }
        }
        $signed = [];
        foreach ($days as $d) {
            [$a, $rej, $c, $o] = $signedByDay[$d] ?? [0, 0, 0, 0];
            $signed[] = [$d, $a, $rej, $c, $o];
        }

        return [
            'apps' => $apps,
            'committee' => $committee,
            'signed' => $signed,
            'generatedAt' => (new \DateTimeImmutable('now'))->format('c'),
        ];
    }

    /** Swaps the three data consts (APPS/COMMITTEE/SIGNED) inside for-sales/for_sales.html for fresh data. */
    public static function applyToHtml(string $html, array $data): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        foreach (['APPS' => 'apps', 'COMMITTEE' => 'committee', 'SIGNED' => 'signed'] as $const => $key) {
            $html = preg_replace(
                '/^const ' . $const . ' = .*?;$/ms',
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
