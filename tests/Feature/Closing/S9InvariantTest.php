<?php

use App\Actions\Closing\PrepareExerciseClosing;
use App\Domain\Company\Capability;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectTransition;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(fn () => CarbonImmutable::setTestNow());

it('enforces Closing state at 31 December and the negative-Actual Carryover cap', function (): void {
    CarbonImmutable::setTestNow('2026-08-23 12:00:00 Europe/Rome');
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    foreach ([Capability::View, Capability::CloseExercise] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => $capability]);
    }
    $source = Exercise::factory()->for($company)->create(['year' => 2025]);
    Exercise::factory()->for($company)->create(['year' => 2026]);
    $project = Project::factory()->for($company)->create([
        'initial_state' => 'planned',
        'initial_effective_date' => '2025-01-01',
    ]);
    ProjectTransition::factory()->for($project)->create([
        'company_id' => $company->id,
        'from_state' => 'planned',
        'to_state' => 'open',
        'effective_date' => '2025-12-20',
    ]);
    $expense = Expense::factory()->forExercise($source)->for($project)->create();
    ExpenseLine::factory()->for($expense)->create(['amount' => '100.00']);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '-10.00', 'note' => 'Rimborso registrato']);

    $valid = app(PrepareExerciseClosing::class)->execute($actor, $source, [
        'projects' => [$project->id => [
            'project_id' => $project->id,
            'final_state' => 'open',
            'mode' => 'carryover',
            'carryover_amount' => '100.00',
            'reason' => 'Riporto finale',
        ]],
    ])['review'];
    $invalid = app(PrepareExerciseClosing::class)->execute($actor, $source, [
        'projects' => [$project->id => [
            'project_id' => $project->id,
            'final_state' => 'open',
            'mode' => 'carryover',
            'carryover_amount' => '100.01',
            'reason' => 'Riporto finale',
        ]],
    ])['review'];

    expect($valid->projectDecisions[0]['current_state'])->toBe('open')
        ->and($valid->projectDecisions[0]['maximum_transferable'])->toBe('100.00')
        ->and(collect($invalid->blocks)->pluck('code'))->toContain('carryover_above_limit');
});
