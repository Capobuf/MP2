<?php

use App\Actions\BusinessBackup\ExportBusinessBackup;
use App\Domain\Company\AuditEventType;
use App\Domain\Company\Capability;
use App\Domain\Company\TenantCompanyStatus;
use App\Domain\Expenses\ExerciseStatus;
use App\Filament\Platform\Pages\ImportCompanyBackup;
use App\Filament\Platform\Resources\TenantCompanies\TenantCompanyResource;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\BusinessBackupImport;
use App\Models\ClosingSnapshot;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Contract;
use App\Models\Exercise;
use App\Models\Proposal;
use App\Models\Supplier;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel('platform');
    Filament::setTenant(null);
});

function storeBusinessBackupPageUpload(Company $company, User $actor, string $filename): string
{
    $artifact = app(ExportBusinessBackup::class)->execute($company, $actor);
    $upload = 'business-backup-uploads/'.$filename;
    Storage::disk('local')->put($upload, (string) file_get_contents($artifact['path']));
    @unlink($artifact['path']);

    return $upload;
}

it('allows only a Platform Admin to validate and preview without writes', function (): void {
    Storage::fake('local');
    $source = Company::factory()->create(['name' => 'Anteprima Backup']);
    $admin = User::factory()->platformAdmin()->create();
    $ordinary = User::factory()->create();
    CompanyCapability::query()->create(['company_id' => $source->id, 'user_id' => $admin->id, 'capability' => Capability::View]);
    Exercise::factory()->for($source)->create(['year' => 2025, 'status' => ExerciseStatus::Open]);
    $closedExercise = Exercise::factory()->for($source)->create(['year' => 2026, 'status' => ExerciseStatus::Open]);
    ClosingSnapshot::query()->create([
        'company_id' => $source->id,
        'company_name' => $source->name,
        'exercise_id' => $closedExercise->id,
        'exercise_year' => 2026,
        'closed_at' => now(),
        'closed_by_id' => $admin->id,
        'initial_budget_id' => null,
        'current_budget_id' => null,
        'total_final_allocation' => '0.00',
        'total_closing_actual' => '0.00',
        'total_operational_variance' => '0.00',
        'total_consolidated_carryover' => '0.00',
        'accepted_warnings' => [],
        'applied_settings' => ['timezone' => $source->timezone],
        'next_exercise_disposition' => 'not_created',
        'next_exercise_id' => null,
        'operation_id' => (string) Str::uuid(),
    ]);
    $closedExercise->update(['status' => ExerciseStatus::Closed]);
    $upload = storeBusinessBackupPageUpload($source, $admin, 'preview.xlsx');
    $source->update(['name' => 'Azienda rinominata dopo export']);
    $companyCount = Company::query()->count();

    $this->actingAs($admin);
    Livewire::test(ImportCompanyBackup::class)
        ->assertSuccessful()
        ->assertSee('Importa Azienda da backup')
        ->set('data.backup', [$upload])
        ->call('validateBackup')
        ->assertHasNoErrors()
        ->assertSet('previewData.company_name', 'Anteprima Backup')
        ->assertSet('previewData.format_version', 1)
        ->assertSet('previewData.name_collision', false)
        ->assertSet('validatedPackageId', fn (?string $value): bool => filled($value))
        ->assertSee('Anteprima del ripristino')
        ->assertSee('Fuso orario')
        ->assertSee('Formato')
        ->assertSee('MP2 Business Data Backup · V1')
        ->assertSee('Aperto')
        ->assertSee('Chiuso')
        ->assertSee(['Fornitori', 'Progetti', 'Contratti', 'Spese', 'Budget', 'Chiusure'])
        ->assertSee('Totale degli snapshot Budget inclusi')
        ->assertSee('Effettivi delle Chiusure incluse')
        ->assertSee('Ripristina come nuova Azienda')
        ->assertDontSee('2025 (open)')
        ->assertDontSee('timezone Europe/Rome')
        ->assertDontSee('allegati non saranno ripristinati')
        ->assertDontSee('Esiste già un’Azienda denominata')
        ->assertNotified('Backup valido');
    expect(Company::query()->count())->toBe($companyCount);

    $this->actingAs($ordinary)
        ->get('/platform/import-company-backup')
        ->assertForbidden();
});

