<?php
// reporting.volta.ge/for_marketing.php — "For Marketing": good-payers list, live.
//
// Serves for-marketing/for_marketing.html with its data consts (GOOD_PAYERS/NEVER_PURCHASED/FM_SUMMARY/
// GENERATED_AT) recomputed from VoltaStoreDB on EVERY request (src/ForMarketingReport.php) — a plain
// browser refresh always shows the current moment's data, per the user's 2026-09-25 standing rule (see
// feedback_prefer_live_refresh_over_static_snapshots), not just a manual "?refresh=1". The per-day JSON
// file in data/ is kept only as a fallback the live query can fall back to if the DB is briefly
// unreachable — it is written every time but never trusted as "fresh enough" on its own. This computation
// takes ~15-30s (a per-loan waterfall over the whole schedule/payments history), so every visit pays that
// cost now — a deliberate tradeoff the user chose over caching. If config.php has no 'voltastoredb' block,
// the last committed static build is served instead (kept fresh daily by bin/for_marketing_dump.php).
declare(strict_types=1);

namespace Volta\Funnel;

// build() now loads the full customers table, every order (not just customer_id-linked ones),
// order_items, addresses and volta_application_data (the 2026-09-22 PID-widening/guest-order work) —
// 256M was fine for the original GOOD_PAYERS-only query set but fatals (bare 500, no fallback banner:
// PHP's OOM kill happens before the try/catch can run) under this larger one. Matches bin/
// for_marketing_dump.php's own 1024M.
ini_set('memory_limit', '1024M');
// The real per-loan waterfall takes ~15-30s (measured on this server) — now run on EVERY visit (no more
// cache-serve, see the file's top comment), so PHP's default 30s max_execution_time is a real risk of
// failing exactly the requests that take the longest, not a theoretical one (hit it once while testing
// this change, 2026-09-25). Same 120s margin as index.php's live build.
set_time_limit(120);

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/ForMarketingReport.php';

$page = __DIR__ . '/../for-marketing/for_marketing.html';
$html = @file_get_contents($page);
if ($html === false) {
    http_response_code(500);
    exit('For Marketing build not found: for-marketing/for_marketing.html');
}

$fallbackNote = null;
$configPath = __DIR__ . '/../config.php';
$config = is_file($configPath) ? require $configPath : [];

if (!isset($config['voltastoredb'])) {
    $fallbackNote = 'config.php has no voltastoredb block — showing the last committed build';
} else {
    $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
    $cacheDir = __DIR__ . '/../data';
    $cacheFile = $cacheDir . '/for_marketing_' . $today . '.json';
    // Always recompute live — the cache file below is written every time only as an emergency fallback
    // for the catch block (DB briefly unreachable), never read as if it were "fresh enough" to serve.
    $cached = null;
    try {
        $pdo = Database::connect($config['voltastoredb']);
        $cached = (new ForMarketingReport($pdo))->build();
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }
        @file_put_contents($cacheFile, json_encode($cached, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        foreach (glob($cacheDir . '/for_marketing_*.json') ?: [] as $old) {
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
        if (is_array($stale) && isset($stale['rows'])) {
            $cached = $stale;
            $fallbackNote = 'live refresh failed (' . $why . ') — showing the last cached numbers from ' . ($stale['generatedAt'] ?? '?');
        } else {
            $cached = null;
            $fallbackNote = 'live refresh failed (' . $why . ') — showing the last committed build';
        }
    }

    if (is_array($cached) && isset($cached['rows'], $cached['summary'])) {
        $html = ForMarketingReport::applyToHtml($html, $cached);
    }
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
echo "<!doctype html>\n<html lang=\"ka\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">\n</head>\n<body>\n";
if ($fallbackNote !== null) {
    echo '<div style="background:#fff2cc;color:#6b6b6b;font:12px -apple-system,sans-serif;padding:6px 20px;">For Marketing: ' . htmlspecialchars($fallbackNote, ENT_QUOTES) . "</div>\n";
}
echo $html;
echo "\n</body>\n</html>\n";
