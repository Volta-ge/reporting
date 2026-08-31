<?php

declare(strict_types=1);

namespace Volta\Funnel;

// This page runs its reports live against a remote DB (myvolta.info) on every load — no cached
// or snapshotted data anywhere. Per-query round-trip latency, not payload size, dominates the
// page's wall time (measured 25-30s end-to-end on 2026-08-26, when the same reports took 47
// round trips); the query set has since been consolidated to roughly two thirds of that, mostly
// by merging queries that scanned identical rows and only differed in their GROUP BY. The limit
// stays raised as a safety margin against network variance rather than as licence to add
// queries back.
set_time_limit(90);

require __DIR__ . '/../src/Segment.php';
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/DateHelper.php';
require __DIR__ . '/../src/FunnelRepository.php';
require __DIR__ . '/../src/ProductClassifier.php';
require __DIR__ . '/../src/PortfolioRepository.php';
require __DIR__ . '/../src/IncomeDelinquencyRepository.php';
require __DIR__ . '/../src/ExCustomerRepository.php';

$configPath = __DIR__ . '/../config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    exit('Missing config.php — copy config.example.php to config.php and fill in real credentials.');
}
$config = require $configPath;

$now = new \DateTimeImmutable('now');
[$yesterdayFrom, $yesterdayTo] = DateHelper::yesterdayRange($now);
[$mtdFrom, $mtdTo] = DateHelper::monthToDateRange($now);
$monthlyFrom = new \DateTimeImmutable($now->format('Y') . '-01-01');
$dailyFrom = new \DateTimeImmutable($now->format('Y') . '-06-01');

// Everything the page shell needs is derived without touching the database, so the shell can be
// sent and painted before the first query runs.
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
// after the fact). A plain file read, independent of the DB — a missing/corrupt log just means
// "no snapshots yet," not a page-breaking error.
$pendingStatusLogPath = __DIR__ . '/../data/pending_status_log.json';
$pendingStatusLog = [];
if (is_file($pendingStatusLogPath)) {
    $decoded = json_decode((string) file_get_contents($pendingStatusLogPath), true);
    if (is_array($decoded)) {
        $pendingStatusLog = $decoded;
    }
}

// The connection is the one thing that must succeed before anything is sent — a failure here is
// still a clean, whole-page error rather than a half-rendered stream.
try {
    $pdo = Database::connect($config['db']);
} catch (\Throwable $e) {
    $connectionError = $e->getMessage();
    require __DIR__ . '/templates/dashboard.php';
    return;
}
$connectionError = null;

$repository = new FunnelRepository($pdo);
$productClassifier = new ProductClassifier();
$incomeDelinquencyRepository = new IncomeDelinquencyRepository($pdo);
$portfolioRepository = new PortfolioRepository($pdo);
$exCustomerRepository = new ExCustomerRepository($pdo);

/**
 * Each report, in the order it is streamed. Ordered cheapest-and-most-looked-at first, so the
 * funnel the page opens on fills in almost immediately and the heavy portfolio reports arrive
 * while it is already readable. Every closure is called exactly once, on this page load — no
 * caching, no snapshots.
 */
