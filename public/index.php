<?php
// reporting.volta.ge entry point — Volta_Analytics_New DB, live.
//
// The page is the static build volta-analytics-new-db/deals_amount_migration.html with its two embedded data
// blocks (REPORT_JSON, SALES_JSON) replaced by numbers computed right now from VoltaStoreDB (src/NewDbReport.php;
// pre-cutover history comes from the frozen old-DB extracts in the same folder). Results are cached in data/ for
// CACHE_TTL seconds, keyed by "yesterday", so the day rolls over automatically and a normal page load costs
// nothing; ?refresh=1 forces a recompute. If config.php has no 'voltastoredb' block or the database is
// unreachable, the last committed static build is served instead, with a note.
//
// The previous live old-DB (myvolta.info) streaming dashboard is kept, unchanged, as index_olddb.php
// (restore point: git tag olddb-live-dashboard).
declare(strict_types=1);

namespace Volta\Funnel;

const CACHE_TTL = 3600;

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/NewDbReport.php';

$page = __DIR__ . '/../volta-analytics-new-db/deals_amount_migration.html';
$html = @file_get_contents($page);
if ($html === false) {
    http_response_code(500);
    exit('Volta_Analytics_New DB build not found: volta-analytics-new-db/deals_amount_migration.html');
}

$fallbackNote = null;
$streamed = false;
$configPath = __DIR__ . '/../config.php';
$config = is_file($configPath) ? require $configPath : [];

