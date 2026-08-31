<?php

use App\Actions\BusinessBackup\ExportBusinessBackup;
use App\Actions\BusinessBackup\ImportBusinessBackup;
use App\Actions\Operations\ChangeProjectDeferral;
use App\Actions\Proposals\ApplyProjectDeferral;
use App\BusinessBackup\V1\BusinessBackupValidator;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectDeferral;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('reverses an imported active Reprogramming with rebuilt local effect metadata', function (): void {
    $company = Company::factory()->create();
    $actor = User::factory()->platformAdmin()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
    }
    $source = Exercise::factory()->for($company)->create(['year' => 2026]);
    $destination = Exercise::factory()->for($company)->create(['year' => 2027]);
    $project = Project::factory()->for($company)->create(['title' => 'Riprogrammazione portabile', 'initial_state' => 'open']);
    $expense = Expense::factory()->forExercise($source)->for($project)->create(['description' => 'Piano origine']);
    $line = ExpenseLine::factory()->for($expense)->create(['amount' => '100.00', 'note' => 'Riga origine']);
    app(ApplyProjectDeferral::class)->executeDirect($project, $source, $destination, [
        'mode' => 'reprogramming',
        'reprogrammed_amount' => '30.00',
        'source_estimate_reductions' => [[
            'source_expense_id' => $expense->id, 'source_expense_origin_key' => $expense->originKey(),
            'source_expense_revision' => 0, 'source_line_id' => $line->id, 'source_line_revision' => 0,
            'source_amount' => '100.00', 'source_annulled' => false, 'reduction_amount' => '30.00',
        ]],
        'destination_plans' => [[
            'copied_from_origin_key' => $expense->originKey(), 'supplier_id' => null,
            'description' => 'Piano origine', 'notes' => null,
            'estimate_lines' => [['amount' => '30.00', 'note' => 'Riga origine']],
        ]],
    ], (string) Str::uuid());

    $artifact = app(ExportBusinessBackup::class)->execute($company, $actor);
    try {
        $restored = app(ImportBusinessBackup::class)->execute($actor, app(BusinessBackupValidator::class)->validate($artifact['path']));
    } finally {
        @unlink($artifact['path']);
    }

    $restoredProject = $restored->projects()->where('title', 'Riprogrammazione portabile')->sole();
    $restoredSource = $restored->exercises()->where('year', 2026)->sole();
    $restoredDestination = $restored->exercises()->where('year', 2027)->sole();
    $restoredLine = $restored->expenses()->where('description', 'Piano origine')->where('exercise_id', $restoredSource->id)->sole()->lines()->where('note', 'Riga origine')->sole();
    $deferral = ProjectDeferral::query()->where('project_id', $restoredProject->id)->sole();
    $destinationLineId = data_get($deferral->reprogramming_effects, 'destination_expenses.0.estimate_lines.0.expense_line_id');

    $action = app(ChangeProjectDeferral::class);
    $preview = $action->preview($actor, $restoredProject, $restoredSource, $restoredDestination, ['mode' => 'none']);
    $changed = $action->execute(
        $actor, $restoredProject->refresh(), $restoredSource, $restoredDestination, ['mode' => 'none'],
        'Annullamento dopo restore', (string) Str::uuid(), $preview['project_revision'], $preview['fingerprint'],
    );

    expect($changed->mode->value)->toBe('none')
        ->and($restoredLine->refresh()->amount)->toBe('100.00')
        ->and(ExpenseLine::query()->findOrFail($destinationLineId)->annulled_at)->not->toBeNull();
});
