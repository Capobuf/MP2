<?php

namespace App\Actions\Operations;

use App\Actions\Proposals\MarkProposalItemsToRealign;
use App\Domain\Company\AuditEventType;
use App\Domain\Contracts\ContractClosedHistoryGuard;
use App\Domain\Contracts\ContractImpactFingerprint;
use App\Domain\Contracts\ContractState;
use App\Domain\Expenses\Decimal;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractLifecycleFact;
use App\Models\Exercise;
use App\Models\ExpenseLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

class CancelContract
{
    public function __construct(private readonly RecalculateContractEstimates $recalculate) {}

    /** @param list<int> $selectedLineIds */
    public function execute(User $actor, Contract $contract, string $reason, int $expectedRevision, string $operationId, array $selectedLineIds = [], ?string $confirmedFingerprint = null): ContractLifecycleFact
    {
        $data = Validator::make(['reason' => trim($reason), 'revision' => $expectedRevision, 'operation_id' => $operationId], [
            'reason' => ['required', 'string'], 'revision' => ['required', 'integer', 'min:0'], 'operation_id' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $contract, $data, $selectedLineIds, $confirmedFingerprint): ContractLifecycleFact {
            $company = Company::query()->lockForUpdate()->findOrFail($contract->company_id);
            $today = CarbonImmutable::now($company->timezone)->startOfDay();
            $exercises = Exercise::query()->whereBelongsTo($company, 'company')->open()->orderBy('id')->lockForUpdate()->get();
            $locked = Contract::query()->lockForUpdate()->findOrFail($contract->id);
            $facts = $locked->lifecycleFacts()->lockForUpdate()->get();
            Gate::forUser($actor)->authorize('update', $locked);
            $existing = AuditEvent::query()->where('operation_id', $data['operation_id'])->where('event_sequence', 0)->first();
            if ($existing !== null) {
                if ($existing->eventType() !== AuditEventType::ContractCancelled || $existing->subject_type !== ContractLifecycleFact::class
                    || $existing->company_id !== $company->id || $existing->reference_type !== Contract::class || $existing->reference_id !== $locked->id) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return ContractLifecycleFact::query()->findOrFail($existing->subject_id);
            }
            if ($locked->revision !== (int) $data['revision']) {
                throw ValidationException::withMessages(['revision' => 'Il Contratto è cambiato dopo l’anteprima.']);
            }
            $everActive = $locked->contractualStartDate()->startOfDay()->lessThanOrEqualTo($today)
                || $facts->contains(fn (ContractLifecycleFact $fact): bool => in_array($fact->type, ['activation', 'reactivation'], true)
                    && $fact->annulledAt() === null && $fact->stateChangeDate() !== null
                    && $fact->stateChangeDate()->startOfDay()->lessThanOrEqualTo($today));
            if ($locked->isArchived() || $everActive || $locked->stateAtDate($today->toDateString()) !== ContractState::Planned) {
                throw ValidationException::withMessages(['contract' => 'Può essere annullato soltanto un Contratto Pianificato mai attivato.']);
            }
            $preview = $this->preview($actor, $locked);
            if (($confirmedFingerprint === null && $preview['candidates'] !== [])
                || ($confirmedFingerprint !== null && ! hash_equals(ContractImpactFingerprint::make($preview), $confirmedFingerprint))) {
                throw ValidationException::withMessages(['costs' => 'I costi o il Contratto sono cambiati: rivedere e confermare l’anteprima aggiornata.']);
            }
            $selected = Validator::make(['lines' => $selectedLineIds], ['lines' => ['array'], 'lines.*' => ['integer', 'distinct']])->validate()['lines'];
            $candidates = collect($preview['candidates'])->keyBy('line_id');
            foreach ($selected as $lineId) {
                if (! $candidates->has($lineId)) {
                    throw ValidationException::withMessages(['costs' => 'La Riga selezionata non è una Stima manuale modificabile del Contratto.']);
                }
                $line = ExpenseLine::query()->lockForUpdate()->findOrFail($lineId);
                Gate::forUser($actor)->authorize('update', $line);
                app(SetExpenseLineActive::class)->execute($actor, $line, false,
                    Uuid::uuid5($data['operation_id'], 'estimate:'.$lineId)->toString(), ['change_reason' => $data['reason']]);
            }
            $locked->refresh();
            foreach ($facts as $fact) {
                if ($fact->annulledAt() === null && $fact->stateChangeDate() !== null && $fact->stateChangeDate()->startOfDay()->greaterThan($today)) {
                    $fact->update(['annulled_at' => now(), 'annulled_by_id' => $actor->id, 'annulment_reason' => $data['reason']]);
                }
            }
            foreach ($locked->conditions()->active()->lockForUpdate()->get() as $condition) {
                $condition->update(['annulled_at' => now(), 'annulled_by_id' => $actor->id, 'reason' => $data['reason']]);
            }
            $fact = ContractLifecycleFact::query()->create([
                'company_id' => $company->id, 'contract_id' => $locked->id, 'type' => 'cancellation',
                'declared_contractual_date' => $today->toDateString(), 'state_change_date' => $today->toDateString(),
                'reason' => $data['reason'], 'created_by_id' => $actor->id,
            ]);
            $locked->increment('revision');
            $exerciseIds = $exercises->pluck('id')->all();
            $sequence = 0;
            AuditEvent::query()->create([
                'operation_id' => $data['operation_id'], 'event_sequence' => $sequence++, 'company_id' => $company->id,
                'actor_id' => $actor->id, 'event_type' => AuditEventType::ContractCancelled,
                'subject_type' => ContractLifecycleFact::class, 'subject_id' => $fact->id, 'affected_exercise_ids' => $exerciseIds,
                'effective_from' => $today, 'previous_value' => ['state' => 'planned'], 'new_value' => ['state' => 'cancelled', 'contract_id' => $locked->id, 'annulled_estimate_line_ids' => $selected],
                'allocated_impact_by_exercise' => array_fill_keys(array_map('strval', $exerciseIds), '0.00'),
                'actual_impact_by_exercise' => array_fill_keys(array_map('strval', $exerciseIds), '0.00'),
                'reason' => $data['reason'], 'reference_type' => Contract::class, 'reference_id' => $locked->id,
            ]);
            $locked->unsetRelation('conditions')->unsetRelation('lifecycleFacts');
            $this->recalculate->recalculateWithinTransaction($actor, $locked, $exercises, $data['operation_id'], $sequence);

            $totals = $locked->annualTotals();
            foreach ($preview['exercises'] as $annual) {
                if (! $annual['open']) {
                    continue;
                }
                $removed = Decimal::sum($candidates->only($selected)->where('exercise_id', $annual['id'])->pluck('amount'));
                $expected = Decimal::subtract($annual['manual_estimates'], $removed);
                if (Decimal::compare($totals[$annual['id']]['allocation'] ?? '0.00', $expected) !== 0) {
                    throw ValidationException::withMessages(['costs' => 'Il totale materializzato non coincide con l’anteprima confermata.']);
                }
            }
            app(MarkProposalItemsToRealign::class)->execute($company->id, contractIds: [$locked->id]);

            return $fact->refresh();
        });
    }

