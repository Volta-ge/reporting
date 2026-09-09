<?php

declare(strict_types=1);

namespace Volta\Funnel;

use PDO;

/**
 * Customers → Customers Analyze, computed live from VoltaStoreDB: the PHP twin of
 * volta-analytics-new-db/pull_cust.sh + build_cust.js. Output shape = exactly CUST_JSON (cust_data.json).
 *
 * Same definitions as the pull script (identity = personal_ID typed on the application, else the account's
 * id_number, else account id / e-mail; application = orders row by created_at; active loan = crm_active=1 and
 * not pending; loan = status 5/99, closed, or active), aggregates only — the identity never leaves the query.
 *
 * Performance: the pull script runs its BASE CTE chain six times (~8 s each on the RDS replica, ~50 s total).
 * Here the chain is materialised ONCE in a single statement whose UNION ALL branches produce every table:
 *   - the application-form fields are LONGTEXT; they are CAST to VARCHAR (same collation) so every CTE stays an
 *     in-memory temp table (the LONGTEXT/TEXT columns were the main cost);
 *   - the shipping city is pre-aggregated (sh) instead of joined row-by-row; custb is forced to materialise once
 *     (LIMIT) instead of being re-joined in every branch; the application rank and the running loan count share one
 *     streamed unbounded-preceding window (rank = COUNT(*) over it, prior loans = SUM(loan) over it minus the row's own)
 *     instead of ROW_NUMBER plus a buffered "1 PRECEDING" frame;
 *   - the session join buffer is raised (and restored) so the rating lookup's hash join does not spill;
 *   - the tables whose value sets are closed literals (gender/age/risk/rating and emp/soc/sal/src, by customer and by
 *     month) come out of two cross-product GROUP BYs and are split per dimension here; city and job title keep their
 *     own ordered branches because their utf8mb4_unicode_ci order cannot be reproduced in PHP;
 *   - new-vs-returning comes by day from MONTH_START and is summed to months here.
 * Every number and every row order equals the pull script's (validated row-by-row against the six original queries).
 */
final class NewDbCust
{
    public const CUTOVER = '2026-08-31';
    /** first month of the by-month series (earlier applications count in the customer totals, not in the series) */
    private const MONTH_START = '2024-01-01';
    /** first day of the by-day series */
    private const DAY_START = '2026-08-01';
    /** session join_buffer_size (bytes) while the customers statement runs; restored afterwards */
    private const JOIN_BUFFER = 8388608;

    private const TYPES = ['1:New', '2:Returning (earlier application only)', '3:Returning (previous loan)'];
    private const RISK_ORDER = ['Low' => 1, 'Medium' => 2, 'High' => 3];
    private const WORST = [0 => 'Not computed', 1 => 'Max 3 days late', 2 => '4 - 9 days late', 3 => '10 - 30 days late', 4 => '31 - 60 days late', 5 => '61 - 90 days late', 6 => '90+ days late'];
    /** dims whose values are closed literal sets (they come out of the cross-product branches), in the branch column order */
    private const DIMS_A = ['gender', 'age', 'risk', 'rating'];
    private const DIMS_B = ['emp', 'soc', 'sal', 'src'];
    private const MON_A = ['gender', 'age', 'risk'];
    private const MON_B = ['emp', 'soc', 'sal'];
    private const MON_C = ['src', 'city'];

