<?php
// CLI: build the Volta_Analytics_New DB Operations group (Applications / Committee) live and write
// volta-analytics-new-db/php_ops_data.json — used to cross-check the PHP port (src/NewDbOps.php) against the Node
// pipeline (pull_ops.sh + build_ops.js -> ops_data.json). Per-query timings and the total build time go to STDERR.
//   php bin/newdb_ops_dump.php [YYYY-MM-DD] [outdir]
declare(strict_types=1);

namespace Volta\Funnel;

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/NewDbOps.php';

$config = require __DIR__ . '/../config.php';
if (!isset($config['voltastoredb'])) {
    fwrite(STDERR, "config.php has no 'voltastoredb' block (see config.example.php)\n");
    exit(1);
}
$end = $argv[1] ?? (new \DateTimeImmutable('yesterday'))->format('Y-m-d');
$out = $argv[2] ?? (__DIR__ . '/../volta-analytics-new-db');

$t = microtime(true);
$pdo = Database::connect($config['voltastoredb']);
$connected = microtime(true) - $t;
$dir = __DIR__ . '/../volta-analytics-new-db';
$ops = new NewDbOps($pdo, $dir, __DIR__ . '/../src/product_mapping.json');
$t2 = microtime(true);
$built = $ops->build($end);
$buildTime = microtime(true) - $t2;
file_put_contents($out . '/php_ops_data.json', json_encode($built, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
foreach ($ops->timings() as $name => $s) {
    fwrite(STDERR, sprintf("  query %-12s %6.2fs\n", $name, $s));
}
fwrite(STDERR, sprintf("end=%s (series through %s)  connect %.2fs  build %.2fs  total %.2fs  -> %s/php_ops_data.json\n", $end, $built['end'], $connected, $buildTime, microtime(true) - $t, $out));
