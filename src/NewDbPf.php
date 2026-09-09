<?php

declare(strict_types=1);

namespace Volta\Funnel;

use PDO;

/**
 * Portfolio -> Portfolio Analyze for Volta_Analytics_New DB, computed live: the PHP twin of
 * volta-analytics-new-db/pull_pf.sh (SQL) + build_pf.js (aggregation), so build() returns exactly the structure of
 * pf_data.json / `const PF_JSON = …;` in deals_amount_migration.html.
 *
 * The loan book = every installment loan ever disbursed (crm_order_status 5, or 1 on rows migrated from the old CRM;
 * crm_active 1 = active, 0 = closed); kind = active | paid (crm_close_type 1) | wo (2) | norec (closed without a close
 * record); closed_at = a sane crm_close_date, else the last non-reversed crm_payments date. Stocks per day (CUTOVER..END)
 * and per month-end (MSTART..END, last point = END = MTD); balance at D = today's schedule balance + principal paid after
 * D (reversed payments and the 99,999,999.99 placeholder excluded).
 *
 * Performance: pull_pf.sh runs the book CTE in 7 separate statements (~1.2 s each on the RDS = 12.6 s in PHP). Here every
 * book-derived aggregate is one branch of a single UNION ALL statement over ONE `book` CTE (MySQL 8 materializes a CTE
 * referenced more than once exactly once), tagged by column `q`; the per-branch SQL and the row order (ORDER BY q, d, k
 * = each pull query's own ORDER BY) are unchanged, so the numbers are identical.
 *
 * MySQL 8.0.45 quirk (verified by bisecting, 2026-09-09): once a UNION branch reads the shared `book` with
 * `WHERE kind = 'active'`, a LATER branch that groups `book` by the raw `kind` column comes back split into ~2,100
 * bogus groups (the constant leaks into that GROUP BY). Grouping by the collated expression (positional `GROUP BY 3`)
 * is immune, and so is any branch placed before the active-only ones. Hence: whole-book branches first, `book_*`
 * (active-only) branches last, and the `close` / `quality` branches group by column position. Same groups, same numbers.
 *
 * END is the pull script's END (its default is today): the tab labels the last column "(today)", so pass today's date,
 * not yesterday's, to reproduce the Node pipeline.
 */
final class NewDbPf
{
    public const CUTOVER = '2026-08-31';
    /** month series / structure tables start here (2025 is the first year fully covered) */
    private const MSTART = '2025-01-01';

    private const SEG = "CASE WHEN EXISTS (SELECT 1 FROM order_items oi JOIN product_flat pf ON pf.product_id=oi.product_id AND pf.locale='ka_GE' WHERE oi.order_id=o.id AND (pf.name LIKE 'ტელეფონი%' OR pf.name LIKE 'ტელევიზორ%')) OR o.base_grand_total>2500 THEN 'A' ELSE 'B' END";

