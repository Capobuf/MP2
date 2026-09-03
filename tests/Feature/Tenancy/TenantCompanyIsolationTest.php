<?php

use App\Actions\Tenancy\DestroyTenantCompany;
use App\Domain\Company\TenantCompanyStatus;
use App\Filament\Resources\Budgets\BudgetResource;
use App\Filament\Resources\Closings\ClosingResource;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\CostCenters\CostCenterResource;
use App\Filament\Resources\Exercises\ExerciseResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Proposals\ProposalResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\Attachment;
use App\Models\BudgetEvidence;
use App\Models\BudgetSnapshot;
use App\Models\Company;
use App\Models\Contract;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Supplier;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('scopes every operational Resource family through the technical Tenant Company relation', function (): void {
    $companyA = Company::factory()->create(['name' => 'Azienda A']);
    $companyB = Company::factory()->create(['name' => 'Azienda B']);
    $actor = User::factory()->create();
    $exerciseA = Exercise::factory()->for($companyA)->create(['year' => 2026]);
    $exerciseB = Exercise::factory()->for($companyB)->create(['year' => 2026]);
    $supplierA = Supplier::factory()->for($companyA)->create();
    $supplierB = Supplier::factory()->for($companyB)->create();
    $costCenterA = CostCenter::factory()->for($companyA)->create();
    $costCenterB = CostCenter::factory()->for($companyB)->create();
    $projectA = Project::factory()->for($companyA)->create();
    $projectB = Project::factory()->for($companyB)->create();
    $contractA = Contract::factory()->for($companyA)->for($supplierA)->create();
    $contractB = Contract::factory()->for($companyB)->for($supplierB)->create();
    $expenseA = Expense::factory()->forExercise($exerciseA)->create();
    $expenseB = Expense::factory()->forExercise($exerciseB)->create();
    $proposalA = Proposal::factory()->for($companyA)->create([
        'exercise_id' => $exerciseA->id,
        'created_by_id' => $actor->id,
    ]);
    $proposalB = Proposal::factory()->for($companyB)->create([
        'exercise_id' => $exerciseB->id,
        'created_by_id' => $actor->id,
    ]);
    $budgetA = BudgetSnapshot::factory()->create([
        'company_id' => $companyA->id,
        'exercise_id' => $exerciseA->id,
        'proposal_id' => $proposalA->id,
        'approved_by_id' => $actor->id,
    ]);
    $budgetB = BudgetSnapshot::factory()->create([
        'company_id' => $companyB->id,
        'exercise_id' => $exerciseB->id,
        'proposal_id' => $proposalB->id,
        'approved_by_id' => $actor->id,
    ]);
    $closingA = closeExerciseFixture($exerciseA, $actor);
    $closingB = closeExerciseFixture($exerciseB, $actor);

    $this->actingAs($actor);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($companyA->tenantCompany);

    $resources = [
        BudgetResource::class => [$budgetA, $budgetB],
        ClosingResource::class => [$closingA, $closingB],
        ContractResource::class => [$contractA, $contractB],
        CostCenterResource::class => [$costCenterA, $costCenterB],
        ExerciseResource::class => [$exerciseA, $exerciseB],
        ExpenseResource::class => [$expenseA, $expenseB],
        ProjectResource::class => [$projectA, $projectB],
        ProposalResource::class => [$proposalA, $proposalB],
        SupplierResource::class => [$supplierA, $supplierB],
    ];

    foreach ($resources as $resource => [$owned, $foreign]) {
        expect($owned->tenantCompany->is($companyA->tenantCompany))->toBeTrue()
            ->and($foreign->tenantCompany->is($companyB->tenantCompany))->toBeTrue()
            ->and($resource::getEloquentQuery()->pluck('id')->all())->toBe([$owned->id]);
    }
});

