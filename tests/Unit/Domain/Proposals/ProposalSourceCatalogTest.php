<?php

use App\Domain\Proposals\ProposalSourceCatalog;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('includes exact current-company automatic sources and excludes prior autonomous expenses', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $prior = Exercise::factory()->for($company)->create(['year' => 2025]);
    $currentExpense = Expense::factory()->forExercise($exercise)->create();
    Expense::factory()->forExercise($prior)->create();
    $project = Project::factory()->for($company)->create(['initial_effective_date' => '2026-01-01']);

    $keys = app(ProposalSourceCatalog::class)->forExercise($exercise)->pluck('origin_key')->all();

    expect($keys)->toContain($currentExpense->originKey(), $project->originKey())
        ->and($keys)->toHaveCount(2);
});
