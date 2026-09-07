<?php

use App\Actions\Reporting\BuildReport;
use App\Domain\Reporting\ComparisonCategory;
use App\Domain\Reporting\ComparisonEngine;
use App\Domain\Reporting\ReportAggregator;
use App\Domain\Reporting\ReportDefinition;
use App\Domain\Reporting\ReportKind;
use App\Domain\Reporting\ReportResult;
use App\Domain\Reporting\ReportSource;
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
use Illuminate\Support\Number;
use Livewire\Livewire;
use Symfony\Component\Process\ExecutableFinder;

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
        && ! str_contains((string) $process->input, 'Definizioni del confronto')
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
    $landscapeHtml = view('reports.contracts', ['document' => $landscape])->render();
    $portraitHtml = view('reports.contracts', ['document' => $portrait])->render();
    $landscapeBarSvg = base64_decode(explode(',', collect($landscape['charts'])->firstWhere('id', 'contract-values')['image'], 2)[1], true);
    $landscapeStateSvg = base64_decode(explode(',', collect($landscape['charts'])->firstWhere('id', 'contract-states')['image'], 2)[1], true);
    $portraitStateSvg = base64_decode(explode(',', collect($portrait['charts'])->firstWhere('id', 'contract-states')['image'], 2)[1], true);
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
        ->and($landscapeHtml)->toContain('class="portfolio-summary"', 'class="economic-summary"', 'Registro contratti')
        ->and($portraitHtml)->toContain('class="portfolio-summary"', 'class="economic-summary"', 'class="contract-secondary"')
        ->and($landscapeBarSvg)->toContain('Contratto Connettività', '120,00', '45,00')
        ->and($landscapeBarSvg)->toContain('fill="#39D5C4"', 'fill="#60A5FA"')
        ->and($landscapeBarSvg)->not->toMatch('/<rect[^>]+fill="#15323B"/')
        ->and($landscapeStateSvg)->toContain('Pianificato', 'Attivo', 'Cessato', 'Annullato')
        ->and($portraitStateSvg)->toBe($landscapeStateSvg)
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

    $html = view('reports.contracts', compact('document'))->render();
    $svg = base64_decode(explode(',', collect($document['charts'])->firstWhere('id', 'contract-values')['image'], 2)[1], true);
    preg_match_all('/<text[^>]*>(.*?)<\/text>/s', $svg, $texts);

    expect($chart['description'])->toBe('Visualizzati 8 di 9 contratti · ordinati per Allocato decrescente.')
        ->and($portraitChart['description'])->toBe('Visualizzati 5 di 9 contratti · ordinati per Allocato decrescente.')
        ->and($html)->toContain($chart['description'], 'Contratto 1', 'Contratto 9')
        ->and($texts[1])->not->toContain(Number::currency(0, in: 'EUR', locale: 'it'))
        ->and($svg)->not->toContain('width="0"')
        ->and($svg)->toContain('90,00', '20,00');

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

it('keeps contract PDF text inside its cells and preserves supplier words', function (string $orientation): void {
    $status = app(WeasyPrintRuntime::class)->status();
    if (! $status['available']) {
        $this->markTestSkipped('WeasyPrint is not installed in this runtime.');
    }

    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create();
    foreach (['FornitoreSpecializzato S.r.l.', 'Fornitore '.str_repeat('X', 200)] as $name) {
        $supplier = Supplier::factory()->for($company)->create(['legal_name' => $name]);
        $contract = Contract::factory()->for($company)->for($supplier)->create([
            'title' => 'Contratto servizi applicativi',
            'contractual_start_date' => '2026-01-01',
            'next_expiry_date' => '2026-12-31',
        ]);
        $expense = Expense::factory()->forExercise($exercise)->for($contract)->for($supplier)->create();
        ExpenseLine::factory()->for($expense)->create(['amount' => '9999999999.99']);
    }

    $result = app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
        'company_id' => $company->id, 'exercise_id' => $exercise->id, 'kind' => 'contracts',
    ]));
    $document = app(ReportPdfComposer::class)->compose($result, $company, [
        'orientation' => $orientation, 'blocks' => ['table:contracts'],
    ]);
    $binary = (new ExecutableFinder)->find($status['binary']);
    expect($binary)->not->toBeNull();
    $python = dirname(realpath($binary)).'/python3';
    $check = Process::input(view('reports.contracts', compact('document'))->render())
        ->run([$python, base_path('tests/Support/check_contract_pdf_layout.py')]);

    expect($check->errorOutput())->toBe('')
        ->and($check->successful())->toBeTrue($check->output());
})->with(['landscape', 'portrait']);

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

