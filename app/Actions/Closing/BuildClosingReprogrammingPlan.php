<?php

namespace App\Actions\Closing;

use App\Actions\Proposals\PlanProjectDeferral;
use App\Domain\Projects\ProjectState;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use Illuminate\Validation\ValidationException;

final class BuildClosingReprogrammingPlan
{
    public function __construct(private readonly PlanProjectDeferral $planner) {}

    /**
     * @param  mixed  $requestedReductions
     * @return array{source_estimate_reductions: list<array<string, mixed>>, destination_plans: list<array<string, mixed>>, reprogrammed_amount: string}
     */
    public function build(Project $project, Exercise $source, Exercise $destination, mixed $requestedReductions): array
    {
        if ($project->company_id !== $source->company_id || $project->company_id !== $destination->company_id) {
            throw ValidationException::withMessages([
                'exercise' => 'Progetto ed Esercizi devono appartenere alla stessa Azienda.',
            ]);
        }
        if ($destination->year !== $source->year + 1 || ! $source->isOpen() || ! $destination->isOpen()) {
            throw ValidationException::withMessages([
                'exercise' => 'La Riprogrammazione richiede due Esercizi consecutivi e Aperti.',
            ]);
        }
        if (! $this->destinationAcceptsPlan($project, $destination)) {
            throw ValidationException::withMessages([
                'project' => 'Il Progetto non può ricevere nuova pianificazione nell’Esercizio destinazione.',
            ]);
        }

        $expenses = Expense::query()
            ->with('supplier')
            ->where('company_id', $project->company_id)
            ->where('project_id', $project->id)
            ->where('exercise_id', $source->id)
            ->orderBy('id')
            ->get();
        $lines = ExpenseLine::query()
            ->whereIn('expense_id', $expenses->pluck('id'))
            ->orderBy('id')
            ->get();
        [$reductions, $plans, $amount] = $this->planner->buildReprogramming(
            Company::query()->findOrFail($project->company_id),
            $expenses->keyBy('id'),
            $lines->keyBy('id'),
            $requestedReductions,
        );

        return [
            'source_estimate_reductions' => $reductions,
            'destination_plans' => $plans,
            'reprogrammed_amount' => $amount,
        ];
    }

    private function destinationAcceptsPlan(Project $project, Exercise $exercise): bool
    {
        $dates = collect([$exercise->year.'-01-01', $exercise->year.'-12-31'])
            ->merge($project->transitions()->whereNull('annulled_at')->pluck('effective_date')->map(fn (mixed $date): string => (string) $date))
            ->filter(fn (string $date): bool => $date >= $exercise->year.'-01-01' && $date <= $exercise->year.'-12-31');

        return $dates->contains(fn (string $date): bool => in_array(
            $project->stateAtDate($date),
            [ProjectState::Planned, ProjectState::Open],
            true,
        ));
    }
}
