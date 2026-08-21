<?php

namespace App\Actions\Operations;

use App\Domain\Company\AuditEventType;
use App\Domain\Contracts\ContractLifecycleRules;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractLifecycleFact;
use App\Models\Exercise;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReplaceContractLifecycleFact
{
    public function __construct(private readonly RecalculateContractEstimates $recalculate) {}

    /** @param array<string, mixed> $input */
    public function execute(User $actor, ContractLifecycleFact $fact, array $input, string $operationId): ContractLifecycleFact
    {
        $data = Validator::make([
            'type' => $input['type'] ?? null, 'date' => $input['declared_contractual_date'] ?? null,
            'reason' => isset($input['reason']) ? trim((string) $input['reason']) : null,
            'replacement_reason' => isset($input['replacement_reason']) ? trim((string) $input['replacement_reason']) : null,
            'revision' => $input['expected_revision'] ?? null, 'operation_id' => $operationId,
        ], [
            'type' => ['required', Rule::in(['activation', 'cessation', 'reactivation', 'cancellation'])],
            'date' => ['required', 'date_format:Y-m-d'], 'reason' => ['nullable', 'string'], 'replacement_reason' => ['required', 'string'],
            'revision' => ['required', 'integer', 'min:0'], 'operation_id' => ['required', 'uuid'],
        ])->validate();
        if (in_array($data['type'], ['cessation', 'reactivation', 'cancellation'], true) && blank($data['reason'])) {
            throw ValidationException::withMessages(['reason' => 'Il motivo è obbligatorio per questo evento.']);
        }

        return DB::transaction(function () use ($actor, $fact, $data): ContractLifecycleFact {
            $company = Company::query()->lockForUpdate()->findOrFail($fact->company_id);
            $today = CarbonImmutable::now($company->timezone)->startOfDay();
            $exercises = Exercise::query()->whereBelongsTo($company, 'company')->open()->orderBy('id')->lockForUpdate()->get();
            $contract = Contract::query()->lockForUpdate()->findOrFail($fact->contract_id);
            $facts = $contract->lifecycleFacts()->lockForUpdate()->get();
            $old = $facts->firstWhere('id', $fact->id);
            abort_unless($old instanceof ContractLifecycleFact, 404);
            Gate::forUser($actor)->authorize('update', $old);
            $existing = AuditEvent::query()->where('operation_id', $data['operation_id'])->where('event_sequence', 0)->first();
            if ($existing !== null) {
                return ContractLifecycleFact::query()->findOrFail($existing->subject_id);
            }
            if ($contract->revision !== (int) $data['revision']) {
                throw ValidationException::withMessages(['revision' => 'Il Contratto è cambiato dopo l’anteprima.']);
            }
            if ($old->annulledAt() !== null || $old->stateChangeDate() === null || ! $old->stateChangeDate()->startOfDay()->greaterThan($today)) {
                throw ValidationException::withMessages(['lifecycle' => 'Solo un evento futuro attivo può essere sostituito.']);
            }
            $declared = CarbonImmutable::parse($data['date'])->startOfDay();
            $stateChange = $data['type'] === 'cessation' ? $declared->addDay() : $declared;
            if (! $stateChange->greaterThan($today)) {
                throw ValidationException::withMessages(['date' => 'L’evento sostitutivo deve essere futuro.']);
            }

            $old->update(['annulled_at' => now(), 'annulled_by_id' => $actor->id, 'annulment_reason' => $data['replacement_reason']]);
            $replacement = ContractLifecycleFact::query()->create([
                'company_id' => $company->id, 'contract_id' => $contract->id, 'type' => $data['type'],
                'declared_contractual_date' => $declared, 'state_change_date' => $stateChange,
                'reason' => $data['reason'], 'created_by_id' => $actor->id,
            ]);
            $facts->push($replacement);
            try {
                ContractLifecycleRules::validate($contract->contractualStartDate()->toDateString(), $facts);
            } catch (\DomainException $exception) {
                throw ValidationException::withMessages(['lifecycle' => $exception->getMessage()]);
            }
            $contract->increment('revision');
            $exerciseIds = $exercises->pluck('id')->all();
            $sequence = 0;
            AuditEvent::query()->create([
                'operation_id' => $data['operation_id'], 'event_sequence' => $sequence++, 'company_id' => $company->id, 'actor_id' => $actor->id,
                'event_type' => AuditEventType::ContractLifecycleFactReplaced, 'subject_type' => ContractLifecycleFact::class,
                'subject_id' => $replacement->id, 'affected_exercise_ids' => $exerciseIds, 'effective_from' => $declared,
                'previous_value' => ['fact_id' => $old->id, 'type' => $old->type], 'new_value' => ['contract_id' => $contract->id, 'type' => $replacement->type],
                'allocated_impact_by_exercise' => array_fill_keys(array_map('strval', $exerciseIds), '0.00'),
                'actual_impact_by_exercise' => array_fill_keys(array_map('strval', $exerciseIds), '0.00'),
                'reason' => $data['replacement_reason'], 'reference_type' => Contract::class, 'reference_id' => $contract->id,
            ]);
            $contract->unsetRelation('conditions')->unsetRelation('lifecycleFacts');
            $this->recalculate->recalculateWithinTransaction($actor, $contract, $exercises, $data['operation_id'], $sequence);

            return $replacement->refresh();
        });
    }
}
