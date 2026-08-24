<?php

use App\Actions\Reporting\BuildReport;
use App\Domain\Reporting\ReportDefinition;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Supplier;
use App\Support\Reporting\ReportPdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('downloads one authenticated PDF from a server reconstructed definition', function (): void {
    $company = Company::factory()->create(['name' => 'PDF Azienda']);
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $supplier = Supplier::factory()->for($company)->create(['legal_name' => 'Fornitore PDF']);
    $expense = Expense::factory()->forExercise($exercise)->for($supplier)->create(['description' => 'Voce PDF']);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '25.00']);
    $definition = [
        'company_id' => $company->id, 'exercise_id' => $exercise->id, 'kind' => 'annual_executive',
        'actual_reference' => 'current', 'final_reference' => ['type' => 'current', 'exercise_id' => $exercise->id],
        'filters' => ['supplier_id' => $supplier->id],
    ];

    $response = $this->actingAs($viewer)->get(route('reports.pdf', ['definition' => $definition]));

    $response->assertOk()->assertHeader('content-type', 'application/pdf');
    expect($response->headers->get('content-disposition'))->toContain('attachment; filename="report-pdf-azienda-2026-annual-executive-')
        ->and($response->getContent())->toStartWith('%PDF-')
        ->and($response->getContent())->not->toContain('http://', 'https://');
});

it('uses the same report result for complete escaped HTML and PDF rendering', function (): void {
    $company = Company::factory()->create(['name' => '<Azienda sicura>']);
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $expense = Expense::factory()->forExercise($exercise)->create(['description' => '<script>alert(1)</script>']);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '15.00']);
    $result = app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
        'company_id' => $company->id, 'exercise_id' => $exercise->id, 'kind' => 'annual_executive',
        'actual_reference' => 'current', 'final_reference' => ['type' => 'current', 'exercise_id' => $exercise->id],
    ]));

    $html = view('reports.pdf', ['report' => $result])->render();
    $pdf = app(ReportPdfRenderer::class)->render($result);

    expect($html)->toContain('&lt;Azienda sicura&gt;', '&lt;script&gt;alert(1)&lt;/script&gt;', 'EUR', 'importi netti IVA', 'Invariato', 'Dettaglio e riconciliazione')
        ->and($html)->not->toContain('<script>alert(1)</script>')
        ->and($pdf)->toStartWith('%PDF-');
});

it('rejects missing authentication and cross tenant PDF definitions', function (): void {
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($other)->create();
    $definition = [
        'company_id' => $other->id, 'exercise_id' => $exercise->id, 'kind' => 'annual_executive',
        'actual_reference' => 'current', 'final_reference' => ['type' => 'current', 'exercise_id' => $exercise->id],
    ];

    $this->get(route('reports.pdf', ['definition' => $definition]))->assertRedirect();
    $this->actingAs($viewer)->get(route('reports.pdf', ['definition' => $definition]))->assertForbidden();
});
