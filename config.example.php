<?php

declare(strict_types=1);

/**
 * Copy this file to config.php (which is git-ignored) and fill in real values.
 * config.php is required by public/index.php and must never be committed.
 */
return [
    'db' => [
        'host' => 'myvolta.info',
        'port' => 3306,
        'database' => 'myvolta8_voltadb',
        'username' => 'myvolta8_analysis',
        'password' => 'CHANGE_ME',
    ],

    // Business-set monthly goals — not derived from the database. Update here when targets change.
    'targets' => [
        'applications' => 2500,
        'amount' => 1900000,
    ],
];