it('restores a validated upload through the page', function (): void {
    Storage::fake('local');
    $source = Company::factory()->create(['name' => 'Ripristino Pagina']);
    $admin = User::factory()->platformAdmin()->create();
    $sourceMember = User::factory()->create();
    CompanyCapability::query()->create(['company_id' => $source->id, 'user_id' => $admin->id, 'capability' => Capability::View]);
    CompanyCapability::query()->create(['company_id' => $source->id, 'user_id' => $sourceMember->id, 'capability' => Capability::ManageOperations]);
    $exercise = Exercise::factory()->for($source)->create(['year' => 2026]);
    Proposal::factory()->for($source)->for($exercise)->for($sourceMember, 'creator')->create();
    $supplier = Supplier::factory()->for($source)->create();
    $contract = Contract::factory()->for($source)->for($supplier)->create();
    Attachment::factory()->forContract($contract)->create(['uploaded_by_id' => $sourceMember->id]);
    AuditEvent::query()->create([
        'company_id' => $source->id,
        'operation_id' => (string) Str::uuid(),
        'actor_id' => $sourceMember->id,
        'event_type' => AuditEventType::ContractCreated,
        'subject_type' => Contract::class,
        'subject_id' => $contract->id,
        'affected_exercise_ids' => [],
        'effective_from' => '2026-01-01',
        'allocated_impact_by_exercise' => [],
        'actual_impact_by_exercise' => [],
    ]);
    $upload = storeBusinessBackupPageUpload($source, $admin, 'restore.xlsx');
    $companyCount = Company::query()->count();
    $userCount = User::query()->count();

    $this->actingAs($admin);
    Exceptions::fake()->throwOnReport();
    Livewire::test(ImportCompanyBackup::class)
        ->set('data.backup', [$upload])
        ->call('validateBackup')
        ->assertSet('previewData.company_name', 'Ripristino Pagina')
        ->assertSee('1 allegati non saranno ripristinati. Il backup ne conserva l’inventario, ma il formato V1 non contiene i file originali.')
        ->assertSee('Esiste già un’Azienda denominata “Ripristino Pagina”.')
        ->assertSee('L’Azienda esistente non verrà modificata né unita ai dati importati.')
        ->assertSee('Ripristina come nuova Azienda')
        ->assertSeeHtml('wire:click="mountAction(\'confirmImport\'')
        ->call('confirmImport')
        ->assertHasNoErrors()
        ->assertRedirect(TenantCompanyResource::getUrl('index', panel: 'platform'));

    $restored = Company::query()->whereKeyNot($source->id)->sole();
    expect(Company::query()->count())->toBe($companyCount + 1)
        ->and($restored->id)->not->toBe($source->id)
        ->and($restored->name)->toBe($source->name)
        ->and($restored->tenantCompany->status)->toBe(TenantCompanyStatus::Active)
        ->and(User::query()->count())->toBe($userCount)
        ->and($restored->capabilities()->where('user_id', $sourceMember->id)->count())->toBe(0)
        ->and($restored->auditEvents()->count())->toBe(0)
        ->and($restored->proposals()->count())->toBe(0)
        ->and(BusinessBackupImport::query()->where('company_id', $restored->id)->count())->toBe(1)
        ->and(Storage::disk('local')->exists($upload))->toBeFalse();
});

