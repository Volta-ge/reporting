<?php
// CLI: build the Portfolio -> Portfolio Analyze data (Volta_Analytics_New DB) live and write php_pf_data.json next to
// the Node pipeline's pf_data.json — used to cross-check the PHP port (same END date as `bash pull_pf.sh END`).
//   php bin/newdb_pf_dump.php [YYYY-MM-DD] [outdir]
// END defaults to TODAY, like pull_pf.sh (the tab's last column is labelled "(today)"), not yesterday.
declare(strict_types=1);

namespace Volta\Funnel;

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/NewDbPf.php';

$config = require __DIR__ . '/../config.php';
if (!isset($config['voltastoredb'])) {
    fwrite(STDERR, "config.php has no 'voltastoredb' block (see config.example.php)\n");
    exit(1);
}
$end = $argv[1] ?? (new \DateTimeImmutable('today'))->format('Y-m-d');
$out = $argv[2] ?? (__DIR__ . '/../volta-analytics-new-db');

$t = microtime(true);
$pdo = Database::connect($config['voltastoredb']);
$connect = microtime(true) - $t;
$dir = __DIR__ . '/../volta-analytics-new-db';
$pf = new NewDbPf($pdo, $dir, __DIR__ . '/../src/product_mapping.json');
$t1 = microtime(true);
$built = $pf->build($end);
$buildSecs = microtime(true) - $t1;
file_put_contents($out . '/php_pf_data.json', json_encode($built, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
foreach ($pf->timings as $name => $secs) {
    fwrite(STDERR, sprintf("  %-20s %6.3fs\n", $name, $secs));
}
fwrite(STDERR, sprintf("end=%s  connect %.2fs  build %.2fs  total %.2fs  -> %s/php_pf_data.json\n", $end, $connect, $buildSecs, microtime(true) - $t, $out));
