<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('backfills legacy receipts and distinct portable Tenant identities without inventing source identities', function (): void {
    $companies = Company::factory()->count(2)->create();
    $actor = User::factory()->platformAdmin()->create();
    $legacy = require database_path('migrations/2026_08_30_000200_create_business_backup_imports_table.php');
    $identity = require database_path('migrations/2026_10_04_000200_add_portable_tenant_identity.php');
    $journal = require database_path('migrations/2026_10_04_000300_extend_business_backup_import_journal.php');

    try {
        $legacy->down();
        $identity->down();
        $legacy->up();
        foreach ($companies as $company) {
            DB::table('business_backup_imports')->insert(['package_id' => (string) Str::uuid(), 'format_version' => 2, 'company_id' => $company->id, 'imported_by_id' => $actor->id, 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $identity->up();
        $journal->up();

        $receipts = DB::table('business_backup_imports')->orderBy('id')->get();
        expect($receipts)->toHaveCount(2)
            ->and($receipts->pluck('import_operation_id')->unique())->toHaveCount(2)
            ->and($receipts->pluck('target_tenant_uuid')->unique())->toHaveCount(2);
        foreach ($receipts as $receipt) {
            expect(Str::isUuid($receipt->import_operation_id))->toBeTrue()
                ->and(Str::isUuid($receipt->target_tenant_uuid))->toBeTrue()
                ->and($receipt->source_tenant_uuid)->toBeNull()
                ->and($receipt->operation)->toBe('create')
                ->and($receipt->target_tenant_uuid)->toBe(DB::table('tenant_companies')->where('company_id', $receipt->company_id)->value('portable_uuid'));
        }
        DB::table('companies')->where('id', $companies->first()->id)->delete();
        expect(DB::table('business_backup_imports')->count())->toBe(2)
            ->and(DB::table('business_backup_imports')->whereNull('company_id')->count())->toBe(1);
    } finally {
        // MySQL DDL commits the test transaction; rebuild isolation before the next test.
        RefreshDatabaseState::$migrated = false;
    }
});
