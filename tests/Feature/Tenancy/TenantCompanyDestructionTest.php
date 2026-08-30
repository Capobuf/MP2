<?php

use App\Actions\Tenancy\ArchiveTenantCompany;
use App\Actions\Tenancy\DestroyTenantCompany;
use App\Domain\Company\AuditEventType;
use App\Domain\Company\Capability;
use App\Domain\Company\TenantCompanyStatus;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\BudgetEvidence;
use App\Models\BudgetSnapshot;
use App\Models\BudgetSourceRow;
use App\Models\BusinessBackupImport;
use App\Models\ClosingSnapshot;
use App\Models\ClosingSourceRow;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractExerciseClassification;
use App\Models\ContractLifecycleFact;
use App\Models\ContractRenewalConfiguration;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\HistoricalErrorAnnotation;
use App\Models\LateCorrection;
use App\Models\PendingFileDeletion;
use App\Models\Project;
use App\Models\ProjectContractLink;
use App\Models\ProjectDeferral;
use App\Models\ProjectExerciseClassification;
use App\Models\ProjectTransition;
use App\Models\Proposal;
use App\Models\ProposalAction;
use App\Models\ProposalItem;
use App\Models\Supplier;
use App\Models\SupplierContact;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('matches the complete foreign-key contract and preserves every global User restriction', function (): void {
    $contract = file_get_contents(base_path('specs/013-tenant-company-lifecycle/contracts/delete-foreign-key-matrix.md'));
    expect($contract)->not->toBeFalse();

    preg_match_all(
        '/^\| `([^`]+)` \| `([^`]+)` \| (CASCADE|\*\*SET NULL\*\*) \|$/m',
        $contract,
        $matches,
        PREG_SET_ORDER,
    );
    $expected = collect($matches)->mapWithKeys(fn (array $match): array => [
        $match[2] => str_replace('*', '', $match[3]),
    ])->sortKeys()->all();
    $actual = DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
        ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
        ->whereIn('CONSTRAINT_NAME', array_keys($expected))
        ->pluck('DELETE_RULE', 'CONSTRAINT_NAME')
        ->sortKeys()
        ->all();

    expect($actual)->toBe($expected)
        ->and(DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('REFERENCED_TABLE_NAME', 'users')
            ->where('DELETE_RULE', '<>', 'RESTRICT')
            ->count())->toBe(0)
        ->and(DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->whereIn('TABLE_NAME', ['expenses', 'project_contract_links'])
            ->whereIn('COLUMN_NAME', ['generated_contract_id', 'generated_exercise_id', 'active_contract_id'])
            ->where('EXTRA', 'VIRTUAL GENERATED')
            ->count())->toBe(3);
});

it('deletes the full Tenant graph while preserving shared Users, another Tenant and its shared physical file', function (): void {
    Storage::fake('tenant-destruction');
    $actor = User::factory()->platformAdmin()->create();
    $company = Company::factory()->create(['name' => 'Target']);
    $otherCompany = Company::factory()->create(['name' => 'Other']);

    $exclusivePath = 'attachments/target-exclusive.pdf';
    $sharedPath = 'attachments/shared.pdf';
    Storage::disk('tenant-destruction')->put($exclusivePath, 'exclusive');
    Storage::disk('tenant-destruction')->put($sharedPath, 'shared');

    createTenantDestructionGraph($company, $actor, $exclusivePath, $sharedPath);
    Contract::factory()->create(['company_id' => $otherCompany->id]);
    $otherExercise = Exercise::factory()->create(['company_id' => $otherCompany->id, 'year' => 2026]);
    $otherProposal = Proposal::factory()->create([
        'company_id' => $otherCompany->id,
        'exercise_id' => $otherExercise->id,
        'created_by_id' => $actor->id,
    ]);
    $otherBudget = BudgetSnapshot::factory()->create([
        'company_id' => $otherCompany->id,
        'exercise_id' => $otherExercise->id,
        'proposal_id' => $otherProposal->id,
        'approved_by_id' => $actor->id,
    ]);
    BudgetEvidence::factory()->create([
        'company_id' => $otherCompany->id,
        'budget_snapshot_id' => $otherBudget->id,
        'storage_disk' => 'tenant-destruction',
        'storage_path' => $sharedPath,
    ]);
    CompanyCapability::query()->create([
        'company_id' => $otherCompany->id,
        'user_id' => $actor->id,
        'capability' => Capability::View,
    ]);

    $tenantOwnedTables = DB::table('information_schema.COLUMNS')
        ->where('TABLE_SCHEMA', DB::getDatabaseName())
        ->where('COLUMN_NAME', 'company_id')
        ->pluck('TABLE_NAME')
        ->all();
    foreach ($tenantOwnedTables as $table) {
        expect(DB::table($table)->where('company_id', $company->id)->count())
            ->toBeGreaterThan(0, "Fixture missing {$table}");
    }

    $result = app(DestroyTenantCompany::class)->execute($actor, $company->tenantCompany, true, true);

    expect($result->isComplete())->toBeTrue()
        ->and($result->filesProcessed)->toBe(2)
        ->and(Company::query()->find($company->id))->toBeNull()
        ->and(User::query()->whereKey($actor->id)->exists())->toBeTrue()
        ->and(Company::query()->whereKey($otherCompany->id)->exists())->toBeTrue()
        ->and(PendingFileDeletion::query()->count())->toBe(0);

    foreach ($tenantOwnedTables as $table) {
        expect(DB::table($table)->where('company_id', $company->id)->count())->toBe(0, "Residual rows in {$table}");
    }

    expect(SupplierContact::query()->whereIn('supplier_id', fn ($query) => $query->select('id')->from('suppliers')->where('company_id', $company->id))->count())->toBe(0)
        ->and(ExpenseLine::query()->whereIn('expense_id', fn ($query) => $query->select('id')->from('expenses')->where('company_id', $company->id))->count())->toBe(0);
    Storage::disk('tenant-destruction')->assertMissing($exclusivePath);
    Storage::disk('tenant-destruction')->assertExists($sharedPath);
});