it('uses only the four canonical contract states in the static distribution', function (): void {
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
        ->and($svg)->toContain('<rect', 'Pianificato', 'Attivo', 'Cessato', 'Annullato')
        ->and($svg)->not->toContain('<circle');
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
            'class="event-timeline"',
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
        ->assertSeeHtml('<input type="radio" name="orientation" value="landscape" wire:model.live="orientation">')
        ->assertSeeHtml('<input type="radio" name="orientation" value="portrait" wire:model.live="orientation">')
        ->set('orientation', 'portrait')
        ->assertSet('orientation', 'portrait');

    expect($component->instance()->previewUrl())->toContain('orientation=portrait')
        ->and($component->instance()->downloadUrl())->toContain('orientation=portrait');

    $component->set('orientation', 'landscape')->assertSet('orientation', 'landscape');

    expect($component->instance()->previewUrl())->toContain('orientation=landscape')
        ->and($component->instance()->downloadUrl())->toContain('orientation=landscape');
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
        && ! str_contains((string) $process->input, 'Definizioni del confronto'));
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

    $dom = new DOMDocument;
    @$dom->loadHTML($withoutLogo);
    $header = (new DOMXPath($dom))->query('//header[contains(concat(" ", normalize-space(@class), " "), " report-header ")]')->item(0);

    expect($withoutLogo)->toContain('Report Contratti', 'Esercizio 2026', 'Allocato', 'Effettivo', 'Scostamento')
        ->and($withoutLogo)->toContain('Data di riferimento 01/06/2026 · EUR · Importi netti IVA · Generato il 01/06/2026')
        ->and($header->textContent)->not->toContain('Esercizio 2026', 'Data di riferimento', 'Generato il')
        ->and($withoutLogo)->not->toContain('class="header-logo"', 'data:image/', 'footer')
        ->and($withLogo)->toContain('class="header-logo"', 'data:image/png;base64,')
        ->and($withLogo)->not->toContain('linear-gradient', 'radial-gradient', 'background: #06121c');
});

it('reports missing, non executable and failed runtimes while accepting installed versions', function (): void {
    Process::fake(fn (): mixed => Process::result('', 'not found', 1));

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

it('preserves the selected interval and its contract annotations in both PDF orientations', function (): void {
    $company = Company::factory()->create(['name' => 'Azienda "Contratti" <sicura>']);
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    foreach (['2026-06-15', '2026-07-15', null] as $index => $deadline) {
        Contract::factory()->for($company)->create([
            'title' => 'Contratto <'.$index.'>',
            'next_expiry_date' => $deadline,
            'renewal_anchor_date' => $deadline,
        ]);
    }
    $input = [
        'company_id' => $company->id, 'exercise_id' => $exercise->id, 'kind' => 'contracts',
        'date_from' => '2026-06-01', 'date_to' => '2026-06-30',
    ];
    $result = app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray($input));
    $composer = app(ReportPdfComposer::class);
    foreach (['portrait', 'landscape'] as $orientation) {
        $document = $composer->compose($result, $company, [
            'orientation' => $orientation,
            'blocks' => ['table:contracts'],
            'columns' => [],
        ]);
        expect(array_column($document['contracts'], 'labels'))->toBe(array_column($result->sections[0]['rows'], 'labels'));
        $html = view('reports.contracts', compact('document'))->render();
        $dom = new DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        $rows = $xpath->query('//table[@class="contracts"]/tbody/tr');
        expect($html)->toContain('Intervallo selezionato', '01/06/2026', '30/06/2026', 'Contratto &lt;0&gt;', '&lt;sicura&gt;')
            ->and($rows->item(0)->textContent)->toContain('Scadenza Contrattuale entro l’Intervallo Selezionato')
            ->and($rows->item(1)->textContent)->not->toContain('Intervallo Selezionato');
    }
    unset($input['date_from'], $input['date_to']);
    $withoutInterval = $composer->compose(app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray($input)), $company);
    expect(view('reports.contracts', ['document' => $withoutInterval])->render())->not->toContain('class="selected-interval"', 'Scadenza Contrattuale entro l’Intervallo Selezionato');
});