it('returns no tenant-owned file or report metadata after the Tenant is archived', function (): void {
    Storage::fake('local');
    $company = Company::factory()->create(['name' => 'Azienda Riservata']);
    $viewer = User::factory()->create();
    grantTestPermissions([
        'company_id' => $company->id,
        'user' => $viewer,
        'permissions' => TestPermissions::VIEW,
    ]);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $supplier = Supplier::factory()->for($company)->create();
    $contract = Contract::factory()->for($company)->for($supplier)->create();
    $attachment = Attachment::factory()->forContract($contract)->create([
        'storage_disk' => 'local',
        'storage_path' => 'tenant/attachment.pdf',
        'original_name' => 'nome-riservato.pdf',
    ]);
    Storage::disk('local')->put($attachment->storage_path, 'contenuto riservato');
    $proposal = Proposal::factory()->for($company)->create([
        'exercise_id' => $exercise->id,
        'created_by_id' => $viewer->id,
    ]);
    $budget = BudgetSnapshot::factory()->create([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'proposal_id' => $proposal->id,
        'approved_by_id' => $viewer->id,
    ]);
    $evidence = BudgetEvidence::query()->create([
        'company_id' => $company->id,
        'budget_snapshot_id' => $budget->id,
        'storage_disk' => 'local',
        'storage_path' => 'tenant/evidence.pdf',
        'original_name' => 'evidenza-riservata.pdf',
        'media_type' => 'application/pdf',
        'size_bytes' => 18,
        'sha256' => hash('sha256', 'evidenza riservata'),
    ]);
    Storage::disk('local')->put($evidence->storage_path, 'evidenza riservata');

    $company->tenantCompany->update(['status' => TenantCompanyStatus::Archived]);
    $this->actingAs($viewer);

    $this->get(route('attachments.download', $attachment))
        ->assertNotFound()
        ->assertDontSee('nome-riservato.pdf');
    $this->get(route('budget-evidence.download', $evidence))
        ->assertNotFound()
        ->assertDontSee('evidenza-riservata.pdf');
    $this->get(route('reports.pdf.download', ['definition' => [
        'company_id' => $company->id,
        'kind' => 'suppliers',
        'exercise_id' => $exercise->id,
        'filters' => [],
    ]]))
        ->assertForbidden()
        ->assertDontSee('Azienda Riservata');
});

it('denies every former Tenant URL after destruction while another Tenant remains usable', function (): void {
    Storage::fake('local');
    $target = Company::factory()->create(['name' => 'Eliminata']);
    $other = Company::factory()->create(['name' => 'Conservata']);
    $platform = User::factory()->platformAdmin()->create();

    $exercise = Exercise::factory()->for($target)->create(['year' => 2026]);
    $contract = Contract::factory()->for($target)->create();
    $attachment = Attachment::factory()->forContract($contract)->create([
        'storage_disk' => 'local',
        'storage_path' => 'destroyed/attachment.pdf',
        'uploaded_by_id' => $platform->id,
    ]);
    Storage::disk('local')->put($attachment->storage_path, 'attachment');
    $proposal = Proposal::factory()->for($target)->create([
        'exercise_id' => $exercise->id,
        'created_by_id' => $platform->id,
    ]);
    $budget = BudgetSnapshot::factory()->create([
        'company_id' => $target->id,
        'exercise_id' => $exercise->id,
        'proposal_id' => $proposal->id,
        'approved_by_id' => $platform->id,
    ]);
    $evidence = BudgetEvidence::query()->create([
        'company_id' => $target->id,
        'budget_snapshot_id' => $budget->id,
        'storage_disk' => 'local',
        'storage_path' => 'destroyed/evidence.pdf',
        'original_name' => 'evidence.pdf',
        'media_type' => 'application/pdf',
        'size_bytes' => 8,
        'sha256' => hash('sha256', 'evidence'),
    ]);
    Storage::disk('local')->put($evidence->storage_path, 'evidence');
    Contract::factory()->for($other)->create();

    app(DestroyTenantCompany::class)->execute($platform, $target->tenantCompany, true, true);
    $this->actingAs($platform);

    $this->get(route('attachments.download', ['attachment' => $attachment->id]))->assertNotFound();
    $this->get(route('budget-evidence.download', ['evidence' => $evidence->id]))->assertNotFound();
    $this->get(route('reports.pdf.download', ['definition' => [
        'company_id' => $target->id,
        'kind' => 'suppliers',
        'exercise_id' => $exercise->id,
        'filters' => [],
    ]]))->assertNotFound();
    $this->get("/admin/{$target->id}/contracts")->assertNotFound();
    $this->get("/admin/{$other->id}/contracts")->assertOk();
});
