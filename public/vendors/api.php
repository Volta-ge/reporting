<?php

declare(strict_types=1);

namespace Volta\Funnel;

// reporting.volta.ge/vendors/api.php -- thin JSON storage API for the vendor price-comparison
// tool. Deliberately NOT behind requireBasicAuth(): colleagues and vendors who only have this
// tool's link should not need the main dashboard's password (see BasicAuth.php and the
// vendors/index.php front page for the rest of the isolation reasoning). Excel parsing happens
// entirely in the browser (SheetJS) -- this file never receives or touches a raw .xlsx file,
// only small already-extracted JSON, so it needs no upload handling and no PHP Excel library.

require __DIR__ . '/../../src/VendorStore.php';
require __DIR__ . '/../../src/Database.php';

header('Content-Type: application/json; charset=utf-8');

function respond($data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function isSafeId(string $id): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id);
}

function requireSafeId(?string $id, string $label): string
{
    if ($id === null || !isSafeId($id)) {
        respond(['error' => "invalid $label"], 400);
    }
    return $id;
}

// Confirmed live (see the former diag endpoint, removed once this was resolved): the PHP-FPM
// user (uid 33) has no write access anywhere inside the deployed code tree (owned by uid 1000,
// the deploy user) -- only sys_get_temp_dir() (/tmp) is writable, with zero server changes
// needed. Caveat: /tmp isn't guaranteed permanent (a reboot or a periodic cleanup job could
// clear it, losing accumulated vendor price history) -- the fully durable fix is a one-time
// chown/chmod of a directory inside the deployed tree for whoever has server access, but this
// works today without waiting on that.
$store = new VendorStore(sys_get_temp_dir() . '/volta-vendors');
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;
$input = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $input = $raw ? (json_decode($raw, true) ?? []) : [];
    $action = $action ?? ($input['action'] ?? null);
}

if (!is_string($action) || $action === '') {
    respond(['error' => 'missing action'], 400);
}