it('composes only selected KPI groups charts and columns without empty layout cells', function (): void {
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    Contract::factory()->for($company)->create(['title' => 'Contratto configurabile']);
    $result = app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
        'company_id' => $company->id, 'exercise_id' => $exercise->id, 'kind' => 'contracts',
    ]));
    $composer = app(ReportPdfComposer::class);
    foreach (['portrait', 'landscape'] as $orientation) {
        foreach ([[], ['chart:contract-values'], ['chart:contract-states'], ['chart:contract-values', 'chart:contract-states']] as $charts) {
            foreach ([[], ['kpi:specialist_count'], ['kpi:specialist_actual'], ['kpi:contracts_expiring', 'kpi:specialist_variance']] as $kpis) {
                $document = $composer->compose($result, $company, [
                    'orientation' => $orientation,
                    'blocks' => ['table:contracts', ...$charts, ...$kpis],
                    'columns' => ['column:contracts:actual'],
                ]);
                $html = view('reports.contracts', compact('document'))->render();
                $dom = new DOMDocument;
                @$dom->loadHTML($html);
                $xpath = new DOMXPath($dom);
                expect($xpath->query('//section[@class="analysis-panel"]')->length)->toBe(count($charts))
                    ->and($xpath->query('//div[@class="portfolio-metric" or @class="economic-metric"]')->length)->toBe(count($kpis))
                    ->and($xpath->query('//table[@class="contracts"]/thead/tr/th')->length)->toBe(2)
                    ->and($xpath->query('//table[@class="contracts"]/tbody/tr/td')->length)->toBe(2)
                    ->and($xpath->query('//article[@class="detail"]')->length)->toBe(0)
                    ->and($html)->not->toContain('class="analysis-cell', 'class="kpi-cell');
                foreach ($charts as $chart) {
                    expect($xpath->query('//section[@data-block="'.$chart.'"]')->length)->toBe(1);
                }
                if ($kpis === []) {
                    expect($xpath->query('//section[@class="summary"]')->length)->toBe(0);
                }
            }
        }
    }
    $document = $composer->compose($result, $company, ['blocks' => [], 'columns' => []]);
    $html = view('reports.contracts', compact('document'))->render();
    expect($html)->not->toContain('class="contracts"', 'class="analysis-panel"', 'class="summary"', 'class="detail"');
});

