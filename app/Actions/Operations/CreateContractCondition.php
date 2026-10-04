<?php

namespace App\Actions\Operations;

use App\Actions\Proposals\MarkProposalItemsToRealign;
use App\Domain\Company\AuditEventType;
use App\Domain\Contracts\ContractAnnualAllocation;
use App\Domain\Contracts\ContractAttributionMode;
use App\Domain\Contracts\ContractClosedHistoryGuard;
use App\Domain\Contracts\ContractConditionRules;
use App\Domain\Contracts\ContractCycleType;
use App\Domain\Contracts\ContractImpactFingerprint;
use App\Domain\Expenses\Decimal;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\Exercise;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CreateContractCondition
{
    public function __construct(private readonly RecalculateContractEstimates $recalculate) {}

    /** @param array<string, mixed> $input */
    public function execute(User $actor, Contract $contract, array $input, string $operationId, ?string $confirmedFingerprint = null): ContractCondition
    {
        $validated = Validator::make([
            'amount' => $input['amount'] ?? null,
            'cycle' => $input['cycle'] ?? null,
            'attribution_mode' => $input['attribution_mode'] ?? null,
            'valid_from' => $input['valid_from'] ?? null,
            'valid_to' => ($input['valid_to'] ?? null) ?: null,
            'reason' => $this->nullableTrim($input['reason'] ?? null),
            'operation_id' => $operationId,
        ], [
            'amount' => ['required', 'decimal:0,2', 'min:0'],
            'cycle' => ['required', Rule::enum(ContractCycleType::class)],
            'attribution_mode' => ['required', Rule::enum(ContractAttributionMode::class)],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'reason' => ['nullable', 'string'],
            'operation_id' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $contract, $validated, $confirmedFingerprint): ContractCondition {
            $company = Company::query()->lockForUpdate()->findOrFail($contract->company_id);
            $locked = Contract::query()->lockForUpdate()->findOrFail($contract->id);
            Gate::forUser($actor)->authorize('update', $locked);
            $existing = AuditEvent::query()->where('operation_id', $validated['operation_id'])->where('event_sequence', 0)->first();
            if ($existing !== null) {
                if ($existing->eventType() !== AuditEventType::ContractConditionCreated
                    || $existing->subject_type !== ContractCondition::class
                    || $existing->company_id !== $company->id
                    || ContractCondition::query()->where('contract_id', $locked->id)->whereKey($existing->subject_id)->doesntExist()) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return ContractCondition::query()->findOrFail($existing->subject_id);
            }

            $conditions = $locked->conditions()->orderBy('valid_from')->orderBy('id')->lockForUpdate()->get();
            $locked->setRelation('lifecycleFacts', $locked->lifecycleFacts()->lockForUpdate()->get());
            if ($conditions->isEmpty()) {
                $preview = $this->preview($actor, $locked, $validated);
                if ($confirmedFingerprint === null || ! hash_equals(ContractImpactFingerprint::make($preview), $confirmedFingerprint)) {
                    throw ValidationException::withMessages(['condition' => 'Confermare l’anteprima aggiornata del primo canone.']);
                }
            } else {
                ContractConditionRules::assertCurrentlyActive($locked, CarbonImmutable::now($company->timezone)->toDateString());
            }
            ContractConditionRules::assertMayPersist($locked, $validated['valid_from'], $validated['valid_to'], $conditions);
            $exercises = Exercise::query()->whereBelongsTo($company, 'company')->open()->orderBy('year')->lockForUpdate()->get();

            $condition = ContractCondition::query()->create([
                'company_id' => $company->id,
                'contract_id' => $locked->id,
                'cycle' => $validated['cycle'],
                'attribution_mode' => $validated['attribution_mode'],
                'amount' => $validated['amount'],
                'valid_from' => $validated['valid_from'],
                'valid_to' => $validated['valid_to'],
                'reason' => $validated['reason'],
                'created_by_id' => $actor->id,
            ]);
            $locked->increment('revision');
            $exerciseIds = $exercises->pluck('id')->all();
            AuditEvent::query()->create([
                'operation_id' => $validated['operation_id'],
                'event_sequence' => 0,
                'company_id' => $company->id,
                'actor_id' => $actor->id,
                'event_type' => AuditEventType::ContractConditionCreated,
                'subject_type' => ContractCondition::class,
                'subject_id' => $condition->id,
                'affected_exercise_ids' => $exerciseIds,
                'effective_from' => $validated['valid_from'],
                'effective_to' => $validated['valid_to'],
                'new_value' => $condition->only(['id', 'contract_id', 'amount', 'cycle', 'attribution_mode', 'valid_from', 'valid_to', 'reason']),
                'allocated_impact_by_exercise' => array_fill_keys(array_map('strval', $exerciseIds), '0.00'),
                'actual_impact_by_exercise' => array_fill_keys(array_map('strval', $exerciseIds), '0.00'),
            ]);
            $sequence = 1;
            $locked->unsetRelation('conditions');
            $this->recalculate->recalculateWithinTransaction($actor, $locked, $exercises, $validated['operation_id'], $sequence);

            app(MarkProposalItemsToRealign::class)->execute($company->id, contractIds: [$locked->id]);

            return $condition;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function preview(User $actor, Contract $contract, array $input): array
    {
        Gate::forUser($actor)->authorize('update', $contract);
        if ($contract->isArchived() || $contract->conditions()->exists()) {
            throw ValidationException::withMessages(['condition' => 'Il primo canone richiede un Contratto non Archiviato senza condizioni precedenti.']);
        }
        $data = Validator::make([
            'amount' => $input['amount'] ?? null, 'cycle' => $input['cycle'] ?? null,
            'attribution_mode' => $input['attribution_mode'] ?? null,
            'valid_from' => $input['valid_from'] ?? null, 'valid_to' => ($input['valid_to'] ?? null) ?: null,
            'reason' => $this->nullableTrim($input['reason'] ?? null),
        ], [
            'amount' => ['required', 'decimal:0,2', 'min:0'],
            'cycle' => ['required', Rule::enum(ContractCycleType::class)],
            'attribution_mode' => ['required', Rule::enum(ContractAttributionMode::class)],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'reason' => ['nullable', 'string'],
        ])->validate();
        ContractConditionRules::assertMayPersist($contract, $data['valid_from'], $data['valid_to'], []);
        ContractConditionRules::assertCurrentlyActive($contract, $data['valid_from']);
        $today = now($contract->company->timezone)->toDateString();
        $reactivation = $contract->lifecycleFacts()->where('type', 'reactivation')->whereNull('annulled_at')
            ->where('state_change_date', '<=', max($today, $data['valid_from']))->max('state_change_date');
        if ($reactivation !== null && $data['valid_from'] < $reactivation) {
            throw ValidationException::withMessages(['valid_from' => 'Il primo canone non può precedere la riattivazione pertinente.']);
        }
        $exercises = $contract->company->exercises()->orderBy('year')->get();
        if ($data['valid_from'] <= $today && ! $exercises->contains(fn (Exercise $exercise): bool => $exercise->isOpen()
            && $exercise->year === CarbonImmutable::parse($data['valid_from'])->year)) {
            throw ValidationException::withMessages(['valid_from' => 'La decorrenza già avvenuta deve appartenere a un Esercizio Aperto.']);
        }
        if ($data['valid_from'] <= $today && $data['reason'] === null) {
            throw ValidationException::withMessages(['reason' => 'Indicare il motivo della registrazione del canone già in vigore.']);
        }
        $totals = $contract->annualTotals();
        $impacts = [];
        foreach ($exercises as $exercise) {
            if (! ContractClosedHistoryGuard::periodOverlapsYear($data['valid_from'], $data['valid_to'], $exercise->year)) {
                continue;
            }
            if (! $exercise->isOpen()) {
                throw ValidationException::withMessages(['valid_from' => 'La decorrenza interessa l’Esercizio Chiuso '.$exercise->year.'. Usare i percorsi storici previsti, senza nuove Stime.']);
            }
            if ($exercise->hasApprovedBudget() && $data['reason'] === null) {
                throw ValidationException::withMessages(['reason' => 'La motivazione è obbligatoria dopo un Budget approvato.']);
            }
            $annual = ContractAnnualAllocation::forYear([$data], $exercise->year, fn (string $date) => $contract->stateAtDate($date));
            $before = $totals[$exercise->id]['allocation'] ?? '0.00';
            $after = Decimal::add($annual->amount, $contract->manualEstimateTotal($exercise->id));
            $impacts[$exercise->id] = [
                'year' => $exercise->year, 'revision' => $exercise->revision,
                'allocation_before' => $before, 'allocation_after' => $after,
                'allocation_delta' => Decimal::subtract($after, $before),
                'composition_after' => $annual->composition,
            ];
        }

        return ['contractId' => $contract->id, 'contractRevision' => $contract->revision,
            'effectiveDate' => $data['valid_from'], 'newTerms' => $data, 'exerciseImpacts' => $impacts];
    }

    private function nullableTrim(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
