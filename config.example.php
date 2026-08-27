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

    // RS.ge WayBillService + ntosservice (invoices) — 'su' format is "{service-user}:{TIN}",
    // NOT the portal login username. A dedicated RS.ge "service user" must be created in the
    // RS.ge cabinet first (see the volta_rsge_api Claude memory for how).
    'rsge' => [
        'su' => 'SERVICE_USER:TIN',
        'sp' => 'SERVICE_USER_PASSWORD',
        'invoice_user_id' => 0,  // returned by the RS.ge auth/chek call for this service user
        'invoice_un_id' => 0,    // returned by the RS.ge auth/chek call for this service user
    ],
];
