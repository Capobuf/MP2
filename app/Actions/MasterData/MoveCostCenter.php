<?php

namespace App\Actions\MasterData;

use App\Actions\Proposals\MarkProposalItemsToRealign;
use App\Domain\Company\AuditEventType;
use App\Domain\CostCenters\CostCenterHierarchy;
use App\Domain\CostCenters\CostCenterMoveImpactPlan;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class MoveCostCenter
{
    public function __construct(private readonly MarkProposalItemsToRealign $markToRealign) {}

    public function preview(User $actor, CostCenter $costCenter, ?int $parentId): CostCenterMoveImpactPlan
    {
        Gate::forUser($actor)->authorize('update', $costCenter);

        return CostCenterMoveImpactPlan::build($costCenter, $parentId);
    }

    public function confirm(User $actor, CostCenter $costCenter, CostCenterMoveImpactPlan $preview, string $operationId, ?string $reason = null): CostCenter
    {
        /** @var array{operation_id: string, reason: string|null} $validated */
        $validated = Validator::make([
            'operation_id' => $operationId,
            'reason' => is_string($reason) && trim($reason) !== '' ? trim($reason) : null,
        ], [
            'operation_id' => ['required', 'uuid'],
            'reason' => ['nullable', 'string'],
        ])->validate();

        return DB::transaction(function () use ($actor, $costCenter, $preview, $validated): CostCenter {
            $company = Company::query()->lockForUpdate()->findOrFail($costCenter->company_id);
            CostCenterHierarchy::forCompany((int) $company->id, true);
            $locked = CostCenter::query()->with('company')->findOrFail($costCenter->id);
            Gate::forUser($actor)->authorize('update', $locked);

            $existing = AuditEvent::query()->where('operation_id', $validated['operation_id'])->first();
            if ($existing !== null) {
                if ($existing->eventType() !== AuditEventType::CostCenterMoved
                    || $existing->subject_type !== CostCenter::class
                    || (int) $existing->subject_id !== (int) $locked->id) {
                    throw ValidationException::withMessages([
                        'operation_id' => 'Identificativo operazione già utilizzato.',
                    ]);
                }

                return $locked;
            }

            $current = CostCenterMoveImpactPlan::build($locked, $preview->newParentId);
            if (! hash_equals($preview->fingerprint(), $current->fingerprint())) {
                throw ValidationException::withMessages([
                    'preview' => 'La gerarchia o l’impatto è cambiato: calcolare una nuova anteprima.',
                ]);
            }

            if ($locked->parent_id === $preview->newParentId) {
                return $locked;
            }

            $previous = [
                'name' => $locked->name,
                'parent_id' => $current->oldParentId,
                'path' => $current->oldPath,
                'archived' => $locked->isArchived(),
            ];
            $locked->forceFill(['parent_id' => $preview->newParentId])->save();

            $this->markToRealign->execute(
                (int) $company->id,
                $current->expenseIds,
                $current->projectIds,
                $current->contractIds,
            );

            AuditEvent::query()->create([
                'operation_id' => $validated['operation_id'],
                'company_id' => $company->id,
                'actor_id' => $actor->id,
                'event_type' => AuditEventType::CostCenterMoved,
                'subject_type' => CostCenter::class,
                'subject_id' => $locked->id,
                'affected_exercise_ids' => array_column($current->exerciseImpacts, 'exercise_id'),
                'effective_from' => now($company->timezone)->toDateString(),
                'previous_value' => $previous,
                'new_value' => [
                    'name' => $locked->name,
                    'parent_id' => $current->newParentId,
                    'path' => $current->newPath,
                    'archived' => $locked->isArchived(),
                    'aggregation_impacts' => $current->exerciseImpacts,
                    'affected_sources' => [
                        'expense_ids' => $current->expenseIds,
                        'project_ids' => $current->projectIds,
                        'contract_ids' => $current->contractIds,
                    ],
                ],
                'allocated_impact_by_exercise' => collect($current->exerciseImpacts)
                    ->mapWithKeys(fn (array $impact): array => [(string) $impact['exercise_id'] => '0.00'])->all(),
                'actual_impact_by_exercise' => collect($current->exerciseImpacts)
                    ->mapWithKeys(fn (array $impact): array => [(string) $impact['exercise_id'] => '0.00'])->all(),
                'reason' => $validated['reason'],
            ]);

            return $locked;
        });
    }
}
