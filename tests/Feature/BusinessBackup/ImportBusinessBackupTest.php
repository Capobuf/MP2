<?php

use App\Actions\BusinessBackup\ExportBusinessBackup;
use App\Actions\BusinessBackup\ImportBusinessBackup;
use App\BusinessBackup\V1\BusinessBackupValidator;
use App\Models\Attachment;
use App\Models\BusinessBackupImport;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Proposal;
use App\Models\Supplier;
use App\Models\SupplierContact;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('rolls back any persistence failure and restores only destination-local access', function (): void {
    $company = Company::factory()->create(['name' => 'Azienda Sicura']);
    $importer = User::factory()->platformAdmin()->create();
    $sourceMember = User::factory()->create();
    $outsider = User::factory()->create();
    grantTestPermissions(['company_id' => $company->id, 'user' => $sourceMember, 'permissions' => TestPermissions::MANAGE_OPERATIONS]);
    $supplier = Supplier::factory()->for($company)->create();
    SupplierContact::factory()->for($supplier)->create();
    $contract = Contract::factory()->for($company)->for($supplier)->create();
    Attachment::factory()->forContract($contract)->create(['uploaded_by_id' => $sourceMember->id]);
    Proposal::factory()->for($company)->for($importer, 'creator')->create();

    $artifact = app(ExportBusinessBackup::class)->execute($company, $importer);
    try {
        $validated = app(BusinessBackupValidator::class)->validate($artifact['path']);
    } finally {
        @unlink($artifact['path']);
    }

    expect(fn () => app(ImportBusinessBackup::class)->execute($outsider, $validated))
        ->toThrow(AuthorizationException::class);

    $broken = $validated;
    $broken['machine']['_MP2_supplier_contacts']['rows'][0][1] = 'SUP-9999999999';
    $countsBefore = [Company::query()->count(), User::query()->count()];
    expect(fn () => app(ImportBusinessBackup::class)->execute($importer, $broken))
        ->toThrow(UnexpectedValueException::class)
        ->and([Company::query()->count(), User::query()->count()])->toBe($countsBefore)
        ->and(BusinessBackupImport::query()->count())->toBe(0);

    $restored = app(ImportBusinessBackup::class)->execute($importer, $validated);
    expect($restored->tenantCompany->status->value)->toBe('active')
        ->and($restored->attachments()->count())->toBe(0)
        ->and($restored->proposals()->count())->toBe(0)
        ->and($restored->auditEvents()->count())->toBe(0)
        ->and(User::query()->count())->toBe($countsBefore[1])
        ->and(BusinessBackupImport::query()->where('company_id', $restored->id)->count())->toBe(1);
});
