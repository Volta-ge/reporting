<?php
// CLI: build the Volta_Analytics_New DB "Customers Analyze" data live and write php_cust_data.json next to the
// Node pipeline's cust_data.json — used to cross-check the PHP port (NewDbCust) against pull_cust.sh + build_cust.js.
//   php bin/newdb_cust_dump.php [YYYY-MM-DD] [outdir]
// (the date is accepted for symmetry with newdb_dump.php; the series always runs through today, like pull_cust.sh's default END)
declare(strict_types=1);

namespace Volta\Funnel;

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/NewDbCust.php';

$config = require __DIR__ . '/../config.php';
if (!isset($config['voltastoredb'])) {
    fwrite(STDERR, "config.php has no 'voltastoredb' block (see config.example.php)\n");
    exit(1);
}
$end = $argv[1] ?? (new \DateTimeImmutable('yesterday'))->format('Y-m-d');
$out = $argv[2] ?? (__DIR__ . '/../volta-analytics-new-db');

$t = microtime(true);
$pdo = Database::connect($config['voltastoredb']);
$connect = microtime(true) - $t;
$dir = __DIR__ . '/../volta-analytics-new-db';
$cust = new NewDbCust($pdo, $dir, __DIR__ . '/../src/product_mapping.json');
$t2 = microtime(true);
$built = $cust->build($end);
$buildTime = microtime(true) - $t2;
$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
file_put_contents($out . '/php_cust_data.json', json_encode($built, $flags));
foreach ($cust->timings as $name => $sec) {
    fwrite(STDERR, sprintf("  query %-10s %6.2fs\n", $name, $sec));
}
fwrite(STDERR, sprintf("connect %.2fs  build %.2fs  total %.1fs  -> %s/php_cust_data.json\n", $connect, $buildTime, microtime(true) - $t, $out));
