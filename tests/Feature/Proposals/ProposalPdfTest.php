<?php

use App\Actions\Proposals\ApproveProposal;
use App\Actions\Proposals\DiscardProposal;
use App\Actions\Proposals\InitializeProposal;
use App\Actions\Proposals\PlanExpense;
use App\Domain\Proposals\ProposalActionType;
use App\Filament\Resources\Proposals\Pages\CustomizeProposalPdf;
use App\Filament\Resources\Proposals\Pages\ViewProposal;
use App\Filament\Resources\Proposals\ProposalResource;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;
use App\Models\Proposal;
use App\Models\User;
use App\Support\Proposals\ProposalPdfComposer;
use App\Support\Reporting\ReportPdfRenderer;
use App\Support\Reporting\WeasyPrintRuntime;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\Process\ExecutableFinder;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

/** @return array{company: Company, exercise: Exercise, actor: User, proposal: Proposal} */
function proposalPdfFixture(string $status = 'draft', int $sourceCount = 1, bool $containers = false): array
{
    $company = Company::factory()->create(['name' => 'Azienda PDF']);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $actor = User::factory()->create(['name' => 'Utente PDF']);
    grantTestPermissions([
        'company_id' => $company->id,
        'user' => $actor,
        'permissions' => [...TestPermissions::VIEW, ...TestPermissions::MANAGE_PROPOSALS, ...TestPermissions::APPROVE_BUDGET],
    ]);
    foreach (range(1, $sourceCount) as $index) {
        $expense = Expense::factory()->forExercise($exercise)->create([
            'description' => 'Spesa PDF '.$index.' · '.str_repeat('contenuto ', 8),
        ]);
        ExpenseLine::factory()->for($expense)->create([
            'amount' => (string) (100 + $index),
            'note' => 'Nota operativa '.$index.' · '.str_repeat('dettaglio ', 6),
        ]);
    }
    if ($containers) {
        $project = Project::factory()->for($company)->create(['initial_state' => 'open', 'initial_effective_date' => '2026-01-01']);
        ProjectExerciseClassification::factory()->forProjectAndExercise($project, $exercise)->create();
        $contract = Contract::factory()->for($company)->create(['contractual_start_date' => '2026-01-01']);
        foreach (['project' => $project, 'contract' => $contract] as $type => $parent) {
            $child = Expense::factory()->forExercise($exercise)->for($parent)->create(['description' => 'Componente '.$type]);
            ExpenseLine::factory()->for($child)->create(['amount' => '75.00', 'note' => 'Dettaglio componente '.$type]);
        }
    }
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());

    if ($status === 'approved') {
        app(ApproveProposal::class)->execute($actor, $proposal, (string) Str::uuid());
    } elseif ($status === 'discarded') {
        app(DiscardProposal::class)->execute($actor, $proposal, 'Proposta non adottata', (string) Str::uuid());
    }

    return compact('company', 'exercise', 'actor', 'proposal');
}

function fakeProposalPdfRuntime(): void
{
    Process::fake(fn (PendingProcess $process) => $process->command === ['weasyprint', '--version']
        ? Process::result('WeasyPrint version 69.0')
        : Process::result('%PDF-1.7 proposta'));
}

/** @return array{pages: int, width: float, height: float} */
function inspectProposalPdfLayout(string $html): array
{
    $binary = (new ExecutableFinder)->find('weasyprint');
    if ($binary === null) {
        throw new RuntimeException('WeasyPrint executable not found.');
    }
    $handle = fopen($binary, 'r');
    $interpreter = is_resource($handle) ? trim((string) fgets($handle)) : '';
    if (is_resource($handle)) {
        fclose($handle);
    }
    $interpreter = str_starts_with($interpreter, '#!') ? substr($interpreter, 2) : '';
    if ($interpreter === '' || ! is_executable($interpreter)) {
        throw new RuntimeException('WeasyPrint Python interpreter not found.');
    }
    $path = tempnam(sys_get_temp_dir(), 'proposal-html-');
    file_put_contents($path, $html);
    $process = new Symfony\Component\Process\Process([
        $interpreter,
        '-c',
        'import sys; from weasyprint import HTML; document = HTML(filename=sys.argv[1]).render(); page = document.pages[0]; print(len(document.pages), page.width, page.height)',
        $path,
    ]);
    $process->mustRun();
    unlink($path);
    preg_match('/^(\d+)\s+([\d.]+)\s+([\d.]+)$/', trim($process->getOutput()), $matches);

    return ['pages' => (int) $matches[1], 'width' => (float) $matches[2], 'height' => (float) $matches[3]];
}

