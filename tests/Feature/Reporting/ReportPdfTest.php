<?php

use App\Actions\Reporting\BuildReport;
use App\Domain\Reporting\ReportDefinition;
use App\Filament\Pages\ReportPdfCustomizer;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractExerciseClassification;
use App\Models\ContractLifecycleFact;
use App\Models\ContractRenewalConfiguration;
use App\Models\CostCenter;
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

it('composes the dedicated contracts document with validated orientation and specialist data', function (): void {
    $this->travelTo('2026-06-01 10:00:00');
    $company = Company::factory()->create(['name' => 'Azienda Contratti']);
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $supplier = Supplier::factory()->for($company)->create(['legal_name' => 'Fornitore Contratti']);
    $costCenter = CostCenter::factory()->for($company)->create(['name' => 'Centro Servizi']);
    $contract = Contract::factory()->for($company)->for($supplier)->create([
        'title' => 'Contratto Connettività',
        'contractual_start_date' => '2026-01-01',
        'next_expiry_date' => '2026-12-31',
        'automatic_renewal' => true,
        'notice_days' => 30,
    ]);
    ContractExerciseClassification::factory()->forContractAndExercise($contract, $exercise)->create([
        'cost_center_id' => $costCenter->id,
    ]);
    $expense = Expense::factory()->forExercise($exercise)->create([
        'contract_id' => $contract->id,
        'supplier_id' => $supplier->id,
    ]);
    ExpenseLine::factory()->for($expense)->create(['amount' => '120.00']);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '45.00']);
    $result = app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'kind' => 'contracts',
    ]));
    $composer = app(ReportPdfComposer::class);
    $landscape = $composer->compose($result, $company);
    $portrait = $composer->compose($result, $company, ['orientation' => 'portrait']);
    $row = $landscape['contracts'][0];

    expect($landscape['orientation'])->toBe('landscape')
        ->and($portrait['orientation'])->toBe('portrait')
        ->and(array_column($landscape['available_columns'], 'id'))->toBe([
            'column:contracts:supplier',
            'column:contracts:state',
            'column:contracts:cost_center',
            'column:contracts:deadline',
            'column:contracts:notice_limit_date',
            'column:contracts:renewal',
            'column:contracts:allocation',
            'column:contracts:actual',
            'column:contracts:operational_variance',
        ])
        ->and(array_column($landscape['available_blocks'], 'id'))->toContain('table:contracts', 'details:contracts')
        ->and(array_column($landscape['available_blocks'], 'id'))->not->toContain('table:sources', 'details:sources', 'section:contratti')
        ->and($row)->toMatchArray([
            'supplier' => 'Fornitore Contratti',
            'state' => 'active',
            'state_label' => 'Attivo',
            'cost_center' => 'Centro Servizi',
            'deadline' => '2026-12-31',
            'notice_limit_date' => '2026-12-01',
            'automatic_renewal' => true,
            'allocation' => '120.00',
            'actual' => '45.00',
            'operational_variance' => '-75.00',
        ])
        ->and($landscape['contract_state_counts'])->toBe([
            ['state' => 'planned', 'label' => 'Pianificato', 'count' => 0],
            ['state' => 'active', 'label' => 'Attivo', 'count' => 1],
            ['state' => 'cessated', 'label' => 'Cessato', 'count' => 0],
            ['state' => 'cancelled', 'label' => 'Annullato', 'count' => 0],
        ])
        ->and(array_column($landscape['kpis'], 'label'))->toBe([
            'Contratti', 'Allocato', 'Effettivo', 'Scostamento operativo', 'Contratti in scadenza',
        ])
        ->and($landscape['selected_blocks'])->not->toContain('details:contracts')
        ->and($landscape['selected_blocks'])->toContain('chart:contract-values', 'chart:contract-states', 'table:contracts');

    expect(fn (): array => $composer->compose($result, $company, ['orientation' => 'diagonal']))
        ->toThrow(InvalidArgumentException::class, 'Invalid PDF orientation.');
});

