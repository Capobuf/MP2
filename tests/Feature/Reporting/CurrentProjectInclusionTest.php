<?php

use App\Actions\Reporting\BuildReport;
use App\Domain\Company\AuditEventType;
use App\Domain\Reporting\ReportDefinition;
use App\Domain\Reporting\ReportResult;
use App\Models\AuditEvent;
use App\Models\BudgetSnapshot;
use App\Models\BudgetSourceRow;
use App\Models\ClosingSourceRow;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\HistoricalErrorAnnotation;
use App\Models\Project;
use App\Models\ProjectTransition;
use App\Models\Proposal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-07 12:00:00 Europe/Rome'));
});

function currentProjectReport(Company $company, Exercise $exercise): ReportResult
{
    return app(BuildReport::class)->execute(s11ReportingViewer($company), ReportDefinition::fromArray([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'kind' => 'projects',
    ]));
}

it('excludes terminal projects without relevance to the selected year', function (string $state, string $history) {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $project = Project::factory()->for($company)->create([
        'initial_state' => $state,
        'initial_effective_date' => '2020-01-01',
    ]);
    if ($history === 'annulled transition') {
        ProjectTransition::factory()->forProject($project)->create([
            'from_state' => $state,
            'to_state' => 'open',
            'effective_date' => '2026-01-01',
            'reason' => 'Tentativo annullato',
            'annulled_at' => now(),
            'annulled_by_id' => User::factory()->create()->id,
            'annulment_reason' => 'Annullata',
        ]);
    } elseif ($history === 'old budget') {
        $previous = Exercise::factory()->for($company)->create(['year' => 2025]);
        $proposal = Proposal::factory()->for($company)->for($previous, 'exercise')->create();
        $budget = BudgetSnapshot::factory()->for($proposal)->create();
        BudgetSourceRow::factory()->for($budget, 'budget')->create([
            'source_type' => 'project',
            'origin_id' => $project->id,
            'origin_key' => $project->originKey(),
        ]);
    } elseif ($history === 'old restoration') {
        createProjectRestorationEvent($company, $project, '2025-12-31');
    }

    $result = currentProjectReport($company, $exercise);

    expect($result->sources)->toBeEmpty()
        ->and($result->sections[0]['rows'])->toBeEmpty()
        ->and($result->totals['source_count'])->toBe(0);
})->with(['closed', 'cancelled'])->with(['none', 'annulled transition', 'old budget', 'old restoration']);

it('keeps projects with a canonical current inclusion reason', function (string $reason) {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $project = Project::factory()->for($company)->create([
        'initial_state' => in_array($reason, ['planned', 'open'], true) ? $reason : 'closed',
        'initial_effective_date' => '2020-01-01',
    ]);

    if (in_array($reason, ['allocation', 'net zero actuals'], true)) {
        $expense = Expense::factory()->forExercise($exercise)->create(['project_id' => $project->id]);
        if ($reason === 'allocation') {
            ExpenseLine::factory()->for($expense)->create(['amount' => '100.00']);
        } else {
            ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '100.00']);
            ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '-100.00', 'note' => 'Rimborso']);
        }
    } elseif ($reason === 'budget') {
        $proposal = Proposal::factory()->for($company)->for($exercise)->create();
        $budget = BudgetSnapshot::factory()->for($proposal)->create();
        BudgetSourceRow::factory()->for($budget, 'budget')->create([
            'source_type' => 'project',
            'origin_id' => $project->id,
            'origin_key' => $project->originKey(),
        ]);
    } elseif ($reason === 'transition') {
        ProjectTransition::factory()->forProject($project)->create([
            'from_state' => 'closed',
            'to_state' => 'open',
            'effective_date' => '2026-01-01',
            'reason' => 'Riapertura',
        ]);
        ProjectTransition::factory()->forProject($project)->create([
            'from_state' => 'open',
            'to_state' => 'closed',
            'effective_date' => '2026-02-01',
            'reason' => 'Chiusura',
        ]);
    } elseif ($reason === 'restoration') {
        createProjectRestorationEvent($company, $project, '2026-01-01');
    } elseif ($reason === 'annotation') {
        $previous = Exercise::factory()->for($company)->create(['year' => 2025]);
        closeExerciseFixture($previous, s11ReportingViewer($company));
        HistoricalErrorAnnotation::factory()->forExercise($previous)->create([
            'affected_sources' => [[
                'type' => 'project',
                'id' => $project->id,
                'origin_key' => $project->originKey(),
                'label' => $project->title,
            ]],
        ]);
    }

    $result = currentProjectReport($company, $exercise);

    expect(collect($result->sources)->pluck('originKey')->all())->toBe([$project->originKey()])
        ->and($result->totals['source_count'])->toBe(1);
})->with(['planned', 'open', 'allocation', 'net zero actuals', 'budget', 'transition', 'restoration', 'annotation']);

