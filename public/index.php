<?php

declare(strict_types=1);

namespace Volta\Funnel;

require __DIR__ . '/../src/Segment.php';
require __DIR__ . '/../src/SegmentMetrics.php';
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/DateHelper.php';
require __DIR__ . '/../src/FunnelRepository.php';

$configPath = __DIR__ . '/../config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    exit('Missing config.php — copy config.example.php to config.php and fill in real credentials.');
}
$config = require $configPath;

$now = new \DateTimeImmutable('now');
[$yesterdayFrom, $yesterdayTo] = DateHelper::yesterdayRange($now);
[$mtdFrom, $mtdTo] = DateHelper::monthToDateRange($now);

try {
    $pdo = Database::connect($config['db']);
    $repository = new FunnelRepository($pdo);

    $data = [
        'mtd' => [
            'label' => sprintf('MTD (%s–%s)', $mtdFrom->format('M j'), $yesterdayFrom->format('j, Y')),
            'A' => $repository->segmentMetrics($mtdFrom, $mtdTo, Segment::A)->toArray(),
            'B' => $repository->segmentMetrics($mtdFrom, $mtdTo, Segment::B)->toArray(),
        ],
        'yest' => [
            'label' => sprintf('Yesterday (%s)', $yesterdayFrom->format('M j, Y')),
            'A' => $repository->segmentMetrics($yesterdayFrom, $yesterdayTo, Segment::A)->toArray(),
            'B' => $repository->segmentMetrics($yesterdayFrom, $yesterdayTo, Segment::B)->toArray(),
        ],
    ];
    $connectionError = null;
} catch (\Throwable $e) {
    $data = null;
    $connectionError = $e->getMessage();
}

$targets = [
    'applications' => $config['targets']['applications'],
    'amount' => $config['targets']['amount'],
    'workingDaysLeft' => DateHelper::remainingWorkingDays($now),
];

$headerYesterday = $yesterdayFrom->format('M j, Y');
$headerMtdRange = $mtdFrom->format('M j') . '–' . $yesterdayFrom->format('j, Y');
$generatedDate = $now->format('Y-m-d');
$generatedAt = $now->format(\DateTimeInterface::ATOM);

require __DIR__ . '/templates/dashboard.php';
