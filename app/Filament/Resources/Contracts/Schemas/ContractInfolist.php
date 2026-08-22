<?php

namespace App\Filament\Resources\Contracts\Schemas;

use App\Domain\Contracts\ContractAnnualAllocation;
use App\Domain\Contracts\ContractAttributionMode;
use App\Domain\Contracts\ContractCycleType;
use App\Domain\Contracts\ContractStateTimeline;
use App\Domain\Expenses\Decimal;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\Exercise;
use App\Support\ExerciseContext;
use Carbon\CarbonImmutable;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Number;

class ContractInfolist
{
    private const ALLOCATION_PREVIEW_LIMIT = 5;

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.resources.contracts.components.overview')
                ->viewData(fn (Contract $record): array => ['overview' => self::overview($record)])
                ->columnSpanFull(),
        ]);
    }

    /** @return array<string, mixed> */
    private static function overview(Contract $contract): array
    {
        $today = CarbonImmutable::now($contract->company->timezone)->startOfDay();
        $selectedExercise = app(ExerciseContext::class)->current($contract->company);
        $currentCondition = $contract->conditions
            ->filter(fn (ContractCondition $condition): bool => ! $condition->isAnnulled()
                && $condition->validFrom()->startOfDay()->lessThanOrEqualTo($today)
                && ($condition->validTo() === null || $condition->validTo()->endOfDay()->greaterThanOrEqualTo($today)))
            ->sortByDesc(fn (ContractCondition $condition): string => $condition->validFrom()->toDateString())
            ->first();

        $annualRows = $contract->company->exercises->sortBy('year')->map(
            fn (Exercise $exercise): array => self::annualRow($contract, $exercise, $today, $selectedExercise?->id),
        )->values()->all();

        $selectedRow = collect($annualRows)->firstWhere('selected', true);

        return [
            'condition' => $currentCondition instanceof ContractCondition ? [
                'amount' => self::money($currentCondition->amount),
                'cycle' => ContractCycleType::from($currentCondition->cycle)->label(),
                'attribution' => ContractAttributionMode::from($currentCondition->attribution_mode)->label(),
                'valid_from' => $currentCondition->validFrom()->format('d/m/Y'),
                'valid_to' => $currentCondition->validTo()?->format('d/m/Y') ?? 'Senza termine',
                'note' => filled($currentCondition->reason) ? $currentCondition->reason : null,
            ] : null,
            'terms' => [
                'automatic_renewal' => $contract->automatic_renewal ? 'Sì' : 'No',
                'renewal_duration' => $contract->renewal_duration_months === null ? '—' : $contract->renewal_duration_months.' mesi',
                'notice' => $contract->notice_days === null ? '—' : $contract->notice_days.' giorni',
            ],
            'selected' => $selectedRow,
            'annual' => $annualRows,
        ];
    }

    /** @return array<string, mixed> */
    private static function annualRow(Contract $contract, Exercise $exercise, CarbonImmutable $today, ?int $selectedExerciseId): array
    {
        $reference = ContractStateTimeline::referenceDateForExercise($exercise->year, $today);
        $allocation = ContractAnnualAllocation::forYear(
            $contract->conditions,
            $exercise->year,
            fn (string $date) => $contract->stateAtDate($date),
        );
        $actual = Decimal::sum($contract->expenses
            ->where('exercise_id', $exercise->id)
            ->where('origin', 'manual')
            ->map(fn ($expense): string => $expense->actual()));
        $classification = $contract->classifications->firstWhere('exercise_id', $exercise->id);
        $composition = collect($allocation->composition)
            ->sortBy([
                ['attribution_date', 'asc'],
                ['cycle_start', 'asc'],
            ])
            ->map(fn (array $item): array => [
                'cycle_start' => CarbonImmutable::parse($item['cycle_start'])->format('d/m/Y'),
                'attribution_date' => CarbonImmutable::parse($item['attribution_date'])->format('d/m/Y'),
                'amount' => self::money($item['amount']),
            ])->values();

        return [
            'year' => $exercise->year,
            'selected' => $exercise->id === $selectedExerciseId,
            'reference_date' => $reference->format('d/m/Y'),
            'state' => $contract->stateAtDate($reference->toDateString())->label(),
            'cost_center' => $classification === null || $classification->cost_center_id === null
                ? 'Non classificato'
                : $classification->costCenter->name.($classification->costCenter->isArchived() ? ' · Archiviato' : ''),
            'allocation' => self::money($allocation->amount),
            'actual' => self::money($actual),
            'variance' => self::money(Decimal::subtract($actual, $allocation->amount)),
            'composition_count' => $composition->count(),
            'composition_preview' => $composition->take(self::ALLOCATION_PREVIEW_LIMIT)->all(),
            'has_more_composition' => $composition->count() > self::ALLOCATION_PREVIEW_LIMIT,
            'first_cycle_start' => $composition->first()['cycle_start'] ?? null,
            'last_cycle_start' => $composition->last()['cycle_start'] ?? null,
            'composition' => $composition->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function allocationDetail(Contract $contract, int $year): array
    {
        $today = CarbonImmutable::now($contract->company->timezone)->startOfDay();
        $exercise = $contract->company->exercises->firstWhere('year', $year);

        abort_unless($exercise instanceof Exercise, 404);

        return self::annualRow($contract, $exercise, $today, null);
    }

    private static function money(string|int|float $amount): string
    {
        return Number::currency((float) $amount, in: 'EUR', locale: 'it');
    }
}