    private const CITY_EN = ['თბილისი' => 'Tbilisi', 'ბათუმი' => 'Batumi', 'რუსთავი' => 'Rustavi', 'ქუთაისი' => 'Kutaisi', 'ზუგდიდი' => 'Zugdidi', 'გორი' => 'Gori', 'მცხეთა' => 'Mtskheta', 'ფოთი' => 'Poti', 'ქობულეთი' => 'Kobuleti', 'ოზურგეთი' => 'Ozurgeti', 'გარდაბანი' => 'Gardabani', 'ხაშური' => 'Khashuri', 'სენაკი' => 'Senaki', 'გურჯაანი' => 'Gurjaani', 'სამტრედია' => 'Samtredia', 'ბოლნისი' => 'Bolnisi', 'წყალტუბო' => 'Tskaltubo', 'საგარეჯო' => 'Sagarejo', 'კასპი' => 'Kaspi', 'ზესტაფონი' => 'Zestafoni', 'თელავი' => 'Telavi', 'დუშეთი' => 'Dusheti', 'ახალციხე' => 'Akhaltsikhe', 'მარნეული' => 'Marneuli', 'ქარელი' => 'Kareli', 'ლანჩხუთი' => 'Lanchkhuti', 'ჭიათურა' => 'Chiatura', 'ტყიბული' => 'Tkibuli', 'ხონი' => 'Khoni', 'ბორჯომი' => 'Borjomi', 'აბაშა' => 'Abasha', 'მარტვილი' => 'Martvili', 'წალენჯიხა' => 'Tsalenjikha', 'სიღნაღი' => 'Sighnaghi', 'ლაგოდეხი' => 'Lagodekhi', 'ყვარელი' => 'Kvareli', 'ახმეტა' => 'Akhmeta', 'ხობი' => 'Khobi', 'ვანი' => 'Vani', 'თერჯოლა' => 'Terjola', 'საჩხერე' => 'Sachkhere', 'ხარაგაული' => 'Kharagauli', 'ბაღდათი' => 'Baghdati', 'ონი' => 'Oni', 'ამბროლაური' => 'Ambrolauri', 'მესტია' => 'Mestia', 'ახალქალაქი' => 'Akhalkalaki', 'თეთრიწყარო' => 'Tetritskaro', 'წალკა' => 'Tsalka', 'დმანისი' => 'Dmanisi', 'ჩხოროწყუ' => 'Chkhorotsku', 'ცაგერი' => 'Tsageri', 'ლენტეხი' => 'Lentekhi', 'ადიგენი' => 'Adigeni', 'ასპინძა' => 'Aspindza', 'ნინოწმინდა' => 'Ninotsminda', 'თიანეთი' => 'Tianeti', 'ხელვაჩაური' => 'Khelvachauri', 'ქედა' => 'Keda', 'შუახევი' => 'Shuakhevi', 'ხულო' => 'Khulo', 'სურამი' => 'Surami', 'წნორი' => 'Tsnori'];
    private const FORM_NOTE = ' Present on ~95% of web applications March&ndash;August 2026; since the 31 Aug 2026 cutover the new form records it on only a minority of applications (~15% in September), so the latest month is thin.';
    /** dimension catalogue = DIMS in build_cust.js (labels/notes verbatim; 'label' = cityLabel, 'sort' = count | risk | null) */
    private const DIMS = [
        ['key' => 'gender', 'title' => 'Gender', 'head' => 'Gender', 'unspecified' => 'Unspecified', 'monthly' => true, 'label' => false, 'sort' => null,
            'def' => 'Gender as answered on the application form (<code>volta_application_data.gender</code>; migrated applications carry the old CRM value, web applications the form value); the customer account\'s gender is the fallback.'],
        ['key' => 'age', 'title' => 'Age', 'head' => 'Age band', 'unspecified' => 'No birth date', 'monthly' => true, 'label' => false, 'sort' => null,
            'def' => 'Age from the birth date on the application form (<code>birth_date</code>; the account\'s <code>date_of_birth</code> as fallback) &mdash; never from the ID number. Customer columns = age today; the by-month table = age on the application date. Ages outside 14&ndash;100 are treated as missing.'],
        ['key' => 'city', 'title' => 'Geography (city of the shipping address)', 'head' => 'City', 'unspecified' => 'No city', 'monthly' => true, 'label' => true, 'sort' => 'count',
            'def' => 'City of the order\'s shipping address (<code>addresses.city</code>, <code>address_type = order_shipping</code>) &mdash; the 15 most frequent cities, the rest grouped as Other cities. The new DB has no region field.'],
        ['key' => 'emp', 'title' => 'Employment status', 'head' => 'Employment', 'unspecified' => 'Not on the form', 'monthly' => true, 'label' => false, 'sort' => 'count',
            'def' => 'Employment status selected on the web application form (<code>employment_status</code>: private sector / self-employed / public sector / unemployed). The field exists only on applications submitted through the web form since 12 March 2026; migrated applications do not carry it, hence "Not on the form".' . self::FORM_NOTE],
        ['key' => 'soc', 'title' => 'Social status', 'head' => 'Social status', 'unspecified' => 'Not on the form', 'monthly' => true, 'label' => false, 'sort' => 'count',
            'def' => 'Social status selected on the web application form (<code>social_status</code>: housewife / student / pensioner; left empty = none of these). On the form since 12 March 2026, like employment status.' . self::FORM_NOTE],
        ['key' => 'sal', 'title' => 'Monthly income (declared salary, GEL)', 'head' => 'Salary band', 'unspecified' => 'Not on the form', 'monthly' => true, 'label' => false, 'sort' => null,
            'def' => 'Salary declared on the web application form (<code>salary</code>, a number since February 2026; for ~1,200 March&ndash;April 2026 applications a range picked from a list, mapped to the same bands). Self-declared, not verified. Migrated applications do not carry it.' . self::FORM_NOTE],
        ['key' => 'src', 'title' => 'How the customer heard about Volta', 'head' => 'Source', 'unspecified' => 'Not on the form', 'monthly' => true, 'label' => false, 'sort' => 'count',
            'def' => '"How did you hear about us" on the web application form (<code>about_us_source</code>), on the form since January 2026. "Used Volta before" is the applicant\'s own answer &mdash; compare it with the New / Returning tables, which are computed from the application history.' . self::FORM_NOTE],
        ['key' => 'risk', 'title' => 'Risk status (CRM)', 'head' => 'Risk status', 'unspecified' => 'Not rated', 'monthly' => true, 'label' => false, 'sort' => 'risk',
            'def' => 'CRM risk status of the loan (<code>orders.crm_risk_status</code>: დაბალი / low = Low, საშუალო / medium = Medium, მაღალი = High; 0 / empty = not rated). It is set when a loan is issued, so applications that never became a loan are mostly "Not rated". Customer columns use the status of the customer\'s active loan (else the latest rated loan).'],
        ['key' => 'pos', 'title' => 'Job title (free text, top 15)', 'head' => 'Job title', 'unspecified' => 'Not on the form', 'monthly' => false, 'label' => false, 'sort' => 'count',
            'def' => 'Job title typed on the application form (<code>position</code>, ~7,600 distinct raw values) &mdash; the 15 most frequent exact values, everything else under Other. Not clustered on purpose (spelling variants stay separate).'],
        ['key' => 'rating', 'title' => 'Customer rating (CRM profile, 1&ndash;5)', 'head' => 'Rating', 'unspecified' => 'Not rated', 'monthly' => false, 'label' => false, 'sort' => null,
            'def' => 'Star rating on the CRM customer profile (<code>crm_customer_profile.rating</code>, migrated from the old CRM; 0 = not rated), linked to the customer through the account or the ID number. Only a small part of the base is rated &mdash; see the coverage line.'],
    ];

