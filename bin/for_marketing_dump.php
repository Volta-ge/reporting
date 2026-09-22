<?php
// CLI: recompute "For Marketing" (for-marketing/for_marketing.html) from VoltaStoreDB and write the fresh
// numbers back into the committed template, in place. Idempotent (regex-replaces the three data consts).
//   php bin/for_marketing_dump.php
// Run daily (see the volta-waybill-dashboard-refresh scheduled task) so the git-committed copy — and the
// GitHub Pages build (build_docs_waybills.py) that reads it — never goes stale.
declare(strict_types=1);

namespace Volta\Funnel;

ini_set('memory_limit', '1024M');

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/ForMarketingReport.php';

$config = require __DIR__ . '/../config.php';
if (!isset($config['voltastoredb'])) {
    fwrite(STDERR, "config.php has no 'voltastoredb' block (see config.example.php)\n");
    exit(1);
}

$page = __DIR__ . '/../for-marketing/for_marketing.html';
$html = file_get_contents($page);
if ($html === false) {
    fwrite(STDERR, "for-marketing/for_marketing.html not found\n");
    exit(1);
}

$t = microtime(true);
$pdo = Database::connect($config['voltastoredb']);
$data = (new ForMarketingReport($pdo))->build();
$html = ForMarketingReport::applyToHtml($html, $data);
file_put_contents($page, $html);

fwrite(STDERR, sprintf(
    "built in %.1fs -> %s (goodPayers=%d, asOf=%s, generatedAt=%s)\n",
    microtime(true) - $t,
    $page,
    count($data['rows']),
    $data['summary']['asOf'],
    $data['generatedAt'],
));