it('materializes a Livewire temporary upload during validation', function (): void {
    Storage::fake('local');
    $source = Company::factory()->create(['name' => 'Upload temporaneo']);
    $admin = User::factory()->platformAdmin()->create();
    CompanyCapability::query()->create(['company_id' => $source->id, 'user_id' => $admin->id, 'capability' => Capability::View]);
    $storedUpload = storeBusinessBackupPageUpload($source, $admin, 'temporary-source.xlsx');
    $temporaryUpload = UploadedFile::fake()
        ->createWithContent('backup.xlsx', (string) Storage::disk('local')->get($storedUpload))
        ->mimeType('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $this->actingAs($admin);
    Livewire::test(ImportCompanyBackup::class)
        ->set('data.backup', [$temporaryUpload])
        ->call('validateBackup')
        ->assertHasNoErrors()
        ->assertSet('previewData.company_name', 'Upload temporaneo')
        ->assertSet('validatedPackageId', fn (?string $value): bool => filled($value))
        ->assertSet('data.backup', fn (mixed $value): bool => is_array($value)
            && count($value) === 1
            && is_string(array_values($value)[0]));
});

it('rejects a valid package that replaces the validated upload contents', function (): void {
    Storage::fake('local');
    $admin = User::factory()->platformAdmin()->create();
    $sourceA = Company::factory()->create(['name' => 'Package A']);
    $sourceB = Company::factory()->create(['name' => 'Package B']);
    CompanyCapability::query()->create(['company_id' => $sourceA->id, 'user_id' => $admin->id, 'capability' => Capability::View]);
    CompanyCapability::query()->create(['company_id' => $sourceB->id, 'user_id' => $admin->id, 'capability' => Capability::View]);
    $uploadA = storeBusinessBackupPageUpload($sourceA, $admin, 'package-a.xlsx');
    $uploadB = storeBusinessBackupPageUpload($sourceB, $admin, 'package-b.xlsx');
    $companyCount = Company::query()->count();

    $this->actingAs($admin);
    $component = Livewire::test(ImportCompanyBackup::class)
        ->set('data.backup', [$uploadA])
        ->call('validateBackup')
        ->assertSet('previewData.company_name', 'Package A')
        ->assertSet('validatedPackageId', fn (?string $value): bool => filled($value));

    Storage::disk('local')->put($uploadA, (string) Storage::disk('local')->get($uploadB));

    $component->call('confirmImport')
        ->assertHasErrors(['data.backup'])
        ->assertSee('Il file caricato è cambiato dopo la validazione. Validalo nuovamente prima di procedere.')
        ->assertSet('previewData', null)
        ->assertSet('validatedPackageId', null);

    expect(Company::query()->count())->toBe($companyCount)
        ->and(BusinessBackupImport::query()->count())->toBe(0);
});

it('invalidates the previous validation when the FileUpload changes', function (): void {
    Storage::fake('local');
    $admin = User::factory()->platformAdmin()->create();
    $sourceA = Company::factory()->create(['name' => 'Upload A']);
    $sourceB = Company::factory()->create(['name' => 'Upload B']);
    CompanyCapability::query()->create(['company_id' => $sourceA->id, 'user_id' => $admin->id, 'capability' => Capability::View]);
    CompanyCapability::query()->create(['company_id' => $sourceB->id, 'user_id' => $admin->id, 'capability' => Capability::View]);
    $uploadA = storeBusinessBackupPageUpload($sourceA, $admin, 'upload-a.xlsx');
    $uploadB = storeBusinessBackupPageUpload($sourceB, $admin, 'upload-b.xlsx');
    $companyCount = Company::query()->count();

    $this->actingAs($admin);
    Livewire::test(ImportCompanyBackup::class)
        ->set('data.backup', [$uploadA])
        ->call('validateBackup')
        ->assertSet('previewData.company_name', 'Upload A')
        ->assertSet('validatedPackageId', fn (?string $value): bool => filled($value))
        ->set('data.backup', [$uploadB])
        ->assertSet('previewData', null)
        ->assertSet('validatedPackageId', null)
        ->call('confirmImport')
        ->assertHasErrors(['data.backup'])
        ->assertSee('Valida nuovamente il backup prima di procedere con il ripristino.');

    expect(Company::query()->count())->toBe($companyCount)
        ->and(BusinessBackupImport::query()->count())->toBe(0);
});