it('requires platform authorization and both independent confirmations with zero partial deletion', function (): void {
    $company = Company::factory()->create();
    $ordinary = User::factory()->create();
    $platform = User::factory()->platformAdmin()->create();
    foreach (Capability::cases() as $capability) {
        CompanyCapability::query()->create([
            'company_id' => $company->id,
            'user_id' => $ordinary->id,
            'capability' => $capability,
        ]);
    }

    expect(fn () => app(DestroyTenantCompany::class)->execute($ordinary, $company->tenantCompany, true, true))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(DestroyTenantCompany::class)->execute($platform, $company->tenantCompany, false, true))
        ->toThrow(ValidationException::class);
    expect(fn () => app(DestroyTenantCompany::class)->execute($platform, $company->tenantCompany, true, false))
        ->toThrow(ValidationException::class);

    expect(Company::query()->whereKey($company->id)->exists())->toBeTrue()
        ->and($company->tenantCompany()->exists())->toBeTrue()
        ->and(PendingFileDeletion::query()->count())->toBe(0);
});

it('serializes an Archive followed by destruction and rejects a stale deleted target', function (): void {
    $actor = User::factory()->platformAdmin()->create();
    $company = Company::factory()->create();
    $selectedBeforeArchive = $company->tenantCompany;

    app(ArchiveTenantCompany::class)->execute($actor, $company->tenantCompany);
    $result = app(DestroyTenantCompany::class)->execute($actor, $selectedBeforeArchive, true, true);

    expect($result->isComplete())->toBeTrue()
        ->and(Company::query()->whereKey($company->id)->exists())->toBeFalse();

    $staleCompany = Company::factory()->create();
    $staleTenant = $staleCompany->tenantCompany;
    DB::table('companies')->where('id', $staleCompany->id)->delete();

    expect(fn () => app(DestroyTenantCompany::class)->execute($actor, $staleTenant, true, true))
        ->toThrow(ModelNotFoundException::class);
});