it('limits the contracts chart to the eight highest allocations without truncating the table', function (): void {
    $this->travelTo('2026-06-01 10:00:00');
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);

    foreach (range(1, 9) as $index) {
        $contract = Contract::factory()->for($company)->create([
            'title' => 'Contratto '.$index,
            'contractual_start_date' => '2026-01-01',
        ]);
        $expense = Expense::factory()->forExercise($exercise)->create(['contract_id' => $contract->id]);
        ExpenseLine::factory()->for($expense)->create(['amount' => number_format($index * 10, 2, '.', '')]);
    }

    $result = app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'kind' => 'contracts',
    ]));
    $document = app(ReportPdfComposer::class)->compose($result, $company);
    $chart = collect(app(ReportPdfComposer::class)->chartDefinitions($result))->firstWhere('id', 'contract-values');
    $portraitChart = collect(app(ReportPdfComposer::class)->chartDefinitions($result, 'portrait'))->firstWhere('id', 'contract-values');

    expect($document['contracts'])->toHaveCount(9)
        ->and($chart['data']['labels'])->toHaveCount(8)
        ->and($chart['data']['labels'])->toBe([
            'Contratto 9', 'Contratto 8', 'Contratto 7', 'Contratto 6',
            'Contratto 5', 'Contratto 4', 'Contratto 3', 'Contratto 2',
        ])
        ->and($portraitChart['data']['labels'])->toBe([
            'Contratto 9', 'Contratto 8', 'Contratto 7', 'Contratto 6', 'Contratto 5',
        ]);
});

it('counts deadlines in the inclusive next 90 days from the report reference date', function (): void {
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);

    foreach (['2026-02-28', '2026-03-01', '2026-05-30', '2026-05-31', null] as $index => $deadline) {
        Contract::factory()->for($company)->create([
            'title' => 'Scadenza '.$index,
            'contractual_start_date' => '2026-01-01',
            'next_expiry_date' => $deadline,
            'renewal_anchor_date' => $deadline,
            'automatic_renewal' => false,
        ]);
    }

    $result = app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'kind' => 'contracts',
        'final_reference' => [
            'type' => 'current',
            'exercise_id' => $exercise->id,
            'reference_date' => '2026-03-01',
        ],
    ]));
    $document = app(ReportPdfComposer::class)->compose($result, $company);
    $kpi = collect($document['kpis'])->firstWhere('id', 'kpi:contracts_expiring');

    expect($result->header['reference_date'])->toBe('2026-03-01')
        ->and($kpi)->toMatchArray([
            'label' => 'Contratti in scadenza',
            'value' => 2,
            'formatted' => '2',
            'description' => 'nei prossimi 90 giorni',
        ]);
});

it('uses only the four canonical contract states in the static donut', function (): void {
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    Contract::factory()->for($company)->create(['contractual_start_date' => '2026-01-01']);
    $result = app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'kind' => 'contracts',
    ]));
    $composer = app(ReportPdfComposer::class);
    $definition = collect($composer->chartDefinitions($result))->firstWhere('id', 'contract-states');
    $document = $composer->compose($result, $company);
    $rendered = collect($document['charts'])->firstWhere('id', 'contract-states');
    $svg = base64_decode(explode(',', $rendered['image'], 2)[1], true);

    expect($definition['type'])->toBe('doughnut')
        ->and($definition['data']['labels'])->toBe(['Pianificato', 'Attivo', 'Cessato', 'Annullato'])
        ->and($definition['data']['labels'])->not->toContain('In scadenza')
        ->and($svg)->toContain('<circle', 'Pianificato', 'Attivo', 'Cessato', 'Annullato');
});

