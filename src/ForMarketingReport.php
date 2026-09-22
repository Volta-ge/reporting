<?php

declare(strict_types=1);

namespace Volta\Funnel;

use PDO;

/**
 * "For Marketing" — a "good payers" list, as of yesterday, per the user's own 4 criteria:
 *   1. not currently overdue
 *   2. was paying / is paying on schedule (no installment settled more than GRACE_DAYS late, ever)
 *   3. at least 2 real payments made
 *   4. de-duplicated by PID (customers.id_number) — one row per person
 *
 * Built entirely from crm_installment_schedules (one row per scheduled installment, RETAINED even after
 * payoff — unlike the old myvolta.info DB, which deleted paid schedule rows) + crm_payments (real payment
 * transactions). A per-loan cumulative "required vs paid" waterfall (same technique as the old-DB
 * "Verified Good Payers v2" export) determines whether every due installment was satisfied on time; a
 * separate, simpler check (any still-open schedule row already past its due date) flags current overdue
 * status. GRACE_DAYS=3 mirrors this codebase's own DPD-bucket vocabulary (bucket 1 = "max 3 days late") and
 * the earlier v2 export's own grace window — not a new, invented threshold.
 *
 * People with no PID on file are excluded outright (criterion 4 requires one to de-duplicate by); a person
 * can hold more than one `customers` row under the same PID (family/guest-order accounts) — all their loans
 * across every such row are pooled before the criteria are applied, and one output row represents them.
 */
final class ForMarketingReport
{
    private const GRACE_DAYS = 3;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{rows: list<array<string,mixed>>, summary: array<string,int|float>, generatedAt: string} */
    public function build(): array
    {
        $cutoff = (new \DateTimeImmutable('today'))->modify('-1 day'); // "as of yesterday"
        $cutoffStr = $cutoff->format('Y-m-d');

        $sched = $this->q('SELECT s.id, s.installment_id, s.schedule_date, s.schedule_amount, s.paid_amount, s.active FROM crm_installment_schedules s');
        $pays = $this->q('SELECT installment_id, payment_date, amount FROM crm_payments WHERE amount < 100000 AND reversed_at IS NULL');
        $orders = $this->q("SELECT id, customer_id, crm_creator_date, created_at FROM orders WHERE customer_id IS NOT NULL");
        $custs = $this->q('SELECT id, first_name, last_name, phone, email, id_number FROM customers WHERE id_number IS NOT NULL AND id_number <> ""');
        $cities = $this->q("SELECT a.order_id, a.city FROM addresses a WHERE a.address_type = 'order_shipping'");

        $orderById = [];
        foreach ($orders as $o) {
            $orderById[(int) $o['id']] = $o;
        }
        $custById = [];
        foreach ($custs as $c) {
            $custById[(int) $c['id']] = $c;
        }
        $cityByOrder = [];
        foreach ($cities as $r) {
            $cityByOrder[(int) $r['order_id']] = (string) $r['city'];
        }

        $schedByLoan = [];
        foreach ($sched as $s) {
            $schedByLoan[(int) $s['installment_id']][] = $s;
        }
        $paysByLoan = [];
        foreach ($pays as $p) {
            $paysByLoan[(int) $p['installment_id']][] = $p;
        }

        // ---- per loan: overdueNow / lateEver / payment count / total paid
        $loan = [];
        foreach ($schedByLoan as $loanId => $rows) {
            usort($rows, static fn ($a, $b) => strcmp((string) $a['schedule_date'], (string) $b['schedule_date']));

            $overdueNow = false;
            foreach ($rows as $r) {
                if ((int) $r['active'] === 1 && (string) $r['schedule_date'] < $cutoffStr) {
                    $due = new \DateTimeImmutable((string) $r['schedule_date']);
                    if ($cutoff->diff($due)->days > self::GRACE_DAYS) {
                        $overdueNow = true;
                        break;
                    }
                }
            }

            $payRows = $paysByLoan[$loanId] ?? [];
            usort($payRows, static fn ($a, $b) => strcmp((string) $a['payment_date'], (string) $b['payment_date']));
            $cumPaid = [];
            $running = 0.0;
            foreach ($payRows as $p) {
                $running += (float) $p['amount'];
                $cumPaid[] = [(string) $p['payment_date'], $running];
            }

            $cumReq = 0.0;
            $lateEver = false;
            foreach ($rows as $r) {
                $cumReq += (float) $r['schedule_amount'];
                $due = (string) $r['schedule_date'];
                if ($due > $cutoffStr) {
                    continue;
                }
                $satisfied = null;
                foreach ($cumPaid as [$d, $amt]) {
                    if ($amt >= $cumReq - 0.01) {
                        $satisfied = $d;
                        break;
                    }
                }
                if ($satisfied === null) {
                    $lateEver = true;
                } else {
                    $dueD = new \DateTimeImmutable($due);
                    $satD = new \DateTimeImmutable($satisfied);
                    if ($satD > $dueD && $dueD->diff($satD)->days > self::GRACE_DAYS) {
                        $lateEver = true;
                    }
                }
            }

            $loan[$loanId] = [
                'overdueNow' => $overdueNow,
                'lateEver' => $lateEver,
                'payments' => count($payRows),
                'totalPaid' => $running,
            ];
        }

        // ---- aggregate per PID (pooling every customer row that shares the same PID)
        $byPid = [];
        foreach ($loan as $loanId => $lr) {
            $o = $orderById[$loanId] ?? null;
            if ($o === null) {
                continue;
            }
            $c = $custById[(int) $o['customer_id']] ?? null;
            if ($c === null) {
                continue;
            }
            $pid = trim((string) $c['id_number']);
            if ($pid === '') {
                continue;
            }
            // PHP silently casts a canonical-decimal-integer string used as an array key to a real int
            // (e.g. PID "62001040470", no leading zero) — never leak the loop/array KEY into output, only
            // this stored 'pid' VALUE (assigned here while $pid is still guaranteed a string). Same bug
            // class documented in AccountingRepository::buildReconciliation().
            $byPid[$pid] ??= [
                'pid' => $pid,
                'overdueAny' => false, 'lateAny' => false, 'payments' => 0, 'loans' => 0, 'totalPaid' => 0.0,
                'names' => [], 'phones' => [], 'emails' => [], 'cities' => [], 'firstOrder' => null, 'lastOrder' => null,
            ];
            $bucket = &$byPid[$pid];
            $bucket['overdueAny'] = $bucket['overdueAny'] || $lr['overdueNow'];
            $bucket['lateAny'] = $bucket['lateAny'] || $lr['lateEver'];
            $bucket['payments'] += $lr['payments'];
            $bucket['loans']++;
            $bucket['totalPaid'] += $lr['totalPaid'];
            $name = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
            if ($name !== '') {
                $bucket['names'][$name] = ($bucket['names'][$name] ?? 0) + 1;
            }
            if (!empty($c['phone'])) {
                $bucket['phones'][(string) $c['phone']] = true;
            }
            if (!empty($c['email'])) {
                $bucket['emails'][(string) $c['email']] = true;
            }
            $city = $cityByOrder[$loanId] ?? null;
            if ($city !== null && $city !== '') {
                $bucket['cities'][$city] = ($bucket['cities'][$city] ?? 0) + 1;
            }
            $created = (string) ($o['created_at'] ?? '');
            if ($created !== '') {
                if ($bucket['firstOrder'] === null || $created < $bucket['firstOrder']) {
                    $bucket['firstOrder'] = $created;
                }
                if ($bucket['lastOrder'] === null || $created > $bucket['lastOrder']) {
                    $bucket['lastOrder'] = $created;
                }
            }
            unset($bucket);
        }

        $rows = [];
        $totalWithLoans = count($byPid);
        $exclOverdue = 0;
        $exclLate = 0;
        $exclFewPayments = 0;
        foreach ($byPid as $v) {
            if ($v['overdueAny']) {
                $exclOverdue++;
                continue;
            }
            if ($v['lateAny']) {
                $exclLate++;
                continue;
            }
            if ($v['payments'] < 2) {
                $exclFewPayments++;
                continue;
            }
            arsort($v['names']);
            arsort($v['cities']);
            $rows[] = [
                'pid' => $v['pid'],
                'name' => array_key_first($v['names']) ?? '',
                'phone' => implode(', ', array_keys($v['phones'])),
                'email' => implode(', ', array_keys($v['emails'])),
                'city' => array_key_first($v['cities']) ?? '',
                'loans' => $v['loans'],
                'payments' => $v['payments'],
                'totalPaid' => round($v['totalPaid'], 2),
                'firstOrder' => $v['firstOrder'] !== null ? substr($v['firstOrder'], 0, 10) : null,
                'lastOrder' => $v['lastOrder'] !== null ? substr($v['lastOrder'], 0, 10) : null,
            ];
        }
        usort($rows, static fn ($a, $b) => $b['totalPaid'] <=> $a['totalPaid']);

        return [
            'rows' => $rows,
            'summary' => [
                'asOf' => $cutoffStr,
                'graceDays' => self::GRACE_DAYS,
                'candidatePeople' => $totalWithLoans,
                'goodPayers' => count($rows),
                'excludedOverdue' => $exclOverdue,
                'excludedLateHistory' => $exclLate,
                'excludedFewPayments' => $exclFewPayments,
            ],
            'generatedAt' => (new \DateTimeImmutable('now'))->format('c'),
        ];
    }

