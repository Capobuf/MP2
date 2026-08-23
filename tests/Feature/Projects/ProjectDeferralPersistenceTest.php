<?php

use App\Domain\Projects\ProjectDeferralMode;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Project;
use App\Models\ProjectDeferral;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('persists one closed deferral mode per Project and consecutive Exercise passage', function (): void {
    $company = Company::factory()->create();
    $source = Exercise::factory()->for($company)->create(['year' => 2026]);
    $destination = Exercise::factory()->for($company)->create(['year' => 2027]);
    $project = Project::factory()->for($company)->create();

    $deferral = ProjectDeferral::query()->create([
        'company_id' => $company->id,
        'project_id' => $project->id,
        'source_exercise_id' => $source->id,
        'destination_exercise_id' => $destination->id,
        'mode' => ProjectDeferralMode::Carryover,
        'carryover_amount' => '125.00',
        'carryover_state' => 'provisional',
        'reprogrammed_amount' => '0.00',
    ]);

    expect($deferral->mode)->toBe(ProjectDeferralMode::Carryover)
        ->and($deferral->carryover_amount)->toBe('125.00')
        ->and(fn () => ProjectDeferral::query()->create($deferral->only([
            'company_id', 'project_id', 'source_exercise_id', 'destination_exercise_id', 'mode',
            'carryover_amount', 'carryover_state', 'reprogrammed_amount',
        ])))->toThrow(QueryException::class)
        ->and(fn () => $deferral->delete())->toThrow(LogicException::class);
});

it('rejects cross-company non-consecutive and non-closed deferral values', function (): void {
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    $source = Exercise::factory()->for($company)->create(['year' => 2026]);
    $nonConsecutive = Exercise::factory()->for($company)->create(['year' => 2028]);
    $foreignDestination = Exercise::factory()->for($other)->create(['year' => 2027]);
    $project = Project::factory()->for($company)->create();
    $base = [
        'company_id' => $company->id,
        'project_id' => $project->id,
        'source_exercise_id' => $source->id,
        'mode' => ProjectDeferralMode::None,
        'carryover_amount' => '0.00',
        'carryover_state' => null,
        'reprogrammed_amount' => '0.00',
    ];

    expect(fn () => ProjectDeferral::query()->create([...$base, 'destination_exercise_id' => $nonConsecutive->id]))
        ->toThrow(ValidationException::class)
        ->and(fn () => ProjectDeferral::query()->create([...$base, 'destination_exercise_id' => $foreignDestination->id]))
        ->toThrow(ValidationException::class);

    $destination = Exercise::factory()->for($company)->create(['year' => 2027]);
    expect(fn () => ProjectDeferral::query()->create([
        ...$base,
        'destination_exercise_id' => $destination->id,
        'carryover_amount' => '1.00',
    ]))->toThrow(QueryException::class)
        ->and(fn () => ProjectDeferral::query()->create([
            ...$base,
            'destination_exercise_id' => $destination->id,
            'mode' => ProjectDeferralMode::Reprogramming,
            'reprogrammed_amount' => '1.00',
            'reprogramming_operation_id' => (string) Str::uuid(),
            'reprogramming_effects' => null,
        ]))->toThrow(QueryException::class);
});