    /** the loan book CTEs (sched, pay, book) — verbatim from pull_pf.sh */
    private const BOOK = "sched AS (SELECT s.installment_id, SUM(s.schedule_amount) tot, SUM(s.schedule_amount - s.paid_amount) rem, SUM(DATEDIFF(s.schedule_date, DATE(o.crm_creator_date)) > 7) term
        FROM crm_installment_schedules s JOIN orders o ON o.id = s.installment_id GROUP BY s.installment_id),
 pay AS (SELECT installment_id, MAX(payment_date) last_pay FROM crm_payments WHERE reversed_at IS NULL GROUP BY installment_id),
 book AS (SELECT o.id, o.crm_creator_date disb, o.base_grand_total price, COALESCE(o.crm_advance_amount, 0) adv,
     CASE WHEN s.tot IS NULL THEN o.base_grand_total WHEN s.tot + COALESCE(o.crm_advance_amount, 0) <= o.base_grand_total + 0.01 THEN s.tot + COALESCE(o.crm_advance_amount, 0) ELSE s.tot END amount,
     COALESCE(s.rem, o.grand_total) rem, COALESCE(s.term, 0) term, o.crm_remaining_months rm, COALESCE(o.crm_risk_status, '') risk,
     CASE WHEN o.crm_active = 1 THEN 'active' WHEN o.crm_close_type = 2 THEN 'wo' WHEN o.crm_close_type = 1 THEN 'paid' ELSE 'norec' END kind,
     CASE WHEN o.crm_active = 1 THEN NULL WHEN o.crm_close_type IN (1, 2) AND o.crm_close_date >= o.crm_creator_date AND YEAR(o.crm_close_date) >= 2019 THEN o.crm_close_date
          ELSE COALESCE(p.last_pay, o.crm_close_date, o.crm_creator_date) END closed_at,
     " . self::SEG . " seg,
     CASE WHEN a.city IS NULL OR a.city = '' THEN 'Without City' WHEN a.city IN ('თბილისი', 'Tbilisi') THEN 'Tbilisi' ELSE 'Other Cities' END city,
     (SELECT oi.product_id FROM order_items oi WHERE oi.order_id = o.id ORDER BY oi.base_total DESC, oi.id LIMIT 1) top_product_id
   FROM orders o LEFT JOIN sched s ON s.installment_id = o.id LEFT JOIN pay p ON p.installment_id = o.id
   LEFT JOIN (SELECT order_id, MIN(city) city FROM addresses WHERE address_type = 'order_shipping' GROUP BY order_id) a ON a.order_id = o.id
   WHERE o.crm_order_status IN (1, 5) AND o.crm_active IN (0, 1) AND o.crm_creator_date IS NOT NULL)";

    /** the contract-amount band (1..6), used by the structure and active-book tables */
    private const AMT_BAND_SQL = "CASE WHEN amount < 500 THEN '1' WHEN amount < 1000 THEN '2' WHEN amount < 2000 THEN '3' WHEN amount < 3000 THEN '4' WHEN amount < 5000 THEN '5' ELSE '6' END";
    private const K = ' COLLATE utf8mb4_unicode_ci';

    private const AMT_BANDS = [1 => '< 500 GEL', 2 => '500 – 999 GEL', 3 => '1,000 – 1,999 GEL', 4 => '2,000 – 2,999 GEL', 5 => '3,000 – 4,999 GEL', 6 => '5,000+ GEL'];
    private const TERM_BANDS = ['1–3 months', '4–6 months', '7–9 months', '10 months', '11–12 months', '13+ months', 'No schedule'];
    private const AGE_BANDS = ['0–1 months', '2–3 months', '4–6 months', '7–9 months', '10–12 months', '13+ months'];
    private const RISK_ORDER = ['Low (დაბალი)', 'Medium (საშუალო)', 'High (მაღალი)', 'Not scored'];
    private const RM_ORDER = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11+', 'Unknown'];
    private const CITY_ORDER = ['Tbilisi', 'Other Cities', 'Without City'];
    private const SEG_LABEL = ['A' => 'Segment A (phone / TV or > 2,500 GEL)', 'B' => 'Segment B (other)'];
    private const SEG_ORDER = ['Segment A (phone / TV or > 2,500 GEL)', 'Segment B (other)'];
    private const KIND_LABEL = ['active' => 'Still active', 'paid' => 'Paid off (recorded close)', 'norec' => 'Paid off (no close record)', 'wo' => 'Written off'];

    /** @var array<string, float> seconds per query, filled by build() (name => seconds) */
    public array $timings = [];

    /** @var array<string, array{categoryGe:string,subcategoryGe:string,categoryEn:string,subcategoryEn:string,productEn:string}> */
    private array $mapping = [];

    public function __construct(private readonly PDO $pdo, private readonly string $dir, private readonly string $mappingPath)
    {
    }

    /** @param string $end the report END date (YYYY-MM-DD) = the last day of the day series / the MTD point of the month series */
    public function build(string $end): array
    {
        $this->timings = [];
        $cutover = self::CUTOVER;
        $mstart = self::MSTART;

        // ---- one statement, one `book`: every branch = one pull_pf.sh query, tagged by q; uniform columns
        //      q, d, k, n, amount, price, rem, act, c1..c5 (branch-specific extras, cast to CHAR)
        $K = self::K;
        $amtBand = self::AMT_BAND_SQL;
        $sql = "WITH RECURSIVE cald AS (SELECT DATE(:cutover) d UNION ALL SELECT d + INTERVAL 1 DAY FROM cald WHERE d < DATE(:endCal)),
 calm AS (SELECT LEAST(LAST_DAY(DATE(:mstart)), DATE(:end1)) d UNION ALL SELECT LEAST(LAST_DAY(d + INTERVAL 1 DAY), DATE(:end2)) FROM calm WHERE d < DATE(:end3)),
 cal AS (SELECT 'day' s, d FROM cald UNION ALL SELECT 'month' s, d FROM calm),
 " . self::BOOK . ",
 act AS (SELECT cal.s, cal.d, b.id, b.rem, b.amount, b.price FROM cal JOIN book b ON b.disb < cal.d + INTERVAL 1 DAY AND (b.closed_at IS NULL OR b.closed_at >= cal.d + INTERVAL 1 DAY)),
 pa AS (SELECT a.s, a.d, SUM(p.principal_part) paid_after FROM act a JOIN crm_payments p ON p.installment_id = a.id AND p.reversed_at IS NULL AND p.amount < 1000000 AND p.payment_date > a.d AND p.payment_date <= DATE(:endPa) GROUP BY a.s, a.d),
 m AS (SELECT b.*, DATE_FORMAT(b.disb, '%Y-%m') mo FROM book b WHERE b.disb >= :mstartM AND DATE(b.disb) <= :endM),
 a AS (SELECT b.* FROM book b WHERE b.kind = 'active')
 -- stocks at the end of each day (cutover .. END) and each month-end (MSTART .. END, last point = END = MTD)
 SELECT CONCAT('stock_', a.s) q, a.d d, ''$K k, COUNT(*) n, ROUND(SUM(a.amount), 2) amount, ROUND(SUM(a.price), 2) price, ROUND(SUM(a.rem), 2) rem, NULL act, CAST(ROUND(COALESCE(MAX(pa.paid_after), 0), 2) AS CHAR) c1, NULL c2, NULL c3, NULL c4, NULL c5
   FROM act a LEFT JOIN pa ON pa.s = a.s AND pa.d = a.d GROUP BY a.s, a.d
 -- flows by day (MSTART .. END): disbursed loans keyed to the disbursement date
 UNION ALL SELECT 'disb', DATE(b.disb), ''$K, COUNT(*), ROUND(SUM(b.amount), 2), ROUND(SUM(b.price), 2), NULL, NULL, CAST(SUM(b.term) AS CHAR), CAST(SUM(b.adv > 0) AS CHAR), CAST(ROUND(SUM(b.adv), 2) AS CHAR), CAST(SUM(b.seg = 'A') AS CHAR), NULL
   FROM book b WHERE b.disb >= :mstartD AND DATE(b.disb) <= :endD GROUP BY DATE(b.disb)
 -- closed loans keyed to closed_at, by kind
 UNION ALL SELECT 'close', DATE(b.closed_at), b.kind$K, COUNT(*), ROUND(SUM(b.amount), 2), ROUND(SUM(b.price), 2), ROUND(SUM(b.rem), 2), NULL, NULL, NULL, NULL, NULL, NULL
   FROM book b WHERE b.kind <> 'active' AND b.closed_at >= :mstartC AND DATE(b.closed_at) <= :endC GROUP BY 2, 3
 -- single-payment sales (crm_order_status 99) — memo row, not part of the loan book
 UNION ALL SELECT 'single', DATE(o.crm_creator_date), ''$K, COUNT(*), NULL, ROUND(SUM(o.base_grand_total), 2), NULL, NULL, NULL, NULL, NULL, NULL, NULL
   FROM orders o WHERE o.crm_order_status = 99 AND o.crm_creator_date >= :mstartS AND DATE(o.crm_creator_date) <= :endS GROUP BY DATE(o.crm_creator_date)
 -- data-quality memo (whole book): how each kind is recorded. Kept BEFORE the active-only branches and grouped by the
 -- collated expression (column 3), not the raw column: see the MySQL note in the class docblock
 UNION ALL SELECT 'quality', '', b.kind$K, COUNT(*), NULL, NULL, ROUND(SUM(b.rem), 2), NULL, CAST(SUM(b.rem <= 1) AS CHAR), CAST(MIN(DATE(b.disb)) AS CHAR), CAST(MAX(DATE(b.disb)) AS CHAR), CAST(MIN(DATE(b.closed_at)) AS CHAR), CAST(MAX(DATE(b.closed_at)) AS CHAR)
   FROM book b GROUP BY 3
 UNION ALL SELECT 'quality2', '', ''$K, NULL, NULL, NULL, NULL, NULL, CAST(SUM(o.crm_close_type IN (1,2) AND (o.crm_close_date < o.crm_creator_date OR YEAR(o.crm_close_date) < 2019)) AS CHAR), CAST(SUM(o.crm_active = 1 AND o.crm_order_status = 5 AND o.grand_total <= 0) AS CHAR), CAST(SUM(o.crm_order_status = 99) AS CHAR), CAST(SUM(o.crm_close_type IN (1,2) AND o.crm_close_date >= :cutoverQ) AS CHAR), NULL
   FROM orders o
 -- structure of the loans disbursed each month: dim, month, key, n, amount, rem (balance left today), act (still active)
 UNION ALL SELECT 'struct_term', mo, CAST(term AS CHAR)$K, COUNT(*), ROUND(SUM(amount), 2), NULL, ROUND(SUM(rem), 2), SUM(kind = 'active'), NULL, NULL, NULL, NULL, NULL FROM m GROUP BY 2, 3
 UNION ALL SELECT 'struct_amt', mo, $amtBand$K, COUNT(*), ROUND(SUM(amount), 2), NULL, ROUND(SUM(rem), 2), SUM(kind = 'active'), NULL, NULL, NULL, NULL, NULL FROM m GROUP BY 2, 3
 UNION ALL SELECT 'struct_seg', mo, seg$K, COUNT(*), ROUND(SUM(amount), 2), NULL, ROUND(SUM(rem), 2), SUM(kind = 'active'), NULL, NULL, NULL, NULL, NULL FROM m GROUP BY 2, 3
 UNION ALL SELECT 'struct_city', mo, city$K, COUNT(*), ROUND(SUM(amount), 2), NULL, ROUND(SUM(rem), 2), SUM(kind = 'active'), NULL, NULL, NULL, NULL, NULL FROM m GROUP BY 2, 3
 UNION ALL SELECT 'struct_risk', mo, risk$K, COUNT(*), ROUND(SUM(amount), 2), NULL, ROUND(SUM(rem), 2), SUM(kind = 'active'), NULL, NULL, NULL, NULL, NULL FROM m GROUP BY 2, 3
 UNION ALL SELECT 'struct_goods', mo, CAST(top_product_id AS CHAR)$K, COUNT(*), ROUND(SUM(amount), 2), NULL, ROUND(SUM(rem), 2), SUM(kind = 'active'), NULL, NULL, NULL, NULL, NULL FROM m GROUP BY 2, 3
 UNION ALL SELECT 'struct_kind', mo, kind$K, COUNT(*), ROUND(SUM(amount), 2), NULL, ROUND(SUM(rem), 2), SUM(kind = 'active'), NULL, NULL, NULL, NULL, NULL FROM m GROUP BY 2, 3
 -- the active book today: dim, key, n, amount, rem
 UNION ALL SELECT 'book_rm', '', CAST(COALESCE(rm, -1) AS CHAR)$K, COUNT(*), ROUND(SUM(amount), 2), NULL, ROUND(SUM(rem), 2), NULL, NULL, NULL, NULL, NULL, NULL FROM a GROUP BY 3
 UNION ALL SELECT 'book_age', '', CAST(TIMESTAMPDIFF(MONTH, disb, DATE(:endAge)) AS CHAR)$K, COUNT(*), ROUND(SUM(amount), 2), NULL, ROUND(SUM(rem), 2), NULL, NULL, NULL, NULL, NULL, NULL FROM a GROUP BY 3
 UNION ALL SELECT 'book_risk', '', risk$K, COUNT(*), ROUND(SUM(amount), 2), NULL, ROUND(SUM(rem), 2), NULL, NULL, NULL, NULL, NULL, NULL FROM a GROUP BY 3
 UNION ALL SELECT 'book_term', '', CAST(term AS CHAR)$K, COUNT(*), ROUND(SUM(amount), 2), NULL, ROUND(SUM(rem), 2), NULL, NULL, NULL, NULL, NULL, NULL FROM a GROUP BY 3
 UNION ALL SELECT 'book_amt', '', $amtBand$K, COUNT(*), ROUND(SUM(amount), 2), NULL, ROUND(SUM(rem), 2), NULL, NULL, NULL, NULL, NULL, NULL FROM a GROUP BY 3
 UNION ALL SELECT 'book_seg', '', seg$K, COUNT(*), ROUND(SUM(amount), 2), NULL, ROUND(SUM(rem), 2), NULL, NULL, NULL, NULL, NULL, NULL FROM a GROUP BY 3
 UNION ALL SELECT 'book_city', '', city$K, COUNT(*), ROUND(SUM(amount), 2), NULL, ROUND(SUM(rem), 2), NULL, NULL, NULL, NULL, NULL, NULL FROM a GROUP BY 3
 UNION ALL SELECT 'book_goods', '', CAST(top_product_id AS CHAR)$K, COUNT(*), ROUND(SUM(amount), 2), NULL, ROUND(SUM(rem), 2), NULL, NULL, NULL, NULL, NULL, NULL FROM a GROUP BY 3
 ORDER BY q, d, k";
        $all = $this->q('book_all', $sql, ['cutover' => $cutover, 'endCal' => $end, 'mstart' => $mstart, 'end1' => $end, 'end2' => $end, 'end3' => $end, 'endPa' => $end,
            'mstartM' => $mstart, 'endM' => $end, 'mstartD' => $mstart, 'endD' => $end, 'mstartC' => $mstart, 'endC' => $end, 'mstartS' => $mstart, 'endS' => $end, 'endAge' => $end, 'cutoverQ' => $cutover]);
        $byQ = [];
        foreach ($all as $r) { $byQ[$r['q']][] = $r; }
        $pick = static fn (string $q): array => $byQ[$q] ?? [];

        // ---- stocks: n, rem_now (rem), contract (amount), price, paid_after (c1)
        $stockOf = static function (array $rows): array {
            $o = ['dates' => [], 'active' => [], 'balance' => [], 'contract' => [], 'price' => []];
            foreach ($rows as $r) {
                $o['dates'][] = (string) $r['d'];
                $o['active'][] = (int) $r['n'];
                $o['balance'][] = self::r2((float) $r['rem'] + (float) $r['c1']);
                $o['contract'][] = self::num((float) $r['amount']);
                $o['price'][] = self::num((float) $r['price']);
            }
            return $o;
        };
        $stockDay = $stockOf($pick('stock_day'));
        $stockMonth = $stockOf($pick('stock_month'));
        $stockMonth['months'] = array_map(static fn ($d) => substr($d, 0, 7), $stockMonth['dates']);
        $END = (string) end($stockDay['dates']);
        $MSTART = $stockMonth['months'][0];
        $dayDates = $stockDay['dates'];
        $months = $stockMonth['months'];

        // ---- flows: day extracts (MSTART .. END) rolled up to the day series (cutover .. END) and the month series
        $disbRows = $pick('disb');     // d, n, amount, price, term_sum (c1), adv_n (c2), adv_sum (c3), seg_a (c4)
        $closeRows = $pick('close');   // d, kind (k), n, amount, price, rem
        $singleRows = $pick('single'); // d, n, price
        $flowsFor = static function (array $keys, callable $keyOf) use ($disbRows, $closeRows, $singleRows): array {
            $z = array_fill(0, count($keys), 0);
            $idx = array_flip($keys);
            $f = ['keys' => $keys,
                'disb' => ['n' => $z, 'amount' => $z, 'price' => $z, 'adv' => $z, 'termSum' => $z, 'advN' => $z, 'segA' => $z],
                'single' => ['n' => $z, 'price' => $z],
                'close' => ['paid' => ['n' => $z, 'amount' => $z], 'norec' => ['n' => $z, 'amount' => $z], 'wo' => ['n' => $z, 'amount' => $z, 'rem' => $z]]];
            foreach ($disbRows as $r) {
                $i = $idx[$keyOf((string) $r['d'])] ?? null;
                if ($i === null) { continue; }
                $f['disb']['n'][$i] += (int) $r['n'];
                $f['disb']['amount'][$i] += (float) $r['amount'];
                $f['disb']['price'][$i] += (float) $r['price'];
                $f['disb']['adv'][$i] += (float) $r['c3'];
                $f['disb']['termSum'][$i] += (int) $r['c1'];
                $f['disb']['advN'][$i] += (int) $r['c2'];
                $f['disb']['segA'][$i] += (int) $r['c4'];
            }
            foreach ($singleRows as $r) {
                $i = $idx[$keyOf((string) $r['d'])] ?? null;
                if ($i === null) { continue; }
                $f['single']['n'][$i] += (int) $r['n'];
                $f['single']['price'][$i] += (float) $r['price'];
            }
            foreach ($closeRows as $r) {
                $i = $idx[$keyOf((string) $r['d'])] ?? null;
                if ($i === null || !isset($f['close'][$r['k']])) { continue; }
                $c = &$f['close'][$r['k']];
                $c['n'][$i] += (int) $r['n'];
                $c['amount'][$i] += (float) $r['amount'];
                if (isset($c['rem'])) { $c['rem'][$i] += (float) $r['rem']; }
                unset($c);
            }
            $r2 = static fn (array $a): array => array_map([self::class, 'r2'], $a);
            foreach (['disb', 'single'] as $g) { foreach ($f[$g] as $k => $a) { $f[$g][$k] = $r2($a); } }
            foreach ($f['close'] as $kind => $g) { foreach ($g as $k => $a) { $f['close'][$kind][$k] = $r2($a); } }
            return $f;
        };
        $flowDay = $flowsFor($dayDates, static fn (string $d) => $d);
        $flowMonth = $flowsFor($months, static fn (string $d) => substr($d, 0, 7));

        // ---- label rules shared by the structure tables and the current-book tables
        $goodsType = $this->goodsTypeClassifier();
        $risk = static function ($raw): string {
            $s = strtolower(trim((string) $raw));
            if ($s === 'დაბალი' || $s === 'low') { return 'Low (დაბალი)'; }
            if ($s === 'საშუალო' || $s === 'medium') { return 'Medium (საშუალო)'; }
            if ($s === 'მაღალი' || $s === 'high') { return 'High (მაღალი)'; }
            return 'Not scored';
        };
        $termBand = static function ($t): string {
            $t = (int) $t;
            if (!$t) { return 'No schedule'; }
            if ($t <= 3) { return '1–3 months'; }
            if ($t <= 6) { return '4–6 months'; }
            if ($t <= 9) { return '7–9 months'; }
            if ($t === 10) { return '10 months'; }
            if ($t <= 12) { return '11–12 months'; }
            return '13+ months';
        };
        $ageBand = static function ($a): string {
            $a = (int) $a;
            if ($a <= 1) { return '0–1 months'; }
            if ($a <= 3) { return '2–3 months'; }
            if ($a <= 6) { return '4–6 months'; }
            if ($a <= 9) { return '7–9 months'; }
            if ($a <= 12) { return '10–12 months'; }
            return '13+ months';
        };
        $rmLabel = static function ($k): string {
            $k = (int) $k;
            if ($k < 0) { return 'Unknown'; }
            if ($k >= 11) { return '11+'; }
            return (string) $k;
        };
        $amtLabel = static fn ($k) => self::AMT_BANDS[(int) $k] ?? 'Unknown';
        $segLabel = static fn ($k) => self::SEG_LABEL[(string) $k] ?? (string) $k;
        $ident = static fn ($k) => (string) $k;

        // ---- structure of the loans disbursed each month: one table per dimension, rows = bands, columns = months (+ totals)
        //      rows: mo (d), k, n, amount, rem, act — in (mo, k) order like pull_pf.sh, so first-appearance order (goods) is the same
        $structTable = static function (string $dim, callable $labelOf, ?array $order, string $title, string $headLabel) use ($pick, $months): array {
            $zero = array_fill(0, count($months), 0);
            $mIdx = array_flip($months);
            $rows = [];
            $get = static function (string $l) use (&$rows, $zero): void {
                if (!isset($rows[$l])) { $rows[$l] = ['label' => $l, 'n' => $zero, 'amount' => $zero, 'act' => $zero]; }
            };
            foreach ($order ?? [] as $l) { $get($l); }
            foreach ($pick('struct_' . $dim) as $r) {
                $i = $mIdx[$r['d']] ?? null;
                if ($i === null) { continue; }
                $l = $labelOf($r['k'] ?? '');
                $get($l);
                $rows[$l]['n'][$i] += (int) $r['n'];
                $rows[$l]['amount'][$i] += (float) $r['amount'];
                $rows[$l]['act'][$i] += (int) $r['act'];
            }
            $out = [];
            foreach ($rows as $g) {
                $out[] = ['label' => (string) $g['label'], 'n' => $g['n'], 'amount' => array_map([self::class, 'r2'], $g['amount']), 'act' => $g['act'], 'total' => array_sum($g['n'])];
            }
            if ($order === null) {
                usort($out, static fn ($a, $b) => (($a['label'] === 'Uncategorized') <=> ($b['label'] === 'Uncategorized')) ?: $b['total'] <=> $a['total']);
            }
            $out = array_values(array_filter($out, static fn ($g) => $g['total'] > 0));
            $tn = [];
            $ta = [];
            foreach ($months as $i => $m) {
                $n = 0;
                $a = 0.0;
                foreach ($out as $g) { $n += $g['n'][$i]; $a += $g['amount'][$i]; }
                $tn[] = $n;
                $ta[] = self::r2($a);
            }
            return ['title' => $title, 'headLabel' => $headLabel, 'rows' => $out, 'total' => ['n' => $tn, 'amount' => $ta]];
        };
        $struct = [
            'term' => $structTable('term', $termBand, self::TERM_BANDS, 'Loans by term (months in the payment schedule)', 'Term'),
            'amt' => $structTable('amt', $amtLabel, array_values(self::AMT_BANDS), 'Loans by contract amount', 'Contract amount'),
            'seg' => $structTable('seg', $segLabel, self::SEG_ORDER, 'Loans by segment', 'Segment'),
            'goods' => $structTable('goods', $goodsType, null, 'Loans by goods type (highest-value product line)', 'Goods Type'),
            'city' => $structTable('city', $ident, self::CITY_ORDER, 'Loans by city (shipping address)', 'City'),
            'risk' => $structTable('risk', $risk, self::RISK_ORDER, 'Loans by risk status (crm_risk_status)', 'Risk status'),
        ];
        // vintage: per disbursement month, how the loans stand today
        $by = [];
        foreach ($months as $m) { $by[$m] = ['disb' => 0, 'amount' => 0.0, 'active' => 0, 'paid' => 0, 'norec' => 0, 'wo' => 0, 'rem' => 0.0, 'remActive' => 0.0]; }
        foreach ($pick('struct_kind') as $r) {
            if (!isset($by[$r['d']])) { continue; }
            $g = &$by[$r['d']];
            $g['disb'] += (int) $r['n'];
            $g['amount'] += (float) $r['amount'];
            $g[(string) $r['k']] = ($g[(string) $r['k']] ?? 0) + (int) $r['n'];
            $g['rem'] += (float) $r['rem'];
            if ($r['k'] === 'active') { $g['remActive'] += (float) $r['rem']; }
            unset($g);
        }
        $vintage = [];
        foreach ($months as $m) {
            $g = $by[$m];
            $vintage[] = array_merge(['month' => $m], $g, ['amount' => self::r2($g['amount']), 'rem' => self::r2($g['rem']), 'remActive' => self::r2($g['remActive'])]);
        }

        // ---- the active book today (as of END): rows k, n, amount, rem — in k order like pull_pf.sh
        $bookTable = static function (string $dim, callable $labelOf, ?array $order, string $title, string $headLabel) use ($pick): array {
            $rows = [];
            $get = static function (string $l) use (&$rows): void {
                if (!isset($rows[$l])) { $rows[$l] = ['label' => $l, 'n' => 0, 'amount' => 0.0, 'rem' => 0.0]; }
            };
            foreach ($order ?? [] as $l) { $get($l); }
            foreach ($pick('book_' . $dim) as $r) {
                $l = $labelOf($r['k'] ?? '');
                $get($l);
                $rows[$l]['n'] += (int) $r['n'];
                $rows[$l]['amount'] += (float) $r['amount'];
                $rows[$l]['rem'] += (float) $r['rem'];
            }
            $out = [];
            foreach ($rows as $g) {
                $out[] = ['label' => (string) $g['label'], 'n' => $g['n'], 'amount' => self::r2($g['amount']), 'rem' => self::r2($g['rem'])];
            }
            if ($order === null) {
                usort($out, static fn ($a, $b) => (($a['label'] === 'Uncategorized') <=> ($b['label'] === 'Uncategorized')) ?: $b['n'] <=> $a['n']);
            }
            $out = array_values(array_filter($out, static fn ($g) => $g['n'] > 0));
            $tn = 0;
            $ta = 0.0;
            $tr = 0.0;
            foreach ($out as $g) { $tn += $g['n']; $ta += $g['amount']; $tr += $g['rem']; }
            return ['title' => $title, 'headLabel' => $headLabel, 'rows' => $out, 'total' => ['n' => $tn, 'amount' => self::r2($ta), 'rem' => self::r2($tr)]];
        };
        $book = [
            'rm' => $bookTable('rm', $rmLabel, self::RM_ORDER, 'Active loans by months remaining (crm_remaining_months)', 'Months remaining'),
            'age' => $bookTable('age', $ageBand, self::AGE_BANDS, 'Active loans by age (months since disbursement)', 'Loan age'),
            'risk' => $bookTable('risk', $risk, self::RISK_ORDER, 'Active loans by risk status', 'Risk status'),
            'term' => $bookTable('term', $termBand, self::TERM_BANDS, 'Active loans by term', 'Term'),
            'amt' => $bookTable('amt', $amtLabel, array_values(self::AMT_BANDS), 'Active loans by contract amount', 'Contract amount'),
            'seg' => $bookTable('seg', $segLabel, self::SEG_ORDER, 'Active loans by segment', 'Segment'),
            'city' => $bookTable('city', $ident, self::CITY_ORDER, 'Active loans by city', 'City'),
            'goods' => $bookTable('goods', $goodsType, null, 'Active loans by goods type', 'Goods Type'),
        ];

        // ---- data-quality memo: kind (k), n, rem, fully_paid (c1), first_disb (c2), last_disb (c3), first_close (c4), last_close (c5)
        $kinds = [];
        foreach ($pick('quality') as $r) {
            $kinds[] = ['kind' => (string) $r['k'], 'label' => self::KIND_LABEL[$r['k']] ?? (string) $r['k'], 'n' => (int) $r['n'], 'rem' => self::num((float) $r['rem']), 'fullyPaid' => (int) $r['c1'],
                'firstDisb' => (string) $r['c2'], 'lastDisb' => (string) $r['c3'], 'firstClose' => $r['c4'] === null ? null : (string) $r['c4'], 'lastClose' => $r['c5'] === null ? null : (string) $r['c5']];
        }
        $q = $pick('quality2')[0] ?? [];
        $intOrNull = static fn ($v) => ($v === null || $v === '') ? null : (int) $v;
        $quality = ['kinds' => $kinds, 'badCloseDates' => $intOrNull($q['c1'] ?? null), 'activeZeroBalance' => $intOrNull($q['c2'] ?? null), 'singleAll' => $intOrNull($q['c3'] ?? null), 'closesSinceCutover' => $intOrNull($q['c4'] ?? null)];

        return ['cutover' => $cutover, 'mstart' => $MSTART, 'end' => $END, 'stockDay' => $stockDay, 'stockMonth' => $stockMonth, 'flowDay' => $flowDay, 'flowMonth' => $flowMonth,
            'struct' => $struct, 'vintage' => $vintage, 'book' => $book, 'quality' => $quality, 'generatedAt' => gmdate('Y-m-d H:i') . ' UTC'];
    }

    // ------------------------------------------------------------------ helpers

    /** prepared query, timed into $this->timings[$name] */
    private function q(string $name, string $sql, array $params = []): array
    {
        $t = microtime(true);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $this->timings[$name] = round(microtime(true) - $t, 3);
        return $rows;
    }

    /** goods type = mapping-sheet categoryEn over the new-DB category tree (categories / category_translations / product_categories), same as NewDbReport::logistics() */
    private function goodsTypeClassifier(): callable
    {
        $this->loadMapping();
        $cats = [];
        foreach ($this->q('categories', "SELECT c.id, c.parent_id, ct.name FROM categories c LEFT JOIN category_translations ct ON ct.category_id=c.id AND ct.locale='ka_GE'") as $r) {
            $cats[(string) $r['id']] = ['parent' => (string) ($r['parent_id'] ?? ''), 'name' => trim((string) ($r['name'] ?? ''))];
        }
        $depth = static function (string $id) use ($cats): int {
            $d = 0;
            $c = $cats[$id] ?? null;
            while ($c && $c['parent'] !== '' && isset($cats[$c['parent']])) { $d++; $c = $cats[$c['parent']]; }
            return $d;
        };
        $productCats = [];
        foreach ($this->q('product_categories', 'SELECT product_id, category_id FROM product_categories ORDER BY product_id, category_id') as $r) {
            $productCats[(string) $r['product_id']][] = (string) $r['category_id'];
        }
        return function ($productId) use ($productCats, $cats, $depth): string {
            $ids = $productCats[(string) $productId] ?? [];
            usort($ids, static fn ($a, $b) => $depth($a) <=> $depth($b));
            $names = [];
            foreach ($ids as $id) { $n = $cats[$id]['name'] ?? ''; if ($n !== '' && $n !== 'none') { $names[] = $n; } }
            $s = self::label(($this->lookup(implode(',', $names)) ?? [])['categoryEn'] ?? null);
            return $s ?? 'Uncategorized';
        };
    }

    private function loadMapping(): void
    {
        $raw = json_decode((string) file_get_contents($this->mappingPath), true, 512, JSON_THROW_ON_ERROR);
        $this->mapping = [];
        foreach ($raw as $k => $v) {
            $this->mapping[trim((string) $k)] = $v;
        }
    }

    private function lookup(?string $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        $name = trim($raw);
        if ($name === '' || $name === 'none') {
            return null;
        }
        if (isset($this->mapping[$name])) {
            return $this->mapping[$name];
        }
        $parts = array_values(array_filter(array_map('trim', explode(',', $name)), static fn ($p) => $p !== ''));
        foreach (array_reverse($parts) as $part) {
            if (isset($this->mapping[$part])) {
                return $this->mapping[$part];
            }
        }
        return null;
    }

    private static function label(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));
        return ($s === '' || strtolower($s) === 'none') ? null : $s;
    }

    /** JS-compatible number: whole floats become ints so json_encode prints 6734, not 6734.0 */
    private static function num(float|int $v): float|int
    {
        if (is_float($v) && floor($v) === $v && abs($v) < 1e15) {
            return (int) $v;
        }
        return $v;
    }

    private static function r2(float|int $v): float|int
    {
        return self::num(round((float) $v, 2));
    }
}