function pdfFamilyFixture(string $kind, int $count = 12, bool $annualComparison = true): ReportResult
{
    $family = ReportKind::from($kind);
    $definition = ['company_id' => 1, 'exercise_id' => 1, 'kind' => $kind];
    $isComparison = $family->isComparison() || ($family === ReportKind::AnnualExecutive && $annualComparison);
    if ($family->isComparison() || $family === ReportKind::AnnualExecutive) {
        $definition['final_reference'] = ['type' => 'current', 'exercise_id' => 1];
        if ($isComparison) {
            $definition['initial_reference'] = ['type' => 'budget', 'exercise_id' => 1, 'budget_snapshot_id' => 1];
        }
        if (in_array($family, [ReportKind::AnnualExecutive, ReportKind::BudgetActual], true)) {
            $definition['actual_reference'] = 'current';
        } elseif ($family === ReportKind::BudgetVersions) {
            $definition['final_reference'] = ['type' => 'budget', 'exercise_id' => 1, 'budget_snapshot_id' => 2];
        } elseif ($family === ReportKind::Exercises) {
            $definition['comparison_exercise_id'] = 2;
            $definition['initial_reference'] = ['type' => 'current', 'exercise_id' => 1];
            $definition['final_reference'] = ['type' => 'current', 'exercise_id' => 2];
        }
    }
    $sources = [];
    foreach (range(1, $count) as $index) {
        $sources[] = new ReportSource(
            sourceType: 'project', originId: $index, originKey: 'project:'.$index, copiedFromOriginKey: null,
            label: 'Progetto '.$index.' · Infrastruttura e servizi applicativi', summary: 'Intervento sul sistema informativo',
            supplierId: null, supplierLabel: null, costCenterId: $index, costCenterLabel: 'Centro '.$index,
            state: 'open', allocation: (string) ($index * 1000), actual: (string) ($index * ($index === 1 ? 1000 : ($index % 2 ? 1250 : 750))),
            hasActuals: true, carryover: (string) ((20 - $index) * 25), receivedCarryover: (string) ((20 - $index) * 25), residual: '150.00',
            detail: ['expenses' => [[
                'id' => $index, 'source' => 'Spesa '.$index, 'supplier_id' => $index, 'supplier_label' => 'Fornitore '.$index,
                'allocation' => (string) (($index * 1000) - ((20 - $index) * 25)), 'actual' => (string) ($index * 750),
                'lines' => [['id' => $index, 'type' => 'actual', 'amount' => '25.00', 'note' => 'Nota verificabile', 'annulled' => false]],
            ]]],
        );
    }
    $aggregator = new ReportAggregator;
    $totals = $aggregator->executive($sources);
    $comparisons = $isComparison ? (new ComparisonEngine)->compare(
        array_slice($sources, 1), array_slice($sources, 0, -1),
        budgetComparison: $family !== ReportKind::Exercises,
        initialMeasure: $family === ReportKind::Exercises ? 'actual' : 'allocation',
        finalMeasure: in_array($family, [ReportKind::BudgetVersions, ReportKind::BudgetCurrentAllocation], true) ? 'allocation' : 'actual',
    ) : [];
    $sections = match ($family) {
        ReportKind::Projects => [['title' => 'Progetti', 'rows' => $sources]],
        ReportKind::Carryovers => [['title' => 'Riporti', 'rows' => $sources]],
        ReportKind::Suppliers => [['title' => 'Aggregazione per Fornitore', 'rows' => $aggregator->suppliers($sources)]],
        default => [],
    };

    return new ReportResult(
        ReportDefinition::fromArray($definition),
        [
            'company_name' => 'MP2 · Azienda di verifica', 'exercise_year' => 2026, 'kind' => $kind, 'title' => $family->label(),
            'initial_reference_label' => $isComparison ? ($family === ReportKind::Exercises ? 'Situazione Corrente · Esercizio 2026' : 'Budget v1 · Budget Iniziale · Esercizio 2026') : null,
            'final_reference_label' => $family === ReportKind::BudgetVersions ? 'Budget v2 · Revisione · Esercizio 2026' : 'Situazione Corrente · Esercizio '.($family === ReportKind::Exercises ? 2027 : 2026),
            'actual_reference' => isset($definition['actual_reference']) ? 'Effettivo Corrente' : null,
            'reference_date' => '2026-09-07', 'generated_at' => '2026-09-07 10:30:00',
            'currency' => 'EUR', 'amount_basis' => 'Importi netti IVA', 'date_from' => null, 'date_to' => null, 'filter_labels' => [],
            'availability' => ['initial_budget' => true, 'current_budget' => true, 'selected_budget' => $isComparison, 'closing' => false],
        ],
        $totals + [
            'current_budget' => '80000.00', 'initial_budget' => '75000.00', 'current_allocation' => $totals['allocation'],
            'selected_actual' => $totals['actual'], 'current_actual' => $totals['actual'], 'current_operational_variance' => $totals['operational_variance'],
            'allocation_vs_selected_budget' => '3000.00', 'selected_budget_actual_variance' => '-5000.00', 'annotation_count' => 0,
        ],
        $sources, $comparisons,
        collect($comparisons)->countBy(fn (array $row): string => $row['category']->value)->all(),
        [], $sections,
    );
}