    /** @var array<string,float> seconds per query of the last build() */
    public array $timings = [];

    public function __construct(private readonly PDO $pdo, private readonly string $dir, private readonly string $mappingPath)
    {
    }

    /**
     * @param string $end accepted for the common constructor/build signature; the series runs through TODAY, exactly
     *                    like `pull_cust.sh` with its default END (the page labels the last day column "today")
     */
    public function build(string $end): array
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $this->timings = [];

        // ---- one statement for every customer/application table. The default 256 KB join buffer makes the hash join of the
        // 25k-row rating lookup (rt) spill and run 2-3x slower: raise it for this statement only, then put it back (the PDO
        // connection is shared with the other report groups).
        $t = microtime(true);
        $joinBuffer = (int) $this->pdo->query('SELECT @@session.join_buffer_size')->fetchColumn();
        try {
            if ($joinBuffer < self::JOIN_BUFFER) {
                $this->pdo->exec('SET SESSION join_buffer_size = ' . self::JOIN_BUFFER);
            }
            $stmt = $this->prepareRepeated($this->customersSql(), ['monthStart' => self::MONTH_START, 'end' => $today, 'cutover' => self::CUTOVER]);
            $stmt->execute();
            $rows = $stmt->fetchAll();
        } finally {
            if ($joinBuffer < self::JOIN_BUFFER) {
                $this->pdo->exec('SET SESSION join_buffer_size = ' . $joinBuffer);
            }
        }
        $this->timings['customers'] = microtime(true) - $t;

        // dims: dim => list of ['val' => string ('NULL' for null, as the mysql CLI prints it), all, active, loan] in the pull script's order
        $dims = [];
        // month: dim => val => m => n ; months = every month in the series
        $month = [];
        $monthsSet = [];
        $nrDay = [];
        $hist = ['apps' => [], 'loans' => []];
        $sumCust = null;
        $sumApp = null;
        $addDim = static function (array &$dims, string $dim, ?string $val, array $r): void {
            $key = $val ?? 'NULL';
            if (!isset($dims[$dim][$key])) {
                $dims[$dim][$key] = ['val' => $key, 'all' => 0, 'active' => 0, 'loan' => 0];
            }
            $dims[$dim][$key]['all'] += (int) $r['n1'];
            $dims[$dim][$key]['active'] += (int) $r['n2'];
            $dims[$dim][$key]['loan'] += (int) $r['n3'];
        };
        $addMonth = static function (array &$month, string $dim, ?string $val, string $m, int $n): void {
            $key = $val ?? 'NULL';
            $month[$dim][$key][$m] = ($month[$dim][$key][$m] ?? 0) + $n;
        };
        foreach ($rows as $r) {
            switch ($r['kind']) {
                case 'dimsA':
                    foreach (self::DIMS_A as $i => $dim) { $addDim($dims, $dim, $r['k' . ($i + 1)], $r); }
                    break;
                case 'dimsB':
                    foreach (self::DIMS_B as $i => $dim) { $addDim($dims, $dim, $r['k' . ($i + 1)], $r); }
                    break;
                case 'city':
                case 'pos':
                    $addDim($dims, $r['kind'], $r['k1'], $r);
                    break;
                case 'monA':
                    $monthsSet[$r['k1']] = true;
                    foreach (self::MON_A as $i => $dim) { $addMonth($month, $dim, $r['k' . ($i + 2)], $r['k1'], (int) $r['n1']); }
                    break;
                case 'monB':
                    foreach (self::MON_B as $i => $dim) { $addMonth($month, $dim, $r['k' . ($i + 2)], $r['k1'], (int) $r['n1']); }
                    break;
                case 'monC':
                    foreach (self::MON_C as $i => $dim) { $addMonth($month, $dim, $r['k' . ($i + 2)], $r['k1'], (int) $r['n1']); }
                    break;
                case 'nr':
                    $nrDay[$r['k1']][$r['k2']] = ['n' => (int) $r['n1'], 'active_n' => (int) $r['n2'], 'loan_n' => (int) $r['n3']];
                    break;
                case 'hist':
                    foreach (['apps' => 'k1', 'loans' => 'k2'] as $kind => $col) {
                        $b = $r[$col];
                        $hist[$kind][$b] = ['n' => ($hist[$kind][$b]['n'] ?? 0) + (int) $r['n1'], 'active_c' => ($hist[$kind][$b]['active_c'] ?? 0) + (int) $r['n2']];
                    }
                    break;
                case 'cust':
                    $sumCust = $r;
                    break;
                case 'app':
                    $sumApp = $r;
                    break;
            }
        }
        if ($sumCust === null || $sumApp === null) {
            throw new \RuntimeException('customers statement returned no summary rows');
        }
        // the cross-product dims are re-ordered like the pull script's ORDER BY val (utf8mb4_unicode_ci: NULL first, then
        // case-insensitive) — their values are closed ASCII literal sets, decided at the first characters
        $cmp = static fn (array $a, array $b): int => ($a['val'] === 'NULL' ? 0 : 1) <=> ($b['val'] === 'NULL' ? 0 : 1) ?: strcasecmp($a['val'], $b['val']);
        foreach (array_merge(self::DIMS_A, self::DIMS_B) as $dim) {
            $list = array_values($dims[$dim] ?? []);
            usort($list, $cmp);
            $dims[$dim] = $list;
        }
        $dims['city'] = array_values($dims['city'] ?? []);
        $dims['pos'] = array_values($dims['pos'] ?? []);

