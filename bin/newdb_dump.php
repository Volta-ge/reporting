<?php
// CLI: build the Volta_Analytics_New DB data live and write report_data.json / sales_data.json next to this
// script's target dir — used to cross-check the PHP port against the Node pipeline (same END date).
//   php bin/newdb_dump.php [YYYY-MM-DD] [outdir]
declare(strict_types=1);

namespace Volta\Funnel;

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/NewDbReport.php';

$config = require __DIR__ . '/../config.php';
if (!isset($config['voltastoredb'])) {
    fwrite(STDERR, "config.php has no 'voltastoredb' block (see config.example.php)\n");
    exit(1);
}
$end = $argv[1] ?? (new \DateTimeImmutable('yesterday'))->format('Y-m-d');
$out = $argv[2] ?? (__DIR__ . '/../volta-analytics-new-db');

$t = microtime(true);
$pdo = Database::connect($config['voltastoredb']);
$dir = __DIR__ . '/../volta-analytics-new-db';
$built = (new NewDbReport($pdo, $dir, __DIR__ . '/../src/product_mapping.json'))->build($end);
$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
file_put_contents($out . '/php_report_data.json', json_encode($built['report'], $flags));
file_put_contents($out . '/php_sales_data.json', json_encode($built['sales'], $flags));
file_put_contents($out . '/php_logistics_data.json', json_encode($built['logistics'], $flags));
foreach (NewDbReport::GROUPS as $key => $class) {
    if (isset($built[$key])) { file_put_contents($out . '/php_' . $key . '_data.json', json_encode($built[$key], $flags)); }
}
fwrite(STDERR, sprintf("end=%s  built in %.1fs  -> %s/php_report_data.json, php_sales_data.json, php_logistics_data.json\n", $end, microtime(true) - $t, $out));