it('renders opt-in contract details as curated user-facing information only', function (): void {
    $this->travelTo('2026-06-01 10:00:00');
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $contract = Contract::factory()->for($company)->create([
        'title' => 'Contratto editoriale',
        'notes' => 'Servizio infrastrutturale',
        'contractual_start_date' => '2026-01-01',
        'next_expiry_date' => '2026-12-31',
        'renewal_anchor_date' => '2026-12-31',
        'automatic_renewal' => true,
        'renewal_duration_months' => 12,
        'notice_days' => 60,
    ]);
    ContractCondition::factory()->forContract($contract)->create([
        'cycle' => 'monthly',
        'attribution_mode' => 'cycle_start',
        'amount' => '1200.00',
        'valid_from' => '2026-01-01',
        'created_by_id' => $viewer->id,
    ]);
    ContractRenewalConfiguration::factory()->forContract($contract)->create([
        'effective_from' => '2026-01-01',
        'automatic_renewal' => true,
        'expiry_anchor_date' => '2026-12-31',
        'renewal_duration_months' => 12,
        'notice_days' => 60,
        'created_by_id' => $viewer->id,
    ]);
    ContractLifecycleFact::factory()->forContract($contract)->create([
        'type' => 'activation',
        'declared_contractual_date' => '2026-01-01',
        'state_change_date' => '2026-01-01',
        'created_by_id' => $viewer->id,
    ]);
    $expense = Expense::factory()->forExercise($exercise)->create([
        'contract_id' => $contract->id,
        'description' => 'Stima di sistema · Contratto editoriale',
    ]);
    ExpenseLine::factory()->for($expense)->create(['amount' => '14400.00']);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '3000.00']);

    $result = app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'kind' => 'contracts',
    ]));
    $composer = app(ReportPdfComposer::class);
    $default = $composer->compose($result, $company);
    $document = $composer->compose($result, $company, ['blocks' => ['details:contracts']]);
    $html = view('reports.contracts', compact('document'))->render();

    expect($default['selected_blocks'])->not->toContain('details:contracts')
        ->and($html)->toContain(
            'Approfondimenti contratti',
            'Contratto editoriale',
            'Servizio infrastrutturale',
            'Condizioni economiche',
            'Mensile',
            'Inizio Ciclo',
            'Configurazione rinnovo',
            'Eventi contrattuali',
            'Attivazione',
            'Spese dell’esercizio',
            'Stima di sistema · Contratto editoriale',
        )
        ->and($html)->not->toContain(
            'company_id',
            'contract_id',
            'created_by_id',
            'created_at',
            'updated_at',
            'origin_key',
            'archived_or_reversed',
            'cycle_start',
            'monthly',
            'Expenses:',
            'Id:',
        );
});

it('validates PDF orientation in the controller and defaults to landscape', function (): void {
    fakeWeasyPrintSuccess();
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $definition = [
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'kind' => 'suppliers',
        'filters' => [],
    ];

    $this->actingAs($viewer)
        ->getJson(route('reports.pdf.preview', ['definition' => $definition, 'orientation' => 'portrait']))
        ->assertOk();
    $this->getJson(route('reports.pdf.preview', ['definition' => $definition, 'orientation' => 'landscape']))
        ->assertOk();
    $this->getJson(route('reports.pdf.preview', ['definition' => $definition]))
        ->assertOk();
    $this->getJson(route('reports.pdf.preview', ['definition' => $definition, 'orientation' => 'square']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('orientation');

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['weasyprint', '-', '-']
        && str_contains((string) $process->input, 'size: A4 portrait;'));
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['weasyprint', '-', '-']
        && str_contains((string) $process->input, 'size: A4 landscape;'));
});

it('keeps the same selected orientation in customizer preview and download URLs', function (): void {
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $definition = [
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'kind' => 'contracts',
        'filters' => [],
    ];
    $this->actingAs($viewer);
    Filament::setTenant($company->tenantCompany);

    $component = Livewire::withQueryParams(['definition' => $definition])
        ->test(ReportPdfCustomizer::class)
        ->assertSet('orientation', 'landscape')
        ->assertSee('Orizzontale')
        ->assertSee('Verticale')
        ->set('orientation', 'portrait')
        ->assertSet('orientation', 'portrait');

    expect($component->instance()->previewUrl())->toContain('orientation=portrait')
        ->and($component->instance()->downloadUrl())->toContain('orientation=portrait');
});