function pdfDocumentXPath(string $html): DOMXPath
{
    $dom = new DOMDocument;
    @$dom->loadHTML($html);

    return new DOMXPath($dom);
}

it('renders every non contract family with real WeasyPrint in both orientations', function (string $kind, string $orientation): void {
    if (! app(WeasyPrintRuntime::class)->status()['available']) {
        $this->markTestSkipped('WeasyPrint is not installed in this runtime.');
    }
    $result = pdfFamilyFixture($kind);
    $company = Company::factory()->make();
    $document = app(ReportPdfComposer::class)->compose($result, $company, compact('orientation'));
    $html = view('reports.pdf', compact('document'))->render();
    expect(app(ReportPdfRenderer::class)->render($result, $company, compact('orientation')))->toStartWith('%PDF-')
        ->and($html)->toContain($result->header['title'], 'data:font/woff2;base64,', 'Importi netti IVA')
        ->and($document['selected_blocks'])->not->toContain('details:sources');
    expect(str_contains($html, 'Definizioni del confronto'))->toBe($result->comparisons !== []);
})->with(array_map(fn (ReportKind $kind): string => $kind->value, array_filter(ReportKind::cases(), fn (ReportKind $kind): bool => $kind !== ReportKind::Contracts)))
    ->with(['portrait', 'landscape']);

it('prints category distributions as exact counts without currency', function (string $kind): void {
    $result = pdfFamilyFixture($kind);
    $document = app(ReportPdfComposer::class)->compose($result, Company::factory()->make());
    $chart = collect($document['charts'])->firstWhere('id', 'comparison-categories');
    $svg = base64_decode(explode(',', $chart['image'], 2)[1]);
    $xml = simplexml_load_string($svg);
    $xml->registerXPathNamespace('svg', 'http://www.w3.org/2000/svg');
    $counts = array_map(fn (SimpleXMLElement $text): int => (int) (string) $text, $xml->xpath('//svg:text[@font-size="24"]'));
    expect($counts)->toBe(array_map(fn ($category): int => $result->categoryCounts[$category->value] ?? 0, ComparisonCategory::cases()))
        ->and($svg)->not->toContain('€', 'EUR', ',00');
})->with(['annual_executive', 'budget_actual', 'budget_current_allocation', 'budget_versions', 'exercises']);

it('draws signed operational variance on opposite sides of zero and orders by magnitude', function (string $orientation): void {
    $result = pdfFamilyFixture('operational_variance', 5);
    $composer = app(ReportPdfComposer::class);
    $document = $composer->compose($result, Company::factory()->make(), compact('orientation'));
    $svg = base64_decode(explode(',', $document['charts'][0]['image'], 2)[1]);
    $xml = simplexml_load_string($svg);
    $xml->registerXPathNamespace('svg', 'http://www.w3.org/2000/svg');
    $zero = (float) $xml->xpath('//svg:line[@class="zero-axis"]')[0]['x1'];
    $positive = $xml->xpath('//svg:rect[@class="positive"]');
    $negative = $xml->xpath('//svg:rect[@class="negative"]');
    expect($positive)->not->toBeEmpty()->and($negative)->not->toBeEmpty();
    foreach ($positive as $bar) {
        expect((float) $bar['x'])->toBe($zero)->and((float) $bar['width'])->toBeGreaterThan(0);
    }
    foreach ($negative as $bar) {
        expect((float) $bar['x'])->toBeLessThan($zero)
            ->and((float) $bar['x'] + (float) $bar['width'])->toEqualWithDelta($zero, 0.001);
    }
    expect($xml->xpath('//svg:circle[@class="zero-value"]'))->toHaveCount(1)
        ->and($svg)->toContain('+1.250,00', '-1.000,00', '0,00')
        ->and($composer->chartDefinitions($result, $orientation)[0]['data']['datasets'][0]['data'])->toBe([1250.0, -1000.0, 750.0, -500.0, 0.0]);
})->with(['portrait', 'landscape']);

