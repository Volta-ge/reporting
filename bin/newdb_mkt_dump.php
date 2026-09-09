<?php
// CLI: build the Marketing -> Leads block of Volta_Analytics_New DB live (src/NewDbMkt.php) and write
// php_mkt_data.json next to the Node pipeline's mkt_data.json — used to cross-check the PHP port
// (same END date; the Leads calendar itself runs through today like pull_mkt.sh's default).
//   php bin/newdb_mkt_dump.php [YYYY-MM-DD] [outdir]
declare(strict_types=1);

namespace Volta\Funnel;

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/NewDbMkt.php';

$config = require __DIR__ . '/../config.php';
if (!isset($config['voltastoredb'])) {
    fwrite(STDERR, "config.php has no 'voltastoredb' block (see config.example.php)\n");
    exit(1);
}
$end = $argv[1] ?? (new \DateTimeImmutable('yesterday'))->format('Y-m-d');
$out = $argv[2] ?? (__DIR__ . '/../volta-analytics-new-db');

$t = microtime(true);
$pdo = Database::connect($config['voltastoredb']);
$tConnect = microtime(true) - $t;
$dir = __DIR__ . '/../volta-analytics-new-db';
$mkt = new NewDbMkt($pdo, $dir, __DIR__ . '/../src/product_mapping.json');
$tb = microtime(true);
$built = $mkt->build($end);
$tBuild = microtime(true) - $tb;
file_put_contents($out . '/php_mkt_data.json', json_encode($built, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
foreach ($mkt->timings() as $name => $s) {
    fwrite(STDERR, sprintf("  query %-9s %6.2fs\n", $name, $s));
}
fwrite(STDERR, sprintf("end=%s  connect %.2fs  build %.2fs  total %.1fs  -> %s/php_mkt_data.json\n", $end, $tConnect, $tBuild, microtime(true) - $t, $out));