it('supports archived targets and rolls back graph and manifest when the database delete fails', function (): void {
    Storage::fake('tenant-destruction-rollback');
    $actor = User::factory()->platformAdmin()->create();
    $archived = Company::factory()->create();
    $archived->tenantCompany->update(['status' => TenantCompanyStatus::Archived]);

    $completed = app(DestroyTenantCompany::class)->execute($actor, $archived->tenantCompany, true, true);
    expect($completed->isComplete())->toBeTrue()
        ->and(Company::query()->whereKey($archived->id)->exists())->toBeFalse();

    $company = Company::factory()->create();
    $contract = Contract::factory()->create(['company_id' => $company->id]);
    $path = 'attachments/rollback.pdf';
    Storage::disk('tenant-destruction-rollback')->put($path, 'content');
    Attachment::factory()->forContract($contract)->create([
        'storage_disk' => 'tenant-destruction-rollback',
        'storage_path' => $path,
        'uploaded_by_id' => $actor->id,
    ]);

    $injectFailure = true;
    DB::listen(function (QueryExecuted $query) use (&$injectFailure): void {
        if ($injectFailure && str_starts_with($query->sql, 'delete from `companies`')) {
            $injectFailure = false;
            throw new RuntimeException('Injected delete failure');
        }
    });
    expect(fn () => app(DestroyTenantCompany::class)->execute($actor, $company->tenantCompany, true, true))
        ->toThrow(RuntimeException::class, 'Injected delete failure');

    expect(Company::query()->whereKey($company->id)->exists())->toBeTrue()
        ->and(Attachment::query()->where('company_id', $company->id)->exists())->toBeTrue()
        ->and(PendingFileDeletion::query()->count())->toBe(0);
    Storage::disk('tenant-destruction-rollback')->assertExists($path);
});