$sections = [
    'monthlyStats' => fn () => $repository->monthlySegmentStats($monthlyFrom, $mtdTo),
    'dailyStats' => fn () => $repository->dailySegmentStats($dailyFrom, $yesterdayTo),
    'salesMonthlyStats' => fn () => $repository->salesMonthlyStats($monthlyFrom, $yesterdayTo, $productClassifier),
    'brandStats' => fn () => $repository->brandMonthlyStats($monthlyFrom, $yesterdayTo),
    'subcategoryStats' => fn () => $repository->subcategoryMonthlyStats($monthlyFrom, $yesterdayTo, $productClassifier),
    'categoryBrandBreakdown' => fn () => $repository->categoryBrandBreakdown($monthlyFrom, $yesterdayTo, $productClassifier),
    'closedLoansMonthly' => fn () => $portfolioRepository->closedLoansMonthly($monthlyFrom, $yesterdayTo),
    'riskSegmentation' => fn () => $portfolioRepository->riskSegmentation(),
    'delinquencyAnalysis' => fn () => $portfolioRepository->delinquencyAnalysis(),
    'rejectionReasonsMonthly' => fn () => $portfolioRepository->reasonsByStatusMonthly($monthlyFrom, $yesterdayTo, 6),
    'clientRefusedReasonsMonthly' => fn () => $portfolioRepository->reasonsByStatusMonthly($monthlyFrom, $yesterdayTo, 12),
    'expiredReasonsMonthly' => fn () => $portfolioRepository->reasonsByStatusMonthly($monthlyFrom, $yesterdayTo, 13),
    'notRespondingReasonsMonthly' => fn () => $portfolioRepository->reasonsByStatusMonthly($monthlyFrom, $yesterdayTo, 14),
    'approvedReasonsMonthly' => fn () => $portfolioRepository->reasonsByStatusMonthly($monthlyFrom, $yesterdayTo, 5),
    'applicationStatusesMonthly' => fn () => $portfolioRepository->applicationStatusesMonthly($monthlyFrom, $yesterdayTo),
    'leadStatusesMonthly' => fn () => $portfolioRepository->leadStatusesMonthly($monthlyFrom, $yesterdayTo),
    'incomeDelinquencyByCategory' => fn () => $incomeDelinquencyRepository->categoryReport($monthlyFrom, $yesterdayTo, $productClassifier),
    'incomeDelinquencyBySubcategory' => fn () => $incomeDelinquencyRepository->subcategoryReport($monthlyFrom, $yesterdayTo, $productClassifier),
    'incomeDelinquencyByBrand' => fn () => $incomeDelinquencyRepository->brandReport($monthlyFrom, $yesterdayTo),
    'incomeDelinquencyByProduct' => fn () => $incomeDelinquencyRepository->productReport($monthlyFrom, $yesterdayTo, $productClassifier),
    'customerAnalysis' => fn () => $portfolioRepository->customerAnalysis(),
    'customerAgeGenderAnalysis' => fn () => $portfolioRepository->customerAgeGenderAnalysis(),
    'customerWorkshopAnalysis' => fn () => $portfolioRepository->customerWorkshopAnalysis(),
    'customerWorkposAnalysis' => fn () => $portfolioRepository->customerWorkposAnalysis(),
    'customerIncomeAnalysis' => fn () => $portfolioRepository->customerIncomeAnalysis(),
    'customerDistrictAnalysis' => fn () => $portfolioRepository->customerDistrictAnalysis(),
    'neverBorrowedByStatus' => fn () => $exCustomerRepository->neverBorrowedByStatus(),
    'exCustomers' => fn () => $exCustomerRepository->exCustomers($productClassifier),
    // Heaviest report on the page (26,781 rows) and the one fewest people open — streamed last
    // so it never holds up anything above it.
    'neverBorrowedDetail' => fn () => $exCustomerRepository->neverBorrowedDetail($productClassifier),
];

// Ask nginx not to buffer this response, and make sure PHP holds nothing back either —
// otherwise the whole point (each report appearing as it lands) is lost to a buffer.
header('Content-Type: text/html; charset=utf-8');
header('X-Accel-Buffering: no');
header('Cache-Control: no-store');
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);

require __DIR__ . '/templates/dashboard.php';
flush();

$jsonFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE;

$loaded = [];
foreach ($sections as $name => $load) {
    try {
        $payload = $load();
        $loaded[$name] = $payload;
        echo '<script>__section(' . json_encode($name, $jsonFlags) . ',' . json_encode($payload, $jsonFlags) . ");</script>\n";
    } catch (\Throwable $e) {
        // One failed report must not cost the reader the other twenty-eight.
        echo '<script>__sectionFailed(' . json_encode($name, $jsonFlags) . ',' . json_encode($e->getMessage(), $jsonFlags) . ");</script>\n";
    }
    flush();

    // The Yesterday/MTD headline figures are rows of the monthly and daily tables rather than
    // queries of their own (see FunnelRepository::periodFigures()), so they cost nothing and are
    // emitted the moment both tables are in — the funnel is the tab the page opens on.
    if ($name === 'dailyStats') {
        if (isset($loaded['monthlyStats'], $loaded['dailyStats'])) {
            $mtdFigures = FunnelRepository::periodFigures($loaded['monthlyStats'], $mtdFrom->format('Y-m'));
            $yestFigures = FunnelRepository::periodFigures($loaded['dailyStats'], $yesterdayFrom->format('Y-m-d'));
            echo '<script>__section("data",' . json_encode([
                'mtd' => [
                    'label' => sprintf('MTD (%s–%s)', $mtdFrom->format('M j'), $yesterdayFrom->format('j, Y')),
                    'A' => $mtdFigures['A'],
                    'B' => $mtdFigures['B'],
                ],
                'yest' => [
                    'label' => sprintf('Yesterday (%s)', $yesterdayFrom->format('M j, Y')),
                    'A' => $yestFigures['A'],
                    'B' => $yestFigures['B'],
                ],
            ], $jsonFlags) . ");</script>\n";
        } else {
            echo '<script>__sectionFailed("data","the monthly and daily tables it reads did not load");</script>' . "\n";
        }
        flush();
    }
}

echo "<script>__streamComplete();</script>\n";
flush();
