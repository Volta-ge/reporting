<?php

declare(strict_types=1);

namespace Volta\Funnel;

use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use PDO;
use SoapClient;

/**
 * Native PHP port of the "Accounting" nav-group (ზედნადებები / ანგარიშ-ფაქტურები /
 * გაყიდვა ↔ ზედნადები) — originally a separate Python pipeline
 * (volta-waybills/refresh_dashboard.py, same repo) that produced a static JSON blob
 * merged into this dashboard by a second script. Ported here 2026-08-27 so Accounting
 * is a first-class, permanently-rendered part of THIS app — no more separate merge
 * step, no more risk of a re-splice wiping it out. See the volta_analytics_merge and
 * volta_php_accounting_port Claude memory files for the full history and the exact
 * business-logic decisions this replicates (sign conventions, tolerances, the
 * split-delivery false-positive problem, etc.) — this class intentionally mirrors the
 * validated Python logic line-for-line rather than re-deriving it.
 *
 * Fetches RS.ge WayBillService (seller-side waybills) + ntosservice (seller-side
 * invoices) via SOAP, cross-matches waybills against CRM sales from `instalments` to
 * find sales missing a waybill (or waybills missing a CRM sale).
 */
final class AccountingRepository
{
    private const WAYBILL_WSDL = 'http://services.rs.ge/WayBillService/WayBillService.asmx?WSDL';
    private const WAYBILL_ENDPOINT = 'http://services.rs.ge/WayBillService/WayBillService.asmx';
    private const INVOICE_WSDL = 'https://www.revenue.mof.ge/ntosservice/ntosservice.asmx?WSDL';

    private const RECON_DATE_TOL_DAYS = 45;
    private const NET_TOL = 1.0;