    /** @return array{contract_id: int, revision: int, today: string, candidates: list<array<string, mixed>>, readonly: list<array<string, mixed>>, exercises: array<int, array<string, mixed>>} */
    public function preview(User $actor, Contract $contract): array
    {
        Gate::forUser($actor)->authorize('update', $contract);
        $today = now($contract->company->timezone)->toDateString();
        if ($contract->isArchived() || $contract->contractualStartDate()->toDateString() <= $today
            || $contract->stateAtDate($today) !== ContractState::Planned) {
            throw ValidationException::withMessages(['contract' => 'Può essere annullato soltanto un Contratto Pianificato mai attivato.']);
        }
        ContractClosedHistoryGuard::assertEventDateIsMutable($contract, $today);
        $totals = $contract->annualTotals();
        $exercises = $contract->company->exercises()->orderBy('year')->get();
        $expenses = $contract->expenses()->where('origin', 'manual')->with('lines')->orderBy('id')->get();
        $candidates = [];
        $readonly = [];
        foreach ($expenses as $expense) {
            $exercise = $exercises->firstWhere('id', $expense->exercise_id);
            foreach ($expense->lines->sortBy('id') as $line) {
                if ($line->lineType()->value !== 'estimate' || $line->isAnnulled() || $expense->isReversed()) {
                    continue;
                }
                $row = ['line_id' => $line->id, 'line_revision' => $line->revision, 'expense_id' => $expense->id,
                    'expense_revision' => $expense->revision, 'exercise_id' => $exercise->id, 'year' => $exercise->year,
                    'description' => $expense->description, 'note' => $line->note, 'amount' => (string) $line->amount,
                    'can_update' => $actor->can('update', $line)];
                if ($exercise->isOpen()) {
                    $candidates[] = $row;
                } else {
                    $readonly[] = $row;
                }
            }
        }

        return ['contract_id' => $contract->id, 'revision' => $contract->revision, 'today' => $today,
            'candidates' => $candidates, 'readonly' => $readonly,
            'exercises' => $exercises->map(fn (Exercise $exercise): array => [
                'id' => $exercise->id, 'year' => $exercise->year, 'revision' => $exercise->revision,
                'open' => $exercise->isOpen(), 'allocation' => $totals[$exercise->id]['allocation'] ?? '0.00',
                'manual_estimates' => $contract->manualEstimateTotal($exercise->id),
            ])->all()];
    }
}
