<?php

use App\Actions\Reporting\BuildReport;
use App\Domain\Reporting\ReportDefinition;
use App\Models\Company;
use App\Models\Exercise;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('always declares canonical report metadata and exact monetary semantics', function (): void {
    $company = Company::factory()->create(['name' => 'Metadata', 'timezone' => 'Europe/Rome']);
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);

    $result = app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'kind' => 'annual_executive',
        'actual_reference' => 'current',
        'final_reference' => ['type' => 'current', 'exercise_id' => $exercise->id],
    ]));

    expect($result->header)->toHaveKeys([
        'company_name', 'exercise_year', 'kind', 'initial_reference', 'final_reference',
        'budget_version', 'actual_reference', 'reference_date', 'generated_at', 'filters', 'filter_labels', 'currency', 'amount_basis',
    ])->and($result->header['currency'])->toBe('EUR')
        ->and($result->header['amount_basis'])->toBe('Importi netti IVA');
});
