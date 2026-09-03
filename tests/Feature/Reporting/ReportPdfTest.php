<?php

use App\Actions\Reporting\BuildReport;
use App\Domain\Reporting\ReportDefinition;
use App\Filament\Pages\ReportPdfCustomizer;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Supplier;
use App\Support\Reporting\ReportPdfComposer;
use App\Support\Reporting\ReportPdfException;
use App\Support\Reporting\ReportPdfRenderer;
use App\Support\Reporting\WeasyPrintRuntime;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\Factory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function fakeWeasyPrintSuccess(): void
{
    Process::fake(fn (PendingProcess $process) => $process->command === ['weasyprint', '--version']
        ? Process::result('WeasyPrint version 69.0')
        : Process::result('%PDF-1.7 fake'));
}

it('downloads and previews the same authenticated PDF pipeline', function (): void {
    fakeWeasyPrintSuccess();
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

    $download = $this->actingAs($viewer)->get(route('reports.pdf.download', ['definition' => $definition]));
    $preview = $this->get(route('reports.pdf.preview', ['definition' => $definition]));
    $minimalPreview = $this->get(route('reports.pdf.preview', [
        'definition' => $definition,
        'blocks_configured' => true,
        'columns_configured' => true,
    ]));

    $download->assertOk()->assertHeader('content-type', 'application/pdf');
    $preview->assertOk();
    $minimalPreview->assertOk();
    expect($preview->headers->get('content-disposition'))->toStartWith('inline;')
        ->and($download->headers->get('content-disposition'))->toContain('attachment; filename="report-pdf-azienda-2026-annual-executive-')
        ->and($download->getContent())->toStartWith('%PDF-')
        ->and($preview->getContent())->toBe($download->getContent());

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['weasyprint', '-', '-']
        && str_contains((string) $process->input, 'Fornitore PDF')
        && $process->timeout === 30);
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['weasyprint', '-', '-']
        && str_contains((string) $process->input, 'Definizioni del confronto')
        && ! str_contains((string) $process->input, 'Dettaglio e riconciliazione')
        && ! str_contains((string) $process->input, '<h2>Riepilogo</h2>'));
});

it('normalizes configurable blocks and columns and escapes all report values', function (): void {
    $company = Company::factory()->create(['name' => '<Azienda sicura>']);
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $expense = Expense::factory()->forExercise($exercise)->create(['description' => '<script>alert(1)</script>']);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '15.00']);
    $result = app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
        'company_id' => $company->id, 'exercise_id' => $exercise->id, 'kind' => 'annual_executive',
        'actual_reference' => 'current', 'final_reference' => ['type' => 'current', 'exercise_id' => $exercise->id],
    ]));
    $composer = app(ReportPdfComposer::class);
    $noLogoDocument = $composer->compose($result, $company);
    Storage::fake('local');
    Storage::disk('local')->put('company-logos/'.$company->id.'/logo.png', 'controlled-logo');
    $company->update([
        'logo_disk' => 'local',
        'logo_path' => 'company-logos/'.$company->id.'/logo.png',
        'logo_media_type' => 'image/png',
    ]);
    $chartDefinitions = $composer->chartDefinitions($result);
    $fullDocument = $composer->compose($result, $company);
    $chartSvg = base64_decode(explode(',', $fullDocument['charts'][0]['image'], 2)[1], true);
    $document = $composer->compose($result, $company, [
        'blocks' => ['table:sources', 'unknown', 'table:sources'],
        'columns' => ['column:sources:actual', 'hostile-column'],
    ]);
    $html = view('reports.pdf', compact('document'))->render();
    $fullHtml = view('reports.pdf', ['document' => $fullDocument])->render();

    expect($document['selected_blocks'])->toBe(['table:sources'])
        ->and($document['selected_columns'])->toBe(['column:sources:actual'])
        ->and(array_column($noLogoDocument['available_blocks'], 'id'))->not->toContain('logo')
        ->and(array_column($fullDocument['available_blocks'], 'id'))->toContain('logo')
        ->and($fullHtml)->toContain('<div class="header-logo">', 'data:image/png;base64,')
        ->and($html)->not->toContain('<div class="header-logo">')
        ->and(array_column($fullDocument['charts'], 'id'))->toBe(array_column($chartDefinitions, 'id'))
        ->and($chartSvg)->toBeString()
        ->and($chartSvg)->toContain(...$chartDefinitions[0]['data']['labels'])
        ->and($chartSvg)->toContain(...array_map('strval', array_merge(...array_column($chartDefinitions[0]['data']['datasets'], 'data'))))
        ->and($html)->toContain('&lt;Azienda sicura&gt;', '&lt;script&gt;alert(1)&lt;/script&gt;', 'Dettaglio e riconciliazione', '15,00')
        ->and($html)->not->toContain('<script>alert(1)</script>', 'hostile-column', 'http://', 'https://');
});