it('renders contracts through the dedicated template in portrait and landscape', function (): void {
    fakeWeasyPrintSuccess();
    $this->travelTo('2026-06-01 10:00:00');
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    Contract::factory()->for($company)->create([
        'title' => 'Contratto dedicato',
        'contractual_start_date' => '2026-01-01',
    ]);
    $contracts = app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'kind' => 'contracts',
    ]));
    $generic = app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'kind' => 'suppliers',
        'filters' => [],
    ]));
    $renderer = app(ReportPdfRenderer::class);

    expect($renderer->render($contracts, $company, ['orientation' => 'portrait']))->toStartWith('%PDF-')
        ->and($renderer->render($contracts, $company, ['orientation' => 'landscape']))->toStartWith('%PDF-')
        ->and($renderer->render($generic, $company))->toStartWith('%PDF-');

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['weasyprint', '-', '-']
        && str_contains((string) $process->input, '<h1>Report Contratti</h1>')
        && str_contains((string) $process->input, 'size: A4 portrait;')
        && ! str_contains((string) $process->input, 'Definizioni del confronto'));
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['weasyprint', '-', '-']
        && str_contains((string) $process->input, '<h1>Report Contratti</h1>')
        && str_contains((string) $process->input, 'size: A4 landscape;'));
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['weasyprint', '-', '-']
        && str_contains((string) $process->input, 'Definizioni del confronto'));
});

it('renders the contracts template with only the configured company logo and approved terminology', function (): void {
    $this->travelTo('2026-06-01 10:00:00');
    $company = Company::factory()->create(['name' => 'Azienda Logo']);
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    Contract::factory()->for($company)->create([
        'title' => 'Contratto Template',
        'contractual_start_date' => '2026-01-01',
    ]);
    $result = app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'kind' => 'contracts',
    ]));
    $composer = app(ReportPdfComposer::class);
    $withoutLogo = view('reports.contracts', ['document' => $composer->compose($result, $company)])->render();

    Storage::fake('local');
    Storage::disk('local')->put('company-logos/'.$company->id.'/logo.png', 'company-logo');
    $company->update([
        'logo_disk' => 'local',
        'logo_path' => 'company-logos/'.$company->id.'/logo.png',
        'logo_media_type' => 'image/png',
    ]);
    $withLogo = view('reports.contracts', ['document' => $composer->compose($result, $company->refresh())])->render();

    expect($withoutLogo)->toContain('Report Contratti', 'Esercizio 2026', 'Allocato', 'Effettivo', 'Scostamento')
        ->and($withoutLogo)->not->toContain('class="header-logo"', 'data:image/', 'footer')
        ->and($withLogo)->toContain('class="header-logo"', 'data:image/png;base64,')
        ->and($withLogo)->not->toContain('linear-gradient', 'radial-gradient', 'background: #06121c');
});

it('reports missing, non executable and failed runtimes while accepting installed versions', function (): void {
    config(['reporting.weasyprint_binary' => '/missing/weasyprint']);
    expect(app(WeasyPrintRuntime::class)->status()['reason'])->toBe('missing');

    $binary = tempnam(sys_get_temp_dir(), 'weasyprint-');
    chmod($binary, 0644);
    config(['reporting.weasyprint_binary' => $binary]);
    expect(app(WeasyPrintRuntime::class)->status()['reason'])->toBe('not_executable');
    unlink($binary);

    config(['reporting.weasyprint_binary' => 'weasyprint']);
    Process::fake(fn (): mixed => Process::result('WeasyPrint version 68.0'));
    expect(app(WeasyPrintRuntime::class)->status())->toMatchArray([
        'available' => true,
        'reason' => null,
        'version' => '68.0',
    ]);

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
        $this->markTestSkipped('WeasyPrint is not installed in this runtime.');
    }
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create();
    $result = app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
        'company_id' => $company->id, 'exercise_id' => $exercise->id, 'kind' => 'suppliers', 'filters' => [],
    ]));

    expect(app(ReportPdfRenderer::class)->render($result, $company))->toStartWith('%PDF-');
});