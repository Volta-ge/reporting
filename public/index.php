<?php
// reporting.volta.ge entry point — serves Volta_Analytics_New DB, the dashboard rebuilt on VoltaStoreDB
// (see ../volta-analytics-new-db/README.md). It is a static build that is refreshed on request and
// committed; deploying an update is just `git pull`.
//
// The previous live old-DB (myvolta.info) streaming dashboard is kept, unchanged, as index_olddb.php
// (restore point: git tag olddb-live-dashboard).
declare(strict_types=1);

$page = __DIR__ . '/../volta-analytics-new-db/deals_amount_migration.html';
if (!is_file($page)) {
    http_response_code(500);
    exit('Volta_Analytics_New DB build not found: volta-analytics-new-db/deals_amount_migration.html');
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
echo "<!doctype html>\n<html lang=\"ka\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">\n</head>\n<body>\n";
readfile($page);
echo "\n</body>\n</html>\n";