it('opens the Proposal PDF customizer from the Proposal and exposes only applicable blocks', function (): void {
    ['company' => $company, 'actor' => $actor, 'proposal' => $proposal] = proposalPdfFixture();
    Storage::fake('local');
    Storage::disk('local')->put('company-logos/'.$company->id.'/logo.png', 'logo-pdf');
    $company->update([
        'logo_disk' => 'local',
        'logo_path' => 'company-logos/'.$company->id.'/logo.png',
        'logo_media_type' => 'image/png',
    ]);
    $this->actingAs($actor);
    Filament::setTenant($company->tenantCompany);

    $this->get(ProposalResource::getUrl('pdf', ['record' => $proposal], tenant: $company->tenantCompany))
        ->assertOk()->assertDontSee('aria-label="Seleziona Esercizio"', escape: false);

    Livewire::test(ViewProposal::class, ['record' => $proposal->id])
        ->assertActionVisible('exportPdf')
        ->assertActionHasUrl('exportPdf', ProposalResource::getUrl('pdf', ['record' => $proposal], tenant: $company->tenantCompany));

    $component = Livewire::test(CustomizeProposalPdf::class, ['record' => $proposal->id])
        ->assertSee('Anteprima reale')
        ->assertSee('Esporta PDF')
        ->assertSee('Logo aziendale')
        ->assertSee('Dettagli Spese')
        ->assertDontSee('Dettagli Progetti')
        ->assertDontSee('Dettagli Contratti')
        ->assertSee('Riprova')
        ->assertSet('orientation', 'portrait')
        ->set('orientation', 'landscape')
        ->call('selectNone')
        ->assertSet('selectedBlocks', []);

    expect($component->instance()->previewUrl())
        ->toContain('/companies/'.$company->id.'/proposals/'.$proposal->id.'/pdf/preview', 'orientation=landscape', 'blocks_configured=1')
        ->and($component->instance()->downloadUrl())
        ->toContain('/companies/'.$company->id.'/proposals/'.$proposal->id.'/pdf/download')
        ->and($component->instance()->downloadFilename())->toBe('proposta-azienda-pdf-2026-'.$proposal->id.'.pdf')
        ->and($component->html())->toContain('URL.createObjectURL', 'x-bind:src="blobUrl"', 'x-bind:href="blobUrl ??');
});

it('composes selectable Proposal content from the same normalized data used by its UI', function (): void {
    ['company' => $company, 'exercise' => $exercise, 'actor' => $actor, 'proposal' => $proposal] = proposalPdfFixture(sourceCount: 2);
    Storage::fake('local');
    Storage::disk('local')->put('company-logos/'.$company->id.'/logo.png', 'logo-pdf');
    $company->update([
        'logo_disk' => 'local',
        'logo_path' => 'company-logos/'.$company->id.'/logo.png',
        'logo_media_type' => 'image/png',
    ]);
    app(PlanExpense::class)->create($actor, $proposal, [
        'description' => '<script>Nuova pianificata</script>',
        'exercise_id' => $exercise->id,
        'supplier_id' => null,
        'cost_center_id' => null,
        'project_id' => null,
        'project_item_id' => null,
        'estimate_lines' => [[
            'proposal_line_id' => (string) Str::uuid(),
            'line_id' => null,
            'amount' => '350.00',
            'quantity' => null,
            'unit_amount' => null,
            'unit_of_measure' => null,
            'note' => null,
            'annulled' => false,
        ]],
    ], null, (string) Str::uuid(), 0);
    $newDocument = app(ProposalPdfComposer::class)->compose($proposal->refresh());
    expect(view('proposals.pdf', ['document' => $newDocument])->render())->toContain('Nuova sorgente');
    $itemToExclude = $proposal->items()->whereNull('expense_id')->sole();
    app(PlanExpense::class)->execute(
        $actor,
        $proposal,
        $itemToExclude,
        ProposalActionType::ExcludeExpense,
        [],
        null,
        (string) Str::uuid(),
        1,
    );
    $composer = app(ProposalPdfComposer::class);
    $full = $composer->compose($proposal->refresh());
    $configured = $composer->compose($proposal, [
        'orientation' => 'landscape',
        'blocks' => ['logo', 'sources', 'details:expense', 'unknown', 'sources'],
    ]);
    $html = view('proposals.pdf', ['document' => $configured])->render();

    expect(array_column($full['available_blocks'], 'id'))
        ->toContain('logo', 'summary', 'verification', 'impacts', 'sources', 'details:expense', 'decisions')
        ->not->toContain('details:project', 'details:contract')
        ->and($configured['selected_blocks'])->toBe(['logo', 'sources', 'details:expense'])
        ->and($html)->toContain('size: A4 landscape;', 'data:image/png;base64,', 'Sorgente esistente', 'Esclusa dalla Proposta', 'Assente alla data')
        ->and($html)->toContain('&lt;script&gt;Nuova pianificata&lt;/script&gt;')
        ->and($html)->not->toContain('<script>Nuova pianificata</script>', 'Riepilogo della Proposta', 'Storico Decisioni', 'unknown');
});

