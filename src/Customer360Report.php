<?php

declare(strict_types=1);

namespace Volta\Funnel;

use PDO;

/**
 * "Customer 360": one profile per customer (joined on personal number) with every application and loan, plus the
 * Analysis tab's aggregates, computed live from VoltaStoreDB for reporting.volta.ge/customer360/.
 *
 * PHP port of Desktop\Volta_Waybills\customer360\{build,analysis,export}.py (which feed the claude.ai Artifact
 * N1W5yKCNsFVJwNYioapnRu). Keep the two in step: same definitions, same output arrays (window.C360_*).
 * - Loans: crm_order_status 1/5/11; active = crm_active=1.
 * - DPD total / worst delay: waterfall of crm_payments (principal part; reversed payments excluded) against the
 *   cumulative schedule; current DPD and outstanding: the CRM's own allocation (paid_amount on active rows).
 * The output holds customer PII: it is only ever served behind requireBasicAuth(), never committed.
 */
final class Customer360Report
{
    private const LOAN_ST = ['1' => true, '5' => true, '11' => true];
    private const YES = ['დიახ' => true, 'კი' => true, 'yes' => true, 'Yes' => true, 'კი მაქვს' => true];
    private const NO = ['არა' => true, 'არ მაქვს' => true, 'არაფერი' => true, 'no' => true, 'No' => true, '0' => true];
    private const FIELDS = ['personal_ID', 'gender', 'birth_date', 'work_place', 'position', 'employment_status', 'salary',
        'extra_income', 'extra_income_amount', 'extra_income_source', 'current_obligation', 'monthly_obligation'];

    public const C_COLS = ['key', 'pid', 'name', 'gender', 'dob', 'phones', 'fact_addr', 'ship_addr', 'work_place', 'position',
        'employment', 'salary', 'extra_income', 'extra_income_amt', 'extra_income_source', 'obligation',
        'obligation_amt', 'apps_n', 'approved_n', 'loans_n', 'single_n', 'rejected_n', 'other_n', 'active_n',
        'active_outstanding', 'active_monthly', 'active_term', 'cur_dpd', 'dpd_sum_all', 'dpd_sum_active',
        'closed_n', 'closed_clean_n', 'closed_overdue_n', 'max_delay', 'first_app', 'last_app', 'key_type'];
    public const A_COLS = ['c', 'id', 'date', 'st', 'status', 'product', 'price', 'legacy', 'rc'];
    public const L_COLS = ['c', 'id', 'issued', 'product', 'price', 'adv', 'sched_total', 'term', 'monthly', 'active', 'outstanding',
        'cur_dpd', 'dpd_sum', 'worst', 'close_date', 'close_kind', 'paid_total', 'penalty_paid', 'legacy', 'ct',
        'first_due', 'last_due'];

    private int $today;   // day number (days since epoch) of today, in Tbilisi
    private \DateTimeImmutable $now;

    public function __construct(private readonly PDO $pdo)
    {
        $this->now = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tbilisi'));
        $this->today = self::day($this->now->format('Y-m-d'));
    }