it('limits dense charts by the declared measure while retaining complete tables', function (string $kind, string $chartId, string $block, string $orientation): void {
    $result = pdfFamilyFixture($kind);
    $composer = app(ReportPdfComposer::class);
    $document = $composer->compose($result, Company::factory()->make(), compact('orientation'));
    $definition = collect($composer->chartDefinitions($result, $orientation))->firstWhere('id', $chartId);
    $limit = $orientation === 'portrait' ? 5 : 8;
    $total = $kind === 'suppliers' ? 13 : 12;
    $xpath = pdfDocumentXPath(view('reports.pdf', compact('document'))->render());
    expect($definition['data']['labels'])->toHaveCount($limit)
        ->and($definition['description'])->toContain('Visualizzati '.$limit.' di '.$total, 'decrescente')
        ->and($xpath->query('//table[@data-block="'.$block.'"]/tbody/tr')->length)->toBe($total);
    $values = $definition['data']['datasets'][0]['data'];
    $rank = $kind === 'operational_variance' ? array_map('abs', $values) : $values;
    $sorted = $rank;
    rsort($sorted);
    expect($rank)->toBe($sorted);
    if ($kind === 'carryovers') {
        expect($definition['data']['datasets'])->toHaveCount(1)
            ->and($definition['data']['datasets'][0]['label'])->toBe('Riporto')
            ->and($definition['data']['labels'][0])->toStartWith('Progetto 1 ·');
    }
    if ($kind === 'suppliers') {
        expect($document['sections'][0]['rows'])->toBe($result->sections[0]['rows']);
    }
})->with([
    ['annual_executive', 'annual-cost-centers', 'table:sources'],
    ['operational_variance', 'operational-variance', 'table:sources'],
    ['projects', 'project-values', 'section:progetti'],
    ['suppliers', 'supplier-values', 'section:aggregazione-per-fornitore'],
    ['carryovers', 'carryover-values', 'section:riporti'],
])->with(['portrait', 'landscape']);

it('recomposes all selected portrait metadata without dropping selected quantitative columns', function (string $group): void {
    $result = pdfFamilyFixture('budget_actual');
    $composer = app(ReportPdfComposer::class);
    $company = Company::factory()->make();
    foreach (['portrait', 'landscape'] as $orientation) {
        $document = $composer->compose($result, $company, ['orientation' => $orientation, 'blocks' => ['table:'.$group]]);
        $xpath = pdfDocumentXPath(view('reports.pdf', compact('document'))->render());
        $columns = array_filter($document['available_columns'], fn (array $column): bool => $column['group'] === $group);
        $count = count($group === 'sources' ? $document['sources'] : $document['comparisons']);
        foreach ($columns as $column) {
            $key = str($column['id'])->afterLast(':')->toString();
            expect($xpath->query('//*[@data-column="'.$key.'"]')->length)->toBe($count);
        }
        expect($xpath->query('//thead/tr/th')->length)->toBe($orientation === 'landscape' ? count($columns) + 1 : ($group === 'sources' ? 5 : 4));
    }
})->with(['sources', 'comparisons']);

