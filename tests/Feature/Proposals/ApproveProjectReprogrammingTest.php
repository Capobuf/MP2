<?php

use App\Actions\Proposals\ApproveProposal;
use App\Actions\Proposals\InitializeProposal;
use App\Actions\Proposals\PlanProjectDeferral;
use App\Domain\Company\Capability;
use App\Models\BudgetSnapshot;
use App\Models\BudgetSourceRow;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectDeferral;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function reprogrammingApprovalFixture(): array
{
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    foreach ([Capability::ManageProposals, Capability::ApproveBudget] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => $capability]);
    }
    $source = Exercise::factory()->for($company)->create(['year' => 2026]);
    $destination = Exercise::factory()->for($company)->create(['year' => 2027]);
    $project = Project::factory()->for($company)->create(['initial_state' => 'open', 'initial_effective_date' => '2026-01-01']);
    $firstExpense = Expense::factory()->forExercise($source)->for($project)->create(['description' => 'Fase uno']);
    $partial = ExpenseLine::factory()->for($firstExpense)->create(['amount' => '80.00', 'note' => 'Parziale']);
    $full = ExpenseLine::factory()->for($firstExpense)->create(['amount' => '20.00', 'note' => 'Completa']);
    ExpenseLine::factory()->for($firstExpense)->actual()->create(['amount' => '20.00', 'note' => 'Effettivo origine']);
    $secondExpense = Expense::factory()->forExercise($source)->for($project)->create(['description' => 'Fase due']);
    $second = ExpenseLine::factory()->for($secondExpense)->create(['amount' => '50.00', 'note' => 'Seconda']);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $destination, (string) Str::uuid());
    $item = $proposal->items()->where('project_id', $project->id)->sole();
    $action = app(PlanProjectDeferral::class)->execute($actor, $proposal, $item, [
        'source_exercise_id' => $source->id,
        'destination_exercise_id' => $destination->id,
        'mode' => 'reprogramming',
        'reprogrammed_amount' => '100.00',
        'source_estimate_reductions' => [
            ['source_line_id' => $partial->id, 'reduction_amount' => '30.00'],
            ['source_line_id' => $full->id, 'reduction_amount' => '20.00'],
            ['source_line_id' => $second->id, 'reduction_amount' => '50.00'],
        ],
    ], 'Riprogrammazione esplicita', (string) Str::uuid(), 0);

    return compact('actor', 'company', 'source', 'destination', 'project', 'firstExpense', 'secondExpense', 'partial', 'full', 'second', 'proposal', 'item', 'action');
}

it('applies balanced Reprogramming with exact new identities lineage and no Actual copy', function (): void {
    extract(reprogrammingApprovalFixture());
    $operation = (string) Str::uuid();
    $budget = app(ApproveProposal::class)->execute($actor, $proposal->refresh(), $operation);
    $retry = app(ApproveProposal::class)->execute($actor, $proposal->refresh(), $operation);
    $deferral = ProjectDeferral::query()->sole();
    $destinationExpenses = Expense::query()->where('exercise_id', $destination->id)->orderBy('id')->get();
    $destinationEstimateLines = ExpenseLine::query()->whereIn('expense_id', $destinationExpenses->pluck('id'))->where('type', 'estimate')->get();

    expect($retry->is($budget))->toBeTrue()
        ->and($partial->refresh()->amount)->toBe('50.00')
        ->and($partial->annulled_at)->toBeNull()
        ->and($partial->revision)->toBe(1)
        ->and($full->refresh()->amount)->toBe('20.00')
        ->and($full->annulled_at)->not->toBeNull()
        ->and($second->refresh()->annulled_at)->not->toBeNull()
        ->and($source->allocation())->toBe('50.00')
        ->and($destination->allocation())->toBe('100.00')
        ->and($destinationExpenses)->toHaveCount(2)
        ->and($destinationExpenses->pluck('copied_from_origin_key')->all())->toEqualCanonicalizing([$firstExpense->originKey(), $secondExpense->originKey()])
        ->and($destinationEstimateLines->pluck('id')->intersect([$partial->id, $full->id, $second->id]))->toBeEmpty()
        ->and(ExpenseLine::query()->whereIn('expense_id', $destinationExpenses->pluck('id'))->where('type', 'actual')->count())->toBe(0)
        ->and($deferral->mode->value)->toBe('reprogramming')
        ->and($deferral->reprogrammed_amount)->toBe('100.00')
        ->and($deferral->reprogramming_operation_id)->toBe($action->operation_id)
        ->and($deferral->reprogramming_effects['source_lines'])->toHaveCount(3)
        ->and($deferral->reprogramming_effects['destination_expenses'])->toHaveCount(2)
        ->and(BudgetSourceRow::query()->where('source_type', 'project')->sole()->approved_allocation)->toBe('100.00')
        ->and(data_get(BudgetSourceRow::query()->where('source_type', 'project')->sole()->detail, 'project.approved_reprogrammed_amount'))->toBe('100.00')
        ->and(BudgetSnapshot::query()->count())->toBe(1);
});

