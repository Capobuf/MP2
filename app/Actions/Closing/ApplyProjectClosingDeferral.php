<?php

namespace App\Actions\Closing;

use App\Actions\Proposals\ApplyProjectDeferral;
use App\Domain\Company\AuditEventType;
use App\Domain\Expenses\Decimal;
use App\Domain\Projects\ProjectDeferralMode;
use App\Models\AuditEvent;
use App\Models\Exercise;
use App\Models\Project;
use App\Models\ProjectDeferral;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class ApplyProjectClosingDeferral
{
    public function __construct(
        private readonly ApplyProjectDeferral $apply,
        private readonly BuildClosingReprogrammingPlan $reprogramming,
    ) {}

    /**
     * @param  array<string, mixed>  $decision
     * @return array{changed: bool, deferral: ProjectDeferral|null, previous: array<string, mixed>, current: array<string, mixed>}
     */
    public function executeWithinTransaction(
        User $actor,
        Project $project,
        Exercise $source,
        ?Exercise $destination,
        array $decision,
        string $operationId,
        int &$sequence,
    ): array {
        $current = ProjectDeferral::query()
            ->where('project_id', $project->id)
            ->where('source_exercise_id', $source->id)
            ->lockForUpdate()
            ->first();
        $mode = ProjectDeferralMode::from((string) $decision['final_mode']);
        $previous = $this->state($current);

        if ($mode === ProjectDeferralMode::Reprogramming && $current?->mode === ProjectDeferralMode::Reprogramming) {
            return ['changed' => false, 'deferral' => $current, 'previous' => $previous, 'current' => $previous];
        }
        if ($mode === ProjectDeferralMode::None && $current === null) {
            return ['changed' => false, 'deferral' => null, 'previous' => $previous, 'current' => $previous];
        }

        $destination ??= $current?->destinationExercise()->first();
        if (! $destination instanceof Exercise) {
            throw ValidationException::withMessages([
                'destination_exercise' => 'L’Esercizio successivo è necessario per applicare il rinvio del Progetto.',
            ]);
        }
        if (! $source->isOpen() || ! $destination->isOpen()) {
            throw ValidationException::withMessages([
                'exercise' => 'Il rinvio finale deve essere applicato mentre entrambi gli Esercizi sono Aperti.',
            ]);
        }

        $rawDecision = is_array($decision['decision_payload'] ?? null) ? $decision['decision_payload'] : [];
        $sourceReductions = [];
        $destinationPlans = [];
        $reprogrammedAmount = '0.00';
        if ($mode === ProjectDeferralMode::Reprogramming) {
            $plan = $this->reprogramming->build(
                $project,
                $source,
                $destination,
                $rawDecision['source_estimate_reductions'] ?? null,
            );
            $sourceReductions = $plan['source_estimate_reductions'];
            $destinationPlans = $plan['destination_plans'];
            $reprogrammedAmount = $plan['reprogrammed_amount'];
            if (Decimal::compare($reprogrammedAmount, (string) $decision['reprogrammed_amount']) !== 0) {
                throw ValidationException::withMessages([
                    'reprogrammed_amount' => 'La Riprogrammazione è cambiata rispetto al riepilogo confermato.',
                ]);
            }
        }

        $payload = [
            'source_exercise_id' => $source->id,
            'destination_exercise_id' => $destination->id,
            'mode' => $mode->value,
            'carryover_amount' => $mode === ProjectDeferralMode::Carryover ? (string) $decision['carryover_amount'] : '0.00',
            'reprogrammed_amount' => $reprogrammedAmount,
            'source_estimate_reductions' => $sourceReductions,
            'destination_plans' => $destinationPlans,
        ];
        $resolved = $this->apply->executeDirect($project, $source, $destination, $payload, $operationId);
        $deferral = ProjectDeferral::query()
            ->where('project_id', $project->id)
            ->where('source_exercise_id', $source->id)
            ->where('destination_exercise_id', $destination->id)
            ->sole();

        if ($mode === ProjectDeferralMode::Carryover && $deferral->carryover_state !== 'consolidated') {
            $deferral->update(['carryover_state' => 'consolidated']);
            $deferral->refresh();
        }

        $after = $this->state($deferral);
        $sourceDelta = $this->sourceDelta($previous, $after);
        $destinationDelta = $this->destinationDelta($previous, $after);
        $eventType = $mode === ProjectDeferralMode::Carryover
            ? AuditEventType::ProjectCarryoverConsolidated
            : AuditEventType::ProjectDeferralChanged;

        AuditEvent::query()->create([
            'operation_id' => $operationId,
            'event_sequence' => $sequence++,
            'company_id' => $project->company_id,
            'actor_id' => $actor->id,
            'event_type' => $eventType,
            'subject_type' => ProjectDeferral::class,
            'subject_id' => $deferral->id,
            'affected_exercise_ids' => [$source->id, $destination->id],
            'effective_from' => $source->year.'-12-31',
            'previous_value' => $previous,
            'new_value' => [
                ...$after,
                'project_id' => $project->id,
                'source_exercise_id' => $source->id,
                'destination_exercise_id' => $destination->id,
                'resolved_effects' => $resolved['reprogramming_effects'],
                'closing' => true,
            ],
            'allocated_impact_by_exercise' => [
                (string) $source->id => $sourceDelta,
                (string) $destination->id => $destinationDelta,
            ],
            'actual_impact_by_exercise' => [
                (string) $source->id => '0.00',
                (string) $destination->id => '0.00',
            ],
            'reason' => $decision['reason'] ?? null,
            'reference_type' => Project::class,
            'reference_id' => $project->id,
        ]);

        return ['changed' => true, 'deferral' => $deferral, 'previous' => $previous, 'current' => $after];
    }

    /** @return array<string, mixed> */
    private function state(?ProjectDeferral $deferral): array
    {
        return [
            'mode' => $deferral?->mode->value ?? ProjectDeferralMode::None->value,
            'carryover_amount' => $deferral?->mode === ProjectDeferralMode::Carryover ? (string) $deferral->carryover_amount : '0.00',
            'carryover_state' => $deferral?->mode === ProjectDeferralMode::Carryover ? $deferral->carryover_state : null,
            'reprogrammed_amount' => $deferral?->mode === ProjectDeferralMode::Reprogramming ? (string) $deferral->reprogrammed_amount : '0.00',
            'reprogramming_operation_id' => $deferral?->mode === ProjectDeferralMode::Reprogramming ? $deferral->reprogramming_operation_id : null,
        ];
    }

    /** @param array<string, mixed> $previous
     * @param  array<string, mixed>  $current
     */
    private function sourceDelta(array $previous, array $current): string
    {
        $before = $previous['mode'] === ProjectDeferralMode::Reprogramming->value ? (string) $previous['reprogrammed_amount'] : '0.00';
        $after = $current['mode'] === ProjectDeferralMode::Reprogramming->value ? (string) $current['reprogrammed_amount'] : '0.00';

        return Decimal::subtract($before, $after);
    }

    /** @param array<string, mixed> $previous
     * @param  array<string, mixed>  $current
     */
    private function destinationDelta(array $previous, array $current): string
    {
        $before = $previous['mode'] === ProjectDeferralMode::Carryover->value
            ? (string) $previous['carryover_amount']
            : ($previous['mode'] === ProjectDeferralMode::Reprogramming->value ? (string) $previous['reprogrammed_amount'] : '0.00');
        $after = $current['mode'] === ProjectDeferralMode::Carryover->value
            ? (string) $current['carryover_amount']
            : ($current['mode'] === ProjectDeferralMode::Reprogramming->value ? (string) $current['reprogrammed_amount'] : '0.00');

        return Decimal::subtract($after, $before);
    }
}