        $months = array_keys($monthsSet);
        sort($months, SORT_STRING);
        $curMonth = $months[count($months) - 1] ?? null;

        // ---- summary (cust_summary.tsv)
        $summary = [
            'customers' => (int) $sumCust['n1'], 'activeCustomers' => (int) $sumCust['n2'], 'loanCustomers' => (int) $sumCust['n3'],
            'applications' => (int) $sumCust['n4'], 'loans' => (int) $sumCust['n5'],
            'customersBeforeSeries' => (int) $sumCust['n6'], 'appsBeforeSeries' => (int) $sumApp['n1'],
            'firstApp' => substr((string) $sumApp['k1'], 0, 10), 'lastApp' => substr((string) $sumApp['k2'], 0, 16),
            'appsWithoutPid' => (int) $sumApp['n4'], 'activeLoans' => (int) $sumApp['n2'], 'appsSinceCutover' => (int) $sumApp['n3'],
        ];

        // ---- customer-level tables + by-month tables (rowsFor / monthFor)
        $dimsOut = [];
        foreach (self::DIMS as $d) {
            $rf = $this->rowsFor($d, $dims[$d['key']] ?? [], $month[$d['key']] ?? [], $curMonth);
            $dimsOut[] = [
                'key' => $d['key'], 'title' => $d['title'], 'head' => $d['head'], 'def' => $d['def'], 'unspecified' => $d['unspecified'], 'monthly' => $d['monthly'],
                'rows' => $rf['rows'], 'total' => $rf['total'], 'coverage' => $rf['coverage'],
                'months' => $d['monthly'] ? $this->monthFor($d, $rf['rows'], $month[$d['key']] ?? [], $months) : null,
            ];
        }

        // ---- new vs returning: by month (summed from the days) and by day (from DAY_START)
        $nrMonth = [];
        $nrDayOnly = [];
        ksort($nrDay, SORT_STRING);
        foreach ($nrDay as $d => $types) {
            $m = substr($d, 0, 7);
            foreach ($types as $type => $v) {
                foreach (['n', 'active_n', 'loan_n'] as $c) { $nrMonth[$m][$type][$c] = ($nrMonth[$m][$type][$c] ?? 0) + $v[$c]; }
            }
            if ($d >= self::DAY_START) { $nrDayOnly[$d] = $types; }
        }
        $newret = ['month' => $this->series($nrMonth), 'day' => $this->series($nrDayOnly)];

        // ---- applications / loans per customer
        $histOut = [];
        foreach (['apps', 'loans'] as $kind) {
            $hrows = [];
            foreach ($hist[$kind] as $bucket => $v) { $hrows[] = ['label' => (string) $bucket, 'all' => $v['n'], 'active' => $v['active_c']]; }
            usort($hrows, static fn ($a, $b) => ((int) $a['label'] ?: 0) <=> ((int) $b['label'] ?: 0));
            $histOut[$kind] = ['rows' => $hrows, 'total' => ['all' => array_sum(array_column($hrows, 'all')), 'active' => array_sum(array_column($hrows, 'active'))]];
        }

        // ---- payment behaviour (crm_customer_payment_stats) — same SQL as pull_cust.sh
        $t = microtime(true);
        $payRaw = $this->pdo->query("SELECT 'worst' metric, CAST(COALESCE(worst_bucket,0) AS CHAR) val, COUNT(*) n FROM crm_customer_payment_stats GROUP BY 2
   UNION ALL SELECT 'overdue', CAST(currently_overdue AS CHAR), COUNT(*) FROM crm_customer_payment_stats GROUP BY 2
   UNION ALL SELECT 'dpd', CASE WHEN currently_overdue=0 OR current_dpd<=0 THEN '0:Not overdue' WHEN current_dpd<=7 THEN '1:1 - 7 days' WHEN current_dpd<=30 THEN '2:8 - 30 days' WHEN current_dpd<=60 THEN '3:31 - 60 days' WHEN current_dpd<=90 THEN '4:61 - 90 days' ELSE '5:90+ days' END, COUNT(*) FROM crm_customer_payment_stats GROUP BY 2
   UNION ALL SELECT 'ontime', CASE WHEN settled_count=0 THEN '9:No settled instalment yet' WHEN on_time_rate>=90 THEN '1:90%+' WHEN on_time_rate>=70 THEN '2:70 - 89%' WHEN on_time_rate>=50 THEN '3:50 - 69%' WHEN on_time_rate>=25 THEN '4:25 - 49%' ELSE '5:< 25%' END, COUNT(*) FROM crm_customer_payment_stats GROUP BY 2
   UNION ALL SELECT 'coverage', 'stats_rows', COUNT(*) FROM crm_customer_payment_stats
   UNION ALL SELECT 'coverage', 'active_customers_by_account', COUNT(DISTINCT customer_id) FROM orders WHERE crm_active=1 AND crm_order_status<>4 AND customer_id IS NOT NULL
   UNION ALL SELECT 'coverage', 'active_customers_with_stats', COUNT(DISTINCT o.customer_id) FROM orders o JOIN crm_customer_payment_stats p ON p.customer_id=o.customer_id WHERE o.crm_active=1 AND o.crm_order_status<>4
   UNION ALL SELECT 'coverage', 'computed_from', DATE_FORMAT(MIN(computed_at),'%Y-%m-%d') FROM crm_customer_payment_stats
   UNION ALL SELECT 'coverage', 'computed_to', DATE_FORMAT(MAX(computed_at),'%Y-%m-%d') FROM crm_customer_payment_stats")->fetchAll();
        $this->timings['paystats'] = microtime(true) - $t;
        $cov = [];
        foreach ($payRaw as $r) { if ($r['metric'] === 'coverage') { $cov[$r['val']] = $r['n']; } }
        $paystats = [
            'worst' => $this->payTable($payRaw, 'worst', static fn (string $v) => self::WORST[(int) $v] ?? ('Bucket ' . $v)),
            'dpd' => $this->payTable($payRaw, 'dpd', [self::class, 'strip']),
            'ontime' => $this->payTable($payRaw, 'ontime', [self::class, 'strip']),
            'coverage' => ['rows' => (int) $cov['stats_rows'], 'activeByAccount' => (int) $cov['active_customers_by_account'], 'activeWithStats' => (int) $cov['active_customers_with_stats'], 'from' => $cov['computed_from'], 'to' => $cov['computed_to']],
        ];

        return [
            'summary' => $summary, 'months' => $months, 'curMonth' => $curMonth, 'dims' => $dimsOut, 'newret' => $newret, 'hist' => $histOut, 'paystats' => $paystats,
            'cutover' => self::CUTOVER, 'dayStart' => $newret['day']['keys'][0] ?? null, 'generatedAt' => gmdate('Y-m-d H:i') . ' UTC',
        ];
    }