it('prints Draft Approved and Discarded Proposals in read only form', function (string $status, string $label, string $budgetLabel): void {
    ['proposal' => $proposal] = proposalPdfFixture($status);
    $document = app(ProposalPdfComposer::class)->compose($proposal->refresh());
    $html = view('proposals.pdf', compact('document'))->render();

    expect($html)->toContain($label, $budgetLabel, 'Esercizio 2026', 'Azienda PDF', 'Spesa PDF 1');
})->with([
    ['draft', 'Bozza', 'Budget proposto'],
    ['approved', 'Approvata', 'Budget materializzato'],
    ['discarded', 'Scartata', 'Budget proposto'],
]);

it('previews and downloads validated configurations through the tenant scoped WeasyPrint pipeline', function (): void {
    fakeProposalPdfRuntime();
    ['company' => $company, 'actor' => $actor, 'proposal' => $proposal] = proposalPdfFixture();
    $otherCompany = Company::factory()->create();
    $otherViewer = s11ReportingViewer($otherCompany);
    $parameters = [
        'tenant' => $company->tenantCompany,
        'proposal' => $proposal,
        'orientation' => 'landscape',
        'blocks_configured' => true,
        'blocks' => ['summary', 'hostile<script>'],
    ];

    $this->get(route('proposals.pdf.preview', $parameters))->assertRedirect();
    $this->actingAs($otherViewer)->get(route('proposals.pdf.preview', $parameters))->assertForbidden();
    $this->get(route('proposals.pdf.download', $parameters))->assertForbidden();
    $unprivileged = User::factory()->create(['company_id' => $company->id]);
    $this->actingAs($unprivileged)->get(route('proposals.pdf.preview', $parameters))->assertForbidden();
    $this->get(route('proposals.pdf.download', $parameters))->assertForbidden();
    $this->actingAs($actor)
        ->get(route('proposals.pdf.preview', [...$parameters, 'tenant' => $otherCompany->tenantCompany]))
        ->assertNotFound();

    $preview = $this->actingAs($actor)->get(route('proposals.pdf.preview', $parameters));
    $download = $this->get(route('proposals.pdf.download', $parameters));
    $preview->assertOk()->assertHeader('content-type', 'application/pdf')->assertHeader('cache-control', 'no-store, private');
    $download->assertOk()->assertHeader('content-type', 'application/pdf');
    expect($preview->headers->get('content-disposition'))->toStartWith('inline;')
        ->and($download->headers->get('content-disposition'))->toStartWith('attachment;')
        ->and($preview->getContent())->toBe($download->getContent())
        ->and($preview->getContent())->toStartWith('%PDF-');
    $this->getJson(route('proposals.pdf.preview', [...$parameters, 'orientation' => 'diagonal']))->assertUnprocessable();

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['weasyprint', '-', '-']
        && str_contains((string) $process->input, 'size: A4 landscape;')
        && str_contains((string) $process->input, 'Riepilogo della Proposta')
        && ! str_contains((string) $process->input, 'Elenco Sorgenti')
        && ! str_contains((string) $process->input, 'hostile<script>'));
});

it('renders portrait landscape and multipage Proposal documents with the installed WeasyPrint runtime', function (): void {
    if (! app(WeasyPrintRuntime::class)->status()['available']) {
        $this->markTestSkipped('WeasyPrint is not installed in this runtime.');
    }
    ['proposal' => $proposal] = proposalPdfFixture(sourceCount: 32);
    $composer = app(ProposalPdfComposer::class);
    $renderer = app(ReportPdfRenderer::class);
    $portraitDocument = $composer->compose($proposal, ['orientation' => 'portrait']);
    $landscapeDocument = $composer->compose($proposal, ['orientation' => 'landscape']);
    $portraitHtml = view('proposals.pdf', ['document' => $portraitDocument])->render();
    $landscapeHtml = view('proposals.pdf', ['document' => $landscapeDocument])->render();
    $portrait = $renderer->renderHtml($portraitHtml);
    $landscape = $renderer->renderHtml($landscapeHtml);
    $portraitInfo = inspectProposalPdfLayout($portraitHtml);
    $landscapeInfo = inspectProposalPdfLayout($landscapeHtml);

    expect($portrait)->toStartWith('%PDF-')
        ->and($landscape)->toStartWith('%PDF-')
        ->and($portraitInfo['pages'])->toBeGreaterThan(1)
        ->and($landscapeInfo['pages'])->toBeGreaterThan(1)
        ->and($portraitInfo['height'])->toBeGreaterThan($portraitInfo['width'])
        ->and($landscapeInfo['width'])->toBeGreaterThan($landscapeInfo['height']);
});

it('includes project and contract expense components when their detail blocks are selected', function (): void {
    ['proposal' => $proposal] = proposalPdfFixture(containers: true);
    $document = app(ProposalPdfComposer::class)->compose($proposal, ['blocks' => ['details:project', 'details:contract']]);
    $html = view('proposals.pdf', compact('document'))->render();
    expect(array_column($document['available_blocks'], 'id'))->toContain('details:project', 'details:contract')
        ->and($html)->toContain('Dettaglio componente project', 'Dettaglio componente contract', 'Componente project', 'Componente contract')
        ->and($html)->not->toContain('data-block="details:expense"');
});