it('collapses partial KPI chart and column selections and keeps generic details opt in', function (): void {
    $result = pdfFamilyFixture('projects', 2);
    $company = Company::factory()->make();
    $composer = app(ReportPdfComposer::class);
    foreach (['portrait', 'landscape'] as $orientation) {
        foreach ([[], ['kpi:specialist_count'], ['kpi:specialist_actual'], ['kpi:specialist_actual', 'kpi:specialist_count']] as $kpis) {
            foreach ([[], ['chart:project-values']] as $charts) {
                $document = $composer->compose($result, $company, [
                    'orientation' => $orientation, 'blocks' => ['table:sources', ...$kpis, ...$charts], 'columns' => ['column:sources:actual'],
                ]);
                $xpath = pdfDocumentXPath(view('reports.pdf', compact('document'))->render());
                expect($xpath->query('//div[@class="metric"]')->length)->toBe(count($kpis))
                    ->and($xpath->query('//section[@class="analysis-panel"]')->length)->toBe(count($charts))
                    ->and($xpath->query('//thead/tr/th')->length)->toBe(2)
                    ->and($xpath->query('//div[@class="metric" and not(normalize-space())]')->length)->toBe(0)
                    ->and($xpath->query('//dl[not(*)]')->length)->toBe(0);
            }
        }
    }
    $default = $composer->compose($result, $company);
    $document = $composer->compose($result, $company, ['blocks' => ['details:sources']]);
    $html = view('reports.pdf', compact('document'))->render();
    expect($default['selected_blocks'])->not->toContain('details:sources')
        ->and(array_column($default['available_blocks'], 'id'))->toContain('details:sources', 'table:sources')
        ->and($html)->toContain('Approfondimenti delle sorgenti', 'Nota verificabile', 'Fornitore 1', '25,00')
        ->and($html)->not->toContain('origin_key', 'supplier_id', '<strong>id:', 'project:1');
    $document = $composer->compose($result, $company, ['blocks' => [], 'columns' => []]);
    $xpath = pdfDocumentXPath(view('reports.pdf', compact('document'))->render());
    expect($xpath->query('//section|//table|//article')->length)->toBe(0);
});

it('omits comparison definitions in annual reports without a comparison', function (): void {
    $document = app(ReportPdfComposer::class)->compose(pdfFamilyFixture('annual_executive', 3, false), Company::factory()->make());
    expect($document['category_definitions'])->toBe([])
        ->and(view('reports.pdf', compact('document'))->render())->not->toContain('Definizioni del confronto');
});

it('escapes source labels inside SVG and loads only embedded PDF resources', function (): void {
    $base = pdfFamilyFixture('projects', 3);
    $values = get_object_vars($base->sources[0]);
    $values['label'] = '<script>unsafe</script><image href="https://example.com/a"/>';
    $sources = [new ReportSource(...$values), ...array_slice($base->sources, 1)];
    $result = new ReportResult($base->definition, $base->header, $base->totals, $sources, sections: [['title' => 'Progetti', 'rows' => $sources]]);
    $document = app(ReportPdfComposer::class)->compose($result, Company::factory()->make());
    $html = view('reports.pdf', compact('document'))->render();
    $svg = base64_decode(explode(',', $document['charts'][0]['image'], 2)[1]);
    $xml = simplexml_load_string($svg);
    $xml->registerXPathNamespace('svg', 'http://www.w3.org/2000/svg');
    expect($svg)->toContain('&lt;script&gt;unsafe&lt;/script&gt;')
        ->and($xml->xpath('//svg:script|//svg:image'))->toBe([])
        ->and($html)->toContain('&lt;script&gt;unsafe&lt;/script&gt;')
        ->and($html)->not->toContain('<script>');
    foreach (pdfDocumentXPath($html)->query('//*[@src]') as $element) {
        expect($element->getAttribute('src'))->toStartWith('data:');
    }
});
