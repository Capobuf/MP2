<?php

namespace App\Filament\Resources\Contracts\Schemas;

use App\Domain\Contracts\ContractAnnualAllocation;
use App\Domain\Contracts\ContractAttributionMode;
use App\Domain\Contracts\ContractCycleType;
use App\Domain\Contracts\ContractState;
use App\Domain\Contracts\ContractStateTimeline;
use App\Domain\CostCenters\CostCenterHierarchy;
use App\Domain\Expenses\Decimal;
use App\Domain\Proposals\ProposalPlanData;
use App\Models\ClosingSnapshot;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\Exercise;
use App\Models\LateCorrection;
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
        $hierarchy = CostCenterHierarchy::forCompany((int) $contract->company_id);
        $currentCondition = $contract->conditions
            ->filter(fn (ContractCondition $condition): bool => ! $condition->isAnnulled()
                && $condition->validFrom()->startOfDay()->lessThanOrEqualTo($today)
                && ($condition->validTo() === null || $condition->validTo()->endOfDay()->greaterThanOrEqualTo($today)))
            ->sortByDesc(fn (ContractCondition $condition): string => $condition->validFrom()->toDateString())
            ->first();

        $annualRows = $contract->company->exercises->sortBy('year')->map(
            fn (Exercise $exercise): array => self::annualRow($contract, $exercise, $today, $selectedExercise?->id, $hierarchy),
        )->values()->all();

        $selectedRow = collect($annualRows)->firstWhere('selected', true);
        $lastCondition = $contract->conditions
            ->filter(fn (ContractCondition $condition): bool => ! $condition->isAnnulled()
                && $condition->validTo()?->startOfDay()->lessThan($today))
            ->sortByDesc('valid_to')
            ->first();

        return [
            'today' => $today->format('d/m/Y'),
            'has_conditions' => $contract->conditions->isNotEmpty(),
            'is_active' => $contract->stateAtDate($today->toDateString()) === ContractState::Active,
            'last_condition_end' => $lastCondition?->validTo()?->format('d/m/Y'),
            'notes' => $contract->notes,
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
    private static function annualRow(Contract $contract, Exercise $exercise, CarbonImmutable $today, ?int $selectedExerciseId, CostCenterHierarchy $hierarchy): array
    {
        $reference = ContractStateTimeline::referenceDateForExercise($exercise->year, $today);
        if ($exercise->isOpen()) {
            $allocation = ContractAnnualAllocation::forYear(
                $contract->conditions, $exercise->year,
                fn (string $date) => $contract->stateAtDate($date),
            );
            $expenses = $contract->expenses->where('exercise_id', $exercise->id);
            $manual = $expenses->where('origin', 'manual');
            $total = Decimal::sum($expenses->map->allocation());
            $actual = Decimal::sum($manual->map->actual());
            $systemTotal = Decimal::sum($expenses->where('origin', 'system')->map->allocation());
            $manualTotal = Decimal::sum($manual->map->allocation());
            $manualExpenses = $manual->map(fn ($expense): array => ['description' => $expense->description, 'allocation' => self::money($expense->allocation())])->values()->all();
            $classification = $contract->classifications->firstWhere('exercise_id', $exercise->id);
            $costCenter = $classification?->cost_center_id === null ? 'Non classificato'
                : $hierarchy->path((int) $classification->cost_center_id).($classification->costCenter->isArchived() ? ' · Archiviato' : '');
            $state = $contract->stateAtDate($reference->toDateString())->label();
            $compositionData = $allocation->composition;
            $referenceLabel = 'Corrente';
        } else {
            $snapshot = ClosingSnapshot::query()->where('company_id', $contract->company_id)->where('exercise_id', $exercise->id)->firstOrFail();
            $row = $snapshot->rows()->where('origin_key', $contract->originKey())->first();
            $corrections = $snapshot->lateCorrections()->where('source_origin_key', $contract->originKey())->with('expenseLine')->get()
                ->filter(fn (LateCorrection $correction): bool => ! $correction->expenseLine->isAnnulled());
            $total = (string) ($row->final_allocation ?? '0.00');
            $actual = Decimal::add((string) ($row->closing_actual ?? '0.00'), Decimal::sum($corrections->map(fn (LateCorrection $correction): string => (string) $correction->expenseLine->amount)));
            $details = collect(ProposalPlanData::rows($row->detail['expenses'] ?? [], 'expenses'));
            $systemTotal = Decimal::sum($details->where('origin', 'system')->pluck('final_estimate_total'));
            $manualTotal = Decimal::sum($details->where('origin', 'manual')->pluck('final_estimate_total'));
            $manualExpenses = $details->where('origin', 'manual')->map(fn (array $expense): array => ['description' => $expense['description'], 'allocation' => self::money($expense['final_estimate_total'])])->values()->all();
            $costCenter = $row->cost_center_label ?? 'Classificazione storica non disponibile';
            $state = $row === null ? 'Stato storico non disponibile' : ContractState::from($row->end_state)->label();
            $compositionData = ProposalPlanData::rows($row->detail['annual_composition'] ?? [], 'annual_composition');
            $referenceLabel = 'Conoscenza Corrente · Snapshot di Chiusura e rettifiche';
        }
        $composition = collect($compositionData)
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
            'reference_label' => $referenceLabel,
            'state' => $state,
            'cost_center' => $costCenter,
            'allocation' => self::money($total),
            'system_allocation' => self::money($systemTotal),
            'manual_allocation' => self::money($manualTotal),
            'manual_expenses' => $manualExpenses,
            'actual' => self::money($actual),
            'variance' => self::money(Decimal::subtract($actual, $total)),
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

        return self::annualRow(
            $contract,
            $exercise,
            $today,
            null,
            CostCenterHierarchy::forCompany((int) $contract->company_id),
        );
    }

    private static function money(string|int|float $amount): string
    {
        return Number::currency((float) $amount, in: 'EUR', locale: 'it');
    }
}
