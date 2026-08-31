<?php

use App\Actions\LateCorrections\RecordHistoricalErrorAnnotation;
use App\Actions\LateCorrections\RecordLateCorrection;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectDeferral;
use App\Models\ProjectExerciseClassification;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

/** @return array<string, mixed> */
function s10InvariantFixture(): array
{
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    grantTestPermissions([
        'company_id' => $company->id,
        'user' => $actor,
        'permissions' => TestPermissions::CORRECT_CLOSED_EXERCISE,
    ]);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $nextExercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $project = Project::factory()->for($company)->create(['initial_effective_date' => '2025-01-01']);
    $supplier = Supplier::factory()->for($company)->create();
    $costCenter = CostCenter::factory()->for($company)->create();
    $classification = ProjectExerciseClassification::factory()->forProjectAndExercise($project, $exercise)->create([
        'cost_center_id' => $costCenter->id,
    ]);
    $expense = Expense::factory()->forExercise($exercise)->for($project)->for($supplier)->create();
    $originalLine = ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '100.00']);
    $deferral = ProjectDeferral::factory()
        ->for($project)
        ->for($exercise, 'sourceExercise')
        ->for($nextExercise, 'destinationExercise')
        ->carryover('20.00')
        ->state(['carryover_state' => 'consolidated'])
        ->create(['company_id' => $company->id]);
    $snapshot = closeExerciseFixture($exercise, $actor);

    return compact(
        'company',
        'actor',
        'exercise',
        'project',
        'supplier',
        'costCenter',
        'classification',
        'expense',
        'originalLine',
        'deferral',
        'snapshot',
    );
}

function recordInvariantCorrection(array $fixture, string $amount): void
{
    $fixture['exercise']->refresh();
    $fixture['expense']->refresh();
    $fixture['project']->refresh();

    app(RecordLateCorrection::class)->execute($fixture['actor'], $fixture['exercise'], [
        'source_type' => 'project',
        'source_origin_id' => $fixture['project']->id,
        'historical_expense_id' => $fixture['expense']->id,
        'amount' => $amount,
        'reason' => 'Verifica invariante S10',
        'belongs_to_closed_exercise' => true,
        'expected_exercise_revision' => $fixture['exercise']->revision,
        'expected_source_revision' => $fixture['project']->revision,
        'expected_expense_revision' => $fixture['expense']->revision,
    ], (string) Str::uuid());
}

it('enforces invariant 28.29 by appending only new Actual lines', function (): void {
    $fixture = s10InvariantFixture();
    $originalLineId = $fixture['originalLine']->id;

    recordInvariantCorrection($fixture, '25.00');
    recordInvariantCorrection($fixture, '-10.00');

    expect($fixture['originalLine']->refresh()->amount)->toBe('100.00')
        ->and($fixture['expense']->lines()->where('type', 'actual')->pluck('id')->all())
        ->toContain($originalLineId)
        ->and($fixture['expense']->lines()->where('type', 'actual')->count())->toBe(3)
        ->and($fixture['expense']->lines()->where('type', 'estimate')->count())->toBe(0);
});

it('enforces invariant 28.30 by leaving every historical attribution unchanged', function (): void {
    $fixture = s10InvariantFixture();
    $expenseAttribution = $fixture['expense']->only([
        'company_id',
        'exercise_id',
        'project_id',
        'contract_id',
        'supplier_id',
        'direct_cost_center_id',
    ]);
    $classification = $fixture['classification']->only(['project_id', 'exercise_id', 'cost_center_id']);

    recordInvariantCorrection($fixture, '5.00');
    $fixture['exercise']->refresh();
    app(RecordHistoricalErrorAnnotation::class)->execute($fixture['actor'], $fixture['exercise'], [
        'kind' => 'cost_center',
        'reason' => 'Errore storico senza riclassificazione',
        'recorded_facts' => ['value' => 'Centro registrato'],
        'believed_correct_facts' => ['value' => 'Centro ritenuto corretto'],
        'affected_sources' => [[
            'type' => 'project',
            'id' => $fixture['project']->id,
            'revision' => $fixture['project']->refresh()->revision,
        ]],
        'expected_exercise_revision' => $fixture['exercise']->revision,
    ], (string) Str::uuid());

    expect($fixture['expense']->refresh()->only(array_keys($expenseAttribution)))->toBe($expenseAttribution)
        ->and($fixture['classification']->refresh()->only(array_keys($classification)))->toBe($classification)
        ->and($fixture['exercise']->refresh()->status()->value)->toBe('closed');
});

it('enforces invariant 28.31 by never recalculating historical Carryover', function (): void {
    $fixture = s10InvariantFixture();
    $deferral = $fixture['deferral']->only(['mode', 'carryover_amount', 'carryover_state']);
    $snapshotCarryover = $fixture['snapshot']->total_consolidated_carryover;

    recordInvariantCorrection($fixture, '7.00');
    $fixture['exercise']->refresh();
    app(RecordHistoricalErrorAnnotation::class)->execute($fixture['actor'], $fixture['exercise'], [
        'kind' => 'carryover',
        'reason' => 'Riporto storico invariato',
        'recorded_facts' => ['value' => '20.00'],
        'believed_correct_facts' => ['value' => '15.00'],
        'affected_sources' => [[
            'type' => 'exercise',
            'id' => $fixture['exercise']->id,
            'revision' => $fixture['exercise']->revision,
        ]],
        'expected_exercise_revision' => $fixture['exercise']->revision,
    ], (string) Str::uuid());

    expect($fixture['deferral']->refresh()->only(array_keys($deferral)))->toBe($deferral)
        ->and($fixture['snapshot']->refresh()->total_consolidated_carryover)->toBe($snapshotCarryover);
});