    // ------------------------------------------------------------------ Node build twins

    /** rowsFor() in build_cust.js: customer-level rows of one dimension, ordered, with totals and coverage */
    private function rowsFor(array $dim, array $rows, array $monthByVal, ?string $curMonth): array
    {
        $out = [];
        foreach ($rows as $r) {
            $isNull = $r['val'] === 'NULL';
            $out[] = [
                'raw' => $r['val'], 'label' => $this->label($dim, $r['val']), 'all' => $r['all'], 'active' => $r['active'], 'loan' => $r['loan'],
                'mtd' => $curMonth === null ? 0 : ($monthByVal[$r['val']][$curMonth] ?? 0),
                'unspecified' => $isNull, 'other' => $r['val'] === 'Other cities' || $r['val'] === 'Other',
            ];
        }
        // ordering: prefixed keys by prefix; counts descending; risk Low/Medium/High; Unspecified (and Other) always last
        usort($out, static function (array $a, array $b) use ($dim): int {
            $d = ((int) $a['unspecified'] <=> (int) $b['unspecified']) ?: ((int) $a['other'] <=> (int) $b['other']);
            if ($d !== 0) { return $d; }
            if ($dim['sort'] === 'risk') { return (self::RISK_ORDER[$a['raw']] ?? 0) <=> (self::RISK_ORDER[$b['raw']] ?? 0); }
            if ($dim['sort'] === 'count') { return $b['all'] <=> $a['all']; }
            return ((self::sortKey($a['raw']) ?? 0) <=> (self::sortKey($b['raw']) ?? 0)) ?: strcmp($a['label'], $b['label']);
        });
        $tot = static fn (string $k): int => array_sum(array_column($out, $k));
        $cov = static function (string $k) use ($out, $tot): int|float {
            $t = $tot($k);
            $u = 0;
            foreach ($out as $r) { if ($r['unspecified']) { $u += $r[$k]; } }
            return $t ? self::num(($t - $u) / $t) : 0;
        };
        return [
            'rows' => $out,
            'total' => ['all' => $tot('all'), 'active' => $tot('active'), 'loan' => $tot('loan'), 'mtd' => $tot('mtd')],
            'coverage' => ['all' => $cov('all'), 'active' => $cov('active'), 'mtd' => $cov('mtd')],
        ];
    }

    /** monthFor() in build_cust.js: applications by month for the dimension's values (rowsFor order), zero-only rows dropped */
    private function monthFor(array $dim, array $orderedRows, array $monthByVal, array $months): array
    {
        $kept = [];
        foreach ($orderedRows as $r) {
            $vals = [];
            $any = false;
            foreach ($months as $m) {
                $n = $monthByVal[$r['raw']][$m] ?? 0;
                $vals[] = $n;
                if ($n > 0) { $any = true; }
            }
            if ($any) { $kept[] = ['label' => $this->label($dim, $r['raw']), 'unspecified' => $r['raw'] === 'NULL', 'vals' => $vals]; }
        }
        $total = [];
        foreach ($months as $i => $m) {
            $t = 0;
            foreach ($kept as $r) { $t += $r['vals'][$i]; }
            $total[] = $t;
        }
        return ['rows' => $kept, 'total' => $total];
    }

