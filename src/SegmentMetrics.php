<?php

declare(strict_types=1);

namespace Volta\Funnel;

/**
 * The six funnel figures for one segment (A or B) over one period (Yesterday or MTD).
 */
final class SegmentMetrics
{
    public function __construct(
        public readonly int $applications,
        public readonly int $termsApproved,
        public readonly int $underwritingApproved,
        public readonly int $dealsClosed,
        public readonly float $amountSold,
        public readonly float $downpaymentCollected,
    ) {
    }

    /**
     * @return array{applications: int, terms: int, uw: int, closed: int, amount: float, dp: float}
     */
    public function toArray(): array
    {
        return [
            'applications' => $this->applications,
            'terms' => $this->termsApproved,
            'uw' => $this->underwritingApproved,
            'closed' => $this->dealsClosed,
            'amount' => $this->amountSold,
            'dp' => $this->downpaymentCollected,
        ];
    }
}
