<?php

namespace App\Actions\Operations;

use App\Actions\Proposals\ApplyProjectDeferral;
use App\Actions\Proposals\MarkProposalItemsToRealign;
use App\Actions\Proposals\PlanProjectDeferral;
use App\Domain\Company\AuditEventType;
use App\Domain\Expenses\Decimal;
use App\Domain\Projects\ProjectDeferralMode;
use App\Domain\Projects\ProjectDeferralValues;
use App\Domain\Projects\ProjectState;
use App\Domain\Proposals\ProposalSourceSnapshot;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectDeferral;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ChangeProjectDeferral
{
    public function __construct(
        private readonly PlanProjectDeferral $planner,
        private readonly ApplyProjectDeferral $apply,
        private readonly MarkProposalItemsToRealign $markToRealign,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function preview(User $actor, Project $project, Exercise $source, Exercise $destination, array $input): array
    {
        Gate::forUser($actor)->authorize('update', $project);

        return $this->buildPreview($project, $source, $destination, $input, false);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(
        User $actor,
        Project $project,
        Exercise $source,
        Exercise $destination,
        array $input,
        string $reason,
        string $operationId,
        int $expectedProjectRevision,
        string $expectedPreviewFingerprint,
    ): ProjectDeferral {
        if (! Str::isUuid($operationId)) {
            throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione non valido.']);
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'La motivazione del cambio rinvio è obbligatoria.']);
        }

        return DB::transaction(function () use ($actor, $project, $source, $destination, $input, $reason, $operationId, $expectedProjectRevision, $expectedPreviewFingerprint): ProjectDeferral {
            $company = Company::query()->lockForUpdate()->findOrFail($project->company_id);
            $lockedProject = Project::query()->where('company_id', $company->id)->lockForUpdate()->findOrFail($project->id);
            $lockedSource = Exercise::query()->where('company_id', $company->id)->lockForUpdate()->findOrFail($source->id);
            $lockedDestination = Exercise::query()->where('company_id', $company->id)->lockForUpdate()->findOrFail($destination->id);
            Gate::forUser($actor)->authorize('update', $lockedProject);

            $existing = AuditEvent::query()->where('operation_id', $operationId)->first();
            if ($existing !== null) {
                if ($existing->eventType() !== AuditEventType::ProjectDeferralChanged
                    || $existing->subject_type !== ProjectDeferral::class
                    || $existing->reference_type !== Project::class
                    || $existing->reference_id !== $lockedProject->id) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return ProjectDeferral::query()->findOrFail($existing->subject_id);
            }
            if ($lockedProject->revision !== $expectedProjectRevision) {
                throw ValidationException::withMessages(['revision' => 'Il Progetto è cambiato: riaprire l’anteprima.']);
            }

            $preview = $this->buildPreview($lockedProject, $lockedSource, $lockedDestination, $input, true);
            if (! hash_equals($preview['fingerprint'], $expectedPreviewFingerprint)) {
                throw ValidationException::withMessages(['preview' => 'I dati del rinvio sono cambiati: riaprire l’anteprima.']);
            }

            $resolved = $this->apply->executeDirect(
                $lockedProject,
                $lockedSource,
                $lockedDestination,
                $preview['payload'],
                $operationId,
            );
            $deferral = ProjectDeferral::query()
                ->where('project_id', $lockedProject->id)
                ->where('source_exercise_id', $lockedSource->id)
                ->where('destination_exercise_id', $lockedDestination->id)
                ->sole();

            $previous = $resolved['previous'];
            $current = $resolved['current'];
            $sourceDelta = $this->sourceDelta($previous, $current);
            $destinationDelta = $this->destinationDelta($previous, $current);
            AuditEvent::query()->create([
                'operation_id' => $operationId,
                'company_id' => $company->id,
                'actor_id' => $actor->id,
                'event_type' => AuditEventType::ProjectDeferralChanged,
                'subject_type' => ProjectDeferral::class,
                'subject_id' => $deferral->id,
                'affected_exercise_ids' => [$lockedSource->id, $lockedDestination->id],
                'effective_from' => now($company->timezone)->toDateString(),
                'previous_value' => $previous,
                'new_value' => [
                    ...$current,
                    'project_id' => $lockedProject->id,
                    'source_exercise_id' => $lockedSource->id,
                    'destination_exercise_id' => $lockedDestination->id,
                    'resolved_effects' => $resolved['reprogramming_effects'],
                ],
                'allocated_impact_by_exercise' => [
                    (string) $lockedSource->id => $sourceDelta,
                    (string) $lockedDestination->id => $destinationDelta,
                ],
                'actual_impact_by_exercise' => [
                    (string) $lockedSource->id => '0.00',
                    (string) $lockedDestination->id => '0.00',
                ],
                'reason' => $reason,
                'reference_type' => Project::class,
                'reference_id' => $lockedProject->id,
            ]);
            $this->markToRealign->execute($company->id, projectIds: [$lockedProject->id]);

            return $deferral->refresh();
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function buildPreview(Project $project, Exercise $source, Exercise $destination, array $input, bool $lock): array
    {
        if ($project->company_id !== $source->company_id || $project->company_id !== $destination->company_id) {
            throw ValidationException::withMessages(['exercise_id' => 'Progetto ed Esercizi devono appartenere alla stessa Azienda.']);
        }
        if ($destination->year !== $source->year + 1) {
            throw ValidationException::withMessages(['destination_exercise_id' => 'Gli Esercizi devono essere consecutivi.']);
        }
        if (! $source->isOpen() || ! $destination->isOpen()) {
            throw ValidationException::withMessages(['exercise_id' => 'Entrambi gli Esercizi devono essere Aperti.']);
        }

        $deferralQuery = ProjectDeferral::query()
            ->where('project_id', $project->id)
            ->where('source_exercise_id', $source->id)
            ->where('destination_exercise_id', $destination->id);
        if ($lock) {
            $deferralQuery->lockForUpdate();
        }
        $deferral = $deferralQuery->first();
        $current = $deferral instanceof ProjectDeferral ? $deferral->mode : ProjectDeferralMode::None;
        $target = ProjectDeferralMode::tryFrom((string) ($input['mode'] ?? ''));
        $allowed = match ($current) {
            ProjectDeferralMode::Carryover => [ProjectDeferralMode::None, ProjectDeferralMode::Reprogramming],
            ProjectDeferralMode::Reprogramming => [ProjectDeferralMode::None, ProjectDeferralMode::Carryover],
            ProjectDeferralMode::None => [],
        };
        if ($target === null || ! in_array($target, $allowed, true)) {
            throw ValidationException::withMessages(['mode' => 'Cambio rinvio diretto non consentito.']);
        }
        if ($target !== ProjectDeferralMode::None
            && in_array($project->stateAtDate($source->year.'-12-31'), [ProjectState::Closed, ProjectState::Cancelled], true)) {
            throw ValidationException::withMessages(['mode' => 'Un Progetto terminale al 31 dicembre può usare soltanto Nessuna.']);
        }

        $expensesQuery = Expense::query()
            ->with('supplier')
            ->where('company_id', $project->company_id)
            ->where('project_id', $project->id)
            ->where('exercise_id', $source->id)
            ->orderBy('id');
        if ($lock) {
            $expensesQuery->lockForUpdate();
        }
        $expenses = $expensesQuery->get();
        $linesQuery = ExpenseLine::query()->whereIn('expense_id', $expenses->pluck('id'))->orderBy('id');
        if ($lock) {
            $linesQuery->lockForUpdate();
        }
        $lines = $linesQuery->get();

        $payload = [
            'source_exercise_id' => $source->id,
            'destination_exercise_id' => $destination->id,
            'mode' => $target->value,
            'carryover_amount' => '0.00',
            'reprogrammed_amount' => '0.00',
            'source_estimate_reductions' => [],
            'destination_plans' => [],
        ];
        $project->unsetRelation('expenses');
        $project->unsetRelation('deferrals');
        $totals = $project->annualTotals()[$source->id] ?? ['allocation' => '0.00', 'actual' => '0.00'];
        $availabilityAllocation = $current === ProjectDeferralMode::Reprogramming
            ? Decimal::add($totals['allocation'], (string) $deferral?->reprogrammed_amount)
            : $totals['allocation'];
        $maximum = ProjectDeferralValues::maximumTransferable($availabilityAllocation, $totals['actual']);

        if ($target === ProjectDeferralMode::Carryover) {
            $amount = Decimal::money((string) ($input['carryover_amount'] ?? '0'));
            if (Decimal::compare($amount, '0.00') <= 0 || Decimal::compare($amount, $maximum) > 0) {
                throw ValidationException::withMessages(['carryover_amount' => 'Il Riporto deve essere positivo e non superiore al massimo corrente dopo il ripristino.']);
            }
            $payload['carryover_amount'] = $amount;
        } elseif ($target === ProjectDeferralMode::Reprogramming) {
            if (! $this->destinationAcceptsPlan($project, $destination)) {
                throw ValidationException::withMessages(['project' => 'Il Progetto destinazione non è Pianificato o Aperto; usare una Proposta con la transizione necessaria.']);
            }
            [$reductions, $plans, $amount] = $this->planner->buildReprogramming(
                Company::query()->findOrFail($project->company_id),
                $expenses->keyBy('id'),
                $lines->keyBy('id'),
                $input['source_estimate_reductions'] ?? null,
            );
            if (Decimal::compare($amount, '0.00') <= 0 || Decimal::compare($amount, $maximum) > 0) {
                throw ValidationException::withMessages(['reprogrammed_amount' => 'La Riprogrammazione supera l’importo disponibile.']);
            }
            $payload['source_estimate_reductions'] = $reductions;
            $payload['destination_plans'] = $plans;
            $payload['reprogrammed_amount'] = $amount;
        }

        $facts = [
            'project_id' => $project->id,
            'project_revision' => (int) $project->revision,
            'source_exercise_id' => $source->id,
            'source_exercise_revision' => (int) $source->revision,
            'destination_exercise_id' => $destination->id,
            'destination_exercise_revision' => (int) $destination->revision,
            'current_deferral' => $deferral?->only(['id', 'updated_at', 'mode', 'carryover_amount', 'reprogrammed_amount', 'reprogramming_operation_id', 'reprogramming_effects']),
            'target' => $target->value,
            'carryover_amount' => $payload['carryover_amount'],
            'reprogrammed_amount' => $payload['reprogrammed_amount'],
            'source_estimate_reductions' => $payload['source_estimate_reductions'],
            'destination_plans' => collect($payload['destination_plans'])->map(fn (array $plan): array => collect($plan)->except('proposal_destination_id')->put(
                'estimate_lines',
                collect((array) ($plan['estimate_lines'] ?? []))->map(fn (mixed $line): array => collect(is_array($line) ? $line : [])->except('proposal_line_id')->all())->all(),
            )->all())->all(),
        ];

        return [
            'payload' => $payload,
            'project_revision' => (int) $project->revision,
            'fingerprint' => ProposalSourceSnapshot::fingerprint($facts),
            'maximum_transferable' => $maximum,
            'source_allocation' => $availabilityAllocation,
            'source_actual' => $totals['actual'],
            'source_lines_restored' => count((array) data_get($deferral?->reprogramming_effects, 'source_lines', [])),
            'destination_lines_annulled' => collect((array) data_get($deferral?->reprogramming_effects, 'destination_expenses', []))->sum(fn (array $expense): int => count($expense['estimate_lines'] ?? [])),
        ];
    }

    private function destinationAcceptsPlan(Project $project, Exercise $exercise): bool
    {
        $dates = collect([$exercise->year.'-01-01', $exercise->year.'-12-31'])
            ->merge($project->transitions()->whereNull('annulled_at')->pluck('effective_date')->map(fn (mixed $date): string => (string) $date))
            ->filter(fn (string $date): bool => $date >= $exercise->year.'-01-01' && $date <= $exercise->year.'-12-31');

        return $dates->contains(fn (string $date): bool => in_array($project->stateAtDate($date), [ProjectState::Planned, ProjectState::Open], true));
    }

    /**
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $current
     */
    private function sourceDelta(array $previous, array $current): string
    {
        $before = $previous['mode'] === ProjectDeferralMode::Reprogramming->value ? (string) $previous['reprogrammed_amount'] : '0.00';
        $after = $current['mode'] === ProjectDeferralMode::Reprogramming->value ? (string) $current['reprogrammed_amount'] : '0.00';

        return Decimal::subtract($before, $after);
    }

    /**
     * @param  array<string, mixed>  $previous
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