function createTenantDestructionGraph(
    Company $company,
    User $actor,
    string $exclusivePath,
    string $sharedPath,
): void {
    BusinessBackupImport::query()->create([
        'package_id' => (string) Str::uuid(),
        'format_version' => 1,
        'company_id' => $company->id,
        'imported_by_id' => $actor->id,
        'completed_at' => now(),
    ]);
    CompanyCapability::query()->create([
        'company_id' => $company->id,
        'user_id' => $actor->id,
        'capability' => Capability::View,
    ]);
    AuditEvent::query()->create([
        'operation_id' => (string) Str::uuid(),
        'company_id' => $company->id,
        'actor_id' => $actor->id,
        'event_type' => AuditEventType::CompanyCreated,
        'subject_type' => Company::class,
        'subject_id' => $company->id,
        'affected_exercise_ids' => [],
        'effective_from' => today(),
        'allocated_impact_by_exercise' => [],
        'actual_impact_by_exercise' => [],
    ]);

    $supplier = Supplier::factory()->create(['company_id' => $company->id]);
    SupplierContact::factory()->create(['supplier_id' => $supplier->id]);
    $costCenter = CostCenter::factory()->create(['company_id' => $company->id]);
    $exercise = Exercise::factory()->create(['company_id' => $company->id, 'year' => 2026]);
    $nextExercise = Exercise::factory()->create(['company_id' => $company->id, 'year' => 2027]);
    $project = Project::factory()->create(['company_id' => $company->id]);
    ProjectTransition::factory()->forProject($project)->create(['created_by_id' => $actor->id]);
    ProjectExerciseClassification::factory()->forProjectAndExercise($project, $exercise)->create([
        'cost_center_id' => $costCenter->id,
    ]);

    $contract = Contract::factory()->create([
        'company_id' => $company->id,
        'supplier_id' => $supplier->id,
    ]);
    $configuration = ContractRenewalConfiguration::factory()->forContract($contract)->create([
        'created_by_id' => $actor->id,
    ]);
    ContractLifecycleFact::factory()->forContract($contract)->create([
        'renewal_configuration_id' => $configuration->id,
        'created_by_id' => $actor->id,
    ]);
    ContractCondition::factory()->forContract($contract)->create(['created_by_id' => $actor->id]);
    ContractExerciseClassification::factory()->forContractAndExercise($contract, $exercise)->create([
        'cost_center_id' => $costCenter->id,
    ]);
    ProjectContractLink::factory()->forProjectAndContract($project, $contract)->create();

    $expense = Expense::factory()->forExercise($exercise)->create([
        'supplier_id' => $supplier->id,
        'direct_cost_center_id' => $costCenter->id,
    ]);
    $line = ExpenseLine::factory()->actual()->create(['expense_id' => $expense->id]);
    Expense::factory()->forExercise($exercise)->create([
        'contract_id' => $contract->id,
        'origin' => 'system',
    ]);

    ProjectDeferral::query()->create([
        'company_id' => $company->id,
        'project_id' => $project->id,
        'source_exercise_id' => $exercise->id,
        'destination_exercise_id' => $nextExercise->id,
        'mode' => 'none',
        'carryover_amount' => '0.00',
        'reprogrammed_amount' => '0.00',
    ]);

    $proposal = Proposal::factory()->create([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'created_by_id' => $actor->id,
    ]);
    $item = ProposalItem::factory()->create([
        'company_id' => $company->id,
        'proposal_id' => $proposal->id,
        'source_type' => 'expense',
        'expense_id' => $expense->id,
        'baseline_revision' => 0,
        'baseline_fingerprint' => str_repeat('a', 64),
    ]);
    ProposalAction::factory()->create([
        'company_id' => $company->id,
        'proposal_id' => $proposal->id,
        'proposal_item_id' => $item->id,
        'created_by_id' => $actor->id,
    ]);
    $budget = BudgetSnapshot::factory()->create([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'proposal_id' => $proposal->id,
        'approved_by_id' => $actor->id,
    ]);
    DB::table('proposals')->where('id', $proposal->id)->update(['reference_budget_id' => $budget->id]);
    BudgetSourceRow::factory()->create([
        'company_id' => $company->id,
        'budget_snapshot_id' => $budget->id,
    ]);

    $exclusiveAttachment = Attachment::factory()->forProposal($proposal)->create([
        'storage_disk' => 'tenant-destruction',
        'storage_path' => $exclusivePath,
        'uploaded_by_id' => $actor->id,
    ]);
    Attachment::factory()->forContract($contract)->create([
        'storage_disk' => 'tenant-destruction',
        'storage_path' => $sharedPath,
        'uploaded_by_id' => $actor->id,
    ]);
    BudgetEvidence::factory()->create([
        'company_id' => $company->id,
        'budget_snapshot_id' => $budget->id,
        'attachment_id' => $exclusiveAttachment->id,
        'storage_disk' => 'tenant-destruction',
        'storage_path' => $exclusivePath,
    ]);

    $snapshot = ClosingSnapshot::query()->create([
        'company_id' => $company->id,
        'company_name' => $company->name,
        'exercise_id' => $exercise->id,
        'exercise_year' => $exercise->year,
        'closed_at' => now(),
        'closed_by_id' => $actor->id,
        'initial_budget_id' => $budget->id,
        'current_budget_id' => $budget->id,
        'total_final_allocation' => '0.00',
        'total_closing_actual' => '0.00',
        'total_operational_variance' => '0.00',
        'total_consolidated_carryover' => '0.00',
        'accepted_warnings' => [],
        'applied_settings' => [],
        'next_exercise_disposition' => 'not_created',
        'next_exercise_id' => null,
        'operation_id' => (string) Str::uuid(),
    ]);
    ClosingSourceRow::query()->create([
        'company_id' => $company->id,
        'closing_snapshot_id' => $snapshot->id,
        'source_type' => 'expense',
        'origin_id' => $expense->id,
        'origin_key' => "expense:{$expense->id}",
        'label' => 'Spesa',
        'cost_center_label' => $costCenter->name,
        'has_actuals' => true,
        'final_estimates' => '0.00',
        'received_carryover' => '0.00',
        'final_allocation' => '0.00',
        'closing_actual' => '0.00',
        'operational_variance' => '0.00',
        'detail_version' => 1,
        'detail' => [],
    ]);
    $exercise->update(['status' => 'closed']);

    LateCorrection::query()->create([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'closing_snapshot_id' => $snapshot->id,
        'expense_id' => $expense->id,
        'expense_line_id' => $line->id,
        'recorded_by_id' => $actor->id,
        'operation_id' => (string) Str::uuid(),
        'reason' => 'Correzione storica',
        'belongs_to_closed_exercise' => true,
        'source_type' => 'expense',
        'source_origin_id' => $expense->id,
        'source_origin_key' => "expense:{$expense->id}",
        'source_label' => 'Spesa',
        'owner_context' => ['schema_version' => 1],
    ]);
    $annotation = HistoricalErrorAnnotation::query()->create([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'closing_snapshot_id' => $snapshot->id,
        'recorded_by_id' => $actor->id,
        'operation_id' => (string) Str::uuid(),
        'kind' => 'cost_center',
        'reason' => 'Errore storico',
        'recorded_facts_version' => 1,
        'recorded_facts' => ['id' => 1],
        'believed_correct_facts_version' => 1,
        'believed_correct_facts' => ['id' => 2],
        'affected_sources_version' => 1,
        'affected_sources' => [[
            'type' => 'closing_snapshot',
            'id' => $snapshot->id,
            'origin_key' => "closing_snapshot:{$snapshot->id}",
            'label' => 'Snapshot di Chiusura',
        ]],
    ]);
    Attachment::factory()->forHistoricalErrorAnnotation($annotation)->create([
        'storage_disk' => 'tenant-destruction',
        'storage_path' => 'attachments/historical-exclusive.pdf',
        'uploaded_by_id' => $actor->id,
    ]);
}