    /** series() in build_cust.js; $raw = key => type => [n, active_n, loan_n] */
    private function series(array $raw): array
    {
        $keys = array_keys($raw);
        sort($keys, SORT_STRING);
        $rows = [];
        foreach (self::TYPES as $t) {
            $row = ['label' => self::strip($t), 'apps' => [], 'loans' => [], 'active' => []];
            foreach ($keys as $k) {
                $row['apps'][] = $raw[$k][$t]['n'] ?? 0;
                $row['loans'][] = $raw[$k][$t]['loan_n'] ?? 0;
                $row['active'][] = $raw[$k][$t]['active_n'] ?? 0;
            }
            $rows[] = $row;
        }
        $sum = static function (string $col) use ($rows, $keys): array {
            $out = [];
            foreach ($keys as $i => $k) { $s = 0; foreach ($rows as $r) { $s += $r[$col][$i]; } $out[] = $s; }
            return $out;
        };
        return ['keys' => array_values($keys), 'rows' => $rows, 'total' => ['apps' => $sum('apps'), 'loans' => $sum('loans'), 'active' => $sum('active')]];
    }

    /** payTable() in build_cust.js */
    private function payTable(array $payRaw, string $metric, callable $labelOf): array
    {
        $rows = [];
        foreach ($payRaw as $r) {
            if ($r['metric'] === $metric) { $rows[] = ['raw' => (string) $r['val'], 'label' => $labelOf((string) $r['val']), 'n' => (int) $r['n']]; }
        }
        $k = static fn (string $v): float => (float) (self::sortKey($v) ?? $v);
        usort($rows, static fn ($a, $b) => $k($a['raw']) <=> $k($b['raw']));
        return ['rows' => $rows, 'total' => array_sum(array_column($rows, 'n'))];
    }

    private function label(array $dim, string $raw): string
    {
        if ($raw === 'NULL') { return $dim['unspecified']; }
        $v = self::strip($raw);
        if ($dim['label']) { return isset(self::CITY_EN[$v]) ? self::CITY_EN[$v] . ' (' . $v . ')' : $v; }
        return $v;
    }

    /** '3:25 - 34' -> '25 - 34' (the prefix only fixes the sort order) */
    private static function strip(string $v): string
    {
        return preg_replace('/^\d+:/', '', $v) ?? $v;
    }

    private static function sortKey(string $v): ?int
    {
        return preg_match('/^(\d+):/', $v, $m) ? (int) $m[1] : null;
    }

    private static function num(float|int $v): float|int
    {
        if (is_float($v) && floor($v) === $v && abs($v) < 1e15) {
            return (int) $v;
        }
        return $v;
    }

    /** native prepares cannot reuse a named placeholder: every occurrence of :name becomes its own :pN, all bound */
    private function prepareRepeated(string $sql, array $params): \PDOStatement
    {
        $bound = [];
        $sql = preg_replace_callback('/:(' . implode('|', array_map('preg_quote', array_keys($params))) . ')\b/', static function (array $m) use (&$bound, $params): string {
            $k = 'p' . count($bound);
            $bound[$k] = $params[$m[1]];
            return ':' . $k;
        }, $sql);
        $stmt = $this->pdo->prepare($sql);
        foreach ($bound as $k => $v) { $stmt->bindValue($k, $v); }
        return $stmt;
    }

    // ------------------------------------------------------------------ SQL