    private static ?SoapClient $waybillClient = null;
    private static ?SoapClient $invoiceClient = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $su,
        private readonly string $sp,
        private readonly int $invoiceUserId,
        private readonly int $invoiceUnId,
        private readonly DateTimeImmutable $start,
    ) {
    }

    private function waybillClient(): SoapClient
    {
        return self::$waybillClient ??= new SoapClient(self::WAYBILL_WSDL, [
            'exceptions' => true, 'connection_timeout' => 30,
        ]);
    }

    private function invoiceClient(): SoapClient
    {
        return self::$invoiceClient ??= new SoapClient(self::INVOICE_WSDL, [
            'exceptions' => true, 'connection_timeout' => 30,
        ]);
    }

    /** RS.ge dates are plain "YYYY-MM-DDTHH:MM:SS", no timezone — pass through as-is. */
    private static function soapDate(DateTimeImmutable $d): string
    {
        return $d->format('Y-m-d\TH:i:s');
    }

    /**
     * Extracts every element with the given local name, anywhere in the document,
     * regardless of namespace — RS.ge responses mix namespaced and unnamespaced
     * elements inconsistently, so matching by local-name() (like Python's
     * `etree.QName(el).localname`) is the only robust way to find them.
     */
    private static function elementsByLocalName(DOMDocument $doc, string $name): array
    {
        $xpath = new DOMXPath($doc);
        $nodes = $xpath->query("//*[local-name()='{$name}']");
        return $nodes === false ? [] : iterator_to_array($nodes);
    }

    /** A DOM element's direct children as a flat tag => text assoc array (RS.ge rows are flat). */
    private static function elementToAssoc(\DOMElement $el, array $skipTags = []): array
    {
        $row = [];
        foreach ($el->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            if (in_array($child->localName, $skipTags, true)) {
                continue;
            }
            $row[$child->localName] = $child->textContent;
        }
        return $row;
    }

    // ---- waybills (bulk register) ----------------------------------------------------

    /** @return array<int, array<string, string>> raw waybill rows */
    public function fetchWaybills(): array
    {
        $result = $this->waybillClient()->get_waybills([
            'su' => $this->su, 'sp' => $this->sp,
            'itypes' => null, 'buyer_tin' => null, 'statuses' => null, 'car_number' => null,
            'begin_date_s' => self::soapDate($this->start), 'begin_date_e' => self::soapDate(new DateTimeImmutable('now')),
            'create_date_s' => null, 'create_date_e' => null,
            'driver_tin' => null,
            'delivery_date_s' => null, 'delivery_date_e' => null,
            'full_amount' => null, 'waybill_number' => null,
            'close_date_s' => null, 'close_date_e' => null,
            's_user_ids' => null, 'comment' => null,
        ]);
        $xml = (string) $result->get_waybillsResult->any;

        $doc = new DOMDocument();
        $doc->loadXML($xml);
        $rows = [];
        foreach (self::elementsByLocalName($doc, 'WAYBILL') as $el) {
            $rows[] = self::elementToAssoc($el, ['SUB_WAYBILLS', 'GOODS_LIST']);
        }
        return $rows;
    }

    /** @return array{0: array<int, array{i:string,n:string,d:string,b:string,t:string,a:float,s:string,y:string,c:string,e:string}>, 1: int} [rows, skippedCount] */
    public function buildWaybillRows(array $raw): array
    {
        $rows = [];
        $skipped = 0;
        foreach ($raw as $r) {
            if (empty($r['WAYBILL_NUMBER'])) {
                $skipped++;
                continue;
            }
            $amount = isset($r['FULL_AMOUNT']) && $r['FULL_AMOUNT'] !== '' ? (float) $r['FULL_AMOUNT'] : 0.0;
            if (($r['TYPE'] ?? null) === '5') {
                $amount = -$amount;
            }
            $rows[] = [
                'i' => $r['ID'] ?? '',
                'n' => $r['WAYBILL_NUMBER'] ?? '',
                'd' => $r['BEGIN_DATE'] ?? '',
                'b' => (!empty($r['BUYER_NAME'])) ? $r['BUYER_NAME'] : 'დაუდგენელი',
                't' => $r['BUYER_TIN'] ?? '',
                'a' => $amount,
                's' => $r['STATUS'] ?? '',
                'y' => $r['TYPE'] ?? '',
                'c' => $r['CAR_NUMBER'] ?? '',
                'e' => $r['END_ADDRESS'] ?? '',
            ];
        }
        return [$rows, $skipped];
    }

    // ---- invoices ----------------------------------------------------------------------

    /** @return array<int, array<string, string>> raw invoice rows */
    public function fetchInvoices(): array
    {
        $result = $this->invoiceClient()->get_seller_invoices([
            'user_id' => $this->invoiceUserId, 'un_id' => $this->invoiceUnId,
            's_dt' => self::soapDate($this->start), 'e_dt' => self::soapDate(new DateTimeImmutable('now')),
            'op_s_dt' => self::soapDate($this->start), 'op_e_dt' => self::soapDate(new DateTimeImmutable('now')),
            'invoice_no' => '', 'sa_ident_no' => '', 'desc' => '', 'doc_mos_nom' => '',
            'su' => $this->su, 'sp' => $this->sp,
        ]);
        // An ADO.NET diffgram: a <xs:schema> element immediately followed by a SIBLING
        // <diffgr:diffgram><DocumentElement><invoices>...</invoices>...</DocumentElement>
        // </diffgr:diffgram> — two root-level elements back to back, which is not
        // well-formed XML on its own (loadXML rejects it with "Extra content at the end
        // of the document"), so wrap it in a synthetic root first. PHP's SoapClient
        // otherwise hands this straight back as a plain string in ->any with no
        // special-casing needed — unlike Python/zeep, which couldn't auto-bind this
        // shape at all and needed a raw SOAP POST workaround.
        $xml = (string) $result->get_seller_invoicesResult->any;

        $doc = new DOMDocument();
        $doc->loadXML('<root>' . $xml . '</root>');
        $rows = [];
        foreach (self::elementsByLocalName($doc, 'invoices') as $el) {
            $rows[] = self::elementToAssoc($el);
        }
        return $rows;
    }

    /** @return array<int, array{d:string,reg:string,f:string,b:string,t:string,a:float,v:float,s:string}> */
    public function buildInvoiceRows(array $raw): array
    {
        $rows = [];
        foreach ($raw as $r) {
            $series = $r['F_SERIES'] ?? '';
            $number = $r['F_NUMBER'] ?? '';
            $rows[] = [
                'd' => $r['OPERATION_DT'] ?? '',
                'reg' => $r['REG_DT'] ?? '',
                'f' => trim($series . '-' . $number, '-'),
                'b' => (!empty($r['ORG_NAME'])) ? $r['ORG_NAME'] : 'დაუდგენელი',
                't' => $r['SA_IDENT_NO'] ?? '',
                'a' => isset($r['TANXA']) && $r['TANXA'] !== '' ? (float) $r['TANXA'] : 0.0,
                'v' => isset($r['VAT']) && $r['VAT'] !== '' ? (float) $r['VAT'] : 0.0,
                's' => $r['STATUS'] ?? '',
            ];
        }
        return $rows;
    }

    // ---- CRM sales -----------------------------------------------------------------------

    /** @return array<int, array{Instalment_ID:int,PID:string,FullName:string,Order_Date:string,Full_Cost:float,Manager_Name:?string,Product_Name:?string}> */
    public function fetchCrmSales(): array
    {
        $stmt = $this->pdo->prepare(<<<SQL
            SELECT i.Instalment_ID, c.PID, c.FullName, i.Order_Date, i.Full_Cost,
                   u.User_FullName AS Manager_Name, p.Model AS Product_Name
            FROM instalments i
            JOIN customers c ON i.Customer_ID = c.Customer_ID
            LEFT JOIN users u ON i.Sales_Manager = u.User_ID
            LEFT JOIN products p ON i.Product_ID = p.Product_ID
            WHERE i.Order_Status = 5
              AND i.Product_ID > 1
              AND i.Order_Date >= :start
              AND c.PID IS NOT NULL AND c.PID <> ''
            SQL);
        $stmt->execute(['start' => $this->start->format('Y-m-d H:i:s')]);
        return $stmt->fetchAll();
    }

    // ---- per-waybill product names (concurrent) -------------------------------------------

    private static function amountTolerance(float $amount): float
    {
        return max(50.0, 0.05 * $amount);
    }

    private function goodsEnvelope(int|string $waybillId): string
    {
        $su = htmlspecialchars($this->su, ENT_XML1);
        $sp = htmlspecialchars($this->sp, ENT_XML1);
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns1="http://tempuri.org/">'
            . '<SOAP-ENV:Body><ns1:get_waybill><ns1:su>' . $su . '</ns1:su>'
            . '<ns1:sp>' . $sp . '</ns1:sp>'
            . '<ns1:waybill_id>' . (int) $waybillId . '</ns1:waybill_id></ns1:get_waybill></SOAP-ENV:Body></SOAP-ENV:Envelope>';
    }

    private static function parseGoodsNamesResponse(string $body): string
    {
        // A raw curl POST (bypassing SoapClient, for concurrency) gets the plain wire
        // XML back: soap:Envelope > soap:Body > get_waybillResponse > get_waybillResult
        // > WAYBILL > GOODS_LIST > GOODS > W_NAME — no "<any>" wrapper. ("any" is a
        // PHP SoapClient deserialization artifact for this WSDL's <xsd:any> return
        // type, not anything present on the wire — a first version of this method
        // wrongly looked for it here and silently got zero matches every time.)
        $doc = new DOMDocument();
        if (!@$doc->loadXML($body)) {
            return '';
        }
        $names = [];
        foreach (self::elementsByLocalName($doc, 'GOODS') as $goods) {
            foreach ($goods->childNodes as $child) {
                if ($child instanceof \DOMElement && $child->localName === 'W_NAME' && $child->textContent !== '') {
                    $names[] = $child->textContent;
                }
            }
        }
        return implode(', ', $names);
    }

    /**
     * get_waybills (the bulk register call) has no GOODS_LIST at all — only the
     * singular get_waybill(waybill_id) returns product names. Fetches many
     * concurrently via curl_multi (PHP has no lightweight thread pool; this is the
     * portable equivalent of the Python pipeline's ThreadPoolExecutor(25)).
     *
     * @param array<int, int|string> $waybillIds
     * @return array<string, string> waybill ID (as string) => comma-joined product names
     */
    public function fetchGoodsNamesBulk(array $waybillIds, int $maxWorkers = 25): array
    {
        $queue = array_values(array_unique(array_map('strval', $waybillIds)));
        $cache = [];
        if ($queue === []) {
            return $cache;
        }

        $headers = [
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: "http://tempuri.org/get_waybill"',
        ];

        $mh = curl_multi_init();
        /** @var array<int, string> $idByHandleId curl-handle-object-id => waybill id */
        $idByHandleId = [];

        $addHandle = function (string $id) use ($mh, $headers, &$idByHandleId): void {
            $ch = curl_init(self::WAYBILL_ENDPOINT);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $this->goodsEnvelope($id),
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 25,
            ]);
            curl_multi_add_handle($mh, $ch);
            $idByHandleId[spl_object_id($ch)] = $id;
        };

        $active = 0;
        while ($queue !== [] && $active < $maxWorkers) {
            $addHandle(array_shift($queue));
            $active++;
        }

        do {
            curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 1.0);
            }
            while (($info = curl_multi_info_read($mh)) !== false) {
                $ch = $info['handle'];
                $hid = spl_object_id($ch);
                $waybillId = $idByHandleId[$hid] ?? null;
                if ($waybillId !== null) {
                    $body = curl_multi_getcontent($ch);
                    $cache[$waybillId] = $body !== false ? self::parseGoodsNamesResponse($body) : '';
                    unset($idByHandleId[$hid]);
                    $active--;
                }
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                if ($queue !== []) {
                    $addHandle(array_shift($queue));
                    $active++;
                }
            }
        } while ($active > 0);

        curl_multi_close($mh);
        return $cache;
    }

    // ---- reconciliation --------------------------------------------------------------------

    /**
     * Per-PID bipartite match: each CRM sale is paired with at most one waybill on
     * the same buyer TIN, within RECON_DATE_TOL_DAYS days and a 5%-or-50-GEL amount
     * tolerance, greedily assigning the closest pairs first so one waybill can't
     * double-count against two different sales. Cancelled (-2) and return (TYPE=5)
     * waybills are excluded from the MATCHING pool — they don't represent a valid
     * "goods delivered for this sale" event — but returns ARE still shown on a
     * person's card (byPerson.waybills, isReturn:true) so a delivery-then-return
     * doesn't look identical to "staff never issued a waybill" (see class docblock).
     *
     * A person's netDiff = (sum of their CRM sales) - (sum of their waybills,
     * returns counted negative). Its SIGN distinguishes two different problems:
     * positive = a real sale has no waybill ("აკლია ზედნადები"); negative = a
     * waybill exists with no CRM sale behind it ("აკლია გაყიდვა"). |netDiff| < 1
     * counts as reconciled regardless of whether every individual sale/waybill
     * pair cleared the tolerance (split/partial-waybill deliveries net to ~0 even
     * when no single pair matches) — this net-total rule, not a per-sale flag
     * count, is what the summary/KPI numbers must always be built from.
     *
     * @param array<int, array{Instalment_ID:int,PID:string,FullName:string,Order_Date:string,Full_Cost:float,Manager_Name:?string,Product_Name:?string}> $crmRows
     * @param array<int, array<string, string>> $waybillRaw
     */
    public function buildReconciliation(array $crmRows, array $waybillRaw): array
    {
        $validWb = array_values(array_filter($waybillRaw, static fn(array $w): bool =>
            !empty($w['WAYBILL_NUMBER']) && ($w['STATUS'] ?? null) !== '-2' && ($w['TYPE'] ?? null) !== '5' && !empty($w['BEGIN_DATE'])
        ));
        $allWb = array_values(array_filter($waybillRaw, static fn(array $w): bool =>
            !empty($w['WAYBILL_NUMBER']) && ($w['STATUS'] ?? null) !== '-2' && !empty($w['BEGIN_DATE'])
        ));

        $crmByPid = [];
        foreach ($crmRows as $r) {
            $crmByPid[$r['PID']][] = $r;
        }
        $wbByTin = [];
        foreach ($validWb as $w) {
            $wbByTin[$w['BUYER_TIN']][] = $w;
        }
        $wbByTinAll = [];
        foreach ($allWb as $w) {
            $wbByTinAll[$w['BUYER_TIN']][] = $w;
        }

        $relevantWbIds = [];
        foreach (array_keys($crmByPid) as $pid) {
            foreach ($wbByTinAll[$pid] ?? [] as $w) {
                $relevantWbIds[] = $w['ID'];
            }
        }
        $goodsCache = $this->fetchGoodsNamesBulk($relevantWbIds);

        $matched = [];
        $missing = [];
        $byPerson = [];

        foreach ($crmByPid as $pid => $sales) {
            $candidates = $wbByTin[$pid] ?? [];
            $pairs = [];
            foreach ($sales as $ci => $c) {
                $cAmt = (float) $c['Full_Cost'];
                $cDate = strtotime((string) $c['Order_Date']);
                $tol = self::amountTolerance($cAmt);
                foreach ($candidates as $wi => $w) {
                    $wAmt = isset($w['FULL_AMOUNT']) && $w['FULL_AMOUNT'] !== '' ? (float) $w['FULL_AMOUNT'] : 0.0;
                    $wDate = strtotime((string) $w['BEGIN_DATE']);
                    $dateDiffDays = abs(($wDate - $cDate) / 86400);
                    $amtDiff = abs($wAmt - $cAmt);
                    if ($dateDiffDays <= self::RECON_DATE_TOL_DAYS && $amtDiff <= $tol) {
                        $score = $dateDiffDays / self::RECON_DATE_TOL_DAYS + $amtDiff / $tol;
                        $pairs[] = [$score, $ci, $wi];
                    }
                }
            }
            usort($pairs, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

            $usedCi = [];
            $usedWi = [];
            foreach ($pairs as [$score, $ci, $wi]) {
                if (isset($usedCi[$ci]) || isset($usedWi[$wi])) {
                    continue;
                }
                $usedCi[$ci] = true;
                $usedWi[$wi] = true;
                $c = $sales[$ci];
                $w = $candidates[$wi];
                $cAmt = (float) $c['Full_Cost'];
                $wAmt = isset($w['FULL_AMOUNT']) && $w['FULL_AMOUNT'] !== '' ? (float) $w['FULL_AMOUNT'] : 0.0;
                $matched[] = [
                    'd' => self::isoDate($c['Order_Date']),
                    'id' => $c['Instalment_ID'],
                    'b' => $c['FullName'] ?? '',
                    't' => (string) $c['PID'],
                    'mgr' => $c['Manager_Name'] ?: '—',
                    'prodDb' => $c['Product_Name'] ?: '—',
                    'amtDb' => $cAmt,
                    'prodWb' => $goodsCache[$w['ID']] ?? '' ?: '—',
                    'amtWb' => $wAmt,
                    'dateWb' => $w['BEGIN_DATE'],
                    'wbNumber' => $w['WAYBILL_NUMBER'] ?? '',
                    'diff' => $wAmt - $cAmt,
                ];
            }

            foreach ($sales as $ci => $c) {
                if (isset($usedCi[$ci])) {
                    continue;
                }
                $cAmt = (float) $c['Full_Cost'];
                $cDate = strtotime((string) $c['Order_Date']);
                $wbProd = null;
                $wbAmt = null;
                $wbDate = null;
                $wbNumber = null;
                if ($candidates !== []) {
                    $nearest = null;
                    $nearestDiff = null;
                    foreach ($candidates as $w) {
                        $d = abs((strtotime((string) $w['BEGIN_DATE']) - $cDate) / 86400);
                        if ($nearestDiff === null || $d < $nearestDiff) {
                            $nearestDiff = $d;
                            $nearest = $w;
                        }
                    }
                    $wbNumber = $nearest['WAYBILL_NUMBER'] ?? '';
                    $wbDate = $nearest['BEGIN_DATE'];
                    $wbAmt = isset($nearest['FULL_AMOUNT']) && $nearest['FULL_AMOUNT'] !== '' ? (float) $nearest['FULL_AMOUNT'] : 0.0;
                    $wbProd = $goodsCache[$nearest['ID']] ?? '';
                }
                $missing[] = [
                    'd' => self::isoDate($c['Order_Date']),
                    'id' => $c['Instalment_ID'],
                    'b' => $c['FullName'] ?? '',
                    't' => (string) $c['PID'],
                    'mgr' => $c['Manager_Name'] ?: '—',
                    'prodDb' => $c['Product_Name'] ?: '—',
                    'amtDb' => $cAmt,
                    'prodWb' => $wbProd ?: '—',
                    'amtWb' => $wbAmt,
                    'dateWb' => $wbDate,
                    'wbNumber' => $wbNumber,
                    'diff' => $wbAmt !== null ? $wbAmt - $cAmt : null,
                ];
            }

            $matchedWbIds = [];
            foreach ($usedWi as $wi => $_) {
                $matchedWbIds[$candidates[$wi]['ID']] = true;
            }
            $allCandidates = $wbByTinAll[$pid] ?? [];
            usort($allCandidates, static fn(array $a, array $b): int => strcmp($a['BEGIN_DATE'], $b['BEGIN_DATE']));

            $personSales = [];
            foreach ($sales as $ci => $c) {
                $personSales[] = [
                    'd' => self::isoDate($c['Order_Date']),
                    'id' => $c['Instalment_ID'],
                    'prod' => $c['Product_Name'] ?: '—',
                    'a' => (float) $c['Full_Cost'],
                    'mgr' => $c['Manager_Name'] ?: '—',
                    'matched' => isset($usedCi[$ci]),
                ];
            }
            $personWaybills = [];
            foreach ($allCandidates as $w) {
                $amt = isset($w['FULL_AMOUNT']) && $w['FULL_AMOUNT'] !== '' ? (float) $w['FULL_AMOUNT'] : 0.0;
                $isReturn = ($w['TYPE'] ?? null) === '5';
                $personWaybills[] = [
                    'd' => $w['BEGIN_DATE'],
                    'n' => $w['WAYBILL_NUMBER'] ?? '',
                    'prod' => $goodsCache[$w['ID']] ?? '' ?: '—',
                    'a' => $isReturn ? -$amt : $amt,
                    'matched' => isset($matchedWbIds[$w['ID']]),
                    'isReturn' => $isReturn,
                ];
            }

            $byPerson[] = [
                't' => (string) $sales[0]['PID'],
                'b' => $sales[0]['FullName'] ?? '',
                'sales' => $personSales,
                'waybills' => $personWaybills,
            ];
        }

        foreach ($byPerson as &$p) {
            $salesTotal = array_sum(array_column($p['sales'], 'a'));
            $wbTotal = array_sum(array_column($p['waybills'], 'a'));
            $p['salesTotal'] = $salesTotal;
            $p['wbTotal'] = $wbTotal;
            $p['netDiff'] = $salesTotal - $wbTotal;
        }
        unset($p);

        $missingWbPeople = array_values(array_filter($byPerson, static fn(array $p): bool => $p['netDiff'] >= self::NET_TOL));
        $missingSalePeople = array_values(array_filter($byPerson, static fn(array $p): bool => $p['netDiff'] <= -self::NET_TOL));
        $matchedPeople = array_values(array_filter($byPerson, static fn(array $p): bool => abs($p['netDiff']) < self::NET_TOL));
        $riskAmountWb = array_sum(array_column($missingWbPeople, 'netDiff'));
        $riskAmountSale = -array_sum(array_column($missingSalePeople, 'netDiff'));

        $missingAmount = array_sum(array_column($missing, 'amtDb'));

        return [
            'summary' => [
                'total' => count($crmRows),
                'matched' => count($matched),
                'missing' => count($missing),
                'missingAmount' => $missingAmount,
                'totalPeople' => count($byPerson),
                'matchedPeople' => count($matchedPeople),
                'missingWbPeople' => count($missingWbPeople),
                'missingSalePeople' => count($missingSalePeople),
                'riskAmountWb' => $riskAmountWb,
                'riskAmountSale' => $riskAmountSale,
                'riskAmount' => $riskAmountWb + $riskAmountSale,
            ],
            'matched' => $matched,
            'missing' => $missing,
            'byPerson' => $byPerson,
        ];
    }

    /** `Order_Date` comes back from PDO as "YYYY-MM-DD HH:MM:SS" — the front-end JS expects ISO 8601 with a "T". */
    private static function isoDate(string $mysqlDatetime): string
    {
        return str_replace(' ', 'T', $mysqlDatetime);
    }
}
