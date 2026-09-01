<?php

declare(strict_types=1);

namespace Volta\Funnel;

// This page runs ~25 separate queries against a remote DB (myvolta.info) — under normal network
// conditions that's comfortably under PHP's 30s default, but on a slower-latency day it isn't
// (measured 25-30s end-to-end on 2026-08-26 with nothing unusual in the queries themselves —
// per-query round-trip latency, not payload size, dominates). Raised as a safety margin rather
// than trying to chase network variance; if this route ever legitimately needs more, that's a
// sign to revisit query count, not just push the limit further.
set_time_limit(90);

// This page embeds several large arrays as inline JS consts, including the ~26,800-row
// neverBorrowedDetail list — on the Accounting-included sibling deployment (which embeds
// even more data) this exceeded PHP's default 128M and fataled mid-render (confirmed
// 2026-08-31). Raised here too as a safety margin, same reasoning as set_time_limit above.
ini_set('memory_limit', '256M');

require __DIR__ . '/../src/Segment.php';
require __DIR__ . '/../src/SegmentMetrics.php';
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

// On the 1st of a month, yesterday belongs to the PREVIOUS month — MTD ("1st of this month
// through yesterday") then legitimately covers zero complete days, but naively formatting
// "{month start}–{yesterday's day number}" produced a nonsensical label (e.g. "Sep 1–31, 2026"
// on 2026-09-01, borrowing August's day count into a September range). Confirmed live 2026-09-01.
$mtdRangeLabel = $yesterdayFrom >= $mtdFrom
    ? $mtdFrom->format('M j') . '–' . $yesterdayFrom->format('j, Y')
    : $mtdFrom->format('M j, Y') . ', no complete days yet';

try {
    $pdo = Database::connect($config['db']);
    $repository = new FunnelRepository($pdo);

    $data = [
        'mtd' => [
            'label' => "MTD ($mtdRangeLabel)",
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
    $categoryBrandBreakdown = $repository->categoryBrandBreakdown($monthlyFrom, $yesterdayTo, $productClassifier);

    // Income & Delinquency by product dimension (Category/Subcategory/Brand/Product) — closed
    // loans only, same Jan-1-through-yesterday window as the tabs above.
    $incomeDelinquencyRepository = new IncomeDelinquencyRepository($pdo);
    $incomeDelinquencyByCategory = $incomeDelinquencyRepository->categoryReport($monthlyFrom, $yesterdayTo, $productClassifier);
    $incomeDelinquencyBySubcategory = $incomeDelinquencyRepository->subcategoryReport($monthlyFrom, $yesterdayTo, $productClassifier);
    $incomeDelinquencyByBrand = $incomeDelinquencyRepository->brandReport($monthlyFrom, $yesterdayTo);
    $incomeDelinquencyByProduct = $incomeDelinquencyRepository->productReport($monthlyFrom, $yesterdayTo, $productClassifier);

    // Customers / Risk Segmentation / Closed Loans / Overdue Analysis (portfolio) tabs.
    $portfolioRepository = new PortfolioRepository($pdo);
    $customerAnalysis = $portfolioRepository->customerAnalysis();
    $customerAgeGenderAnalysis = $portfolioRepository->customerAgeGenderAnalysis();
    $customerWorkshopAnalysis = $portfolioRepository->customerWorkshopAnalysis();
    $customerWorkposAnalysis = $portfolioRepository->customerWorkposAnalysis();
    $customerIncomeAnalysis = $portfolioRepository->customerIncomeAnalysis();
    $customerDistrictAnalysis = $portfolioRepository->customerDistrictAnalysis();
    $riskSegmentation = $portfolioRepository->riskSegmentation();
    // Ex Customers: individual-row PII report (~6s, the heaviest single addition on this page so
    // far) — kept in its own repository class rather than PortfolioRepository, see its docblock.
    $exCustomerRepository = new ExCustomerRepository($pdo);
    $exCustomers = $exCustomerRepository->exCustomers($productClassifier);
    // Aggregate-only (no PII) breakdown of the other ~26,500 inactive customers who never had a
    // genuinely closed loan — reconciles this tab's total against Customer Analysis's own
    // Total − Active figure. See ExCustomerRepository::neverBorrowedByStatus() docblock.
    $neverBorrowedByStatus = $exCustomerRepository->neverBorrowedByStatus();
    // Individual-row detail for that same never-borrowed population (~5s) — added per explicit
    // user request alongside the aggregate above. See ExCustomerRepository::neverBorrowedDetail().
    $neverBorrowedDetail = $exCustomerRepository->neverBorrowedDetail($productClassifier);
    $closedLoansMonthly = $portfolioRepository->closedLoansMonthly($monthlyFrom, $yesterdayTo);
    $delinquencyAnalysis = $portfolioRepository->delinquencyAnalysis();
    $rejectionReasonsMonthly = $portfolioRepository->reasonsByStatusMonthly($monthlyFrom, $yesterdayTo, 6);
    $clientRefusedReasonsMonthly = $portfolioRepository->reasonsByStatusMonthly($monthlyFrom, $yesterdayTo, 12);
    $expiredReasonsMonthly = $portfolioRepository->reasonsByStatusMonthly($monthlyFrom, $yesterdayTo, 13);
    $notRespondingReasonsMonthly = $portfolioRepository->reasonsByStatusMonthly($monthlyFrom, $yesterdayTo, 14);
    $approvedReasonsMonthly = $portfolioRepository->reasonsByStatusMonthly($monthlyFrom, $yesterdayTo, 5);
    $applicationStatusesMonthly = $portfolioRepository->applicationStatusesMonthly($monthlyFrom, $yesterdayTo);
    $leadStatusesMonthly = $portfolioRepository->leadStatusesMonthly($monthlyFrom, $yesterdayTo);

    $connectionError = null;
} catch (\Throwable $e) {
    $data = null;
    $monthlyStats = null;
    $dailyStats = null;
    $salesMonthlyStats = null;
    $brandStats = null;
    $subcategoryStats = null;
    $categoryBrandBreakdown = null;
    $incomeDelinquencyByCategory = null;
    $incomeDelinquencyBySubcategory = null;
    $incomeDelinquencyByBrand = null;
    $incomeDelinquencyByProduct = null;
    $customerAnalysis = null;
    $customerAgeGenderAnalysis = null;
    $customerWorkshopAnalysis = null;
    $customerWorkposAnalysis = null;
    $customerIncomeAnalysis = null;
    $customerDistrictAnalysis = null;
    $riskSegmentation = null;
    $exCustomers = null;
    $neverBorrowedByStatus = null;
    $neverBorrowedDetail = null;
    $closedLoansMonthly = null;
    $delinquencyAnalysis = null;
    $rejectionReasonsMonthly = null;
    $clientRefusedReasonsMonthly = null;
    $expiredReasonsMonthly = null;
    $notRespondingReasonsMonthly = null;
    $approvedReasonsMonthly = null;
    $applicationStatusesMonthly = null;
    $leadStatusesMonthly = null;
    $connectionError = $e->getMessage();
}

$targets = [
    'applications' => $config['targets']['applications'],
    'amount' => $config['targets']['amount'],
    'workingDaysLeft' => DateHelper::remainingWorkingDays($now),
];

$headerYesterday = $yesterdayFrom->format('M j, Y');
$headerMtdRange = $mtdRangeLabel;
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
