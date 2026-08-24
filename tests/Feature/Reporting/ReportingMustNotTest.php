<?php

use App\Actions\Reporting\BuildReport;
use App\Domain\Reporting\ReportDefinition;
use App\Domain\Reporting\SecondaryLabel;
use App\Models\BudgetSnapshot;
use App\Models\ClosingSnapshot;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\ExpenseLine;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not persist reports or mutate economic sources and excludes Sostituito', function (): void {
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create();
    $before = [ExpenseLine::count(), BudgetSnapshot::count(), ClosingSnapshot::count()];

    app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
        'company_id' => $company->id, 'exercise_id' => $exercise->id, 'kind' => 'annual_executive',
        'actual_reference' => 'current', 'final_reference' => ['type' => 'current', 'exercise_id' => $exercise->id],
    ]));

    expect([ExpenseLine::count(), BudgetSnapshot::count(), ClosingSnapshot::count()])->toBe($before)
        ->and(array_column(SecondaryLabel::cases(), 'value'))->not->toContain('replaced', 'sostituito');
});
