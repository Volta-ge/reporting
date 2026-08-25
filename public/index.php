<?php

declare(strict_types=1);

namespace Volta\Funnel;

require __DIR__ . '/../src/Segment.php';
require __DIR__ . '/../src/SegmentMetrics.php';
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/DateHelper.php';
require __DIR__ . '/../src/FunnelRepository.php';
require __DIR__ . '/../src/ProductClassifier.php';
require __DIR__ . '/../src/PortfolioRepository.php';

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

    // MTD Statistics tab: one column-pair per calendar month, Jan through the current month
    // (which naturally comes out as MTD-to-date since it's still in progress).
    $monthlyFrom = new \DateTimeImmutable($now->format('Y') . '-01-01');
    $monthlyStats = $repository->monthlySegmentStats($monthlyFrom, $mtdTo);

    // Daily Statistics tab: every day from June 1 through yesterday (today is excluded —
    // not yet finished — same convention as everywhere else in this project).
    $dailyFrom = new \DateTimeImmutable($now->format('Y') . '-06-01');
    $dailyStats = $repository->dailySegmentStats($dailyFrom, $yesterdayTo);

    // Sales Monthly / Brand Analyze / Subcategory Analyze tabs: same Jan-1-through-yesterday
    // window as MTD Statistics.
    $productClassifier = new ProductClassifier();
    $salesMonthlyStats = $repository->salesMonthlyStats($monthlyFrom, $yesterdayTo, $productClassifier);
    $brandStats = $repository->brandMonthlyStats($monthlyFrom, $yesterdayTo);
    $subcategoryStats = $repository->subcategoryMonthlyStats($monthlyFrom, $yesterdayTo, $productClassifier);

    // Customers / Risk Segmentation / Closed Loans / Overdue Analysis (portfolio) tabs.
    $portfolioRepository = new PortfolioRepository($pdo);
    $customerAnalysis = $portfolioRepository->customerAnalysis();
    $riskSegmentation = $portfolioRepository->riskSegmentation();
    $closedLoansMonthly = $portfolioRepository->closedLoansMonthly($monthlyFrom, $yesterdayTo);
    $delinquencyAnalysis = $portfolioRepository->delinquencyAnalysis();

    $connectionError = null;
} catch (\Throwable $e) {
    $data = null;
    $monthlyStats = null;
    $dailyStats = null;
    $salesMonthlyStats = null;
    $brandStats = null;
    $subcategoryStats = null;
    $customerAnalysis = null;
    $riskSegmentation = null;
    $closedLoansMonthly = null;
    $delinquencyAnalysis = null;
    $connectionError = $e->getMessage();
}

$targets = [
    'applications' => $config['targets']['applications'],
    'amount' => $config['targets']['amount'],
    'workingDaysLeft' => DateHelper::remainingWorkingDays($now),
];

$headerYesterday = $yesterdayFrom->format('M j, Y');
$headerMtdRange = $mtdFrom->format('M j') . '–' . $yesterdayFrom->format('j, Y');
$generatedAt = $now->format(\DateTimeInterface::ATOM);

// Loan Applications — Pending (Order_Status=4) tab: written daily by bin/capture_pending_status.php
// (run via cron on the server, since it's a point-in-time snapshot that can't be reconstructed
// after the fact). A plain file read, independent of the DB try/catch above — a missing/corrupt
// log just means "no snapshots yet," not a page-breaking error.
$pendingStatusLogPath = __DIR__ . '/../data/pending_status_log.json';
$pendingStatusLog = [];
if (is_file($pendingStatusLogPath)) {
    $decoded = json_decode((string) file_get_contents($pendingStatusLogPath), true);
    if (is_array($decoded)) {
        $pendingStatusLog = $decoded;
    }
}

require __DIR__ . '/templates/dashboard.php';