if (!isset($config['voltastoredb'])) {
    $fallbackNote = 'config.php has no voltastoredb block — showing the last committed build';
} else {
    $end = (new \DateTimeImmutable('yesterday'))->format('Y-m-d');
    $cacheDir = __DIR__ . '/../data';
    $cacheFile = $cacheDir . '/newdb_' . $end . '.json';
    $force = isset($_GET['refresh']);
    $cached = (!$force && is_file($cacheFile) && (time() - filemtime($cacheFile)) < CACHE_TTL) ? json_decode((string) file_get_contents($cacheFile), true) : null;
    $complete = static fn ($c) => is_array($c) && isset($c['report'], $c['sales']) && !array_diff_key(NewDbReport::GROUPS, $c);
    if (!$complete($cached)) {
        try {
            set_time_limit(120);
            $pdo = Database::connect($config['voltastoredb']);
            $report = new NewDbReport($pdo, __DIR__ . '/../volta-analytics-new-db', __DIR__ . '/../src/product_mapping.json');

            // No usable cache: a blocking build() here would hold the reader on a blank tab for
            // the full ~15-20s the 5 database-heavy groups take (see buildStreaming()'s docblock).
            // Stream instead — send the shell the instant the cheap "core" groups are ready (report/
            // sales/logistics, ~5s), with the 5 slower groups still showing their last committed
            // numbers, then replace each one in place as its own query returns. ?refresh=1 also
            // streams (it always misses the cache by design).
            $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
            $setConst = static function (string &$html, string $name, $payload) use ($flags): bool {
                $const = 'const ' . $name . ' = ';
                $html = preg_replace('/^' . preg_quote($const, '/') . '.*;$/m', $const . json_encode($payload, $flags) . ';', $html, 1, $count);
                return $count === 1;
            };
            $jsFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE;
            $streamErrors = [];

            header('Content-Type: text/html; charset=utf-8');
            header('X-Accel-Buffering: no');
            header('Cache-Control: no-store');
            @ini_set('zlib.output_compression', '0');
            @ini_set('output_buffering', '0');
            @ini_set('implicit_flush', '1');
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            ob_implicit_flush(true);

            $cached = $report->buildStreaming($end, function (string $key, ?array $payload, ?string $error) use (&$html, $setConst, $jsFlags, &$streamErrors) {
                if ($key === 'core') {
                    // $payload is ['report'=>..., 'sales'=>..., 'logistics'=>...] — substitute into the
                    // shell and send it; the 5 slower groups below still carry their committed numbers.
                    $setConst($html, 'REPORT_JSON', $payload['report']);
                    $setConst($html, 'SALES_JSON', $payload['sales']);
                    $setConst($html, 'LOGI_JSON', $payload['logistics']);
                    echo "<!doctype html>\n<html lang=\"ka\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">\n</head>\n<body>\n";
                    echo $html;
                    echo '<script>window.__streamInit && window.__streamInit(5);</script>' . "\n";
                    flush();
                    return;
                }
                if ($error !== null) {
                    // no data script for this one — that tab keeps its committed numbers — but still
                    // advance the progress banner so it reaches 100% even when a group fails
                    $streamErrors[$key] = $error;
                    echo "<script>window.__onLiveSection && window.__onLiveSection('" . $key . "');</script>\n";
                } else {
                    echo '<script>window.' . strtoupper($key) . '_JSON = ' . json_encode($payload, $jsFlags) . "; window.__onLiveSection && window.__onLiveSection('" . $key . "');</script>\n";
                }
                flush();
            });

            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0775, true);
            }
            @file_put_contents($cacheFile, json_encode($cached, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
            foreach (glob($cacheDir . '/newdb_*.json') ?: [] as $old) {
                if ($old !== $cacheFile) {
                    @unlink($old);
                }
            }

            if (!empty($streamErrors)) {
                $note = 'Volta_Analytics_New DB: live numbers for ' . implode(', ', array_keys($streamErrors)) . ' could not be computed (' . implode(' | ', $streamErrors) . ') - those tabs show the last committed build';
                echo '<script>(function(){var d=document.createElement("div");d.style.cssText="background:#fff2cc;color:#6b6b6b;font:12px -apple-system,sans-serif;padding:6px 20px;";d.textContent=' . json_encode($note, JSON_UNESCAPED_UNICODE) . ';document.body.insertBefore(d, document.body.firstChild);})();</script>' . "\n";
            }
            echo '<script>window.__streamComplete && window.__streamComplete();</script>' . "\n";
            echo "\n</body>\n</html>\n";
            flush();
            $streamed = true;
        } catch (\Throwable $e) {
            // Database::connect() wraps the driver error; surface the root cause so the note itself says
            // whether it is a wrong password ("Access denied for user ...") or a network/firewall problem
            // ("Connection timed out" / "Connection refused" = this server's IP is not allowed on the RDS
            // security group, port 3306).
            $root = $e;
            while ($root->getPrevious() !== null) {
                $root = $root->getPrevious();
            }
            $why = $root === $e ? $e->getMessage() : ($e->getMessage() . ' Driver said: ' . $root->getMessage());
            $stale = is_file($cacheFile) ? json_decode((string) file_get_contents($cacheFile), true) : null;
            if (is_array($stale) && isset($stale['report'], $stale['sales'])) {
                $cached = $stale;
                $fallbackNote = 'live refresh failed (' . $why . ') — showing the last cached numbers from ' . ($stale['report']['generatedAt'] ?? '?');
            } else {
                $cached = null;
                $fallbackNote = 'live refresh failed (' . $why . ') — showing the last committed build';
            }
        }
    }
    if (!$streamed && is_array($cached) && !empty($cached['errors']) && $fallbackNote === null) {
        $fallbackNote = 'live numbers for ' . implode(', ', array_keys($cached['errors'])) . ' could not be computed (' . implode(' | ', $cached['errors']) . ') - those tabs show the last committed build';
    }
    if (!$streamed && is_array($cached)) {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $html = preg_replace('/^const REPORT_JSON = .*;$/m', 'const REPORT_JSON = ' . json_encode($cached['report'], $flags) . ';', $html, 1, $c1);
        $html = preg_replace('/^const SALES_JSON = .*;$/m', 'const SALES_JSON = ' . json_encode($cached['sales'], $flags) . ';', $html, 1, $c2);
        if (isset($cached['logistics'])) {
            $html = preg_replace('/^const LOGI_JSON = .*;$/m', 'const LOGI_JSON = ' . json_encode($cached['logistics'], $flags) . ';', $html, 1);
        }
        foreach (NewDbReport::GROUPS as $key => $class) {
            if (!isset($cached[$key])) { continue; }
            $const = 'const ' . strtoupper($key) . '_JSON = ';
            $html = preg_replace('/^' . preg_quote($const, '/') . '.*;$/m', $const . json_encode($cached[$key], $flags) . ';', $html, 1);
        }
        if ($c1 !== 1 || $c2 !== 1) {
            $html = (string) file_get_contents($page);
            $fallbackNote = 'template anchors not found — showing the last committed build';
        }
    }
}

// The streaming path (above) already sent its own headers and the full response — nothing left
// to do here. Every other path (cache hit, config/DB unavailable, live refresh failed) still
// renders and sends the whole page in one shot, exactly as before streaming existed.
if (!$streamed) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    echo "<!doctype html>\n<html lang=\"ka\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">\n</head>\n<body>\n";
    if ($fallbackNote !== null) {
        echo '<div style="background:#fff2cc;color:#6b6b6b;font:12px -apple-system,sans-serif;padding:6px 20px;">Volta_Analytics_New DB: ' . htmlspecialchars($fallbackNote, ENT_QUOTES) . "</div>\n";
    }
    echo $html;
    echo "\n</body>\n</html>\n";
}
