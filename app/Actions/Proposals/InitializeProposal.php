<?php

namespace App\Actions\Proposals;

use App\Domain\Company\AuditEventType;
use App\Domain\Proposals\ProposalSourceCatalog;
use App\Domain\Proposals\ProposalSourceSnapshot;
use App\Models\AuditEvent;
use App\Models\BudgetSnapshot;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class InitializeProposal
{
    public function __construct(private ProposalSourceCatalog $catalog) {}

    public function execute(User $actor, Company $company, Exercise $exercise, string $operationId): Proposal
    {
        if (! Str::isUuid($operationId)) {
            throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione non valido.']);
        }

        return DB::transaction(function () use ($actor, $company, $exercise, $operationId): Proposal {
            $lockedCompany = Company::query()->lockForUpdate()->findOrFail($company->id);
            Gate::forUser($actor)->authorize('create', [Proposal::class, $lockedCompany]);

            $receipt = AuditEvent::query()->where('operation_id', $operationId)->first();
            if ($receipt !== null) {
                if ($receipt->eventType() !== AuditEventType::ProposalInitialized || $receipt->subject_type !== Proposal::class || $receipt->company_id !== $lockedCompany->id) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return Proposal::query()->with('items')->findOrFail($receipt->subject_id);
            }

            $lockedExercise = Exercise::query()->lockForUpdate()->find($exercise->id);
            if ($lockedExercise === null || $lockedExercise->company_id !== $lockedCompany->id) {
                throw ValidationException::withMessages(['exercise_id' => 'Esercizio non disponibile per questa Azienda.']);
            }
            if (! $lockedExercise->isOpen()) {
                throw ValidationException::withMessages(['exercise_id' => 'L’Esercizio deve essere Aperto.']);
            }
            if (BudgetSnapshot::query()->where('exercise_id', $lockedExercise->id)->exists()) {
                throw ValidationException::withMessages(['exercise_id' => 'L’Esercizio possiede già un Budget.']);
            }
            if (Proposal::query()->where('company_id', $lockedCompany->id)->where('exercise_id', $lockedExercise->id)->where('status', 'draft')->exists()) {
                throw ValidationException::withMessages(['exercise_id' => 'Esiste già una Proposta attiva per questo Esercizio.']);
            }

            $sources = $this->catalog->forExercise($lockedExercise);
            $proposal = Proposal::query()->create(['company_id' => $lockedCompany->id, 'exercise_id' => $lockedExercise->id, 'purpose' => 'initial_budget', 'status' => 'draft', 'created_by_id' => $actor->id]);

            foreach ($sources as $source) {
                $model = $source['model'];
                $snapshot = match (true) {
                    $model instanceof Expense => ProposalSourceSnapshot::expense($model),
                    $model instanceof Project => ProposalSourceSnapshot::project($model, $lockedExercise->id),
                    $model instanceof Contract => ProposalSourceSnapshot::contract($model, $lockedExercise->id),
                };
                $proposal->items()->create([
                    'proposal_item_id' => (string) Str::uuid(), 'company_id' => $lockedCompany->id,
                    'source_type' => $source['source_type'], 'expense_id' => $model instanceof Expense ? $model->id : null,
                    'project_id' => $model instanceof Project ? $model->id : null, 'contract_id' => $model instanceof Contract ? $model->id : null,
                    'baseline_revision' => (int) $model->revision, 'baseline_fingerprint' => ProposalSourceSnapshot::fingerprint($snapshot),
                    'baseline' => $snapshot, 'result' => $snapshot['plan_baseline'], 'readiness_state' => 'aligned', 'readiness_reasons' => [],
                    'read_only_source' => $source['read_only'], 'last_aligned_at' => now(), 'last_aligned_by_id' => $actor->id,
                ]);
            }

            AuditEvent::query()->create([
                'operation_id' => $operationId, 'company_id' => $lockedCompany->id, 'actor_id' => $actor->id,
                'event_type' => AuditEventType::ProposalInitialized, 'subject_type' => Proposal::class, 'subject_id' => $proposal->id,
                'affected_exercise_ids' => [$lockedExercise->id], 'effective_from' => now($lockedCompany->timezone)->toDateString(),
                'previous_value' => null, 'new_value' => ['exercise_id' => $lockedExercise->id, 'exercise_revision' => $lockedExercise->revision, 'item_count' => $sources->count(), 'origin_keys' => $sources->pluck('origin_key')->values()->all()],
                'allocated_impact_by_exercise' => [(string) $lockedExercise->id => '0.00'], 'actual_impact_by_exercise' => [(string) $lockedExercise->id => '0.00'],
            ]);

            return $proposal->load('items');
        });
    }
}
