<?php

use App\Actions\Reporting\BuildReport;
use App\Domain\Reporting\ReportDefinition;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Supplier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('requires visualizza for the exact company', function (): void {
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($other)->create();

    expect(fn () => app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
        'company_id' => $other->id,
        'exercise_id' => $exercise->id,
        'kind' => 'annual_executive',
        'actual_reference' => 'current',
        'final_reference' => ['type' => 'current', 'exercise_id' => $exercise->id],
    ])))->toThrow(AuthorizationException::class);
});

it('rejects a cross tenant exercise without exposing it', function (): void {
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $foreignExercise = Exercise::factory()->for($other)->create();

    expect(fn () => app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
        'company_id' => $company->id,
        'exercise_id' => $foreignExercise->id,
        'kind' => 'annual_executive',
        'actual_reference' => 'current',
        'final_reference' => ['type' => 'current', 'exercise_id' => $foreignExercise->id],
    ])))->toThrow(ModelNotFoundException::class);
});

it('rejects a cross tenant filter reference', function (): void {
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create();
    $foreignSupplier = Supplier::factory()->for($other)->create();

    expect(fn () => app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'kind' => 'suppliers',
        'filters' => ['supplier_id' => $foreignSupplier->id],
    ])))->toThrow(ModelNotFoundException::class);
});
