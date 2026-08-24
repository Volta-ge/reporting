<?php

declare(strict_types=1);

namespace Volta\Funnel;

/**
 * Meant to run via cron, once a day at 20:00 server time: counts how many loan applications
 * currently sit at instalments.Order_Status = 4 ("Pending" — the very first funnel stage,
 * captured the instant an application is submitted, before underwriting even looks at it;
 * see volta_sales_monthly / the "Pending status" chat thread for how this was identified and
 * why it's unrelated to the Google-Sheet-sourced "SALES — Pending Status" table on the same
 * Logistics Daily tab, despite the name coincidence).
 *
 * This is a point-in-time snapshot, same as "Not Delivered" on the Logistics table — the count
 * right now cannot be reconstructed for a past date later, so a day with no captured snapshot
 * just has no data point; there's no way to backfill it after the fact.
 *
 * Usage (add to crontab, once daily): 0 20 * * * /usr/bin/php /path/to/bin/capture_pending_status.php
 * Writes/updates one entry per calendar date in data/pending_status_log.json (keyed by date, so
 * running this more than once on the same day just overwrites that day's entry rather than
 * creating duplicates — safe to re-run manually for testing).
 */

require __DIR__ . '/../src/Database.php';

$configPath = __DIR__ . '/../config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Missing config.php — copy config.example.php to config.php and fill in real credentials.\n");
    exit(1);
}
$config = require $configPath;

try {
    $pdo = Database::connect($config['db']);
    $stmt = $pdo->query('SELECT COUNT(*) AS n FROM instalments WHERE Order_Status = 4');
    $row = $stmt->fetch();
    $count = (int) $row['n'];
} catch (\Throwable $e) {
    fwrite(STDERR, 'Capture failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$logPath = __DIR__ . '/../data/pending_status_log.json';
$log = [];
if (is_file($logPath)) {
    $decoded = json_decode((string) file_get_contents($logPath), true);
    if (is_array($decoded)) {
        $log = $decoded;
    }
}

$now = new \DateTimeImmutable('now');
$date = $now->format('Y-m-d');
$log[$date] = [
    'count' => $count,
    'capturedAt' => $now->format(\DateTimeInterface::ATOM),
];
ksort($log);

$dataDir = dirname($logPath);
if (!is_dir($dataDir) && !mkdir($dataDir, 0755, true) && !is_dir($dataDir)) {
    fwrite(STDERR, "Could not create data directory: {$dataDir}\n");
    exit(1);
}

$written = file_put_contents($logPath, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
if ($written === false) {
    fwrite(STDERR, "Could not write to {$logPath} — check the web server user has write access to the data/ directory.\n");
    exit(1);
}

echo "Captured Order_Status=4 (Pending) count = {$count} for {$date}\n";
