<?php

use App\Actions\BusinessBackup\ExportBusinessBackup;
use App\Actions\BusinessBackup\ImportBusinessBackup;
use App\Actions\BusinessBackup\ReplaceBusinessBackup;
use App\Actions\Proposals\ApproveProposal;
use App\Actions\Proposals\CopyExpenseIntoProposal;
use App\Actions\Proposals\DiscardProposal;
use App\Actions\Proposals\InitializeProposal;
use App\Actions\Proposals\PlanContract;
use App\Actions\Proposals\PlanExpense;
use App\Actions\Proposals\PlanProject;
use App\Actions\Proposals\PlanProjectDeferral;
use App\Actions\Proposals\PlanProposalRelation;
use App\Actions\Proposals\RealignProposalItem;
use App\Actions\Proposals\ReviewProposalReadiness;
use App\Actions\Tenancy\DeletePendingTenantFiles;
use App\Actions\Tenancy\DestroyTenantCompany;
use App\BusinessBackup\BusinessBackupBundle;
use App\BusinessBackup\V1\BusinessBackupCollector;
use App\BusinessBackup\V1\ProposalPackageRules;
use App\Domain\Proposals\ProposalActionType;
use App\Domain\Proposals\ProposalReadiness;
use App\Domain\Proposals\ProposalRealignmentChoice;
use App\Filament\Resources\Proposals\Schemas\ProposalInfolist;
use App\Models\Attachment;
use App\Models\BudgetEvidence;
use App\Models\BusinessBackupImport;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\PendingFileDeletion;
use App\Models\PlatformLifecycleEvent;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\LegacyBusinessBackup;

uses(RefreshDatabase::class);

it('restores a live and a new Draft item without historical users or audit and retries one confirmation', function (): void {
    $company = Company::factory()->create();
    $actor = User::factory()->platformAdmin()->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $expense = Expense::factory()->forExercise($exercise)->create();
    ExpenseLine::factory()->for($expense)->create(['type' => 'estimate', 'amount' => '10.00']);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());
    app(PlanExpense::class)->create($actor, $proposal, [
        'description' => 'Nuova Spesa', 'exercise_id' => $exercise->id,
        'estimate_lines' => [['proposal_line_id' => (string) Str::uuid(), 'line_id' => null, 'amount' => '20.00', 'annulled' => false]],
    ], null, (string) Str::uuid(), $proposal->refresh()->revision);
    $artifact = app(ExportBusinessBackup::class)->execute($company, $actor, false, false);
    try {
        $package = app(BusinessBackupBundle::class)->validate($artifact['path'], 'zip');
        $operationId = (string) Str::uuid();
        $restored = app(ImportBusinessBackup::class)->execute($actor, $package, $operationId, 'copy');
        $retried = app(ImportBusinessBackup::class)->execute($actor, $package, $operationId, 'copy');
        $anotherCopy = app(ImportBusinessBackup::class)->execute($actor, $package, (string) Str::uuid(), 'copy');
        $draft = $restored->proposals()->sole();
        expect($artifact['filename'])->toEndWith('.zip')
            ->and($retried->id)->toBe($restored->id)
            ->and($anotherCopy->id)->not->toBe($restored->id)
            ->and($anotherCopy->tenantCompany->portable_uuid)->not->toBe($restored->tenantCompany->portable_uuid)
            ->and($restored->tenantCompany->portable_uuid)->not->toBe($company->tenantCompany->portable_uuid)
            ->and($draft->creator)->toBeNull()
            ->and($draft->items()->count())->toBe(2)
            ->and($draft->revision)->toBe(0)
            ->and($restored->auditEvents()->count())->toBe(0)
            ->and(app(ProposalReadiness::class)->assessProposal($draft)['ready'])->toBeTrue();
        $liveItem = $draft->items()->whereNotNull('expense_id')->sole();
        $liveItem->expense->increment('revision');
        app(ReviewProposalReadiness::class)->execute($actor, $draft, (string) Str::uuid());
        app(RealignProposalItem::class)->execute($actor, $draft->refresh(), $liveItem->refresh(), ProposalRealignmentChoice::Reload, null, [], (string) Str::uuid(), $draft->revision);
        $budget = app(ApproveProposal::class)->execute($actor, $draft->refresh(), (string) Str::uuid());
        expect($budget->total_approved_allocation)->toBe('30.00');
    } finally {
        @unlink($artifact['path']);
    }
});

