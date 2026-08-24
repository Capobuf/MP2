<?php

use App\Models\Company;
use App\Models\Exercise;
use App\Models\Project;
use App\Models\ProjectDeferral;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('keeps the current carryover check when rollback is incompatible with consolidated rows', function (): void {
    $company = Company::factory()->create();
    $source = Exercise::factory()->for($company)->create(['year' => 2025]);
    $destination = Exercise::factory()->for($company)->create(['year' => 2026]);
    $project = Project::factory()->for($company)->create();
    ProjectDeferral::factory()->for($company)->for($project)->create([
        'source_exercise_id' => $source->id,
        'destination_exercise_id' => $destination->id,
        'mode' => 'carryover',
        'carryover_amount' => '10.00',
        'carryover_state' => 'consolidated',
    ]);
    $migration = require database_path('migrations/2026_08_23_000310_allow_consolidated_project_carryover.php');

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'consolidated Project Carryovers');

    $constraintExists = DB::table('information_schema.table_constraints')
        ->where('constraint_schema', DB::getDatabaseName())
        ->where('table_name', 'project_deferrals')
        ->where('constraint_name', 'project_deferrals_closed_values')
        ->where('constraint_type', 'CHECK')
        ->exists();

    expect($constraintExists)->toBeTrue();
});
