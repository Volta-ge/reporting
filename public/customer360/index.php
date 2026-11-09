<?php

declare(strict_types=1);

namespace Volta\Funnel;

// reporting.volta.ge/customer360/ -- "Volta Customer 360": one profile per customer with every application and
// loan, the Analysis tab and a per-customer assessment, computed live from VoltaStoreDB on every visit
// (src/Customer360Report.php), same page as the claude.ai Artifact N1W5yKCNsFVJwNYioapnRu.
//
// Unlike vendors/ this page IS behind requireBasicAuth(): it carries ~39K customers' personal numbers, phones,
// addresses and incomes. The repo is public, so nothing here or in customer360/customer360.html holds data:
// each build is written to sys_get_temp_dir() (the only place the PHP-FPM user can write, see vendors/api.php)
// under a random id and served by data.php, which checks the same password.

// ~65K orders, ~470K form fields, ~160K schedule rows and ~116K payments held in memory at once, and a per-loan
// waterfall -- same order of work as for_marketing.php, so the same limits.
ini_set('memory_limit', '1024M');
set_time_limit(180);

require __DIR__ . '/../../src/Database.php';
require __DIR__ . '/../../src/Customer360Report.php';
require __DIR__ . '/../../src/BasicAuth.php';

$configPath = __DIR__ . '/../../config.php';
$config = is_file($configPath) ? require $configPath : [];
requireBasicAuth($config);

$page = @file_get_contents(__DIR__ . '/../../customer360/customer360.html');
if ($page === false) {
    http_response_code(500);
    exit('Customer 360 page not found: customer360/customer360.html');
}
// The template loads its data as six sibling scripts (that's how the Artifact gets it); here they come from
// data.php for this request's build, so cut the page around that block.
if (!preg_match('/<script src="c360_meta\.js".*?c360_loans\.js" charset="utf-8"><\/script>/s', $page, $m, PREG_OFFSET_CAPTURE)) {
    http_response_code(500);
    exit('Customer 360 page has no data script block');
}
$head = substr($page, 0, $m[0][1]);
$tail = substr($page, $m[0][1] + strlen($m[0][0]));

// Streamed: the shell (header, tabs, empty table) paints immediately, the live build runs, then the data
// scripts and the page's own script follow.
header('Content-Type: text/html; charset=utf-8');
header('X-Accel-Buffering: no');
header('Cache-Control: no-store');
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);
echo "<!doctype html>\n<html lang=\"ka\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width,initial-scale=1,viewport-fit=cover\">\n</head>\n<body>\n";
echo $head;
flush();

$base = sys_get_temp_dir() . '/volta-c360';
$note = null;
$id = null;
try {
    if (!isset($config['voltastoredb'])) {
        throw new \RuntimeException('config.php has no voltastoredb block');
    }
    $pdo = Database::connect($config['voltastoredb']);
    $data = (new Customer360Report($pdo))->build();
    $id = bin2hex(random_bytes(8));
    Customer360Report::writeScripts($data, $base . '/' . $id);
    unset($data);
    // keep the last couple of hours of builds (a page someone still has open may reload its scripts), drop the rest
    foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (basename($dir) !== $id && filemtime($dir) < time() - 7200) {
            array_map('unlink', glob($dir . '/*.js') ?: []);
            @rmdir($dir);
        }
    }
} catch (\Throwable $e) {
    $root = $e;
    while ($root->getPrevious() !== null) {
        $root = $root->getPrevious();
    }
    $note = 'Customer 360: live build failed (' . $e->getMessage() . ($root !== $e ? ' Driver said: ' . $root->getMessage() : '') . ')';
}

if ($id !== null) {
    foreach (['meta', 'analysis', 'cust1', 'cust2', 'apps', 'loans'] as $part) {
        echo '<script src="data.php?b=' . $id . '&amp;p=' . $part . '" charset="utf-8"></script>' . "\n";
    }
} else {
    echo '<div style="background:#fff2cc;color:#6b6b6b;font:12px -apple-system,sans-serif;padding:6px 20px;">'
        . htmlspecialchars((string) $note, ENT_QUOTES) . "</div>\n";
}
echo $tail;
echo "\n</body>\n</html>\n";
