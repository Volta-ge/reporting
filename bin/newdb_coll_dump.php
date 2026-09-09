<?php
// CLI: build the Collections Analyze block (COLL_JSON) live and write php_coll_data.json next to the Node pipeline's
// coll_data.json — used to cross-check the PHP port (src/NewDbColl.php) against pull_coll.sh + build_coll.js.
// Prints per-query timings and the build time to STDERR.
//   php bin/newdb_coll_dump.php [YYYY-MM-DD] [outdir]
declare(strict_types=1);

namespace Volta\Funnel;

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/NewDbColl.php';

$config = require __DIR__ . '/../config.php';
if (!isset($config['voltastoredb'])) {
    fwrite(STDERR, "config.php has no 'voltastoredb' block (see config.example.php)\n");
    exit(1);
}
$end = $argv[1] ?? (new \DateTimeImmutable('yesterday'))->format('Y-m-d');
$out = $argv[2] ?? (__DIR__ . '/../volta-analytics-new-db');

$t0 = microtime(true);
$pdo = Database::connect($config['voltastoredb']);
$tConnect = microtime(true) - $t0;
$dir = __DIR__ . '/../volta-analytics-new-db';
$coll = new NewDbColl($pdo, $dir, __DIR__ . '/../src/product_mapping.json');
$t1 = microtime(true);
$built = $coll->build($end);
$tBuild = microtime(true) - $t1;
$json = json_encode($built, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    fwrite(STDERR, 'json_encode failed: ' . json_last_error_msg() . "\n");
    exit(1);
}
file_put_contents($out . '/php_coll_data.json', $json);
foreach ($coll->timings() as $name => $sec) {
    fwrite(STDERR, sprintf("  %-10s %6.2fs\n", $name, $sec));
}
fwrite(STDERR, sprintf("end=%s  connect %.2fs  build %.2fs  -> %s/php_coll_data.json\n", $end, $tConnect, $tBuild, $out));
