<?php

use App\Actions\Proposals\ApproveProposal;
use App\Actions\Proposals\InitializeProposal;
use App\Actions\Proposals\PlanProjectDeferral;
use App\Filament\Resources\Budgets\Pages\ViewBudget;
use App\Models\BudgetSourceRow;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

function approvedDeferralBudget(string $mode): array
{
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_PROPOSALS, TestPermissions::APPROVE_BUDGET] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
    }
    $source = Exercise::factory()->for($company)->create(['year' => 2026]);
    $destination = Exercise::factory()->for($company)->create(['year' => 2027]);
    $project = Project::factory()->for($company)->create(['initial_state' => 'open', 'initial_effective_date' => '2026-01-01']);
    $expense = Expense::factory()->forExercise($source)->for($project)->create(['description' => 'Piano rinviabile']);
    $line = ExpenseLine::factory()->for($expense)->create(['amount' => '100.00']);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '20.00']);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $destination, (string) Str::uuid());
    $item = $proposal->items()->where('project_id', $project->id)->sole();
    $input = [
        'source_exercise_id' => $source->id,
        'destination_exercise_id' => $destination->id,
        'mode' => $mode,
    ];
    if ($mode === 'carryover') {
        $input['carryover_amount'] = '40.00';
    } elseif ($mode === 'reprogramming') {
        $input['reprogrammed_amount'] = '30.00';
        $input['source_estimate_reductions'] = [['source_line_id' => $line->id, 'reduction_amount' => '30.00']];
    }
    app(PlanProjectDeferral::class)->execute($actor, $proposal, $item, $input, $mode === 'none' ? null : 'Decisione rinvio', (string) Str::uuid(), 0);
    $budget = app(ApproveProposal::class)->execute($actor, $proposal->refresh(), (string) Str::uuid());

    return compact('actor', 'company', 'source', 'destination', 'project', 'expense', 'line', 'budget');
}

it('materializes Estimates and provisional Carryover separately without double counting', function (): void {
    $fixture = approvedDeferralBudget('carryover');
    $row = BudgetSourceRow::query()->where('source_type', 'project')->sole();

    expect($row->approved_estimates)->toBe('0.00')
        ->and($row->approved_carryover)->toBe('40.00')
        ->and($row->carryover_state)->toBe('provisional')
        ->and($row->approved_allocation)->toBe('40.00')
        ->and(data_get($row->detail, 'project.deferral_mode'))->toBe('carryover')
        ->and(data_get($row->detail, 'project.approved_reprogrammed_amount'))->toBe('0.00');

    $this->actingAs($fixture['actor']);
    Filament::setTenant(($fixture['company'])->tenantCompany);
    Livewire::test(ViewBudget::class, ['record' => $fixture['budget']->id])
        ->assertSee('Stime Approvate')
        ->assertSee('Riporto Approvato')
        ->assertSee('Stato Riporto')
        ->assertSee('Provvisorio')
        ->assertSee('Modalità Rinvio')
        ->assertSee('Allocato Approvato');
});

it('materializes Reprogramming once with exact lineage and zero Carryover', function (): void {
    approvedDeferralBudget('reprogramming');
    $row = BudgetSourceRow::query()->where('source_type', 'project')->sole();

    expect($row->approved_estimates)->toBe('30.00')
        ->and($row->approved_carryover)->toBe('0.00')
        ->and($row->approved_allocation)->toBe('30.00')
        ->and(data_get($row->detail, 'project.deferral_mode'))->toBe('reprogramming')
        ->and(data_get($row->detail, 'project.approved_reprogrammed_amount'))->toBe('30.00')
        ->and(data_get($row->detail, 'project.reprogramming_effects.destination_expenses.0.copied_from_origin_key'))->not->toBeNull();
});

it('materializes None with all deferral values at zero', function (): void {
    approvedDeferralBudget('none');
    $row = BudgetSourceRow::query()->where('source_type', 'project')->sole();

    expect($row->approved_carryover)->toBe('0.00')
        ->and($row->carryover_state)->toBeNull()
        ->and(data_get($row->detail, 'project.deferral_mode'))->toBe('none')
        ->and(data_get($row->detail, 'project.approved_reprogrammed_amount'))->toBe('0.00');
});
