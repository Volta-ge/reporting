<?php

declare(strict_types=1);

namespace Volta\Funnel;

use DateTimeImmutable;

/**
 * All ranges are half-open [start, end) at midnight, matching the SQL convention used
 * throughout this project (>= start AND < end).
 */
final class DateHelper
{
    /**
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable} [yesterday 00:00, today 00:00)
     */
    public static function yesterdayRange(DateTimeImmutable $now): array
    {
        $todayStart = $now->setTime(0, 0);
        $yesterdayStart = $todayStart->modify('-1 day');

        return [$yesterdayStart, $todayStart];
    }

    /**
     * Month-to-date: the 1st of the month through yesterday (today is not yet finished, so it
     * is excluded — consistent with every other report in this project). "The month" is
     * yesterday's month, not today's — on the 1st of a month that makes this the just-completed
     * PREVIOUS month in full (yesterday was its last day), not an empty zero-day window in the
     * brand-new month. Every other day of the month, yesterday and today share a month, so this
     * is identical to "1st of the current month" as before. User caught the wrong behavior live
     * on 2026-09-01 ("MTD უნდა იყოს აგვისტოს თვე სრულად და არა სექტემბერი" — MTD should be all of
     * August, not September) after an earlier fix here only patched a resulting label bug
     * without fixing this underlying date-range choice — see volta_funnel_dashboard memory.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable} [month start 00:00, today 00:00)
     */
    public static function monthToDateRange(DateTimeImmutable $now): array
    {
        $todayStart = $now->setTime(0, 0);
        $yesterdayStart = $todayStart->modify('-1 day');
        $monthStart = $yesterdayStart->modify('first day of this month');

        return [$monthStart, $todayStart];
    }

    /**
     * Every calendar day AFTER yesterday through month-end (no days excluded — confirmed with
     * the business, not a 5- or 6-day work week calculation). Anchored on yesterday, not today,
     * for the same reason as monthToDateRange() above: yesterday is the last day this page
     * actually has data for, so "days remaining" and the Budget & Pacing figures built on top of
     * it (Required Daily Sales, Attainment %) should read as "days left to hit the target given
     * what's landed so far," not include a today that has zero sales recorded yet.
     *
     * On every normal day this produces the exact same number as the old today-anchored formula
     * (daysInMonth - dayOfMonth(today) + 1 == daysInMonth - dayOfMonth(yesterday), since
     * dayOfMonth(today) = dayOfMonth(yesterday) + 1 when they share a month) — it only differs at
     * a month boundary, where it now correctly reads 0 on the last day of a month instead of
     * resetting to a full month's count against a brand-new month nothing has been sold in yet.
     * User confirmed directly: "რეპორტი ხომ არის გუშინდელი თარიღით 31/08/2026, შესაბამისად თვის
     * ბოლომდე 0 დღე არის დარჩენილი" (the report is dated yesterday, Aug 31 — so 0 days remain
     * until month-end, since August has 31 days). Verified live 2026-09-01.
     */
    public static function remainingWorkingDays(DateTimeImmutable $now): int
    {
        $yesterday = $now->setTime(0, 0)->modify('-1 day');
        $daysInMonth = (int) $yesterday->format('t');
        $dayOfMonth = (int) $yesterday->format('j');

        return $daysInMonth - $dayOfMonth;
    }
}
