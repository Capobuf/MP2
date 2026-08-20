<?php

namespace App\Domain\Contracts;

use Carbon\CarbonImmutable;

final readonly class ContractRenewalSchedule
{
    /** @param list<string> $elapsed */
    public function __construct(public array $elapsed, public string $nextExpiry) {}

    public static function fromAnchor(string $anchorDate, int $durationMonths, string $today): self
    {
        if ($durationMonths < 1) {
            throw new \InvalidArgumentException('Renewal duration must be positive.');
        }

        $anchor = CarbonImmutable::parse($anchorDate)->startOfDay();
        $reference = CarbonImmutable::parse($today)->startOfDay();
        $elapsed = [];
        $index = 0;

        do {
            $expiry = ContractCycle::anchoredDate($anchor, $index * $durationMonths);
            if ($expiry->lessThanOrEqualTo($reference)) {
                $elapsed[] = $expiry->toDateString();
                $index++;
            }
        } while ($expiry->lessThanOrEqualTo($reference));

        return new self($elapsed, ContractCycle::anchoredDate($anchor, $index * $durationMonths)->toDateString());
    }
}
