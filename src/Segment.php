<?php

declare(strict_types=1);

namespace Volta\Funnel;

/**
 * A. Requires downpayment — TV, phone, or any single product priced over 2,500 GEL.
 * B. Standard terms — everything else.
 */
enum Segment: string
{
    case A = 'A';
    case B = 'B';

    /**
     * SQL boolean expression for this segment, referencing the instalments alias `i`
     * and the joined products alias `p`. Not user input — safe to interpolate directly.
     */
    public function sqlCondition(): string
    {
        $isSegmentA = "(p.Model LIKE 'ტელეფონი%' OR p.Model LIKE 'ტელევიზორ%' OR i.Full_Cost > 2500)";

        return match ($this) {
            self::A => $isSegmentA,
            self::B => "NOT {$isSegmentA}",
        };
    }
}