it('uses the annual reference date when selecting current projects', function () {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $next = Exercise::factory()->for($company)->create(['year' => 2027]);
    $project = Project::factory()->for($company)->create(['initial_state' => 'open']);
    ProjectTransition::factory()->forProject($project)->create([
        'from_state' => 'open',
        'to_state' => 'closed',
        'effective_date' => '2026-12-31',
        'reason' => 'Chiusura',
    ]);
    Project::factory()->for($company)->create(['initial_effective_date' => '2027-02-01']);

    expect(collect(currentProjectReport($company, $exercise)->sources)->pluck('originKey')->all())
        ->toBe([$project->originKey()]);
    expect(currentProjectReport($company, $next)->sources)->toBeEmpty();
});

it('keeps budget and closing snapshots independent of current project inclusion', function () {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $project = Project::factory()->for($company)->create([
        'initial_state' => 'closed',
        'initial_effective_date' => '2020-01-01',
    ]);
    $viewer = s11ReportingViewer($company);
    $proposal = Proposal::factory()->for($company)->for($exercise)->create();
    $budget = BudgetSnapshot::factory()->for($proposal)->create();
    $budgetRow = BudgetSourceRow::factory()->for($budget, 'budget')->create([
        'source_type' => 'project',
        'origin_id' => $project->id,
        'origin_key' => $project->originKey(),
        'label' => 'Storico Budget',
        'end_state' => 'closed',
    ]);
    $snapshot = closeExerciseFixture($exercise, $viewer);
    $closingRow = ClosingSourceRow::query()->create([
        'company_id' => $company->id,
        'closing_snapshot_id' => $snapshot->id,
        'source_type' => 'project',
        'origin_id' => $project->id,
        'origin_key' => $project->originKey(),
        'label' => 'Storico Chiusura',
        'cost_center_label' => 'Non classificato',
        'end_state' => 'closed',
        'has_actuals' => false,
        'final_estimates' => '0.00',
        'received_carryover' => '0.00',
        'final_allocation' => '0.00',
        'closing_actual' => '0.00',
        'operational_variance' => '0.00',
        'detail_version' => 1,
        'detail' => [],
    ]);
    $before = [
        DB::table('budget_source_rows')->find($budgetRow->id),
        DB::table('closing_source_rows')->find($closingRow->id),
    ];

    foreach (['budget' => 'Storico Budget', 'closing' => 'Storico Chiusura', 'current_knowledge' => 'Storico Chiusura'] as $type => $label) {
        $reference = ['type' => $type, 'exercise_id' => $exercise->id];
        if ($type === 'budget') {
            $reference['budget_snapshot_id'] = $budget->id;
        }
        $result = app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
            'company_id' => $company->id,
            'exercise_id' => $exercise->id,
            'kind' => 'projects',
            'final_reference' => $reference,
        ]));
        expect($result->sources)->toHaveCount(1)
            ->and($result->sources[0]->label)->toBe($label);
    }

    expect([
        DB::table('budget_source_rows')->find($budgetRow->id),
        DB::table('closing_source_rows')->find($closingRow->id),
    ])->toEqual($before);
});

function createProjectRestorationEvent(Company $company, Project $project, string $effectiveFrom): void
{
    AuditEvent::query()->create([
        'company_id' => $company->id,
        'actor_id' => User::factory()->create()->id,
        'event_type' => AuditEventType::ProjectRestored,
        'subject_type' => Project::class,
        'subject_id' => $project->id,
        'affected_exercise_ids' => [],
        'effective_from' => $effectiveFrom,
        'allocated_impact_by_exercise' => [],
        'actual_impact_by_exercise' => [],
    ]);
}
