<?php

use App\Actions\BusinessBackup\ExportBusinessBackup;
use App\Actions\BusinessBackup\ImportBusinessBackup;
use App\BusinessBackup\V1\BusinessBackupValidator;
use App\Models\BudgetSnapshot;
use App\Models\BudgetSourceRow;
use App\Models\BusinessBackupImport;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Proposal;
use App\Models\Supplier;
use App\Models\SupplierContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('round trips a representative portable business graph and retries idempotently', function (): void {
    $company = Company::factory()->create(['name' => '= Azienda È', 'overspend_note_required' => true]);
    $actor = User::factory()->platformAdmin()->create();
    grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => TestPermissions::VIEW]);
    $longNotes = str_repeat('è', 16000);
    $supplier = Supplier::factory()->for($company)->create(['legal_name' => '+ Fornitore', 'notes' => $longNotes]);
    SupplierContact::factory()->for($supplier)->create(['role_tags' => ['Amministrazione', 'Tecnico']]);
    $costCenter = CostCenter::factory()->for($company)->create(['name' => 'Software']);
    $parent = CostCenter::factory()->for($company)->create(['name' => 'IT']);
    $costCenter->update(['parent_id' => $parent->id]);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $expense = Expense::factory()->forExercise($exercise)->create([
        'supplier_id' => $supplier->id, 'direct_cost_center_id' => $costCenter->id, 'description' => '@ Canone',
    ]);
    ExpenseLine::factory()->for($expense)->create(['type' => 'estimate', 'amount' => '123.40', 'quantity' => '2.500000', 'unit_amount' => '49.360000']);
    ExpenseLine::factory()->for($expense)->create(['type' => 'actual', 'amount' => '0.00', 'note' => 'Presenza a saldo zero']);
    $proposal = Proposal::factory()->for($company)->for($exercise)->for($actor, 'creator')->create(['status' => 'approved']);
    $budget = BudgetSnapshot::factory()->for($proposal)->create([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'approved_by_id' => $actor->id,
        'total_approved_allocation' => '123.40',
    ]);
    BudgetSourceRow::factory()->for($budget, 'budget')->create([
        'company_id' => $company->id,
        'origin_id' => $expense->id,
        'origin_key' => $expense->originKey(),
        'cost_center_id' => $costCenter->id,
        'cost_center_label' => 'IT / Software',
        'approved_estimates' => '123.40',
        'approved_allocation' => '123.40',
        'detail_version' => 2,
        'detail' => ['cost_center_lineage' => [
            ['cost_center_id' => $parent->id, 'cost_center_label' => 'IT'],
            ['cost_center_id' => $costCenter->id, 'cost_center_label' => 'Software'],
        ]],
    ]);

    $artifact = app(ExportBusinessBackup::class)->execute($company, $actor);

    try {
        $package = app(BusinessBackupValidator::class)->validate($artifact['path']);
        $restored = app(ImportBusinessBackup::class)->execute($actor, $package);
        $retried = app(ImportBusinessBackup::class)->execute($actor, $package);

        expect($restored->id)->not->toBe($company->id)
            ->and($retried->id)->toBe($restored->id)
            ->and(BusinessBackupImport::query()->count())->toBe(1)
            ->and(Company::query()->count())->toBe(2)
            ->and($restored->name)->toBe($company->name)
            ->and($restored->suppliers()->sole()->legal_name)->toBe('+ Fornitore')
            ->and($restored->suppliers()->sole()->notes)->toBe($longNotes)
            ->and($restored->expenses()->sole()->lines()->count())->toBe(2)
            ->and($restored->expenses()->sole()->lines()->where('type', 'actual')->sole()->amount)->toBe('0.00')
            ->and($restored->costCenters()->where('name', 'Software')->sole()->parent->name)->toBe('IT')
            ->and($restored->budgets()->sole()->rows()->sole()->cost_center_label)->toBe('IT / Software')
            ->and($restored->budgets()->sole()->rows()->sole()->detail['cost_center_lineage'][0]['cost_center_id'])
            ->toBe($restored->costCenters()->where('name', 'IT')->sole()->id)
            ->and($actor->refresh()->hasRole('super_admin'))->toBeTrue()
            ->and($restored->auditEvents()->count())->toBe(0);
    } finally {
        @unlink($artifact['path']);
    }
});