    /**
     * Swaps GOOD_PAYERS / FM_SUMMARY / GENERATED_AT consts inside for-marketing/for_marketing.html for
     * fresh data. Plain string search (not regex): GOOD_PAYERS alone is ~4,400 objects on one line, and a
     * `.*?;$` PCRE pattern (the technique ForSalesReport uses, fine for its much smaller day-series
     * payloads) hits PHP's pcre.backtrack_limit on a line this long and preg_replace() throws/returns null.
     */
    public static function applyToHtml(string $html, array $data): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $consts = ['GOOD_PAYERS' => 'rows', 'FM_SUMMARY' => 'summary', 'GENERATED_AT' => 'generatedAt'];
        foreach ($consts as $const => $key) {
            $needle = "const $const = ";
            $start = strpos($html, "\n$needle");
            if ($start === false) {
                throw new \RuntimeException("ForMarketingReport::applyToHtml: const $const not found in template");
            }
            $start++; // move past the leading \n, to the start of "const ..."
            // ";\n" rather than a bare ";": json_encode() never emits a raw newline inside the value, so
            // this is guaranteed to be the statement terminator, not a ';' that happens to sit inside a
            // name/city string.
            $end = strpos($html, ";\n", $start);
            if ($end === false) {
                throw new \RuntimeException("ForMarketingReport::applyToHtml: terminating ';' for const $const not found");
            }
            $html = substr($html, 0, $start) . $needle . json_encode($data[$key], $flags) . substr($html, $end);
        }
        return $html;
    }

    /** @return list<array<string,mixed>> */
    private function q(string $sql): array
    {
        return $this->pdo->query($sql)->fetchAll();
    }
}
