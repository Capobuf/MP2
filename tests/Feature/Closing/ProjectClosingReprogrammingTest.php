<?php

use App\Actions\Closing\CloseExercise;
use App\Actions\Closing\PrepareExerciseClosing;
use App\Actions\Proposals\ApplyProjectDeferral;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectDeferral;
use App\Models\ProjectExerciseClassification;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

afterEach(fn () => CarbonImmutable::setTestNow());

function s9ReprogrammingActor(Company $company): User
{
    $user = User::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::CLOSE_EXERCISE] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $user, 'permissions' => $capability]);
    }

    return $user;
}

function s9ReprogrammingFixture(bool $withDestination = false): array
{
    $company = Company::factory()->create();
    $actor = s9ReprogrammingActor($company);
    $source = Exercise::factory()->for($company)->create(['year' => 2025]);
    $destination = $withDestination ? Exercise::factory()->for($company)->create(['year' => 2026]) : null;
    $project = Project::factory()->for($company)->create([
        'title' => 'Closing reprogramming',
        'initial_state' => 'open',
        'initial_effective_date' => '2025-01-01',
    ]);
    $expense = Expense::factory()->forExercise($source)->for($project)->create(['description' => 'Source estimate']);
    $estimate = ExpenseLine::factory()->for($expense)->create(['amount' => '100.00', 'note' => 'Source line']);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '20.00']);

    return compact('company', 'actor', 'source', 'destination', 'project', 'expense', 'estimate');
}

it('reprograms selected source Estimates exactly once at Closing without copying Actuals', function (): void {
    CarbonImmutable::setTestNow('2026-08-23 12:00:00 Europe/Rome');
    $fixture = s9ReprogrammingFixture();
    $prepared = app(PrepareExerciseClosing::class)->execute($fixture['actor'], $fixture['source'], [
        'create_next_exercise' => true,
        'projects' => [$fixture['project']->id => [
            'project_id' => $fixture['project']->id,
            'final_state' => 'open',
            'mode' => 'reprogramming',
            'reason' => 'Riprogrammazione finale',
            'source_estimate_reductions' => [[
                'source_line_id' => $fixture['estimate']->id,
                'reduction_amount' => '30.00',
                'destination_supplier_id' => null,
            ]],
        ]],
    ]);

    expect($prepared['review']->canClose())->toBeTrue()
        ->and($prepared['review']->projectDecisions[0]['reprogrammed_amount'])->toBe('30.00')
        ->and($prepared['review']->totals['final_allocation'])->toBe('70.00');

    $snapshot = app(CloseExercise::class)->execute($fixture['actor'], $fixture['source'], [
        ...$prepared['input'],
        'review_fingerprint' => $prepared['execution_fingerprint'],
        'warnings_acknowledged' => true,
        'confirmed' => true,
    ], (string) Str::uuid());

    $destination = Exercise::query()->where('company_id', $fixture['company']->id)->where('year', 2026)->sole();
    $deferral = ProjectDeferral::query()->where('project_id', $fixture['project']->id)->sole();
    $destinationExpenses = Expense::query()->where('project_id', $fixture['project']->id)->where('exercise_id', $destination->id)->get();

    expect($fixture['estimate']->refresh()->amount)->toBe('70.00')
        ->and($deferral->mode->value)->toBe('reprogramming')
        ->and($deferral->reprogrammed_amount)->toBe('30.00')
        ->and($destinationExpenses)->toHaveCount(1)
        ->and($destinationExpenses->first()->allocation())->toBe('30.00')
        ->and($destinationExpenses->first()->actual())->toBe('0.00')
        ->and($destinationExpenses->first()->copied_from_origin_key)->toBe($fixture['expense']->originKey())
        ->and($snapshot->total_final_allocation)->toBe('70.00')
        ->and($snapshot->total_consolidated_carryover)->toBe('0.00');
});

it('verifies an already executed Reprogramming without applying it again', function (): void {
    CarbonImmutable::setTestNow('2026-08-23 12:00:00 Europe/Rome');
    $fixture = s9ReprogrammingFixture(true);
    $operation = (string) Str::uuid();
    app(ApplyProjectDeferral::class)->executeDirect($fixture['project'], $fixture['source'], $fixture['destination'], [
        'mode' => 'reprogramming',
        'reprogrammed_amount' => '30.00',
        'source_estimate_reductions' => [[
            'source_expense_id' => $fixture['expense']->id,
            'source_expense_origin_key' => $fixture['expense']->originKey(),
            'source_expense_revision' => 0,
            'source_line_id' => $fixture['estimate']->id,
            'source_line_revision' => 0,
            'source_amount' => '100.00',
            'source_annulled' => false,
            'reduction_amount' => '30.00',
        ]],
        'destination_plans' => [[
            'copied_from_origin_key' => $fixture['expense']->originKey(),
            'supplier_id' => null,
            'description' => $fixture['expense']->description,
            'notes' => null,
            'estimate_lines' => [['amount' => '30.00', 'note' => $fixture['estimate']->note]],
        ]],
    ], $operation);
    $destinationExpenseIds = Expense::query()->where('project_id', $fixture['project']->id)->where('exercise_id', $fixture['destination']->id)->pluck('id')->all();

    $prepared = app(PrepareExerciseClosing::class)->execute($fixture['actor'], $fixture['source'], [
        'projects' => [$fixture['project']->id => [
            'project_id' => $fixture['project']->id,
            'final_state' => 'open',
            'mode' => 'reprogramming',
            'reason' => 'Conferma Riprogrammazione già eseguita',
        ]],
    ]);
    app(CloseExercise::class)->execute($fixture['actor'], $fixture['source'], [
        ...$prepared['input'],
        'review_fingerprint' => $prepared['execution_fingerprint'],
        'warnings_acknowledged' => true,
        'confirmed' => true,
    ], (string) Str::uuid());

    expect($fixture['estimate']->refresh()->amount)->toBe('70.00')
        ->and(Expense::query()->where('project_id', $fixture['project']->id)->where('exercise_id', $fixture['destination']->id)->pluck('id')->all())->toBe($destinationExpenseIds)
        ->and(ProjectDeferral::query()->where('project_id', $fixture['project']->id)->sole()->reprogramming_operation_id)->toBe($operation);
});