it('rolls back after source reduction and after destination creation', function (string $stage): void {
    extract(reprogrammingApprovalFixture());

    expect(fn () => app(ApproveProposal::class)->execute(
        $actor,
        $proposal->refresh(),
        (string) Str::uuid(),
        checkpoint: fn (string $current) => $current === $stage ? throw new RuntimeException('failure') : null,
    ))->toThrow(RuntimeException::class);

    expect($partial->refresh()->amount)->toBe('80.00')
        ->and($partial->revision)->toBe(0)
        ->and($full->refresh()->annulled_at)->toBeNull()
        ->and($second->refresh()->annulled_at)->toBeNull()
        ->and(Expense::query()->where('exercise_id', $destination->id)->count())->toBe(0)
        ->and(ProjectDeferral::query()->count())->toBe(0)
        ->and(BudgetSnapshot::query()->count())->toBe(0);
})->with(['after_deferral_source_reduction', 'after_deferral_destination_creation', 'after_budget_header']);

it('does not retroactively invalidate applied Reprogramming after later Actuals', function (): void {
    extract(reprogrammingApprovalFixture());
    app(ApproveProposal::class)->execute($actor, $proposal->refresh(), (string) Str::uuid());

    ExpenseLine::factory()->for($firstExpense)->actual()->create(['amount' => '200.00', 'note' => 'Effettivo successivo']);

    expect(ProjectDeferral::query()->sole()->mode->value)->toBe('reprogramming')
        ->and(ProjectDeferral::query()->sole()->reprogrammed_amount)->toBe('100.00')
        ->and($source->actual())->toBe('220.00');
});

it('reverses an approved Reprogramming exactly when a Revision selects Nessuna', function (): void {
    extract(reprogrammingApprovalFixture());
    $firstBudget = app(ApproveProposal::class)->execute($actor, $proposal->refresh(), (string) Str::uuid());
    $createdIds = collect(ProjectDeferral::query()->sole()->reprogramming_effects['destination_expenses'])->pluck('expense_id');
    $independent = Expense::factory()->forExercise($destination)->for($project)->create(['description' => 'Nuova allocazione indipendente']);
    $independentLine = ExpenseLine::factory()->for($independent)->create(['amount' => '7.00']);
    $project->increment('revision');
    $destination->increment('revision');

    $revision = app(InitializeProposal::class)->execute($actor, $company, $destination, (string) Str::uuid());
    $revisionItem = $revision->items()->where('project_id', $project->id)->sole();
    app(PlanProjectDeferral::class)->execute($actor, $revision, $revisionItem, [
        'source_exercise_id' => $source->id,
        'destination_exercise_id' => $destination->id,
        'mode' => 'none',
    ], 'Rimozione rinvio', (string) Str::uuid(), 0);
    app(ApproveProposal::class)->execute($actor, $revision->refresh(), (string) Str::uuid(), ['reason' => 'Revisione rinvio']);

    expect($partial->refresh()->amount)->toBe('80.00')
        ->and($full->refresh()->annulled_at)->toBeNull()
        ->and($second->refresh()->annulled_at)->toBeNull()
        ->and(ExpenseLine::query()->whereIn('expense_id', $createdIds)->whereNull('annulled_at')->count())->toBe(0)
        ->and($independentLine->refresh()->annulled_at)->toBeNull()
        ->and($independentLine->amount)->toBe('7.00')
        ->and(ProjectDeferral::query()->sole()->mode->value)->toBe('none')
        ->and($firstBudget->fresh()->total_approved_allocation)->toBe('100.00');
});
