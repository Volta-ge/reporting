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

$store = new VendorStore(__DIR__ . '/../../data');
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
        case 'diag':
            // TEMPORARY diagnostic -- probing several candidate writable locations so we don't
            // have to touch server permissions if one of them already works. Remove once resolved.
            $candidates = [
                'data/ (repo root, current)' => __DIR__ . '/../../data/_diag_probe',
                'public/vendors/_store (inside webroot)' => __DIR__ . '/_diag_probe',
                'sys_get_temp_dir()' => rtrim(sys_get_temp_dir(), '/\\') . '/volta-vendors-diag',
                '/var/www/reporting (repo root itself)' => __DIR__ . '/../../_diag_probe',
            ];
            $results = [];
            foreach ($candidates as $label => $dir) {
                error_clear_last();
                $mkdirOk = is_dir($dir) || @mkdir($dir, 0775, true);
                $testFile = $dir . '/_diag_test_' . bin2hex(random_bytes(4)) . '.json';
                $writeBytes = $mkdirOk ? @file_put_contents($testFile, '{"ok":true}') : false;
                $readBack = $writeBytes !== false ? @file_get_contents($testFile) : null;
                if ($writeBytes !== false) {
                    @unlink($testFile);
                }
                $results[$label] = [
                    'path' => $dir,
                    'realpath' => realpath($dir) ?: realpath(dirname($dir)) ?: '(neither exists)',
                    'mkdir_ok' => $mkdirOk,
                    'write_ok' => $writeBytes !== false,
                    'read_back_ok' => $readBack === '{"ok":true}',
                    'error' => error_get_last()['message'] ?? null,
                ];
            }
            respond([
                'hostname' => gethostname(),
                'php_sapi' => PHP_SAPI,
                'whoami_uid' => function_exists('posix_getuid') ? posix_getuid() : null,
                'script_owner_uid' => @fileowner(__FILE__),
                'candidates' => $results,
            ]);
            // no break

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
                $clean[] = [
                    'sheet' => isset($p['sheet']) ? (string) $p['sheet'] : null,
                    'code' => isset($p['code']) && $p['code'] !== '' ? (string) $p['code'] : null,
                    'name' => isset($p['name']) && $p['name'] !== '' ? (string) $p['name'] : null,
                    'price' => isset($p['price']) && is_numeric($p['price']) ? (float) $p['price'] : null,
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