it('round trips terminal Proposals and optional binaries without attributing historical authors', function (): void {
    Storage::fake('local');
    $company = Company::factory()->create();
    $actor = User::factory()->platformAdmin()->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $approved = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());
    app(PlanExpense::class)->create($actor, $approved, [
        'description' => 'Nata nella Proposta', 'exercise_id' => $exercise->id,
        'estimate_lines' => [['proposal_line_id' => (string) Str::uuid(), 'line_id' => null, 'amount' => '35.00', 'annulled' => false]],
    ], null, (string) Str::uuid(), 0);
    $budget = app(ApproveProposal::class)->execute($actor, $approved->refresh(), (string) Str::uuid());
    $discarded = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());
    $item = $discarded->items->sole();
    $action = app(PlanExpense::class)->execute($actor, $discarded->refresh(), $item, ProposalActionType::SetExpenseSupplier, ['supplier_id' => null], null, (string) Str::uuid(), 0);
    $action->update(['status' => 'withdrawn', 'withdrawn_at' => now(), 'withdrawn_by_id' => $actor->id, 'withdraw_operation_id' => (string) Str::uuid(), 'withdraw_reason' => 'Ritirata']);
    app(DiscardProposal::class)->execute($actor, $discarded->refresh(), 'Alternativa scartata', (string) Str::uuid());
    $bytes = 'originale verificabile';
    Storage::disk('local')->put('source/proposal.pdf', $bytes);
    Storage::disk('local')->put('source/evidence.pdf', $bytes);
    Storage::disk('local')->put('source/logo.png', 'logo');
    $company->update(['logo_disk' => 'local', 'logo_path' => 'source/logo.png', 'logo_media_type' => 'image/png']);
    $attachment = Attachment::factory()->forProposal($approved)->create(['uploaded_by_id' => $actor->id, 'storage_path' => 'source/proposal.pdf', 'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)]);
    $attachment->update(['detached_at' => now('UTC'), 'detached_by_id' => $actor->id]);
    BudgetEvidence::query()->create(['company_id' => $company->id, 'budget_snapshot_id' => $budget->id, 'attachment_id' => $attachment->id, 'storage_disk' => 'local', 'storage_path' => $attachment->storage_path, 'original_name' => 'documento.pdf', 'media_type' => 'application/pdf', 'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)]);
    BudgetEvidence::query()->create(['company_id' => $company->id, 'budget_snapshot_id' => $budget->id, 'storage_disk' => 'local', 'storage_path' => 'source/evidence.pdf', 'original_name' => 'evidenza.pdf', 'media_type' => 'application/pdf', 'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)]);
    foreach ([[true, true], [true, false], [false, true], [false, false]] as [$logoIncluded, $filesIncluded]) {
        $artifact = app(ExportBusinessBackup::class)->execute($company, $actor, $logoIncluded, $filesIncluded);
        try {
            $package = app(BusinessBackupBundle::class)->validate($artifact['path'], 'zip');
            $restored = app(ImportBusinessBackup::class)->execute($actor, $package, (string) Str::uuid(), 'copy');
            $restoredApproved = $restored->proposals()->where('status', 'approved')->sole();
            $restoredItem = $restoredApproved->items()->sole();
            $restoredBudget = $restoredApproved->budget;
            $budgetRow = $restoredBudget->rows()->sole();
            expect($restoredItem->expense_id)->toBeNull()
                ->and($budgetRow->origin_id)->toBe($restored->expenses()->sole()->id)
                ->and($budgetRow->proposal_item_id)->toBe($restoredItem->proposal_item_id)
                ->and($budgetRow->detail['identity']['proposal_item_id'])->toBe($restoredItem->proposal_item_id)
                ->and($restored->proposals()->where('status', 'discarded')->sole()->actionHistory()->sole()->status->value)->toBe('withdrawn')
                ->and($restored->auditEvents()->count())->toBe(0)
                ->and($restored->logo_path !== null)->toBe($logoIncluded)
                ->and(ProposalInfolist::overview($restoredApproved)['proposal']['created_by'])->toBe('Autore storico non disponibile');
            $discardedOverview = ProposalInfolist::overview($restored->proposals()->where('status', 'discarded')->sole());
            expect(array_values($discardedOverview['items'])[0]['actions'][0]['withdrawn_by'])->toBe('Autore storico non disponibile');
            if ($filesIncluded) {
                $restoredAttachment = $restored->attachments()->sole();
                expect(Storage::disk($restoredAttachment->storage_disk)->get($restoredAttachment->storage_path))->toBe($bytes)
                    ->and($restoredAttachment->uploaded_by_id)->toBeNull()
                    ->and($restoredAttachment->detached_by_id)->toBeNull()
                    ->and($restoredAttachment->detached_at->equalTo($attachment->detached_at))->toBeTrue()
                    ->and($restoredAttachment->proposal_id)->toBe($restoredApproved->id)
                    ->and($restoredBudget->evidence()->whereNotNull('attachment_id')->sole()->storage_path)->toBe($restoredAttachment->storage_path);
            } else {
                expect($restored->attachments()->count())->toBe(0)
                    ->and($restoredBudget->evidence()->whereNotNull('storage_path')->count())->toBe(0)
                    ->and($restoredBudget->evidence()->count())->toBe(3);
                $metadataArtifact = app(ExportBusinessBackup::class)->execute($restored, $actor);
                try {
                    $metadataPackage = app(BusinessBackupBundle::class)->validate($metadataArtifact['path'], 'zip');
                    expect(array_column($metadataPackage['bundle']['files'], 'kind'))->toBe($logoIncluded ? ['logo'] : []);
                } finally {
                    @unlink($metadataArtifact['path']);
                }
            }
        } finally {
            @unlink($artifact['path']);
        }
    }
});

it('replaces an Archived Tenant atomically and preserves identity journal and global administrators', function (): void {
    Storage::fake('local');
    $company = Company::factory()->create();
    $actor = User::factory()->platformAdmin()->create();
    $member = User::factory()->create(['company_id' => $company->id]);
    $exercise = Exercise::factory()->for($company)->create();
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());
    DB::table('proposals')->where('id', $proposal->id)->update(['created_by_id' => $member->id]);
    $attachment = Attachment::factory()->forProposal($proposal)->create(['uploaded_by_id' => $member->id, 'storage_path' => 'old/attachment.pdf']);
    Storage::disk('local')->put($attachment->storage_path, 'old');
    BusinessBackupImport::query()->create(['package_id' => (string) Str::uuid(), 'import_operation_id' => (string) Str::uuid(), 'target_tenant_uuid' => $company->tenantCompany->portable_uuid, 'operation' => 'create', 'format_version' => 2, 'company_id' => $company->id, 'imported_by_id' => $actor->id, 'completed_at' => now()]);
    $artifact = app(ExportBusinessBackup::class)->execute($company, $actor, false, false);
    try {
        $package = app(BusinessBackupBundle::class)->validate($artifact['path'], 'zip');
        $uuid = $company->tenantCompany->portable_uuid;
        $company->tenantCompany()->update(['status' => 'archived']);
        $actor->forceFill(['company_id' => $company->id])->save();
        $operation = (string) Str::uuid();
        $restored = app(ReplaceBusinessBackup::class)->execute($actor, $package, $operation, $company->id, true, true);
        expect($restored->id)->not->toBe($company->id)
            ->and($restored->tenantCompany->portable_uuid)->toBe($uuid)
            ->and($restored->tenantCompany->status->value)->toBe('active')
            ->and(User::query()->find($member->id))->toBeNull()
            ->and($actor->refresh()->company_id)->toBeNull()
            ->and($actor->hasRole('super_admin'))->toBeTrue()
            ->and(PlatformLifecycleEvent::query()->where('operation', 'destroy')->count())->toBe(1)
            ->and(BusinessBackupImport::query()->whereNull('company_id')->count())->toBe(1)
            ->and(Storage::disk('local')->exists($attachment->storage_path))->toBeFalse();
        expect(fn () => app(ReplaceBusinessBackup::class)->execute($actor, $package, (string) Str::uuid(), $company->id, true, true))->toThrow(ValidationException::class);
        expect(app(ReplaceBusinessBackup::class)->execute($actor, $package, $operation, $company->id, true, true)->id)->toBe($restored->id);
        app(DestroyTenantCompany::class)->execute($actor, $restored->tenantCompany, true, true);
        expect(BusinessBackupImport::query()->where('import_operation_id', $operation)->sole()->company_id)->toBeNull();
        expect(fn () => app(ImportBusinessBackup::class)->execute($actor, $package, $operation, 'copy'))->toThrow(ValidationException::class, 'Operazione già completata');
    } finally {
        @unlink($artifact['path']);
    }
});

it('rejects a replace when the target disappears between lookup and lock', function (): void {
    $company = Company::factory()->create();
    $actor = User::factory()->platformAdmin()->create();
    $artifact = app(ExportBusinessBackup::class)->execute($company, $actor, false, false);
    try {
        $package = app(BusinessBackupBundle::class)->validate($artifact['path'], 'zip');
        $removeTarget = true;
        DB::listen(function (QueryExecuted $query) use (&$removeTarget, $company): void {
            if ($removeTarget && str_starts_with($query->sql, 'select * from `tenant_companies` where `portable_uuid`')) {
                $removeTarget = false;
                DB::table('companies')->where('id', $company->id)->delete();
            }
        });
        expect(fn () => app(ReplaceBusinessBackup::class)->execute($actor, $package, (string) Str::uuid(), $company->id, true, true))
            ->toThrow(ValidationException::class, 'Il Tenant target è cambiato. Generare una nuova anteprima.');
        expect($removeTarget)->toBeFalse()
            ->and(BusinessBackupImport::query()->count())->toBe(0);
    } finally {
        @unlink($artifact['path']);
    }
});

it('rolls back replacement and staged files when persistence fails', function (): void {
    Storage::fake('local');
    $company = Company::factory()->create();
    $actor = User::factory()->platformAdmin()->create();
    $member = User::factory()->create(['company_id' => $company->id]);
    $exercise = Exercise::factory()->for($company)->create();
    $expense = Expense::factory()->forExercise($exercise)->create();
    Storage::disk('local')->put('source/file.pdf', 'binary');
    Attachment::factory()->forExpense($expense)->create(['uploaded_by_id' => $member->id, 'storage_path' => 'source/file.pdf', 'size_bytes' => 6, 'sha256' => hash('sha256', 'binary')]);
    $artifact = app(ExportBusinessBackup::class)->execute($company, $actor);
    try {
        $package = app(BusinessBackupBundle::class)->validate($artifact['path'], 'zip');
        $package['machine']['_MP2_expenses']['rows'][0][1] = 'EXE-9999999999';
        expect(fn () => app(ReplaceBusinessBackup::class)->execute($actor, $package, (string) Str::uuid(), $company->id, true, true))->toThrow(UnexpectedValueException::class)
            ->and(Company::query()->find($company->id))->not->toBeNull()
            ->and(User::query()->find($member->id))->not->toBeNull()
            ->and(PlatformLifecycleEvent::query()->count())->toBe(0)
            ->and(PendingFileDeletion::query()->count())->toBe(0)
            ->and(Storage::disk('local')->allFiles('business-backup-restores'))->toBe([]);
        Storage::disk('local')->assertExists('source/file.pdf');
    } finally {
        @unlink($artifact['path']);
    }
});

it('reports pending old file cleanup as a completed replace', function (): void {
    Storage::fake('local');
    $company = Company::factory()->create(['logo_disk' => 'local', 'logo_path' => 'old/logo.png', 'logo_media_type' => 'image/png']);
    Storage::disk('local')->put('old/logo.png', 'logo');
    $actor = User::factory()->platformAdmin()->create();
    $artifact = app(ExportBusinessBackup::class)->execute($company, $actor, false, false);
    try {
        $package = app(BusinessBackupBundle::class)->validate($artifact['path'], 'zip');
        $this->mock(DeletePendingTenantFiles::class)->shouldReceive('execute')->once()->andReturn(['processed' => 1, 'completed' => 0, 'failed' => 1]);
        $operation = (string) Str::uuid();
        $restored = app(ReplaceBusinessBackup::class)->execute($actor, $package, $operation, $company->id, true, true);
        expect($restored->getAttribute('backup_cleanup_pending'))->toBe(1)
            ->and($restored->logo_path)->toBeNull()
            ->and(BusinessBackupImport::query()->where('import_operation_id', $operation)->sole()->company_id)->toBe($restored->id)
            ->and(PendingFileDeletion::query()->count())->toBe(1);
    } finally {
        @unlink($artifact['path']);
    }
});

it('rejects unsafe or altered ZIP envelopes and technical resource limits before persistence', function (): void {
    $company = Company::factory()->create();
    $actor = User::factory()->platformAdmin()->create();
    $artifact = app(ExportBusinessBackup::class)->execute($company, $actor, false, false);
    try {
        $cases = [
            'traversal' => fn (ZipArchive $zip) => $zip->addFromString('../escape', 'bad'),
            'unexpected' => fn (ZipArchive $zip) => $zip->addFromString('unexpected.txt', 'bad'),
            'absolute' => fn (ZipArchive $zip) => $zip->addFromString('/absolute', 'bad'),
            'symlink' => fn (ZipArchive $zip) => $zip->setExternalAttributesName('data.xlsx', ZipArchive::OPSYS_UNIX, 0120777 << 16),
            'encrypted' => fn (ZipArchive $zip) => $zip->setEncryptionName('data.xlsx', ZipArchive::EM_AES_256, 'test-password'),
            'wrong hash' => function (ZipArchive $zip): void {
                $manifest = json_decode($zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
                $manifest['data']['sha256'] = str_repeat('0', 64);
                $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
            },
        ];
        foreach ($cases as $mutate) {
            $path = tempnam(sys_get_temp_dir(), 'mp2-unsafe-');
            copy($artifact['path'], $path);
            $zip = new ZipArchive;
            $zip->open($path);
            $mutate($zip);
            $zip->close();
            try {
                expect(fn () => app(BusinessBackupBundle::class)->validate($path, 'zip'))->toThrow(ValidationException::class);
            } finally {
                unlink($path);
            }
        }
        $bytes = file_get_contents($artifact['path']);
        $endOffset = strrpos($bytes, "PK\x05\x06");
        $end = unpack('vdisk/vdirectory_disk/ventries_disk/ventries/Vsize/Voffset/vcomment', substr($bytes, $endOffset + 4, 18));
        $entryOffset = $end['offset'];
        $entry = unpack('vname/vextra/vcomment', substr($bytes, $entryOffset + 28, 6));
        $duplicate = substr($bytes, $entryOffset, 46 + $entry['name'] + $entry['extra'] + $entry['comment']);
        $duplicatePath = tempnam(sys_get_temp_dir(), 'mp2-duplicate-');
        file_put_contents($duplicatePath, substr($bytes, 0, $endOffset).$duplicate.pack('VvvvvVVv', 0x06054B50, $end['disk'], $end['directory_disk'], $end['entries_disk'] + 1, $end['entries'] + 1, $end['size'] + strlen($duplicate), $end['offset'], $end['comment']).substr($bytes, $endOffset + 22));
        try {
            expect(fn () => app(BusinessBackupBundle::class)->validate($duplicatePath, 'zip'))->toThrow(ValidationException::class, 'duplicata');
        } finally {
            unlink($duplicatePath);
        }
        foreach (['max_entries', 'max_total_bytes', 'max_entry_bytes', 'max_manifest_bytes', 'max_workbook_bytes'] as $limit) {
            $previous = config('business_backup.'.$limit);
            config()->set('business_backup.'.$limit, 1);
            expect(fn () => app(BusinessBackupBundle::class)->validate($artifact['path'], 'zip'))->toThrow(ValidationException::class, 'Limite tecnico');
            config()->set('business_backup.'.$limit, $previous);
        }
        expect(BusinessBackupImport::query()->count())->toBe(0)
            ->and(Company::query()->count())->toBe(1);
    } finally {
        @unlink($artifact['path']);
    }
});

it('rejects legacy workbook data inside a V3 bundle with a validation error', function (): void {
    $company = Company::factory()->create();
    $actor = User::factory()->platformAdmin()->create();
    $artifact = app(ExportBusinessBackup::class)->execute($company, $actor, false, false);
    $legacy = app(LegacyBusinessBackup::class)->execute($company, $actor);
    try {
        $zip = new ZipArchive;
        $zip->open($artifact['path']);
        $manifest = json_decode($zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $manifest['package_id'] = $legacy['package_id'];
        $manifest['data']['size'] = filesize($legacy['path']);
        $manifest['data']['sha256'] = hash_file('sha256', $legacy['path']);
        $zip->addFile($legacy['path'], 'data.xlsx');
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $zip->close();
        expect(fn () => app(BusinessBackupBundle::class)->validate($artifact['path'], 'zip'))
            ->toThrow(ValidationException::class, 'Il bundle richiede dati V3.');
        expect(BusinessBackupImport::query()->count())->toBe(0);
    } finally {
        @unlink($artifact['path']);
        @unlink($legacy['path']);
    }
});

it('rebuilds a Draft with copied expenses conditions nested deferrals and proposed container links', function (): void {
    Storage::fake('local');
    $company = Company::factory()->create();
    $actor = User::factory()->platformAdmin()->create();
    $source = Exercise::factory()->for($company)->create(['year' => 2026]);
    $destination = Exercise::factory()->for($company)->create(['year' => 2027]);
    $project = Project::factory()->for($company)->create(['initial_state' => 'open', 'initial_effective_date' => '2026-01-01']);
    $expense = Expense::factory()->forExercise($source)->for($project)->create(['description' => 'Piano da rinviare']);
    $line = ExpenseLine::factory()->for($expense)->create(['amount' => '100.00']);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '40.00']);
    $standalone = Expense::factory()->forExercise($source)->create(['description' => 'Spesa da copiare']);
    ExpenseLine::factory()->for($standalone)->create(['amount' => '10.00']);
    $supplier = Supplier::factory()->for($company)->create();
    $contract = Contract::factory()->for($company)->for($supplier)->create(['contractual_start_date' => '2026-01-01', 'next_expiry_date' => null, 'renewal_anchor_date' => null]);
    $condition = ContractCondition::factory()->forContract($contract)->create(['cycle' => 'monthly', 'valid_from' => '2026-01-01', 'valid_to' => null, 'amount' => '20.00']);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $destination, (string) Str::uuid());
    $projectItem = $proposal->items()->where('project_id', $project->id)->sole();
    $contractItem = $proposal->items()->where('contract_id', $contract->id)->sole();
    app(PlanProjectDeferral::class)->execute($actor, $proposal->refresh(), $projectItem, [
        'source_exercise_id' => $source->id, 'destination_exercise_id' => $destination->id,
        'mode' => 'reprogramming', 'reprogrammed_amount' => '60.00',
        'source_estimate_reductions' => [['source_line_id' => $line->id, 'reduction_amount' => '60.00']],
    ], 'Piano rinviato', (string) Str::uuid(), 0);
    app(PlanContract::class)->execute($actor, $proposal->refresh(), $contractItem, ProposalActionType::ChangeContractEconomics, [
        'condition_id' => $condition->id, 'amount' => '25.00', 'cycle' => 'monthly', 'attribution_mode' => 'cycle_start',
        'requested_date' => '2027-01-15', 'confirmed_effective_date' => '2027-02-01', 'reason' => 'Nuovo accordo',
    ], 'Nuovo accordo', (string) Str::uuid(), 1);
    app(CopyExpenseIntoProposal::class)->execute($actor, $proposal->refresh(), $standalone, (string) Str::uuid(), 2);
    $plannedProject = app(PlanProject::class)->create($actor, $proposal->refresh(), [
        'title' => 'Nuovo Progetto', 'initial_state' => 'planned', 'initial_effective_date' => '2027-01-01', 'exercise_id' => $destination->id,
    ], (string) Str::uuid(), 3);
    $plannedContract = app(PlanContract::class)->create($actor, $proposal->refresh(), [
        'title' => 'Nuovo Contratto', 'supplier_id' => $supplier->id, 'contractual_start_date' => '2027-01-01',
        'exercise_id' => $destination->id, 'automatic_renewal' => false,
    ], (string) Str::uuid(), 4);
    app(PlanContract::class)->execute($actor, $proposal->refresh(), $plannedContract->item, ProposalActionType::AddContractCondition, [
        'cycle' => 'annual', 'attribution_mode' => 'cycle_start', 'amount' => '30.00', 'valid_from' => '2027-01-01',
    ], null, (string) Str::uuid(), 5);
    app(PlanExpense::class)->create($actor, $proposal->refresh(), [
        'description' => 'Spesa del Progetto proposto', 'exercise_id' => $destination->id, 'project_item_id' => $plannedProject->item->proposal_item_id,
        'estimate_lines' => [['proposal_line_id' => (string) Str::uuid(), 'line_id' => null, 'amount' => '15.00', 'annulled' => false]],
    ], null, (string) Str::uuid(), 6);
    app(PlanProposalRelation::class)->execute($actor, $proposal->refresh(), [
        'project_item_id' => $plannedProject->item->proposal_item_id, 'contract_item_id' => $plannedContract->item->proposal_item_id,
    ], (string) Str::uuid(), 7);
    foreach ([$contract, $standalone] as $owner) {
        $path = 'source/'.Str::uuid();
        Storage::disk('local')->put($path, 'binary');
        $factory = $owner instanceof Contract ? Attachment::factory()->forContract($owner) : Attachment::factory()->forExpense($owner);
        $factory->create([
            'uploaded_by_id' => $actor->id, 'storage_path' => $path, 'size_bytes' => 6, 'sha256' => hash('sha256', 'binary')]);
    }
    $artifact = app(ExportBusinessBackup::class)->execute($company, $actor);
    try {
        $package = app(BusinessBackupBundle::class)->validate($artifact['path'], 'zip');
        $restored = app(ImportBusinessBackup::class)->execute($actor, $package, (string) Str::uuid(), 'copy');
        $draft = $restored->proposals()->sole();
        $deferral = $draft->actions()->where('action_type', 'plan_project_deferral')->sole();
        $restoredLine = $restored->expenses()->where('description', 'Piano da rinviare')->sole()->lines()->where('type', 'estimate')->sole();
        $copy = $draft->actions()->where('action_type', 'copy_expense')->sole();
        $link = $draft->actions()->where('action_type', 'link_project_contract')->sole();
        expect($deferral->payload['source_estimate_reductions'][0]['source_line_id'])->toBe($restoredLine->id)
            ->and($copy->payload['source_expense_id'])->toBe($restored->expenses()->where('description', 'Spesa da copiare')->sole()->id)
            ->and($link->payload['project_item_id'])->not->toBe($plannedProject->item->proposal_item_id)
            ->and($draft->items()->where('proposal_item_id', $link->payload['project_item_id'])->count())->toBe(1)
            ->and($restored->attachments()->count())->toBe(2)
            ->and(app(ProposalReadiness::class)->assessProposal($draft)['ready'])->toBeTrue()
            ->and($restored->auditEvents()->count())->toBe(0);
        app(ApproveProposal::class)->execute($actor, $draft->refresh(), (string) Str::uuid());
        expect($draft->refresh()->status->value)->toBe('approved');
    } finally {
        @unlink($artifact['path']);
    }
});

it('rejects invalid Proposal identities terminal facts and Draft replay before persistence', function (): void {
    $company = Company::factory()->create();
    $actor = User::factory()->platformAdmin()->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());
    app(PlanExpense::class)->create($actor, $proposal, [
        'description' => 'Piano valido', 'exercise_id' => $exercise->id,
        'estimate_lines' => [['proposal_line_id' => (string) Str::uuid(), 'line_id' => null, 'amount' => '20.00', 'annulled' => false]],
    ], null, (string) Str::uuid(), 0);
    $machine = app(BusinessBackupCollector::class)->collect($company)['machine'];
    app(ProposalPackageRules::class)->validate($machine);
    $cases = [];
    $cases['approved without Budget'] = $machine;
    $cases['approved without Budget']['_MP2_proposals']['rows'][0][4] = 'approved';
    $cases['approved without Budget']['_MP2_proposals']['rows'][0][7] = $machine['_MP2_proposals']['rows'][0][5];
    $cases['two Drafts'] = $machine;
    $second = $machine['_MP2_proposals']['rows'][0];
    $second[0] = 'PRO-0000000002';
    $cases['two Drafts']['_MP2_proposals']['rows'][] = $second;
    $cases['missing creation'] = $machine;
    $cases['missing creation']['_MP2_proposal_actions']['rows'] = [];
    $cases['orphan reference'] = $machine;
    $payload = json_decode($machine['_MP2_proposal_actions']['rows'][0][6], true, flags: JSON_THROW_ON_ERROR);
    $payload['exercise_ref'] = 'EXE-9999999999';
    $cases['orphan reference']['_MP2_proposal_actions']['rows'][0][6] = json_encode($payload, JSON_THROW_ON_ERROR);
    $cases['cross Proposal Action'] = $machine;
    $second[4] = 'discarded';
    $second[8] = $second[5];
    $second[9] = 'Alternativa';
    $cases['cross Proposal Action']['_MP2_proposals']['rows'][] = $second;
    $cases['cross Proposal Action']['_MP2_proposal_actions']['rows'][0][1] = $second[0];
    foreach ($cases as $invalid) {
        expect(fn () => app(ProposalPackageRules::class)->validate($invalid))->toThrow(ValidationException::class);
    }
    expect(BusinessBackupImport::query()->count())->toBe(0)
        ->and(Company::query()->count())->toBe(1);
});

it('fails a complete export when an inventoried binary is missing or changed', function (): void {
    Storage::fake('local');
    $company = Company::factory()->create();
    $actor = User::factory()->platformAdmin()->create();
    $expense = Expense::factory()->create(['company_id' => $company->id]);
    Attachment::factory()->forExpense($expense)->create(['uploaded_by_id' => $actor->id, 'storage_path' => 'missing.pdf', 'size_bytes' => 4, 'sha256' => hash('sha256', 'good')]);
    expect(fn () => app(ExportBusinessBackup::class)->execute($company, $actor))->toThrow(RuntimeException::class);
    Storage::disk('local')->put('missing.pdf', 'evil');
    expect(fn () => app(ExportBusinessBackup::class)->execute($company, $actor))->toThrow(RuntimeException::class, 'SHA-256');
});