try {
    switch ($action) {
        case 'vendors':
            respond(['vendors' => $store->listVendors()]);
            // no break

        case 'create_vendor':
            $name = trim((string) ($input['name'] ?? ''));
            if ($name === '') {
                respond(['error' => 'name is required'], 400);
            }
            respond(['vendor' => $store->createVendor($name)]);
            // no break

        case 'vendor':
            $id = requireSafeId($_GET['id'] ?? null, 'id');
            $vendor = $store->getVendor($id);
            if ($vendor === null) {
                respond(['error' => 'vendor not found'], 404);
            }
            respond(['vendor' => $vendor, 'snapshots' => $store->listSnapshots($id)]);
            // no break

        case 'delete_vendor':
            $id = requireSafeId($input['id'] ?? null, 'id');
            $store->deleteVendor($id);
            respond(['ok' => true]);
            // no break

        case 'rename_vendor':
            $id = requireSafeId($input['id'] ?? null, 'id');
            $name = trim((string) ($input['name'] ?? ''));
            if ($name === '') {
                respond(['error' => 'name is required'], 400);
            }
            respond(['vendor' => $store->renameVendor($id, $name)]);
            // no break

        case 'snapshots':
            $id = requireSafeId($_GET['vendorId'] ?? null, 'vendorId');
            respond(['snapshots' => $store->listSnapshots($id)]);
            // no break

        case 'import_snapshot':
            $vendorId = requireSafeId($input['vendorId'] ?? null, 'vendorId');
            if ($store->getVendor($vendorId) === null) {
                respond(['error' => 'vendor not found'], 404);
            }
            $label = trim((string) ($input['label'] ?? ''));
            $sheetsIncluded = $input['sheetsIncluded'] ?? [];
            $products = $input['products'] ?? [];
            if (!is_array($products) || !is_array($sheetsIncluded)) {
                respond(['error' => 'products and sheetsIncluded must be arrays'], 400);
            }
            $clean = [];
            foreach ($products as $p) {
                $extra = [];
                if (!empty($p['extra']) && is_array($p['extra'])) {
                    foreach ($p['extra'] as $e) {
                        if (!is_array($e) || !isset($e['label'])) {
                            continue;
                        }
                        $extra[] = [
                            'label' => (string) $e['label'],
                            'value' => isset($e['value']) ? (string) $e['value'] : '',
                            'col' => isset($e['col']) && is_numeric($e['col']) ? (int) $e['col'] : 0,
                        ];
                    }
                }
                $clean[] = [
                    'sheet' => isset($p['sheet']) ? (string) $p['sheet'] : null,
                    'code' => isset($p['code']) && $p['code'] !== '' ? (string) $p['code'] : null,
                    'name' => isset($p['name']) && $p['name'] !== '' ? (string) $p['name'] : null,
                    'price' => isset($p['price']) && is_numeric($p['price']) ? (float) $p['price'] : null,
                    'extra' => $extra,
                ];
            }
            $meta = $store->createSnapshot($vendorId, $label, array_values($sheetsIncluded), $clean);
            if (!empty($input['mappingTemplates']) && is_array($input['mappingTemplates'])) {
                $store->updateMappingTemplates($vendorId, $input['mappingTemplates']);
            }
            respond(['snapshot' => $meta]);
            // no break

        case 'snapshot_products':
            $vendorId = requireSafeId($_GET['vendorId'] ?? null, 'vendorId');
            $snapshotId = requireSafeId($_GET['snapshotId'] ?? null, 'snapshotId');
            $snap = $store->getSnapshot($vendorId, $snapshotId);
            if ($snap === null) {
                respond(['error' => 'snapshot not found'], 404);
            }
            respond(['products' => $snap['products'] ?? []]);
            // no break

        case 'site_prices':
            // Looks up the live, public storefront price for each vendor code, matched against
            // products.sku (exact, case/whitespace-insensitive) -- validated against the real
            // "ეს ჯი ჯი" vendor data: all 29 barcodes matched a sku exactly. Read-only, and the
            // prices returned are the same ones any shopper already sees on volta.ge, so this
            // needs no more protection than the rest of this deliberately public tool (see the
            // file-level comment above).
            $codes = $input['codes'] ?? [];
            if (!is_array($codes)) {
                respond(['error' => 'codes must be an array'], 400);
            }
            $norm = [];
            foreach ($codes as $c) {
                $c = strtoupper(trim((string) $c));
                if ($c !== '') {
                    $norm[$c] = true;
                }
            }
            $norm = array_keys($norm);
            if (count($norm) > 3000) {
                respond(['error' => 'too many codes (max 3000)'], 400);
            }
            if (empty($norm)) {
                respond(['prices' => []]);
            }
            $cfg = require __DIR__ . '/../../config.php';
            if (!isset($cfg['voltastoredb'])) {
                respond(['error' => 'voltastoredb is not configured on this server (config.php)'], 500);
            }
            $pdo = Database::connect($cfg['voltastoredb']);
            $placeholders = implode(',', array_fill(0, count($norm), '?'));
            $stmt = $pdo->prepare(
                "SELECT UPPER(TRIM(pr.sku)) AS sku, pf.name, pf.price, pf.special_price, pf.special_price_from, pf.special_price_to
                 FROM products pr
                 JOIN product_flat pf ON pf.product_id = pr.id AND pf.locale = 'ka_GE' AND pf.channel = 'default'
                 WHERE UPPER(TRIM(pr.sku)) IN ($placeholders)"
            );
            $stmt->execute($norm);
            $today = date('Y-m-d');
            $prices = [];
            foreach ($stmt->fetchAll() as $r) {
                $specialPrice = $r['special_price'] !== null ? (float) $r['special_price'] : null;
                $specialActive = $specialPrice !== null && $specialPrice > 0
                    && ($r['special_price_from'] === null || $r['special_price_from'] <= $today)
                    && ($r['special_price_to'] === null || $r['special_price_to'] >= $today);
                $prices[$r['sku']] = [
                    'name' => $r['name'],
                    'price' => $r['price'] !== null ? (float) $r['price'] : null,
                    'specialPrice' => $specialPrice,
                    'specialActive' => $specialActive,
                ];
            }
            respond(['prices' => $prices]);
            // no break

        case 'delete_snapshot':
            $vendorId = requireSafeId($input['vendorId'] ?? null, 'vendorId');
            $snapshotId = requireSafeId($input['snapshotId'] ?? null, 'snapshotId');
            $store->deleteSnapshot($vendorId, $snapshotId);
            respond(['ok' => true]);
            // no break

        default:
            respond(['error' => 'unknown action'], 400);
    }
} catch (\Throwable $e) {
    respond(['error' => $e->getMessage()], 500);
}
