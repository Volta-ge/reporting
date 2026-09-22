<?php
// reporting.volta.ge/for_sales.php — "For Sales": application pipeline, live.
//
// Serves for-sales/for_sales.html with its data consts (APPS/COMMITTEE/COMMITTEE_BY_MANAGER/
// SALES_BY_MANAGER/GENERATED_AT) recomputed from VoltaStoreDB right now (src/ForSalesReport.php). Cached
// in data/ for CACHE_TTL seconds, keyed by today's date, so a normal page load costs nothing; ?refresh=1
// forces a recompute. If config.php has no 'voltastoredb' block or the database is unreachable, the last
// committed static build is served instead (kept fresh daily by bin/for_sales_dump.php), with a note.
declare(strict_types=1);

namespace Volta\Funnel;

const CACHE_TTL = 3600;

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/ForSalesReport.php';

$page = __DIR__ . '/../for-sales/for_sales.html';
$html = @file_get_contents($page);
if ($html === false) {
    http_response_code(500);
    exit('For Sales build not found: for-sales/for_sales.html');
}

$fallbackNote = null;
$configPath = __DIR__ . '/../config.php';
$config = is_file($configPath) ? require $configPath : [];

if (!isset($config['voltastoredb'])) {
    $fallbackNote = 'config.php has no voltastoredb block — showing the last committed build';
} else {
    $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
    $cacheDir = __DIR__ . '/../data';
    $cacheFile = $cacheDir . '/for_sales_' . $today . '.json';
    $force = isset($_GET['refresh']);
    $cached = (!$force && is_file($cacheFile) && (time() - filemtime($cacheFile)) < CACHE_TTL)
        ? json_decode((string) file_get_contents($cacheFile), true) : null;

    if (!is_array($cached) || !isset($cached['apps'], $cached['committee'], $cached['committeeByManager'], $cached['salesByManager'])) {
        try {
            $pdo = Database::connect($config['voltastoredb']);
            $cached = (new ForSalesReport($pdo))->build();
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0775, true);
            }
            @file_put_contents($cacheFile, json_encode($cached, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
            foreach (glob($cacheDir . '/for_sales_*.json') ?: [] as $old) {
                if ($old !== $cacheFile) {
                    @unlink($old);
                }
            }
        } catch (\Throwable $e) {
            $root = $e;
            while ($root->getPrevious() !== null) {
                $root = $root->getPrevious();
            }
            $why = $root === $e ? $e->getMessage() : ($e->getMessage() . ' Driver said: ' . $root->getMessage());
            $stale = is_file($cacheFile) ? json_decode((string) file_get_contents($cacheFile), true) : null;
            if (is_array($stale) && isset($stale['apps'], $stale['committee'], $stale['committeeByManager'], $stale['salesByManager'])) {
                $cached = $stale;
                $fallbackNote = 'live refresh failed (' . $why . ') — showing the last cached numbers from ' . ($stale['generatedAt'] ?? '?');
            } else {
                $cached = null;
                $fallbackNote = 'live refresh failed (' . $why . ') — showing the last committed build';
            }
        }
    }

    if (is_array($cached) && isset($cached['apps'], $cached['committee'], $cached['committeeByManager'], $cached['salesByManager'])) {
        $html = ForSalesReport::applyToHtml($html, $cached);
    }
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
echo "<!doctype html>\n<html lang=\"ka\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">\n</head>\n<body>\n";
if ($fallbackNote !== null) {
    echo '<div style="background:#fff2cc;color:#6b6b6b;font:12px -apple-system,sans-serif;padding:6px 20px;">For Sales: ' . htmlspecialchars($fallbackNote, ENT_QUOTES) . "</div>\n";
}
echo $html;
echo "\n</body>\n</html>\n";