    /** @return array{meta: array, an: array, cust: array, apps: array, loans: array} */
    public function build(): array
    {
        // ---------------- source rows ----------------
        $C = [];
        foreach ($this->q('SELECT id, first_name fn, last_name ln, gender, date_of_birth dob, phone, id_number FROM customers') as $r) {
            $C[(string) $r['id']] = $r;
        }
        $F = [];
        foreach ($this->q("SELECT order_id, field_code, field_value v FROM volta_application_data WHERE field_code IN ('"
            . implode("','", self::FIELDS) . "') ORDER BY id") as $r) {
            $v = self::clean($r['v']);
            if ($v !== '') {
                $F[(string) $r['order_id']][$r['field_code']] = $v;
            }
        }
        $bill = $ship = [];
        foreach ($this->q("SELECT order_id, address_type t, address, city, phone FROM addresses
                           WHERE address_type IN ('order_billing','order_shipping') ORDER BY id") as $r) {
            $oid = (string) $r['order_id'];
            if ($r['t'] === 'order_billing') {
                $bill[$oid] ??= $r;
            } else {
                $ship[$oid] ??= $r;
            }
        }
        $items = [];
        foreach ($this->q('SELECT order_id, name, base_total FROM order_items WHERE parent_id IS NULL ORDER BY base_total DESC, id') as $r) {
            $items[(string) $r['order_id']][strtr((string) $r['name'], "\t\r\n", '   ')] = true;
        }
        $PR = [];
        foreach ($this->q('SELECT customer_id, fact_city, fact_address, legal_address, workshop FROM crm_customer_profile ORDER BY modified_at, id') as $r) {
            $PR[(string) $r['customer_id']] = [
                'fact_city' => self::clean($r['fact_city']), 'fact_address' => self::clean($r['fact_address']),
                'legal_address' => self::clean($r['legal_address']), 'workshop' => self::clean($r['workshop']),
            ];
        }

        // ---------------- applications ----------------
        $O = [];
        foreach ($this->q('SELECT id, customer_id, DATE(created_at) created, DATE(crm_creator_date) creator, crm_order_status st, status,
                crm_active act, crm_close_type ct, DATE(crm_close_date) cd, base_grand_total price, crm_advance_amount adv,
                crm_reason_code rc, customer_first_name fn, customer_last_name ln, crm_contact_phone phone,
                my_volta_installment_id legacy FROM orders ORDER BY id') as $r) {
            $id = (string) $r['id'];
            $cid = $r['customer_id'] === null ? '' : (string) $r['customer_id'];
            $c = $C[$cid] ?? null;
            $f = $F[$id] ?? [];
            $pid = self::pidNorm($f['personal_ID'] ?? '') ?: self::pidNorm($c['id_number'] ?? '');
            $name = trim(self::clean($r['fn']) . ' ' . self::clean($r['ln']));
            if ($name === '' && $c) {
                $name = trim(self::clean($c['fn']) . ' ' . self::clean($c['ln']));
            }
            $created = $r['created'] === null ? null : self::day($r['created']);
            $creator = $r['creator'] === null ? null : self::day($r['creator']);
            $O[] = [
                'id' => $id, 'cid' => $cid, 'st' => (string) $r['st'], 'status' => (string) $r['status'],
                'act' => (string) $r['act'], 'ct' => $r['ct'] === null ? '' : (string) $r['ct'],
                'cd' => $r['cd'] === null ? null : self::day($r['cd']),
                'price' => $r['price'] === null ? null : (float) $r['price'], 'adv' => $r['adv'] === null ? null : (float) $r['adv'],
                'rc' => $r['rc'] === null || (string) $r['rc'] === '0' ? '' : (string) $r['rc'],
                'legacy' => $r['legacy'] === null ? '' : (string) $r['legacy'],
                'created' => $created, 'date' => $creator ?? $created, 'name' => $name, 'pid' => $pid,
                'key' => $pid !== '' ? $pid : (ctype_digit($cid) ? 'C' . $cid : 'O' . $id),
                'phone9' => self::phone9($r['phone']) ?: self::phone9($bill[$id]['phone'] ?? '') ?: self::phone9($c['phone'] ?? ''),
                'ship' => isset($ship[$id]) ? self::addr($ship[$id]) : '',
                'product' => isset($items[$id]) ? implode(' + ', array_keys($items[$id])) : '',
                'f' => $f,
            ];
        }
        unset($F, $items, $bill, $ship);

        // ---------------- loans: schedule + payment waterfall ----------------
        $loanIdx = [];
        foreach ($O as $i => $o) {
            if (isset(self::LOAN_ST[$o['st']])) {
                $loanIdx[$o['id']] = $i;
            }
        }
        $pays = [];
        foreach ($this->q('SELECT installment_id, DATE(payment_date) d, amount, principal_part, penalty_part FROM crm_payments
                           WHERE reversed_at IS NULL ORDER BY installment_id, payment_date, id') as $r) {
            $lid = (string) $r['installment_id'];
            if (!isset($loanIdx[$lid])) {
                continue;
            }
            $amt = (float) $r['amount'];
            $pp = (float) $r['principal_part'];
            $pen = (float) $r['penalty_part'];
            $cov = $pp > 0 ? $pp : ($pen > 0 ? 0.0 : $amt);
            $p = &$pays[$lid];
            $p ??= ['d' => [], 'c' => [], 'amt' => 0.0, 'pen' => 0.0, 'run' => 0.0];
            $p['run'] += $cov;
            $p['d'][] = $r['d'] === null ? null : self::day($r['d']);
            $p['c'][] = $p['run'];
            $p['amt'] += $amt;
            $p['pen'] += $pen;
            unset($p);
        }
        $sched = [];
        foreach ($this->q('SELECT installment_id, month, schedule_date, schedule_amount, paid_amount, active FROM crm_installment_schedules
                           ORDER BY installment_id, schedule_date, month') as $r) {
            $lid = (string) $r['installment_id'];
            if (isset($loanIdx[$lid])) {
                $sched[$lid][] = [(int) $r['month'], self::day((string) $r['schedule_date']), (float) $r['schedule_amount'],
                    (float) $r['paid_amount'], (string) $r['active']];
            }
        }
        $T = $this->today;
        $L = [];
        foreach ($loanIdx as $lid => $oi) {
            $o = $O[$oi];
            $x = ['oi' => $oi, 'term' => null, 'sched_total' => null, 'monthly' => null, 'paid_total' => null, 'penalty_paid' => null,
                'outstanding' => null, 'cur_dpd' => null, 'dpd_sum' => null, 'worst' => null, 'payoff' => null, 'last_pay' => null,
                'first_due' => null, 'last_due' => null];
            if (isset($sched[$lid])) {
                $rows = $sched[$lid];
                $p = $pays[$lid] ?? ['d' => [], 'c' => [], 'amt' => 0.0, 'pen' => 0.0];
                $d = $p['d'];
                $c = $p['c'];
                $nc = count($c);
                $last = $nc ? $d[$nc - 1] : null;
                $closedRows = true;
                $hasInst = false;
                foreach ($rows as $s) {
                    if ($s[4] !== '0') {
                        $closedRows = false;
                    }
                    if ($s[0] > 0) {
                        $hasInst = true;
                    }
                }
                $req = 0.0;
                $dpd = 0;
                $worst = 0;
                $out = 0.0;
                $curMin = null;
                $amts = [];
                $first = $lastDue = null;
                foreach ($rows as $s) {
                    [$month, $sd, $amt, $paid, $active] = $s;
                    $req += $amt;
                    $i = self::searchsorted($c, $req - 0.01);
                    if ($i < $nc) {
                        $late = max(0, $d[$i] - $sd);
                    } elseif ($closedRows && $last !== null) {
                        $late = max(0, $last - $sd);
                    } else {
                        $late = max(0, $T - $sd);
                    }
                    $dpd += $late;
                    $worst = max($worst, $late);
                    if ($active === '1') {
                        $out += max(0.0, $amt - $paid);
                        if ($sd < $T && $paid < $amt - 0.01) {
                            $curMin = $curMin === null ? $sd : min($curMin, $sd);
                        }
                    }
                    if (!$hasInst || $month > 0) {
                        $amts[] = round($amt, 2);
                    }
                    $first = $first === null ? $sd : min($first, $sd);
                    $lastDue = $lastDue === null ? $sd : max($lastDue, $sd);
                }
                $i = $req > 0 ? self::searchsorted($c, $req - 0.01) : $nc;
                $x = ['oi' => $oi, 'term' => count($amts), 'sched_total' => round($req, 2), 'monthly' => self::mode($amts),
                    'paid_total' => round($p['amt'], 2), 'penalty_paid' => round($p['pen'], 2), 'outstanding' => round($out, 2),
                    'cur_dpd' => $curMin === null ? 0 : $T - $curMin, 'dpd_sum' => $dpd, 'worst' => $worst,
                    'payoff' => $i < $nc ? $d[$i] : null, 'last_pay' => $last, 'first_due' => $first, 'last_due' => $lastDue];
            }
            $x['id'] = $lid;
            $x['key'] = $o['key'];
            $x['date'] = $o['date'];
            $x['active'] = $o['act'] === '1';
            $x['close_date'] = $x['active'] ? null : ($x['payoff'] ?? $o['cd'] ?? $x['last_pay']);
            $x['had_overdue'] = ($x['dpd_sum'] ?? 0) > 0;
            $x['close_kind'] = $x['active'] ? '' : ($o['ct'] === '2' ? 'ჩამოწერილი/სხვა (close type 2)'
                : ($x['had_overdue'] ? 'დაიხურა, იყო ვადაგადაცილება' : 'დაიხურა ვადაგადაცილების გარეშე'));
            $L[] = $x;
        }
        unset($sched, $pays);

        // ---------------- customer profiles ----------------
        $order = array_keys($O);
        usort($order, fn ($a, $b) => self::cmpApp($O[$a], $O[$b]));
        $cust = [];
        foreach ($order as $i) {
            $o = $O[$i];
            $f = $o['f'];
            $k = $o['key'];
            $u = &$cust[$k];
            $u ??= ['pid' => '', 'name' => '', 'gender' => '', 'dob' => '', 'phones' => [], 'ship_addr' => '', 'work_place' => '',
                'position' => '', 'employment' => '', 'salary' => '', 'ei_raw' => '', 'ei_amt' => '', 'extra_income_source' => '',
                'ob_raw' => '', 'ob_amt' => '', 'cids' => [], 'first_app' => null, 'last_app' => null, 'apps' => []];
            foreach (['pid' => $o['pid'], 'name' => $o['name'], 'gender' => self::gender($f['gender'] ?? ''),
                'dob' => self::dob($f['birth_date'] ?? ''), 'ship_addr' => $o['ship'], 'work_place' => $f['work_place'] ?? '',
                'position' => $f['position'] ?? '', 'employment' => $f['employment_status'] ?? '', 'salary' => $f['salary'] ?? '',
                'ei_raw' => $f['extra_income'] ?? '', 'ei_amt' => $f['extra_income_amount'] ?? '',
                'extra_income_source' => $f['extra_income_source'] ?? '', 'ob_raw' => $f['current_obligation'] ?? '',
                'ob_amt' => $f['monthly_obligation'] ?? ''] as $fk => $fv) {
                if ($fv !== '') {
                    $u[$fk] = $fv;
                }
            }
            if ($o['phone9'] !== '') {
                $u['phones'][$o['phone9']] = true;
            }
            if ($o['cid'] !== '') {
                $u['cids'][$o['cid']] = true;
            }
            if ($o['created'] !== null) {
                $u['first_app'] = $u['first_app'] === null ? $o['created'] : min($u['first_app'], $o['created']);
                $u['last_app'] = $u['last_app'] === null ? $o['created'] : max($u['last_app'], $o['created']);
            }
            $u['apps'][] = $i;
            unset($u);
        }
        foreach ($cust as $k => &$u) {
            $ids = array_keys($u['cids']);
            if ($u['gender'] === '') {
                $u['gender'] = self::latest(array_map(fn ($i) => self::gender($C[$i]['gender'] ?? ''), $ids));
            }
            if ($u['dob'] === '') {
                $u['dob'] = self::latest(array_map(fn ($i) => self::dob((string) ($C[$i]['dob'] ?? '')), $ids));
            }
            $fa = [];
            $la = [];
            $ws = [];
            foreach ($ids as $i) {
                if (!isset($PR[$i])) {
                    continue;
                }
                $p = $PR[$i];
                $fa[] = ($p['fact_city'] !== '' && str_starts_with($p['fact_address'], $p['fact_city'])) ? $p['fact_address']
                    : implode(', ', array_filter([$p['fact_city'], $p['fact_address']], 'strlen'));
                $la[] = $p['legal_address'];
                $ws[] = $p['workshop'];
            }
            $u['fact_addr'] = self::latest($fa) ?: self::latest($la);
            if ($u['work_place'] === '') {
                $u['work_place'] = self::latest($ws);
            }
            if ($u['name'] === '') {
                $u['name'] = self::latest(array_map(fn ($i) => trim(self::clean($C[$i]['fn'] ?? '') . ' ' . self::clean($C[$i]['ln'] ?? '')), $ids));
            }
            [$u['extra_income'], $u['extra_income_amt']] = self::ynAmount($u['ei_raw'], $u['ei_amt']);
            [$u['obligation'], $u['obligation_amt']] = self::ynAmount($u['ob_raw'], $u['ob_amt']);
            $u['apps_n'] = count($u['apps']);
            $u['loans_n'] = $u['single_n'] = $u['rejected_n'] = $u['other_n'] = 0;
            foreach ($u['apps'] as $i) {
                $st = $O[$i]['st'];
                if (isset(self::LOAN_ST[$st])) {
                    $u['loans_n']++;
                } elseif ($st === '99') {
                    $u['single_n']++;
                } elseif ($st === '6') {
                    $u['rejected_n']++;
                } else {
                    $u['other_n']++;
                }
            }
            $u['approved_n'] = $u['loans_n'] + $u['single_n'];
            $u['loans'] = [];
            $u['key_type'] = preg_match('/^\d{11}$/', (string) $k) ? 'პირადი ნომერი' : (str_starts_with((string) $k, 'C') ? 'მხოლოდ ანგარიში (PID არ არის)' : 'მხოლოდ განაცხადი');
        }
        unset($u);
        foreach ($L as $li => $x) {
            $cust[$x['key']]['loans'][] = $li;
        }
        foreach ($cust as &$u) {
            $u['active_n'] = $u['closed_n'] = $u['closed_overdue_n'] = 0;
            $u['active_outstanding'] = $u['active_monthly'] = 0.0;
            $u['cur_dpd'] = $u['dpd_sum_all'] = $u['dpd_sum_active'] = $u['max_delay'] = 0;
            $terms = [];
            foreach ($u['loans'] as $li) {
                $x = $L[$li];
                $u['dpd_sum_all'] += $x['dpd_sum'] ?? 0;
                $u['max_delay'] = max($u['max_delay'], $x['worst'] ?? 0);
                if ($x['active']) {
                    $u['active_n']++;
                    $u['active_outstanding'] += $x['outstanding'] ?? 0;
                    $u['active_monthly'] += $x['monthly'] ?? 0;
                    $u['cur_dpd'] = max($u['cur_dpd'], $x['cur_dpd'] ?? 0);
                    $u['dpd_sum_active'] += $x['dpd_sum'] ?? 0;
                    if ($x['term'] !== null) {
                        $terms[] = (string) $x['term'];
                    }
                } else {
                    $u['closed_n']++;
                    if ($x['had_overdue']) {
                        $u['closed_overdue_n']++;
                    }
                }
            }
            $u['closed_clean_n'] = $u['closed_n'] - $u['closed_overdue_n'];
            $u['active_term'] = implode(', ', $terms);
        }
        unset($u);

        $an = $this->analysis($O, $L, $cust);
        return $this->export($O, $L, $cust, $an);
    }

    // ================= analysis (analysis.py) =================
    private function analysis(array $O, array $L, array $cust): array
    {
        $T = $this->today;
        $EMP = ['კერძო' => 'emp_private', 'საჯარო' => 'emp_public', 'თვითდასაქმებული' => 'emp_self', 'დაუსაქმებელი' => 'emp_none'];
        $YES = ['დიახ' => true, 'კი' => true, 'yes' => true, 'Yes' => true];
        // sequence number of each loan within its customer, by issue date
        $byKey = [];
        foreach ($L as $li => $x) {
            $byKey[$x['key']][] = $li;
        }
        $seq = [];
        foreach ($byKey as $lis) {
            usort($lis, fn ($a, $b) => [$L[$a]['date'] ?? PHP_INT_MAX, $a] <=> [$L[$b]['date'] ?? PHP_INT_MAX, $b]);
            foreach ($lis as $n => $li) {
                $seq[$li] = $n + 1;
            }
        }
        $R = [];
        foreach ($L as $li => $x) {
            $o = $O[$x['oi']];
            $f = $o['f'];
            $u = $cust[$x['key']];
            $sal = self::salNum($f['salary'] ?? '');
            $obl = self::numv($f['monthly_obligation'] ?? '');
            $raw = trim($f['current_obligation'] ?? '');
            if (isset($YES[$raw])) {
                $oflag = 'yes';
            } elseif (isset(self::NO[$raw])) {
                $oflag = 'no';
            } elseif (($xv = self::numv($raw)) !== null) {
                $oflag = $xv > 0 ? 'yes' : 'no';
            } elseif ($obl !== null && $obl > 0) {
                $oflag = 'yes';
            } else {
                $oflag = $raw === '' ? 'unknown' : 'yes';
            }
            $dob = $u['dob'] !== '' ? self::day($u['dob']) : null;
            $age = ($dob !== null && $x['date'] !== null) ? ($x['date'] - $dob) / 365.25 : null;
            $dti = $sal ? (($obl ?? 0) + ($x['monthly'] ?? 0)) / $sal : null;
            $worst = $x['worst'] ?? 0;
            $R[$li] = [
                'bad' => $worst >= 30, 'clean' => $worst == 0, 'act' => $x['active'], 'cur' => $x['cur_dpd'], 'out' => $x['outstanding'] ?? 0,
                'mob' => $x['date'] === null ? null : ($T - $x['date']) / 30.44,
                'b_seq' => $seq[$li] === 1 ? 'seq1' : ($seq[$li] === 2 ? 'seq2' : 'seq3'),
                'b_amt' => self::band($o['price'], [0, 500, 1000, 2000, 4000, 1e9], ['<500', '500–999', '1000–1999', '2000–3999', '4000+']),
                'b_term' => self::band($x['term'], [0, 7, 10, 11, 12, 100], ['≤6', '7–9', '10', '11', '12+']),
                'b_age' => self::band($age, [0, 25, 35, 45, 55, 200], ['18–24', '25–34', '35–44', '45–54', '55+']),
                'g' => $u['gender'] === 'ქალი' ? 'female' : ($u['gender'] === 'კაცი' ? 'male' : 'unknown'),
                'emp' => $EMP[$f['employment_status'] ?? ''] ?? 'unknown',
                'b_sal' => self::band($sal, [0, 1000, 1500, 2000, 3000, 1e9], ['<1000', '1000–1499', '1500–1999', '2000–2999', '3000+']),
                'obl' => $oflag,
                'b_dti' => self::band($dti, [0, 0.3, 0.5, 0.7, 1e9], ['<30%', '30–49%', '50–69%', '70%+']),
            ];
        }
        $riskTable = function (string $col, array $order) use ($R): array {
            $out = [];
            foreach ($order as $v) {
                $n = $bad = $clean = $act = $c30 = 0;
                $sum = 0.0;
                foreach ($R as $r) {
                    if ($r[$col] !== $v) {
                        continue;
                    }
                    if ($r['mob'] !== null && $r['mob'] >= 3) {
                        $n++;
                        $bad += $r['bad'] ? 1 : 0;
                        $clean += $r['clean'] ? 1 : 0;
                    }
                    if ($r['act']) {
                        $act++;
                        $c30 += ($r['cur'] ?? 0) > 30 ? 1 : 0;
                        $sum += $r['out'];
                    }
                }
                if ($n === 0 && $act === 0) {
                    continue;
                }
                $out[] = ['k' => $v, 'n' => $n, 'bad' => $n ? round($bad / $n * 100, 1) : null, 'clean' => $n ? round($clean / $n * 100, 1) : null,
                    'cur30' => $act ? round($c30 / $act * 100, 1) : null, 'act' => $act, 'out' => (int) round($sum)];
            }
            return $out;
        };
        $risk = [
            'seq' => $riskTable('b_seq', ['seq1', 'seq2', 'seq3']),
            'amt' => $riskTable('b_amt', ['<500', '500–999', '1000–1999', '2000–3999', '4000+']),
            'term' => $riskTable('b_term', ['≤6', '7–9', '10', '11', '12+']),
            'age' => $riskTable('b_age', ['18–24', '25–34', '35–44', '45–54', '55+', 'unknown']),
            'gender' => $riskTable('g', ['female', 'male', 'unknown']),
            'emp' => $riskTable('emp', ['emp_private', 'emp_public', 'emp_self', 'emp_none', 'unknown']),
            'sal' => $riskTable('b_sal', ['<1000', '1000–1499', '1500–1999', '2000–2999', '3000+', 'unknown']),
            'obl' => $riskTable('obl', ['no', 'yes', 'unknown']),
            'dti' => $riskTable('b_dti', ['<30%', '30–49%', '50–69%', '70%+', 'unknown']),
        ];
        $n = $bad = $clean = 0;
        foreach ($R as $r) {
            if ($r['mob'] !== null && $r['mob'] >= 3) {
                $n++;
                $bad += $r['bad'] ? 1 : 0;
                $clean += $r['clean'] ? 1 : 0;
            }
        }
        $overall = ['n' => $n, 'bad' => $n ? round($bad / $n * 100, 1) : null, 'clean' => $n ? round($clean / $n * 100, 1) : null];

        // vintages by issue half-year
        $V = [];
        $from = self::day('2022-01-01');
        foreach ($L as $li => $x) {
            if ($x['date'] === null || $x['date'] < $from) {
                continue;
            }
            $ymd = gmdate('Y-m', $x['date'] * 86400);
            $h = substr($ymd, 0, 4) . '-H' . ((int) substr($ymd, 5, 2) <= 6 ? '1' : '2');
            $v = &$V[$h];
            $v ??= ['n' => 0, 'bad' => 0, 'clean' => 0, 'active' => 0, 'c30' => 0, 'amt' => 0.0];
            $v['n']++;
            $v['bad'] += $R[$li]['bad'] ? 1 : 0;
            $v['clean'] += $R[$li]['clean'] ? 1 : 0;
            $v['amt'] += $O[$x['oi']]['price'] ?? 0;
            if ($x['active']) {
                $v['active']++;
                $v['c30'] += ($x['cur_dpd'] ?? 0) > 30 ? 1 : 0;
            }
            unset($v);
        }
        ksort($V);
        $vint = [];
        foreach ($V as $h => $v) {
            $vint[] = ['k' => $h, 'n' => $v['n'], 'bad' => round($v['bad'] / $v['n'] * 100, 1), 'clean' => round($v['clean'] / $v['n'] * 100, 1),
                'active' => $v['active'], 'cur30' => $v['active'] ? round($v['c30'] / $v['active'] * 100, 1) : null, 'amt' => (int) round($v['amt'])];
        }

        // active portfolio by DPD bucket
        $buckets = [['dpd0', 0, 0], ['dpd1_7', 1, 7], ['dpd8_30', 8, 30], ['dpd31_60', 31, 60], ['dpd61_90', 61, 90], ['dpd91_180', 91, 180], ['dpd180', 181, PHP_INT_MAX]];
        $par = [];
        $activeOut = 0.0;
        $activeN = 0;
        foreach ($L as $x) {
            if ($x['active']) {
                $activeN++;
                $activeOut += $x['outstanding'] ?? 0;
            }
        }
        foreach ($buckets as [$k, $lo, $hi]) {
            $cnt = 0;
            $sum = 0.0;
            foreach ($L as $x) {
                if ($x['active'] && $x['cur_dpd'] !== null && $x['cur_dpd'] >= $lo && $x['cur_dpd'] <= $hi) {
                    $cnt++;
                    $sum += $x['outstanding'] ?? 0;
                }
            }
            $par[] = ['k' => $k, 'n' => $cnt, 'out' => (int) round($sum)];
        }

        // applications funnel by month
        $kind2 = static function (string $st): string {
            return isset(self::LOAN_ST[$st]) ? 'loan' : ($st === '99' ? 'single' : ($st === '6' ? 'rejected' : ($st === '12' ? 'refused' : ($st === '14' ? 'nocontact' : 'other'))));
        };
        $FM = [];
        $from24 = self::day('2024-01-01');
        foreach ($O as $o) {
            if ($o['created'] === null || $o['created'] < $from24) {
                continue;
            }
            $m = gmdate('Y-m', $o['created'] * 86400);
            $FM[$m] ??= ['apps' => 0, 'loan' => 0, 'single' => 0, 'rejected' => 0, 'refused' => 0, 'nocontact' => 0, 'other' => 0];
            $FM[$m]['apps']++;
            $FM[$m][$kind2($o['st'])]++;
        }
        ksort($FM);
        $funnel = [];
        foreach ($FM as $m => $r) {
            $funnel[] = ['k' => $m] + $r;
        }

        // approval by applicant history at the moment of applying
        $firstLoan = $lastClose = $rejBefore = $lastApp = [];
        foreach ($L as $x) {
            if ($x['date'] !== null) {
                $firstLoan[$x['key']] = min($firstLoan[$x['key']] ?? PHP_INT_MAX, $x['date']);
            }
            if (!$x['active'] && $x['close_date'] !== null) {
                $lastClose[$x['key']] = max($lastClose[$x['key']] ?? PHP_INT_MIN, $x['close_date']);
            }
        }
        foreach ($O as $o) {
            if ($o['created'] === null) {
                continue;
            }
            if ($o['st'] === '6') {
                $rejBefore[$o['key']] = min($rejBefore[$o['key']] ?? PHP_INT_MAX, $o['created']);
            }
            $lastApp[$o['key']] = max($lastApp[$o['key']] ?? PHP_INT_MIN, $o['created']);
        }
        $H = [];
        $from25 = self::day('2025-01-01');
        foreach ($O as $o) {
            if ($o['created'] === null || $o['created'] < $from25) {
                continue;
            }
            $fl = $firstLoan[$o['key']] ?? null;
            $h = ($fl !== null && $fl < $o['created']) ? 'returning' : ((($rejBefore[$o['key']] ?? PHP_INT_MAX) < $o['created']) ? 'prev_rejected' : 'new');
            $k2 = $kind2($o['st']);
            $H[$h] ??= ['n' => 0, 'a' => 0, 'r' => 0, 'f' => 0];
            $H[$h]['n']++;
            $H[$h]['a'] += ($k2 === 'loan' || $k2 === 'single') ? 1 : 0;
            $H[$h]['r'] += $k2 === 'rejected' ? 1 : 0;
            $H[$h]['f'] += ($k2 === 'refused' || $k2 === 'nocontact') ? 1 : 0;
        }
        $appr = [];
        foreach (['new', 'prev_rejected', 'returning'] as $h) {
            $r = $H[$h] ?? ['n' => 0, 'a' => 0, 'r' => 0, 'f' => 0];
            $d = max(1, $r['n']);
            $appr[] = ['k' => $h, 'apps' => $r['n'], 'approved' => round($r['a'] / $d * 100, 1), 'rejected' => round($r['r'] / $d * 100, 1), 'refused' => round($r['f'] / $d * 100, 1)];
        }

        // customer segments
        $S = [];
        $clean = [];
        $pool3 = ['n' => 0, 'no_app' => 0];
        foreach ($cust as $k => $u) {
            $seg = $u['cur_dpd'] > 30 ? 's_bad' : ($u['cur_dpd'] > 0 ? 's_late' : ($u['active_n'] > 0 ? 's_active'
                : (($u['closed_n'] > 0 && $u['closed_overdue_n'] === 0) ? 's_clean' : ($u['closed_n'] > 0 ? 's_closedlate'
                : ($u['single_n'] > 0 ? 's_single' : ($u['rejected_n'] > 0 ? 's_rejected' : 's_other'))))));
            $S[$seg] ??= ['n' => 0, 'out' => 0.0, 'apps' => 0, 'loans' => 0];
            $S[$seg]['n']++;
            $S[$seg]['out'] += $u['active_outstanding'];
            $S[$seg]['apps'] += $u['apps_n'];
            $S[$seg]['loans'] += $u['loans_n'];
            $lc = $lastClose[$k] ?? null;
            $after = $lc !== null && ($lastApp[$k] ?? PHP_INT_MIN) > $lc;
            if ($seg === 's_clean') {
                $clean[] = [$lc === null ? null : $T - $lc, $after];
            }
            if ($u['active_n'] === 0 && $u['closed_n'] > 0 && $u['max_delay'] <= 3) {
                $pool3['n']++;
                $pool3['no_app'] += $after ? 0 : 1;
            }
        }
        $segs = [];
        foreach (['s_active', 's_late', 's_bad', 's_clean', 's_closedlate', 's_single', 's_rejected', 's_other'] as $s) {
            $r = $S[$s] ?? ['n' => 0, 'out' => 0.0, 'apps' => 0, 'loans' => 0];
            $segs[] = ['k' => $s, 'n' => $r['n'], 'out' => (int) round($r['out']), 'apps' => $r['n'] ? round($r['apps'] / $r['n'], 2) : 0,
                'loans' => $r['n'] ? round($r['loans'] / $r['n'], 2) : 0];
        }
        $rep = [];
        foreach ([['r0_3', 0, 91], ['r3_6', 91, 183], ['r6_12', 183, 366], ['r12_24', 366, 731], ['r24', 731, PHP_INT_MAX]] as [$k, $lo, $hi]) {
            $cnt = $ap = 0;
            foreach ($clean as [$since, $after]) {
                if ($since !== null && $since >= $lo && $since < $hi) {
                    $cnt++;
                    $ap += $after ? 1 : 0;
                }
            }
            $rep[] = ['k' => $k, 'n' => $cnt, 'applied' => $ap, 'not_applied' => $cnt - $ap];
        }
        $cleanNoApp = 0;
        foreach ($clean as [, $after]) {
            $cleanNoApp += $after ? 0 : 1;
        }

        // back-test: previous loans CLOSED before the next one was issued vs that next loan
        $BT = [];
        $firstN = $firstBad = 0;
        foreach ($byKey as $lis) {
            usort($lis, fn ($a, $b) => [$L[$a]['date'] ?? PHP_INT_MAX, $a] <=> [$L[$b]['date'] ?? PHP_INT_MAX, $b]);
            foreach ($lis as $n => $li) {
                $cur = $L[$li];
                if ($cur['date'] === null || $T - $cur['date'] < 90) {
                    continue;
                }
                if ($n === 0) {
                    $firstN++;
                    $firstBad += $R[$li]['bad'] ? 1 : 0;
                    continue;
                }
                // previous loans closed before this one was issued; one with no schedule tells nothing, so it is ignored,
                // and a loan whose only closed predecessors are such is left out rather than called "clean"
                $w = null;
                $noInfo = false;
                for ($j = 0; $j < $n; $j++) {
                    $p = $L[$lis[$j]];
                    if (!$p['active'] && $p['close_date'] !== null && $p['close_date'] <= $cur['date']) {
                        if ($p['worst'] === null) {
                            $noInfo = true;
                        } else {
                            $w = max($w ?? 0, $p['worst']);
                        }
                    }
                }
                if ($w === null && $noInfo) {
                    continue;
                }
                $b = $w === null ? 'bt_open' : ($w <= 7 ? 'bt_0_7' : ($w <= 30 ? 'bt_8_30' : 'bt_31'));
                $BT[$b] ??= [0, 0];
                $BT[$b][0]++;
                $BT[$b][1] += $R[$li]['bad'] ? 1 : 0;
            }
        }
        $backtest = [['k' => 'bt_first', 'n' => $firstN, 'bad' => $firstN ? round($firstBad / $firstN * 100, 1) : null]];
        foreach (['bt_0_7', 'bt_8_30', 'bt_31', 'bt_open'] as $k) {
            [$cn, $cb] = $BT[$k] ?? [0, 0];
            $backtest[] = ['k' => $k, 'n' => $cn, 'bad' => $cn ? round($cb / $cn * 100, 1) : null];
        }

        return ['backtest' => $backtest, 'pool3' => $pool3, 'overall' => $overall, 'risk' => $risk, 'vint' => $vint, 'par' => $par,
            'funnel' => $funnel, 'appr' => $appr, 'segs' => $segs, 'rep' => $rep,
            'totals' => ['active_out' => (int) round($activeOut), 'active_n' => $activeN, 'clean_pool' => count($clean), 'clean_pool_no_app' => $cleanNoApp]];
    }

    // ================= page arrays (export.py) =================
    private function export(array $O, array $L, array $cust, array $an): array
    {
        $keys = array_keys($cust);
        $pos = array_flip($keys);
        sort($keys, SORT_STRING);
        $rank = array_flip($keys);
        $keys = array_keys($cust);
        usort($keys, fn ($a, $b) => [-$cust[$a]['active_outstanding'], -$cust[$a]['apps_n'], $rank[$a]] <=> [-$cust[$b]['active_outstanding'], -$cust[$b]['apps_n'], $rank[$b]]);
        $idx = array_flip($keys);
        unset($pos);
        $rows = [];
        foreach ($keys as $k) {
            $u = $cust[$k];
            $rows[] = [(string) $k, $u['pid'], $u['name'], $u['gender'], $u['dob'], implode(' | ', array_keys($u['phones'])), $u['fact_addr'],
                $u['ship_addr'], $u['work_place'], $u['position'], $u['employment'], $u['salary'], $u['extra_income'],
                $u['extra_income_amt'] === null ? null : round($u['extra_income_amt'], 2), $u['extra_income_source'], $u['obligation'],
                $u['obligation_amt'] === null ? null : round($u['obligation_amt'], 2), $u['apps_n'], $u['approved_n'], $u['loans_n'],
                $u['single_n'], $u['rejected_n'], $u['other_n'], $u['active_n'], round($u['active_outstanding'], 2),
                round($u['active_monthly'], 2), $u['active_term'], (int) $u['cur_dpd'], (int) $u['dpd_sum_all'], (int) $u['dpd_sum_active'],
                $u['closed_n'], $u['closed_clean_n'], $u['closed_overdue_n'], (int) $u['max_delay'], self::ymd($u['first_app']),
                self::ymd($u['last_app']), $u['key_type']];
        }
        $prod = [];
        $pi = function (string $name) use (&$prod): int {
            if (!isset($prod[$name])) {
                $prod[$name] = count($prod);
            }
            return $prod[$name];
        };
        $ao = array_keys($O);
        usort($ao, fn ($a, $b) => self::cmpApp($O[$a], $O[$b]));
        $apps = [];
        foreach ($ao as $i) {
            $o = $O[$i];
            $apps[] = [$idx[$o['key']], $o['id'], self::ymd($o['created']), $o['st'], $o['status'], $pi($o['product']),
                $o['price'] === null ? null : round($o['price'], 2), $o['legacy'], $o['rc']];
        }
        $lo = array_keys($L);
        usort($lo, fn ($a, $b) => strcmp((string) $L[$a]['key'], (string) $L[$b]['key']) ?: (($L[$a]['date'] ?? PHP_INT_MAX) <=> ($L[$b]['date'] ?? PHP_INT_MAX)) ?: $a <=> $b);
        $loans = [];
        foreach ($lo as $li) {
            $x = $L[$li];
            $o = $O[$x['oi']];
            $loans[] = [$idx[$x['key']], $x['id'], self::ymd($x['date']), $pi($o['product']), $o['price'] === null ? null : round($o['price'], 2),
                $o['adv'] === null ? null : round($o['adv'], 2), $x['sched_total'], $x['term'], $x['monthly'], $x['active'] ? 1 : 0,
                $x['active'] ? $x['outstanding'] : null, $x['cur_dpd'], $x['dpd_sum'], $x['worst'], self::ymd($x['close_date']), $x['close_kind'],
                $x['paid_total'], $x['penalty_paid'], $o['legacy'], $o['ct'], self::ymd($x['first_due']), self::ymd($x['last_due'])];
        }
        $meta = ['asOf' => $this->now->format('Y-m-d H:i'), 'today' => $this->now->format('Y-m-d'), 'C' => self::C_COLS, 'A' => self::A_COLS, 'L' => self::L_COLS,
            'P' => array_map('strval', array_keys($prod))];
        return ['meta' => $meta, 'an' => $an, 'cust' => $rows, 'apps' => $apps, 'loans' => $loans];
    }

    /** Writes the six window.C360_* scripts the page loads into $dir; returns their names. */
    public static function writeScripts(array $data, string $dir): array
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION;
        $half = intdiv(count($data['cust']), 2);
        $parts = [
            'meta' => ['C360_META', $data['meta']], 'analysis' => ['C360_AN', $data['an']],
            'cust1' => ['C360_C1', array_slice($data['cust'], 0, $half)], 'cust2' => ['C360_C2', array_slice($data['cust'], $half)],
            'apps' => ['C360_A', $data['apps']], 'loans' => ['C360_L', $data['loans']],
        ];
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        foreach ($parts as $name => [$var, $obj]) {
            file_put_contents($dir . '/' . $name . '.js', 'window.' . $var . '=' . json_encode($obj, $flags) . ";\n", LOCK_EX);
        }
        return array_keys($parts);
    }

    // ================= helpers =================
    /** Applications by customer key (as text), then application date (missing last), then numeric order id. */
    private static function cmpApp(array $a, array $b): int
    {
        return strcmp((string) $a['key'], (string) $b['key']) ?: (($a['created'] ?? PHP_INT_MAX) <=> ($b['created'] ?? PHP_INT_MAX)) ?: ((int) $a['id'] <=> (int) $b['id']);
    }

    private function q(string $sql): array
    {
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function day(string $ymd): int
    {
        return intdiv((int) gmmktime(0, 0, 0, (int) substr($ymd, 5, 2), (int) substr($ymd, 8, 2), (int) substr($ymd, 0, 4)), 86400);
    }

    private static function ymd(?int $day): string
    {
        return $day === null ? '' : gmdate('Y-m-d', $day * 86400);
    }

    private static function searchsorted(array $c, float $v): int
    {
        $lo = 0;
        $hi = count($c);
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($c[$mid] < $v) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        return $lo;
    }

    /** pandas Series.mode().iloc[0]: most frequent value, smallest on a tie. */
    private static function mode(array $vals): ?float
    {
        if (!$vals) {
            return null;
        }
        $cnt = [];
        foreach ($vals as $v) {
            $k = number_format($v, 2, '.', '');
            $cnt[$k] = ($cnt[$k] ?? 0) + 1;
        }
        $best = null;
        foreach ($cnt as $k => $n) {
            if ($best === null || $n > $cnt[$best] || ($n === $cnt[$best] && (float) $k < (float) $best)) {
                $best = (string) $k;
            }
        }
        return (float) $best;
    }

    private static function clean($v): string
    {
        if (!is_string($v)) {
            return $v === null ? '' : trim((string) $v, ' ,;.');
        }
        $v = trim(strtr($v, "\t\r\n", '   '), ' ,;.');
        if (!preg_match('/[0-9A-Za-z\x{10A0}-\x{10FF}]/u', $v)) {
            return '';
        }
        return in_array($v, ['NULL', '-', '--', '.', 'N/A', 'n/a', 'null', 'Option1', 'Option2', 'Option3', 'Option4', 'Option5'], true) ? '' : $v;
    }

    private static function pidNorm($v): string
    {
        $d = preg_replace('/\D/', '', (string) $v);
        return strlen($d) === 11 ? $d : '';
    }

    private static function phone9($v): string
    {
        $d = preg_replace('/\D/', '', (string) $v);
        if (str_starts_with($d, '995')) {
            $d = substr($d, 3);
        }
        return (strlen($d) === 9 && $d[0] === '5') ? $d : '';
    }

    private static function gender($v): string
    {
        $v = strtolower(trim((string) $v));   // ASCII-only lowering: the server has no mbstring, Georgian has no case here
        if (str_starts_with($v, 'მდედრ') || $v === 'female') {
            return 'ქალი';
        }
        if (str_starts_with($v, 'მამრ') || $v === 'male') {
            return 'კაცი';
        }
        return '';
    }

    private static function dob($v): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim((string) $v), $m)) {
            return '';
        }
        $y = (int) $m[1];
        return ($y >= 1930 && $y <= 2010) ? $m[0] : '';
    }

    private static function addr(array $r): string
    {
        $a = self::clean($r['address']);
        $c = self::clean($r['city']);
        return ($c !== '' && str_starts_with($a, $c)) ? $a : implode(', ', array_filter([$c, $a], 'strlen'));
    }

    private static function latest(array $vals): string
    {
        $out = '';
        foreach ($vals as $v) {
            if ($v !== '' && $v !== null) {
                $out = $v;
            }
        }
        return $out;
    }

    /** float('x') with the build.py limits (0 <= x < 1e7), else null. */
    private static function num($v): ?float
    {
        $v = str_replace([',', ' '], ['.', ''], (string) $v);
        if ($v === '' || !is_numeric($v)) {
            return null;
        }
        $x = (float) $v;
        return ($x >= 0 && $x < 1e7) ? $x : null;
    }

    /** analysis.py numv(): 0 <= x < 1e6. */
    private static function numv($v): ?float
    {
        $v = str_replace(',', '.', (string) $v);
        if ($v === '' || !is_numeric($v)) {
            return null;
        }
        $x = (float) $v;
        return ($x >= 0 && $x < 1e6) ? $x : null;
    }

    private static function salNum($v): ?float
    {
        preg_match_all('/\d+(?:\.\d+)?/', (string) $v, $m);
        $n = array_values(array_filter(array_map('floatval', $m[0]), fn ($x) => $x >= 50 && $x <= 50000));
        if (!$n) {
            return null;
        }
        $n = array_slice($n, 0, 2);
        return array_sum($n) / count($n);
    }

    /** Old forms put an amount in the yes/no field; returns [label, amount|null] like build.py yn_amount(). */
    private static function ynAmount(string $raw, string $amt): array
    {
        $r = trim($raw);
        $a = self::num($amt);
        if (isset(self::YES[$r])) {
            return ['კი', $a];
        }
        if (isset(self::NO[$r])) {
            return ['არა', ($a !== null && $a > 0) ? $a : null];
        }
        $x = self::num($r);
        if ($x !== null) {
            return [$x > 0 ? 'კი' : 'არა', $a ?? $x];
        }
        if ($r !== '') {
            return ['კი (' . $r . ')', $a];
        }
        return ($a !== null && $a > 0) ? ['კი', $a] : ['', null];
    }

    /** pd.cut(right=False) into labels; null or out of range -> 'unknown'. */
    private static function band(int|float|null $v, array $edges, array $labels): string
    {
        if ($v === null) {
            return 'unknown';
        }
        for ($i = 0; $i < count($labels); $i++) {
            if ($v >= $edges[$i] && $v < $edges[$i + 1]) {
                return $labels[$i];
            }
        }
        return 'unknown';
    }
}
