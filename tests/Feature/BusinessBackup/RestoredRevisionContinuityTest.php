<?php

use App\Actions\BusinessBackup\ExportBusinessBackup;
use App\Actions\BusinessBackup\ImportBusinessBackup;
use App\Actions\Proposals\ApproveProposal;
use App\Actions\Proposals\InitializeProposal;
use App\BusinessBackup\V1\BusinessBackupValidator;
use App\Models\BudgetSnapshot;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('creates and approves vN plus one from an imported Budget without a synthetic Proposal', function (): void {
    $company = Company::factory()->create();
    $actor = User::factory()->platformAdmin()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_PROPOSALS, TestPermissions::APPROVE_BUDGET] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $expense = Expense::factory()->forExercise($exercise)->create(['description' => 'Voce revisionabile']);
    ExpenseLine::factory()->for($expense)->create(['amount' => '75.00']);
    $initialProposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());
    $sourceBudget = app(ApproveProposal::class)->execute($actor, $initialProposal, (string) Str::uuid());

    $artifact = app(ExportBusinessBackup::class)->execute($company, $actor);
    try {
        $restored = app(ImportBusinessBackup::class)->execute($actor, app(BusinessBackupValidator::class)->validate($artifact['path']));
    } finally {
        @unlink($artifact['path']);
    }

    $restoredExercise = $restored->exercises()->where('year', 2026)->sole();
    $importedBudget = $restored->budgets()->sole();
    expect($importedBudget->version)->toBe($sourceBudget->version)
        ->and($importedBudget->proposal_id)->toBeNull()
        ->and($importedBudget->approved_by_id)->toBeNull()
        ->and(Proposal::query()->where('company_id', $restored->id)->count())->toBe(0);

    $revision = app(InitializeProposal::class)->execute($actor, $restored, $restoredExercise, (string) Str::uuid());
    $v2 = app(ApproveProposal::class)->execute($actor, $revision, (string) Str::uuid(), ['reason' => 'Revisione dopo restore']);

    expect($v2->version)->toBe(2)
        ->and($v2->previous_budget_id)->toBe($importedBudget->id)
        ->and($importedBudget->refresh()->version)->toBe(1)
        ->and(BudgetSnapshot::query()->where('company_id', $restored->id)->count())->toBe(2);
});