it('opens the ephemeral customizer with only applicable choices', function (): void {
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $definition = [
        'company_id' => $company->id, 'exercise_id' => $exercise->id, 'kind' => 'suppliers', 'filters' => [],
    ];
    $this->actingAs($viewer);
    Filament::setTenant($company->tenantCompany);

    Livewire::withQueryParams(['definition' => $definition])
        ->test(ReportPdfCustomizer::class)
        ->assertSee('Anteprima reale')
        ->assertSee('Esporta PDF')
        ->assertSee('Bucket Fornitore')
        ->assertDontSee('Budget Approvato Corrente')
        ->assertSet('definition.company_id', $company->id)
        ->call('selectNone')
        ->assertSet('selectedBlocks', [])
        ->assertSet('selectedColumns', []);
});

it('reports missing, non executable, unsupported and failed runtimes', function (): void {
    config(['reporting.weasyprint_binary' => '/missing/weasyprint']);
    expect(app(WeasyPrintRuntime::class)->status()['reason'])->toBe('missing');

    $binary = tempnam(sys_get_temp_dir(), 'weasyprint-');
    chmod($binary, 0644);
    config(['reporting.weasyprint_binary' => $binary]);
    expect(app(WeasyPrintRuntime::class)->status()['reason'])->toBe('not_executable');
    unlink($binary);

    config(['reporting.weasyprint_binary' => 'weasyprint']);
    Process::fake(fn (): mixed => Process::result('WeasyPrint version 68.0'));
    expect(app(WeasyPrintRuntime::class)->status()['reason'])->toBe('unsupported_version');

    Process::fake(fn (PendingProcess $process) => $process->command === ['weasyprint', '--version']
        ? Process::result('WeasyPrint version 69.0')
        : Process::result('', 'render error', 1));
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create();
    $result = app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
        'company_id' => $company->id, 'exercise_id' => $exercise->id, 'kind' => 'suppliers', 'filters' => [],
    ]));
    expect(fn () => app(ReportPdfRenderer::class)->render($result, $company))
        ->toThrow(ReportPdfException::class, 'non ha prodotto un PDF valido');
    $this->actingAs($viewer)
        ->get(route('reports.pdf.preview', ['definition' => $result->definition->toArray()]))
        ->assertStatus(503)
        ->assertSee('Il servizio PDF non è al momento disponibile.')
        ->assertDontSee('render error');

    Process::swap(new Factory);
    $slowBinary = tempnam(sys_get_temp_dir(), 'weasyprint-slow-');
    file_put_contents($slowBinary, <<<'SH'
#!/bin/sh
if [ "$1" = "--version" ]; then
    echo "WeasyPrint version 69.0"
    exit 0
fi
sleep 2
SH);
    chmod($slowBinary, 0755);
    config(['reporting.weasyprint_binary' => $slowBinary, 'reporting.timeout' => 1]);
    expect(fn () => app(ReportPdfRenderer::class)->render($result, $company))
        ->toThrow(ReportPdfException::class, 'tempo massimo');
    unlink($slowBinary);
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

    $this->get(route('reports.pdf.download', ['definition' => $definition]))->assertRedirect();
    $this->actingAs($viewer)->get(route('reports.pdf.preview', ['definition' => $definition]))->assertForbidden();
});

it('renders a valid document with the installed WeasyPrint baseline', function (): void {
    $status = app(WeasyPrintRuntime::class)->status();
    if (! $status['available']) {
        $this->markTestSkipped('WeasyPrint 69.0 is not installed in this runtime.');
    }
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create();
    $result = app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
        'company_id' => $company->id, 'exercise_id' => $exercise->id, 'kind' => 'suppliers', 'filters' => [],
    ]));

    expect(app(ReportPdfRenderer::class)->render($result, $company))->toStartWith('%PDF-');
});
