<?php

declare(strict_types=1);

namespace Volta\Funnel;

// reporting.volta.ge/customer360/data.php?b=<build>&p=<part> -- serves one of the six window.C360_* scripts that
// index.php wrote for its build. Same password as the page: these files ARE the customer data.

require __DIR__ . '/../../src/BasicAuth.php';

$configPath = __DIR__ . '/../../config.php';
$config = is_file($configPath) ? require $configPath : [];
requireBasicAuth($config);

$b = (string) ($_GET['b'] ?? '');
$p = (string) ($_GET['p'] ?? '');
if (!preg_match('/^[a-f0-9]{16}$/', $b) || !in_array($p, ['meta', 'analysis', 'cust1', 'cust2', 'apps', 'loans'], true)) {
    http_response_code(400);
    exit('bad request');
}
$file = sys_get_temp_dir() . '/volta-c360/' . $b . '/' . $p . '.js';
if (!is_file($file)) {
    http_response_code(404);
    header('Content-Type: application/javascript; charset=utf-8');
    exit("/* build expired -- reload the page */\n");
}
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: private, max-age=7200');
header('Content-Length: ' . filesize($file));
readfile($file);
