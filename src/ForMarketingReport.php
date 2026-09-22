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
 *
 * PID resolution (widened 2026-09-22, matching Customer Analyze's own identity logic in
 * volta-analytics-new-db/pull_new.sh): prefer the personal ID typed on the order's own application form
 * (`volta_application_data.personal_ID`), fall back to the linked `customers.id_number`. A meaningful slice
 * of orders carry a real personal ID on the form even though the customer ACCOUNT record's own id_number is
 * blank — those people were previously invisible to both this report's lists even though they're clearly
 * identifiable, once flagged by the user comparing this report's totals against Customer Analyze's.
 *
 * Guest orders (widened further, same day): an order with no linked `customers` account at all
 * (`customer_id IS NULL`) is no longer skipped outright — 2,935 of them carry real installment-schedule
 * data (genuine loans), and 15,518 of the rest carry an application-form personal ID. Contact info for
 * these falls back to the order's own shipping address (`addresses` row, `address_type='order_shipping'`,
 * which snapshots name/phone/email/gender same as a real account) and finally to the order's own
 * `customer_email`/`customer_first_name`/`customer_last_name` columns — see `resolveOrderContact()`.
 *
 * Complete-contact filter (same day, later): a person is dropped from EITHER final list unless all three
 * of gender, phone, and email resolved to something — a marketing outreach list is only useful with a
 * real channel to reach someone on, and gender is asked for alongside the other two rather than shown as
 * "–". Counted separately in the summary (`excludedIncompleteContact` / `excludedNeverIncompleteContact`),
 * not folded into the payment-behaviour exclusion counters.
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
        $orders = $this->q('SELECT id, customer_id, customer_email, customer_first_name, customer_last_name, crm_creator_date, created_at, status FROM orders');
        $custs = $this->q('SELECT id, first_name, last_name, phone, email, id_number, gender FROM customers');
        $addrs = $this->q("SELECT order_id, first_name, last_name, phone, email, gender, city FROM addresses WHERE address_type = 'order_shipping'");
        $appPids = $this->q("SELECT order_id, MAX(field_value) pid FROM volta_application_data WHERE field_code = 'personal_ID' AND field_value <> '' GROUP BY order_id");

        $orderById = [];
        foreach ($orders as $o) {
            $orderById[(int) $o['id']] = $o;
        }
        $custById = [];
        foreach ($custs as $c) {
            $custById[(int) $c['id']] = $c;
        }
        $addrByOrder = [];
        foreach ($addrs as $r) {
            $addrByOrder[(int) $r['order_id']] = $r;
        }
        $cityByOrder = [];
        foreach ($addrByOrder as $orderId => $r) {
            $cityByOrder[$orderId] = (string) $r['city'];
        }
        $adPidByOrder = [];
        foreach ($appPids as $r) {
            $adPidByOrder[(int) $r['order_id']] = trim((string) $r['pid']);
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

        // ---- aggregate per PID (pooling every customer row — real account or guest order — that
        // shares the same PID)
        $byPid = [];
        foreach ($loan as $loanId => $lr) {
            $o = $orderById[$loanId] ?? null;
            if ($o === null) {
                continue;
            }
            $c = $custById[(int) $o['customer_id']] ?? null;
            $pid = self::resolveOrderPid($adPidByOrder, $loanId, $c['id_number'] ?? null);
            if ($pid === '') {
                continue;
            }
            $contact = self::resolveOrderContact($o, $c, $addrByOrder[$loanId] ?? null);
            // PHP silently casts a canonical-decimal-integer string used as an array key to a real int
            // (e.g. PID "62001040470", no leading zero) — never leak the loop/array KEY into output, only
            // this stored 'pid' VALUE (assigned here while $pid is still guaranteed a string). Same bug
            // class documented in AccountingRepository::buildReconciliation().
            $byPid[$pid] ??= [
                'pid' => $pid,
                'overdueAny' => false, 'lateAny' => false, 'payments' => 0, 'loans' => 0, 'totalPaid' => 0.0,
                'names' => [], 'phones' => [], 'emails' => [], 'cities' => [], 'genders' => [], 'firstOrder' => null, 'lastOrder' => null,
            ];
            $bucket = &$byPid[$pid];
            $bucket['overdueAny'] = $bucket['overdueAny'] || $lr['overdueNow'];
            $bucket['lateAny'] = $bucket['lateAny'] || $lr['lateEver'];
            $bucket['payments'] += $lr['payments'];
            $bucket['loans']++;
            $bucket['totalPaid'] += $lr['totalPaid'];
            if ($contact['name'] !== '') {
                $bucket['names'][$contact['name']] = ($bucket['names'][$contact['name']] ?? 0) + 1;
            }
            if ($contact['gender'] !== '') {
                $bucket['genders'][$contact['gender']] = ($bucket['genders'][$contact['gender']] ?? 0) + 1;
            }
            if ($contact['phone'] !== '') {
                $bucket['phones'][$contact['phone']] = true;
            }
            if ($contact['email'] !== '') {
                $bucket['emails'][$contact['email']] = true;
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
        $exclIncompleteContact = 0;
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
            if (empty($v['genders']) || empty($v['phones']) || empty($v['emails'])) {
                $exclIncompleteContact++;
                continue;
            }
            arsort($v['names']);
            arsort($v['cities']);
            arsort($v['genders']);
            $rows[] = [
                'pid' => $v['pid'],
                'name' => array_key_first($v['names']) ?? '',
                'gender' => array_key_first($v['genders']) ?? '',
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

        // ---- Registered, Never Purchased: customers on file who never had a loan with schedule data
        // (i.e. never in $byPid above — the same "≥1 real loan" pool the good-payers criteria run on).
        $items = $this->q('SELECT order_id, name FROM order_items');
        $itemsByOrder = [];
        foreach ($items as $it) {
            $itemsByOrder[(int) $it['order_id']][] = (string) $it['name'];
        }
        // One pass per order (not per customer account): the resolved PID can pool several customer
        // accounts (family/guest orders, same as the good-payers loop above) and this is also what lets
        // an order's own application-form PID stand in for a blank customers.id_number.
        $neverByPid = [];
        foreach ($orders as $o) {
            $orderId = (int) $o['id'];
            $c = $custById[(int) $o['customer_id']] ?? null;
            $pid = self::resolveOrderPid($adPidByOrder, $orderId, $c['id_number'] ?? null);
            if ($pid === '' || isset($byPid[$pid])) {
                continue;
            }
            $contact = self::resolveOrderContact($o, $c, $addrByOrder[$orderId] ?? null);
            if ($contact['phone'] === '' && $contact['email'] === '') {
                continue;
            }
            $neverByPid[$pid] ??= [
                'pid' => $pid, 'names' => [], 'phones' => [], 'emails' => [], 'cities' => [], 'genders' => [],
                'orders' => 0, 'lastOrder' => null, 'lastStatus' => null, 'products' => [],
            ];
            $bucket = &$neverByPid[$pid];
            if ($contact['name'] !== '') {
                $bucket['names'][$contact['name']] = ($bucket['names'][$contact['name']] ?? 0) + 1;
            }
            if ($contact['phone'] !== '') {
                $bucket['phones'][$contact['phone']] = true;
            }
            if ($contact['email'] !== '') {
                $bucket['emails'][$contact['email']] = true;
            }
            if ($contact['gender'] !== '') {
                $bucket['genders'][$contact['gender']] = ($bucket['genders'][$contact['gender']] ?? 0) + 1;
            }
            $bucket['orders']++;
            $created = (string) ($o['created_at'] ?? '');
            $city = $cityByOrder[$orderId] ?? null;
            if ($city !== null && $city !== '') {
                $bucket['cities'][$city] = ($bucket['cities'][$city] ?? 0) + 1;
            }
            if ($created !== '' && ($bucket['lastOrder'] === null || $created > $bucket['lastOrder'])) {
                $bucket['lastOrder'] = $created;
                $bucket['lastStatus'] = (string) ($o['status'] ?? '');
            }
            foreach ($itemsByOrder[$orderId] ?? [] as $itemName) {
                $bucket['products'][$itemName] = ($bucket['products'][$itemName] ?? 0) + 1;
            }
            unset($bucket);
        }

        $neverRows = [];
        $exclNeverIncompleteContact = 0;
        foreach ($neverByPid as $v) {
            if (empty($v['genders']) || empty($v['phones']) || empty($v['emails'])) {
                $exclNeverIncompleteContact++;
                continue;
            }
            arsort($v['names']);
            arsort($v['cities']);
            arsort($v['genders']);
            arsort($v['products']);
            $products = [];
            foreach ($v['products'] as $pname => $n) {
                $products[] = $n > 1 ? "$pname x$n" : $pname;
            }
            $neverRows[] = [
                'pid' => $v['pid'],
                'name' => array_key_first($v['names']) ?? '',
                'gender' => array_key_first($v['genders']) ?? '',
                'phone' => implode(', ', array_keys($v['phones'])),
                'email' => implode(', ', array_keys($v['emails'])),
                'city' => array_key_first($v['cities']) ?? '',
                'orders' => $v['orders'],
                'lastOrder' => $v['lastOrder'] !== null ? substr($v['lastOrder'], 0, 10) : null,
                'lastStatus' => $v['lastStatus'],
                'products' => implode(', ', $products),
            ];
        }
        usort($neverRows, static fn ($a, $b) => strcmp((string) $b['lastOrder'], (string) $a['lastOrder']));

        return [
            'rows' => $rows,
            'neverPurchased' => $neverRows,
            'summary' => [
                'asOf' => $cutoffStr,
                'graceDays' => self::GRACE_DAYS,
                'candidatePeople' => $totalWithLoans,
                'goodPayers' => count($rows),
                'excludedOverdue' => $exclOverdue,
                'excludedLateHistory' => $exclLate,
                'excludedFewPayments' => $exclFewPayments,
                'excludedIncompleteContact' => $exclIncompleteContact,
                'neverPurchasedCount' => count($neverRows),
                'excludedNeverIncompleteContact' => $exclNeverIncompleteContact,
            ],
            'generatedAt' => (new \DateTimeImmutable('now'))->format('c'),
        ];
    }

    /**
     * PID for one order: prefer the personal ID typed on that order's own application form
     * (`volta_application_data.personal_ID`, looked up by order id — same source and priority as
     * Customer Analyze's `ident` resolution), fall back to the linked customer account's id_number.
     *
     * @param array<int,string> $adPidByOrder
     */
    private static function resolveOrderPid(array $adPidByOrder, int $orderId, ?string $customerIdNumber): string
    {
        $adPid = $adPidByOrder[$orderId] ?? '';
        if ($adPid !== '') {
            return $adPid;
        }
        return trim((string) $customerIdNumber);
    }

    /**
     * Contact info for one order: prefer the linked `customers` account (verified, kept up to date),
     * fall back to the order's own shipping address snapshot (`addresses`, address_type='order_shipping'
     * — same shape as a real account: name/phone/email/gender), and finally the order's own
     * `customer_email`/`customer_first_name`/`customer_last_name` columns (name only — orders carries no
     * direct phone column). Guest orders (`$c === null`) resolve entirely from the last two sources.
     *
     * @param array<string,mixed> $o
     * @param array<string,mixed>|null $c
     * @param array<string,mixed>|null $addr
     * @return array{name:string,phone:string,email:string,gender:string}
     */
    private static function resolveOrderContact(array $o, ?array $c, ?array $addr): array
    {
        $name = self::pickFirst(
            trim((string) (($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''))),
            trim((string) (($addr['first_name'] ?? '') . ' ' . ($addr['last_name'] ?? ''))),
            trim((string) (($o['customer_first_name'] ?? '') . ' ' . ($o['customer_last_name'] ?? '')))
        );
        $phone = self::pickPlausiblePhone((string) ($c['phone'] ?? ''), (string) ($addr['phone'] ?? ''));
        $email = self::pickFirst((string) ($c['email'] ?? ''), (string) ($addr['email'] ?? ''), (string) ($o['customer_email'] ?? ''));
        $gender = self::normalizeGender($c['gender'] ?? null);
        if ($gender === '') {
            $gender = self::normalizeGender($addr['gender'] ?? null);
        }
        return ['name' => $name, 'phone' => $phone, 'email' => $email, 'gender' => $gender];
    }

    private static function pickFirst(string ...$candidates): string
    {
        foreach ($candidates as $cand) {
            $cand = trim($cand);
            if ($cand !== '') {
                return $cand;
            }
        }
        return '';
    }

    private static function pickPlausiblePhone(string ...$candidates): string
    {
        foreach ($candidates as $cand) {
            $cand = trim($cand);
            if ($cand !== '' && self::isPlausiblePhone($cand)) {
                return $cand;
            }
        }
        return '';
    }

    private static function normalizeGender(?string $raw): string
    {
        $raw = trim((string) $raw);
        return match (true) {
            in_array($raw, ['მდედრ.', 'Female', 'female', 'F', 'f'], true) => 'მდედრობითი',
            in_array($raw, ['მამრ.', 'Male', 'male', 'M', 'm'], true) => 'მამრობითი',
            default => '',
        };
    }

    /**
     * Rejects junk phone values before they ever reach a report row: literal "N/A"/"#N/A"-style text,
     * and numbers that aren't real phone numbers (too short/long once non-digits are stripped, or every
     * digit the same — "111111111", "0000000" etc, the pattern the DB's own test/QA customer rows use).
     */
    private static function isPlausiblePhone(string $raw): bool
    {
        $raw = trim($raw);
        if ($raw === '') {
            return false;
        }
        if (preg_match('/n\s*\/?\s*a/i', $raw) === 1) {
            return false;
        }
        $digits = preg_replace('/\D/', '', $raw) ?? '';
        $len = strlen($digits);
        if ($len < 6 || $len > 15) {
            return false;
        }
        if (preg_match('/^(\d)\1+$/', $digits) === 1) {
            return false;
        }
        return true;
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
        $consts = ['GOOD_PAYERS' => 'rows', 'NEVER_PURCHASED' => 'neverPurchased', 'FM_SUMMARY' => 'summary', 'GENERATED_AT' => 'generatedAt'];
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
