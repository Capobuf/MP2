<?php

namespace App\Domain\Reporting;

use App\Domain\Expenses\Decimal;
use Carbon\CarbonImmutable;

final class ComparisonEngine
{
    /**
     * @param  array<int, ReportSource>  $initialSources
     * @param  array<int, ReportSource>  $finalSources
     * @return array<int, array<string, mixed>>
     */
    public function compare(
        array $initialSources,
        array $finalSources,
        bool $budgetComparison = false,
        bool $exerciseClosed = false,
        ?CarbonImmutable $dateFrom = null,
        ?CarbonImmutable $dateTo = null,
        string $initialMeasure = 'automatic',
        string $finalMeasure = 'automatic',
    ): array {
        $initial = collect($initialSources)->keyBy(fn (ReportSource $source): string => $source->originKey);
        $final = collect($finalSources)->keyBy(fn (ReportSource $source): string => $source->originKey);
        $keys = $initial->keys()->merge($final->keys())->unique()->sort()->values();

        return $keys->map(function (string $key) use ($initial, $final, $budgetComparison, $exerciseClosed, $dateFrom, $dateTo, $initialSources, $initialMeasure, $finalMeasure): array {
            /** @var ReportSource|null $before */
            $before = $initial->get($key);
            /** @var ReportSource|null $after */
            $after = $final->get($key);
            $dimensions = $this->dimensions($before, $after);
            $category = match (true) {
                $before === null => ComparisonCategory::Added,
                $after === null => ComparisonCategory::Removed,
                $dimensions === [] => ComparisonCategory::Unchanged,
                default => ComparisonCategory::Modified,
            };
            $labels = $this->labels($before, $after, $budgetComparison, $exerciseClosed, $dateFrom, $dateTo);
            $initialValue = $this->value($before, $initialMeasure);
            $finalValue = $this->value($after, $finalMeasure);
            $derivedFrom = $after?->copiedFromOriginKey;
            $derivationExists = $derivedFrom !== null && collect($initialSources)->contains(
                fn (ReportSource $source): bool => $source->originKey === $derivedFrom,
            );

            return [
                'origin_key' => $key,
                'source_type' => ($after ?? $before)?->sourceType,
                'label' => ($after ?? $before)?->label,
                'initial_source' => $before,
                'final_source' => $after,
                'initial_value' => $initialValue,
                'final_value' => $finalValue,
                'delta' => Decimal::subtract($finalValue, $initialValue),
                'category' => $category,
                'dimensions' => $dimensions,
                'labels' => $labels,
                'derived_from_origin_key' => $derivationExists ? $derivedFrom : null,
                'insufficiently_explained' => $category === ComparisonCategory::Modified,
            ];
        })->all();
    }

    private function value(?ReportSource $source, string $measure): string
    {
        if ($source === null) {
            return '0.00';
        }

        return match ($measure) {
            'allocation' => $source->allocation,
            'actual' => $source->actual,
            'automatic' => $source->comparisonValue(),
            default => throw new \InvalidArgumentException('Misura di confronto non supportata.'),
        };
    }

    /** @return array<int, ModificationDimension> */
    private function dimensions(?ReportSource $before, ?ReportSource $after): array
    {
        if ($before === null || $after === null) {
            return [];
        }

        $checks = [
            [ModificationDimension::AllocationOrEstimate, $before->allocation, $after->allocation],
            [ModificationDimension::Actual, $before->actual, $after->actual],
            [ModificationDimension::Carryover, $before->carryover, $after->carryover],
            [ModificationDimension::CostCenter, $before->costCenterId, $after->costCenterId],
            [ModificationDimension::Supplier, $before->supplierId, $after->supplierId],
            [ModificationDimension::StateOrTransitions, $before->state, $after->state],
            [ModificationDimension::ArchiveOrReversal, $before->detail['archived_or_reversed'] ?? false, $after->detail['archived_or_reversed'] ?? false],
            [ModificationDimension::ContractEconomics, $before->detail['contract_economics'] ?? null, $after->detail['contract_economics'] ?? null],
            [ModificationDimension::DeadlineRenewalTermination, $before->detail['deadline'] ?? null, $after->detail['deadline'] ?? null],
            [ModificationDimension::InformativeRelations, $before->detail['relations'] ?? [], $after->detail['relations'] ?? []],
        ];

        $changed = [];
        foreach ($checks as [$dimension, $initial, $final]) {
            if ($initial !== $final) {
                $changed[] = $dimension;
            }
        }

        return $changed;
    }

    /** @return array<int, SecondaryLabel> */
    private function labels(
        ?ReportSource $before,
        ?ReportSource $after,
        bool $budgetComparison,
        bool $exerciseClosed,
        ?CarbonImmutable $dateFrom,
        ?CarbonImmutable $dateTo,
    ): array {
        if ($after === null) {
            return [];
        }

        $labels = [];
        $planned = $before !== null && Decimal::compare($before->allocation, '0.00') > 0;
        if ($budgetComparison && ! $planned
            && (Decimal::compare($after->allocation, '0.00') !== 0 || $after->hasActuals)) {
            $labels[] = SecondaryLabel::Unplanned;
        }

        if ($budgetComparison && $planned && ! $after->hasActuals) {
            $terminal = $exerciseClosed || in_array($after->state, ['closed', 'cancelled', 'cessated', 'reversed'], true);
            $labels[] = $terminal ? SecondaryLabel::PlannedNotOccurred : SecondaryLabel::WithoutActuals;
        }
        if (($after->detail['archived_or_reversed'] ?? false) === true) {
            $labels[] = SecondaryLabel::Reversed;
        }
        if (in_array($after->state, ['cancelled', 'cessated'], true)) {
            $labels[] = SecondaryLabel::Cancelled;
        }
        if (($after->detail['deferred'] ?? false) === true) {
            $labels[] = SecondaryLabel::Deferred;
        }
        if ($after->corrections !== []) {
            $labels[] = SecondaryLabel::LateCorrection;
        }
        if ($before !== null && $before->carryover !== $after->carryover) {
            $labels[] = SecondaryLabel::CarryoverChanged;
        }
        if ($after->annotations !== []) {
            $labels[] = SecondaryLabel::HistoricalAttributionDisputed;
        }
        if ($after->sourceType === 'contract') {
            $deadline = isset($after->detail['deadline']) ? CarbonImmutable::parse((string) $after->detail['deadline']) : null;
            if ($deadline === null) {
                $labels[] = SecondaryLabel::UndefinedExpiry;
            } elseif ($dateFrom !== null && $dateTo !== null && $deadline->betweenIncluded($dateFrom, $dateTo)) {
                $labels[] = SecondaryLabel::ContractExpiryInSelectedInterval;
            }
        }

        return array_values(array_unique($labels, SORT_REGULAR));
    }
}
