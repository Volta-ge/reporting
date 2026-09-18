<?php
// CLI: recompute "For Sales" (for-sales/for_sales.html) from VoltaStoreDB and write the fresh numbers
// back into the committed template, in place. Idempotent (regex-replaces the three data consts).
//   php bin/for_sales_dump.php
// Run daily (see the volta-waybill-dashboard-refresh scheduled task) so the git-committed copy — and the
// GitHub Pages build (build_docs_waybills.py) that reads it — never goes stale.
declare(strict_types=1);

namespace Volta\Funnel;

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/ForSalesReport.php';

$config = require __DIR__ . '/../config.php';
if (!isset($config['voltastoredb'])) {
    fwrite(STDERR, "config.php has no 'voltastoredb' block (see config.example.php)\n");
    exit(1);
}

$page = __DIR__ . '/../for-sales/for_sales.html';
$html = file_get_contents($page);
if ($html === false) {
    fwrite(STDERR, "for-sales/for_sales.html not found\n");
    exit(1);
}

$t = microtime(true);
$pdo = Database::connect($config['voltastoredb']);
$data = (new ForSalesReport($pdo))->build();
$html = ForSalesReport::applyToHtml($html, $data);
file_put_contents($page, $html);

fwrite(STDERR, sprintf(
    "built in %.1fs -> %s (apps rows=%d, committee rows=%d, sales managers=%d, generatedAt=%s)\n",
    microtime(true) - $t,
    $page,
    count($data['apps']),
    count($data['committee']),
    count($data['salesByManager']),
    $data['generatedAt'],
));
