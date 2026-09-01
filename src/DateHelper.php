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
     * Every calendar day from today through month-end, inclusive of today. No days are
     * excluded (confirmed with the business — not a 5- or 6-day work week calculation).
     */
    public static function remainingWorkingDays(DateTimeImmutable $now): int
    {
        $daysInMonth = (int) $now->format('t');
        $dayOfMonth = (int) $now->format('j');

        return $daysInMonth - $dayOfMonth + 1;
    }
}