    /** The pull script's BASE chain (restructured for speed, same results) + every output table as UNION ALL branches. */
    private function customersSql(): string
    {
        // application-form fields (LONGTEXT) as VARCHAR, keeping the column collation so comparisons/grouping are unchanged
        $fv = static fn (string $code, string $col, string $expr = 'v.field_value', string $extra = '', int $len = 255): string =>
            "MAX(CASE WHEN v.field_code='$code'$extra THEN CAST($expr AS CHAR($len)) COLLATE utf8mb4_unicode_ci END) $col";
        $age = static fn (string $at): string => "CASE WHEN dob IS NULL OR TIMESTAMPDIFF(YEAR,dob,$at) NOT BETWEEN 14 AND 100 THEN NULL
         WHEN TIMESTAMPDIFF(YEAR,dob,$at)<18 THEN '1:< 18' WHEN TIMESTAMPDIFF(YEAR,dob,$at)<25 THEN '2:18 - 24' WHEN TIMESTAMPDIFF(YEAR,dob,$at)<35 THEN '3:25 - 34'
         WHEN TIMESTAMPDIFF(YEAR,dob,$at)<45 THEN '4:35 - 44' WHEN TIMESTAMPDIFF(YEAR,dob,$at)<60 THEN '5:45 - 59' ELSE '6:60+' END";
        // latest application's value as in the pull script; GROUP_CONCAT would be TEXT (group_concat_max_len > 512): cast to
        // VARCHAR, city/pos keeping the column collation (the literal-valued columns have the connection collation either way)
        $latest = static fn (string $c, int $len = 64, string $coll = '', string $ord = 'created_at DESC, id DESC'): string =>
            "CAST(SUBSTRING_INDEX(GROUP_CONCAT($c ORDER BY $ord SEPARATOR '|'),'|',1) AS CHAR($len))$coll $c";
        $uc = ' COLLATE utf8mb4_unicode_ci';
        $nr = "CASE WHEN rn=1 THEN '1:New' WHEN prior_loans>0 THEN '3:Returning (previous loan)' ELSE '2:Returning (earlier application only)' END";
        $win = 'created_at>=:monthStart AND created_at < DATE(:end) + INTERVAL 1 DAY';
        // every key column of the union in one type/collation (its ORDER BY = the pull script's ORDER BY val)
        $k = static fn (string $x): string => "CAST($x AS CHAR) COLLATE utf8mb4_unicode_ci";
        $m = $k("DATE_FORMAT(created_at,'%Y-%m')");

        $base = "WITH
x AS (SELECT o.id, o.created_at, o.customer_id, o.customer_email, o.crm_active, o.crm_order_status, o.crm_close_type, o.crm_risk_status,
    {$fv('personal_ID', 'pid', 'v.field_value', " AND v.field_value<>''")},
    {$fv('gender', 'g')}, {$fv('birth_date', 'dob_raw')}, {$fv('employment_status', 'emp')}, {$fv('social_status', 'soc')}, {$fv('salary', 'sal')}, {$fv('about_us_source', 'src')},
    {$fv('position', 'pos', 'TRIM(v.field_value)', " AND TRIM(v.field_value)<>''", 500)}
  FROM orders o
  LEFT JOIN volta_application_data v ON v.order_id=o.id AND v.field_code IN ('personal_ID','gender','birth_date','employment_status','social_status','salary','about_us_source','position')
  GROUP BY o.id),
sh AS (SELECT order_id, MIN(NULLIF(NULLIF(TRIM(city),''),'N/A')) city FROM addresses WHERE address_type='order_shipping' GROUP BY order_id),
rt AS (SELECT c.id_number pid, MAX(pr.rating) rating FROM customers c JOIN crm_customer_profile pr ON pr.customer_id=c.id WHERE c.id_number IS NOT NULL AND c.id_number<>'' GROUP BY c.id_number),
app0 AS (SELECT x.id, x.created_at,
    COALESCE(x.pid, NULLIF(c.id_number,''), CONCAT('c',x.customer_id), CONCAT('e',NULLIF(x.customer_email,'')), CONCAT('o',x.id)) ident,
    (x.crm_active=1 AND x.crm_order_status<>4) act,
    (x.crm_order_status IN (5,99) OR x.crm_close_type IS NOT NULL OR (x.crm_active=1 AND x.crm_order_status<>4)) loan,
    CASE WHEN g IN ('მდედრ.','female','Female') OR (g IS NULL AND c.gender IN ('მდედრ.','Female')) THEN 'Female'
         WHEN g IN ('მამრ.','male','Male') OR (g IS NULL AND c.gender IN ('მამრ.','Male')) THEN 'Male' END gender,
    CASE WHEN dob_raw LIKE '____-__-__' THEN STR_TO_DATE(dob_raw,'%Y-%m-%d')
         WHEN dob_raw LIKE '__.__.____' THEN STR_TO_DATE(dob_raw,'%d.%m.%Y')
         WHEN dob_raw LIKE '__/__/____' THEN STR_TO_DATE(dob_raw,'%d/%m/%Y')
         WHEN c.date_of_birth > '1900-01-01' THEN c.date_of_birth END dob,
    CASE WHEN a.city='Tbilisi' THEN 'თბილისი' ELSE a.city END city,
    CASE WHEN emp='კერძო' THEN 'Private sector' WHEN emp='თვითდასაქმებული' THEN 'Self-employed' WHEN emp='საჯარო' THEN 'Public sector'
         WHEN emp='დაუსაქმებელი' THEN 'Unemployed' WHEN emp IS NOT NULL THEN 'Not specified' END emp,
    CASE WHEN soc='დიასახლისი' THEN 'Housewife' WHEN soc='სტუდენტი' THEN 'Student' WHEN soc='პენსიონერი' THEN 'Pensioner'
         WHEN soc IS NOT NULL THEN 'None of these' END soc,
    CASE WHEN sal REGEXP '^[0-9]+([.][0-9]+)?$' THEN
           CASE WHEN sal+0=0 THEN '0:0 (no salary)' WHEN sal+0<500 THEN '1:< 500' WHEN sal+0<1000 THEN '2:500 - 999' WHEN sal+0<2000 THEN '3:1,000 - 1,999'
                WHEN sal+0<3000 THEN '4:2,000 - 2,999' WHEN sal+0<5000 THEN '5:3,000 - 4,999' ELSE '6:5,000+' END
         WHEN sal='500-1000' THEN '2:500 - 999' WHEN sal='1000_2000' THEN '3:1,000 - 1,999' WHEN sal='2000-3000' THEN '4:2,000 - 2,999'
         WHEN sal IN ('3000-4000','4000-5000') THEN '5:3,000 - 4,999' WHEN sal='5000-მეტი' THEN '6:5,000+'
         WHEN sal IS NOT NULL AND sal<>'' THEN '7:Not specified' END sal,
    CASE WHEN src IN ('Facebook','Instagram','TikTok','Google') THEN src WHEN src='მეგობრის / ახლობლის რეკომენდაციით' THEN 'Friend / family recommendation'
         WHEN src='ადრეც ვსარგებლობდი ვოლტას განვადებით' THEN 'Used Volta before' WHEN src='გარე სარეკლამო ბანერით' THEN 'Outdoor banner'
         WHEN src IS NOT NULL AND src<>'' THEN 'Other' END src,
    CASE WHEN x.crm_risk_status IN ('საშუალო','medium','Medium') THEN 'Medium' WHEN x.crm_risk_status IN ('დაბალი','low','Low') THEN 'Low'
         WHEN x.crm_risk_status IN ('მაღალი','high','High') THEN 'High' END risk,
    x.pos, COALESCE(NULLIF(rt.rating,0), NULLIF(pr2.rating,0)) rating
  FROM x LEFT JOIN sh a ON a.order_id=x.id LEFT JOIN customers c ON c.id=x.customer_id
  LEFT JOIN rt ON rt.pid=COALESCE(x.pid, NULLIF(c.id_number,'')) LEFT JOIN crm_customer_profile pr2 ON pr2.customer_id=x.customer_id),
app AS (SELECT a.*, COUNT(*) OVER w rn, SUM(loan) OVER w - COALESCE(loan,0) prior_loans,
    {$age('created_at')} age
  FROM app0 a WINDOW w AS (PARTITION BY ident ORDER BY created_at, id ROWS UNBOUNDED PRECEDING)),
topc AS (SELECT city FROM app WHERE city IS NOT NULL GROUP BY city ORDER BY COUNT(*) DESC LIMIT 15),
topp AS (SELECT pos FROM app WHERE pos IS NOT NULL GROUP BY pos ORDER BY COUNT(*) DESC LIMIT 15),
cust AS (SELECT ident, MAX(act) act, MAX(loan) has_loan, COUNT(*) apps, SUM(loan) loans, MAX(rating) rating, MIN(created_at) first_at,
    {$latest('gender')}, {$latest('dob')}, {$latest('city', 255, $uc)}, {$latest('emp')}, {$latest('soc')}, {$latest('sal')}, {$latest('src')}, {$latest('pos', 500, $uc)},
    {$latest('risk', 64, '', 'act DESC, created_at DESC, id DESC')}
  FROM app GROUP BY ident),
custb AS (SELECT c.*, {$age('CURDATE()')} age,
    COALESCE(t.city, CASE WHEN c.city IS NULL THEN NULL ELSE 'Other cities' END) cityg,
    COALESCE(p.pos, CASE WHEN c.pos IS NULL THEN NULL ELSE 'Other' END) posg
  FROM cust c LEFT JOIN topc t ON t.city=c.city LEFT JOIN topp p ON p.pos=c.pos LIMIT 18446744073709551615)";

        $parts = [
            // 1) customer-level distribution per dimension (all / with active loan / ever had a loan)
            "SELECT 1 seq, 'dimsA' kind, {$k('gender')} k1, {$k('age')} k2, {$k('risk')} k3, {$k('rating')} k4, COUNT(*) n1, SUM(act) n2, SUM(has_loan) n3, NULL n4, NULL n5, NULL n6 FROM custb GROUP BY 3,4,5,6",
            "SELECT 1, 'dimsB', {$k('emp')}, {$k('soc')}, {$k('sal')}, {$k('src')}, COUNT(*), SUM(act), SUM(has_loan), NULL, NULL, NULL FROM custb GROUP BY 3,4,5,6",
            "SELECT 1, 'city', {$k('cityg')}, NULL, NULL, NULL, COUNT(*), SUM(act), SUM(has_loan), NULL, NULL, NULL FROM custb GROUP BY 3",
            "SELECT 1, 'pos', {$k('posg')}, NULL, NULL, NULL, COUNT(*), SUM(act), SUM(has_loan), NULL, NULL, NULL FROM custb GROUP BY 3",
            // 2) applications by calendar month per dimension (the application's own value; age at the application date)
            "SELECT 2, 'monA', $m, {$k('gender')}, {$k('age')}, {$k('risk')}, COUNT(*), NULL, NULL, NULL, NULL, NULL FROM app WHERE $win GROUP BY 3,4,5,6",
            "SELECT 2, 'monB', $m, {$k('emp')}, {$k('soc')}, {$k('sal')}, COUNT(*), NULL, NULL, NULL, NULL, NULL FROM app WHERE $win GROUP BY 3,4,5,6",
            "SELECT 2, 'monC', {$k("DATE_FORMAT(a.created_at,'%Y-%m')")}, {$k('a.src')}, {$k("COALESCE(t.city, CASE WHEN a.city IS NULL THEN NULL ELSE 'Other cities' END)")}, NULL, COUNT(*), NULL, NULL, NULL, NULL, NULL FROM app a LEFT JOIN topc t ON t.city=a.city WHERE a.created_at>=:monthStart AND a.created_at < DATE(:end) + INTERVAL 1 DAY GROUP BY 3,4,5",
            // 3) new vs returning applicants by day (months = sum of the days)
            "SELECT 3, 'nr', {$k("DATE_FORMAT(created_at,'%Y-%m-%d')")}, {$k($nr)}, NULL, NULL, COUNT(*), SUM(act), SUM(loan), NULL, NULL, NULL FROM app WHERE $win GROUP BY 3,4",
            // 4) applications / loans per customer
            "SELECT 4, 'hist', {$k("CASE WHEN apps>=5 THEN '5+' ELSE CAST(apps AS CHAR) END")}, {$k("CASE WHEN loans>=5 THEN '5+' ELSE CAST(loans AS CHAR) END")}, NULL, NULL, COUNT(*), SUM(act), NULL, NULL, NULL, NULL FROM cust GROUP BY 3,4",
            // 5) totals
            "SELECT 5, 'cust', NULL, NULL, NULL, NULL, COUNT(*), SUM(act), SUM(has_loan), SUM(apps), SUM(loans), SUM(first_at < :monthStart) FROM cust",
            "SELECT 5, 'app', {$k('MIN(created_at)')}, {$k('MAX(created_at)')}, NULL, NULL, SUM(created_at < :monthStart), SUM(act), SUM(created_at >= :cutover), SUM(ident LIKE 'c%' OR ident LIKE 'e%' OR ident LIKE 'o%'), NULL, NULL FROM app",
        ];
        return $base . "\n" . implode("\nUNION ALL ", $parts) . "\nORDER BY 1, 2, 3, 4, 5, 6";
    }
}
