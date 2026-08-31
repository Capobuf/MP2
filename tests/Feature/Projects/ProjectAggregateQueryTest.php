<?php

use App\Domain\Expenses\ExpenseLineType;
use App\Domain\Projects\ProjectAnnualSituation;
use App\Filament\Resources\Exercises\Pages\ListExercises;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;
use App\Models\ProjectTransition;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-08-18 10:00:00 Europe/Rome');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    DB::disableQueryLog();
});

function grantAggregateViewer(User $user, Company $company): void
{
    grantTestPermissions([
        'company_id' => $company->id,
        'user' => $user,
        'permissions' => TestPermissions::VIEW,
    ]);
}

/** @return list<string> */
function capturedQueries(): array
{
    return array_map(
        fn (array $query): string => strtolower((string) $query['query']),
        DB::getQueryLog(),
    );
}

it('loads the Project list relationships in sets and annual totals in the list query', function () {
    $viewer = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    grantAggregateViewer($viewer, $company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $projects = Project::factory()->count(8)->for($company)->create();

    foreach ($projects as $project) {
        ProjectTransition::factory()->forProject($project)->create([
            'effective_date' => '2027-01-01',
            'created_by_id' => $viewer->id,
        ]);
        ProjectExerciseClassification::factory()->forProjectAndExercise($project, $exercise)->create();
        $expense = Expense::factory()->forExercise($exercise)->for($project)->create(['direct_cost_center_id' => null]);
        ExpenseLine::factory()->for($expense)->create();
    }

    $this->actingAs($viewer);
    Filament::setTenant(($company)->tenantCompany);
    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::test(ListProjects::class)->assertCanSeeTableRecords($projects);

    $queries = capturedQueries();

    expect(collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'from `project_transitions`'))->count())->toBe(1)
        ->and(collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'from `project_exercise_classifications`'))->count())->toBe(1)
        ->and(collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'from `expense_lines`'))->count())->toBe(1)
        ->and(collect($queries)->first(fn (string $sql): bool => str_contains($sql, 'from `expense_lines`')))->toContain('sum(expense_lines.amount)');
});

it('builds every annual Project situation with one aggregate Line query', function () {
    $viewer = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    grantAggregateViewer($viewer, $company);
    $project = Project::factory()->for($company)->create();

    foreach (range(2023, 2028) as $year) {
        $exercise = Exercise::factory()->for($company)->create(['year' => $year]);
        ProjectExerciseClassification::factory()->forProjectAndExercise($project, $exercise)->create();
        foreach (range(1, 3) as $index) {
            $expense = Expense::factory()->forExercise($exercise)->for($project)->create([
                'direct_cost_center_id' => null,
                'description' => "Spesa {$year}-{$index}",
            ]);
            ExpenseLine::factory()->for($expense)->create([
                'type' => ExpenseLineType::Estimate,
                'amount' => '10.00',
            ]);
        }
    }

    $this->actingAs($viewer);
    Filament::setTenant(($company)->tenantCompany);
    DB::flushQueryLog();
    DB::enableQueryLog();

    $loadedProject = ProjectResource::getEloquentQuery()->findOrFail($project->id);
    $situations = ProjectAnnualSituation::build(
        $loadedProject,
        $loadedProject->company->exercises,
        CarbonImmutable::now($company->timezone),
    );

    $queries = capturedQueries();
    $lineQueries = collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'from `expense_lines`'));

    expect($situations)->toHaveCount(6)
        ->and(collect($situations)->pluck('allocation')->unique()->all())->toBe(['30.00'])
        ->and($lineQueries)->toHaveCount(1)
        ->and($lineQueries->first())->toContain('sum(expense_lines.amount)');
});

it('renders Exercise aggregates with one Line load and counts Project children exactly once', function () {
    $viewer = User::factory()->create();
    $company = Company::factory()->create();
    grantAggregateViewer($viewer, $company);
    $exercises = Exercise::factory()->count(6)->for($company)->sequence(
        ...array_map(fn (int $year): array => ['year' => $year], range(2023, 2028)),
    )->create();
    $target = $exercises->firstWhere('year', 2026);
    $project = Project::factory()->for($company)->create();
    $projectExpense = Expense::factory()->forExercise($target)->for($project)->create(['direct_cost_center_id' => null]);
    $autonomousExpense = Expense::factory()->forExercise($target)->create();
    ExpenseLine::factory()->for($projectExpense)->create(['type' => ExpenseLineType::Estimate, 'amount' => '100.00']);
    ExpenseLine::factory()->for($projectExpense)->create(['type' => ExpenseLineType::Actual, 'amount' => '40.00']);
    ExpenseLine::factory()->for($autonomousExpense)->create(['type' => ExpenseLineType::Estimate, 'amount' => '25.00']);
    ExpenseLine::factory()->for($autonomousExpense)->create(['type' => ExpenseLineType::Actual, 'amount' => '10.00']);

    foreach ($exercises->where('id', '!=', $target->id) as $exercise) {
        $expense = Expense::factory()->forExercise($exercise)->create();
        ExpenseLine::factory()->count(3)->for($expense)->create();
    }

    expect($project->annualTotals()[$target->id]['allocation'])->toBe('100.00')
        ->and($project->annualTotals()[$target->id]['actual'])->toBe('40.00')
        ->and($target->allocation())->toBe('125.00')
        ->and($target->actual())->toBe('50.00');

    $this->actingAs($viewer);
    Filament::setTenant(($company)->tenantCompany);
    DB::flushQueryLog();
    DB::enableQueryLog();

    $component = Livewire::test(ListExercises::class);
    $queries = capturedQueries();
    DB::disableQueryLog();

    $component->assertCanSeeTableRecords($exercises)
        ->assertTableColumnStateSet('allocation', '125.00', $target)
        ->assertTableColumnStateSet('actual', '50.00', $target);

    expect(collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'from `expense_lines`'))->count())->toBe(1);
});
