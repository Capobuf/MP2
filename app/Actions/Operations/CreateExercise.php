<?php

namespace App\Actions\Operations;

use App\Domain\Company\AuditEventType;
use App\Domain\Expenses\ExerciseStatus;
use App\Domain\Expenses\ExpenseAuditSnapshot;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractExerciseClassification;
use App\Models\Exercise;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CreateExercise
{
    public function __construct(private readonly RecalculateContractEstimates $recalculate) {}

    /** @param array{year?: mixed} $input */
    public function execute(User $actor, Company $company, array $input, string $operationId): Exercise
    {
        /** @var array{year: int, operation_id: string} $validated */
        $validated = Validator::make([...$input, 'operation_id' => $operationId], [
            'year' => ['required', 'integer', 'between:1,9999'],
            'operation_id' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $company, $validated): Exercise {
            $lockedCompany = Company::query()->lockForUpdate()->findOrFail($company->id);
            Gate::forUser($actor)->authorize('create', [Exercise::class, $lockedCompany]);

            $existing = AuditEvent::query()->where('operation_id', $validated['operation_id'])->where('event_sequence', 0)->first();
            if ($existing !== null) {
                if (
                    $existing->eventType() !== AuditEventType::ExerciseCreated
                    || $existing->subject_type !== Exercise::class
                    || $existing->company_id !== $lockedCompany->id
                ) {
                    throw ValidationException::withMessages([
                        'operation_id' => 'Identificativo operazione già utilizzato.',
                    ]);
                }

                return Exercise::query()->findOrFail($existing->subject_id);
            }

            $sequence = 0;

            return $this->createWithinTransaction(
                actor: $actor,
                company: $lockedCompany,
                year: $validated['year'],
                operationId: $validated['operation_id'],
                sequence: $sequence,
            );
        });
    }

    public function createWithinTransaction(
        User $actor,
        Company $company,
        int $year,
        string $operationId,
        int &$sequence,
    ): Exercise {
        if (Exercise::query()->where('company_id', $company->id)->where('year', $year)->exists()) {
            throw ValidationException::withMessages([
                'year' => 'Esiste già un Esercizio per questo anno nell’Azienda.',
            ]);
        }

        $projects = Project::query()->where('company_id', $company->id)->orderBy('id')->lockForUpdate()->get();
        $contracts = Contract::query()->where('company_id', $company->id)->orderBy('id')->lockForUpdate()->get();

        $exercise = Exercise::query()->create([
            'company_id' => $company->id,
            'year' => $year,
            'status' => ExerciseStatus::Open,
        ]);

        $projectClassificationIds = [];
        foreach ($projects as $project) {
            $latest = ProjectExerciseClassification::query()
                ->select('project_exercise_classifications.*')
                ->join('exercises', 'exercises.id', '=', 'project_exercise_classifications.exercise_id')
                ->where('project_exercise_classifications.project_id', $project->id)
                ->where('exercises.year', '<', $year)
                ->orderByDesc('exercises.year')
                ->orderByDesc('project_exercise_classifications.id')
                ->lockForUpdate()
                ->first();
            $classification = ProjectExerciseClassification::query()->create([
                'company_id' => $company->id,
                'project_id' => $project->id,
                'exercise_id' => $exercise->id,
                'cost_center_id' => $latest?->cost_center_id,
            ]);
            $projectClassificationIds[] = $classification->id;
            $project->increment('revision');
        }

        $contractClassificationIds = [];
        foreach ($contracts as $contract) {
            $latest = ContractExerciseClassification::query()
                ->select('contract_exercise_classifications.*')
                ->join('exercises', 'exercises.id', '=', 'contract_exercise_classifications.exercise_id')
                ->where('contract_exercise_classifications.contract_id', $contract->id)
                ->where('exercises.year', '<', $year)
                ->orderByDesc('exercises.year')
                ->orderByDesc('contract_exercise_classifications.id')
                ->lockForUpdate()
                ->first();
            $classification = ContractExerciseClassification::query()->create([
                'company_id' => $company->id,
                'contract_id' => $contract->id,
                'exercise_id' => $exercise->id,
                'cost_center_id' => $latest?->cost_center_id,
            ]);
            $contractClassificationIds[] = $classification->id;
            $contract->increment('revision');
        }

        if ($projects->isNotEmpty() || $contracts->isNotEmpty()) {
            $exercise->increment('revision');
            $exercise->refresh();
        }

        $zeroImpact = ExpenseAuditSnapshot::impact($exercise->id, '0');
        AuditEvent::query()->create([
            'operation_id' => $operationId,
            'event_sequence' => $sequence++,
            'company_id' => $company->id,
            'actor_id' => $actor->id,
            'event_type' => AuditEventType::ExerciseCreated,
            'subject_type' => Exercise::class,
            'subject_id' => $exercise->id,
            'affected_exercise_ids' => [$exercise->id],
            'effective_from' => now($company->timezone)->toDateString(),
            'previous_value' => null,
            'new_value' => [
                ...ExpenseAuditSnapshot::exercise($exercise),
                'project_classification_ids' => $projectClassificationIds,
                'contract_classification_ids' => $contractClassificationIds,
            ],
            'allocated_impact_by_exercise' => $zeroImpact,
            'actual_impact_by_exercise' => $zeroImpact,
        ]);

        foreach ($contracts as $contract) {
            $contract->unsetRelation('conditions')->unsetRelation('lifecycleFacts')->unsetRelation('renewalConfigurations');
            $this->recalculate->recalculateWithinTransaction(
                $actor,
                $contract,
                collect([$exercise]),
                $operationId,
                $sequence,
            );
        }

        return $exercise->refresh();
    }
}