it('blocks Closing when an executed Reprogramming effect was changed independently', function (): void {
    CarbonImmutable::setTestNow('2026-08-23 12:00:00 Europe/Rome');
    $fixture = s9ReprogrammingFixture(true);
    app(ApplyProjectDeferral::class)->executeDirect($fixture['project'], $fixture['source'], $fixture['destination'], [
        'mode' => 'reprogramming',
        'reprogrammed_amount' => '30.00',
        'source_estimate_reductions' => [[
            'source_expense_id' => $fixture['expense']->id,
            'source_expense_origin_key' => $fixture['expense']->originKey(),
            'source_expense_revision' => 0,
            'source_line_id' => $fixture['estimate']->id,
            'source_line_revision' => 0,
            'source_amount' => '100.00',
            'source_annulled' => false,
            'reduction_amount' => '30.00',
        ]],
        'destination_plans' => [[
            'copied_from_origin_key' => $fixture['expense']->originKey(),
            'supplier_id' => null,
            'description' => $fixture['expense']->description,
            'notes' => null,
            'estimate_lines' => [['amount' => '30.00', 'note' => $fixture['estimate']->note]],
        ]],
    ], (string) Str::uuid());
    $fixture['estimate']->refresh()->forceFill(['amount' => '69.00'])->save();

    $prepared = app(PrepareExerciseClosing::class)->execute($fixture['actor'], $fixture['source'], [
        'projects' => [$fixture['project']->id => [
            'project_id' => $fixture['project']->id,
            'final_state' => 'open',
            'mode' => 'reprogramming',
            'reason' => 'Conferma',
        ]],
    ]);

    expect(collect($prepared['review']->blocks)->pluck('code'))->toContain('executed_reprogramming_changed_independently');
});

it('reverses an active Reprogramming before a terminal Project transition', function (): void {
    CarbonImmutable::setTestNow('2026-08-23 12:00:00 Europe/Rome');
    $fixture = s9ReprogrammingFixture(true);
    $costCenter = CostCenter::factory()->for($fixture['company'])->create(['name' => 'Centro ereditato']);
    ProjectExerciseClassification::factory()->forProjectAndExercise($fixture['project'], $fixture['source'])->create([
        'cost_center_id' => $costCenter->id,
    ]);
    app(ApplyProjectDeferral::class)->executeDirect($fixture['project'], $fixture['source'], $fixture['destination'], [
        'mode' => 'reprogramming',
        'reprogrammed_amount' => '30.00',
        'source_estimate_reductions' => [[
            'source_expense_id' => $fixture['expense']->id,
            'source_expense_origin_key' => $fixture['expense']->originKey(),
            'source_expense_revision' => 0,
            'source_line_id' => $fixture['estimate']->id,
            'source_line_revision' => 0,
            'source_amount' => '100.00',
            'source_annulled' => false,
            'reduction_amount' => '30.00',
        ]],
        'destination_plans' => [[
            'copied_from_origin_key' => $fixture['expense']->originKey(),
            'supplier_id' => null,
            'description' => $fixture['expense']->description,
            'notes' => null,
            'estimate_lines' => [['amount' => '30.00', 'note' => $fixture['estimate']->note]],
        ]],
    ], (string) Str::uuid());
    $independent = Expense::factory()->forExercise($fixture['destination'])->for($fixture['project'])->create([
        'description' => 'Independent destination allocation',
    ]);
    ExpenseLine::factory()->for($independent)->create(['amount' => '7.00']);

    $prepared = app(PrepareExerciseClosing::class)->execute($fixture['actor'], $fixture['source'], [
        'projects' => [$fixture['project']->id => [
            'project_id' => $fixture['project']->id,
            'final_state' => 'closed',
            'mode' => 'none',
            'reason' => 'Conclusione definitiva del Progetto',
        ]],
    ]);
    $snapshot = app(CloseExercise::class)->execute($fixture['actor'], $fixture['source'], [
        ...$prepared['input'],
        'review_fingerprint' => $prepared['execution_fingerprint'],
        'warnings_acknowledged' => true,
        'confirmed' => true,
    ], (string) Str::uuid());

    $deferral = ProjectDeferral::query()->where('project_id', $fixture['project']->id)->sole();
    $copied = Expense::query()
        ->where('project_id', $fixture['project']->id)
        ->where('exercise_id', $fixture['destination']->id)
        ->whereNotNull('copied_from_origin_key')
        ->sole();
    $projectRow = $snapshot->rows()->where('origin_key', $fixture['project']->originKey())->sole();

    expect($fixture['estimate']->refresh()->amount)->toBe('100.00')
        ->and($deferral->mode->value)->toBe('none')
        ->and($deferral->reprogrammed_amount)->toBe('0.00')
        ->and($copied->allocation())->toBe('0.00')
        ->and($independent->refresh()->allocation())->toBe('7.00')
        ->and($projectRow->end_state)->toBe('closed')
        ->and($projectRow->final_allocation)->toBe('100.00')
        ->and($projectRow->detail['saving'])->toBe('80.00')
        ->and(data_get($projectRow->detail, 'expenses.0.cost_center.label'))->toBe('Centro ereditato')
        ->and(data_get($projectRow->detail, 'expenses.0.cost_center.source'))->toBe('inherited');
});
