<?php

namespace App\Domain\Contracts;

use Carbon\CarbonImmutable;

final readonly class ContractCycle
{
    public function __construct(
        public int $conditionId,
        public CarbonImmutable $start,
        public CarbonImmutable $attributionDate,
        public string $amount,
    ) {}

    /**
     * @return list<self>
     */
    public static function enumerate(
        int $conditionId,
        ContractCycleType $cycle,
        ContractAttributionMode $attributionMode,
        string $amount,
        string $validFrom,
        ?string $validTo,
        string $through,
    ): array {
        $anchor = CarbonImmutable::parse($validFrom)->startOfDay();
        $limit = CarbonImmutable::parse($through)->startOfDay();
        if ($validTo !== null) {
            $limit = $limit->min(CarbonImmutable::parse($validTo)->startOfDay());
        }

        $cycles = [];
        for ($index = 0; ; $index++) {
            $start = self::anchoredDate($anchor, $index * $cycle->months());
            if ($start->greaterThan($limit)) {
                break;
            }

            $attribution = $attributionMode === ContractAttributionMode::CycleStart
                ? $start
                : self::anchoredDate($anchor, ($index + 1) * $cycle->months());
            $cycles[] = new self($conditionId, $start, $attribution, $amount);
        }

        return $cycles;
    }

    public static function anchoredDate(CarbonImmutable $anchor, int $months): CarbonImmutable
    {
        $monthIndex = ($anchor->year * 12) + ($anchor->month - 1) + $months;
        $year = intdiv($monthIndex, 12);
        $month = ($monthIndex % 12) + 1;
        $first = CarbonImmutable::create($year, $month, 1, 0, 0, 0, $anchor->timezone);

        return $first->day(min($anchor->day, $first->daysInMonth));
    }
}
