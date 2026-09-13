<?php

use App\Actions\MasterData\MoveCostCenter;
use App\Actions\MasterData\SetCostCenterArchived;
use App\Domain\Company\AuditEventType;
use App\Domain\CostCenters\CostCenterHierarchy;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractExerciseClassification;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;
use App\Models\Proposal;
use App\Models\ProposalItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

function grantCostCenterHierarchyManagement(User $user, Company $company): void
{
    grantTestPermissions([
        'company_id' => $company->id,
        'user' => $user,
        'permissions' => TestPermissions::MANAGE_MASTER_DATA,
    ]);
}

it('supports roots multiple children arbitrary depth moves and return to root', function (): void {
    $company = Company::factory()->create();
    $root = CostCenter::factory()->for($company)->create(['name' => 'IT']);
    $software = CostCenter::factory()->for($company)->create(['name' => 'Software', 'parent_id' => $root->id]);
    $hardware = CostCenter::factory()->for($company)->create(['name' => 'Hardware', 'parent_id' => $root->id]);
    $saas = CostCenter::factory()->for($company)->create(['name' => 'SaaS', 'parent_id' => $software->id]);
    $licenses = CostCenter::factory()->for($company)->create(['name' => 'Licenze', 'parent_id' => $saas->id]);
    $hierarchy = CostCenterHierarchy::forCompany((int) $company->id);

    expect($root->parent_id)->toBeNull()
        ->and($root->children()->orderBy('id')->pluck('id')->all())->toBe([$software->id, $hardware->id])
        ->and($hierarchy->path((int) $licenses->id))->toBe('IT / Software / SaaS / Licenze')
        ->and($hierarchy->ancestorIds((int) $licenses->id))->toBe([$root->id, $software->id, $saas->id, $licenses->id])
        ->and($hierarchy->descendantIds((int) $root->id))->toEqualCanonicalizing([$root->id, $software->id, $hardware->id, $saas->id, $licenses->id])
        ->and($hierarchy->belongsToSubtree((int) $licenses->id, (int) $root->id))->toBeTrue();

    $licenses->update(['parent_id' => $hardware->id]);
    expect(CostCenterHierarchy::forCompany((int) $company->id)->path((int) $licenses->id))->toBe('IT / Hardware / Licenze');

    $licenses->update(['parent_id' => null]);
    expect($licenses->refresh()->parent_id)->toBeNull()
        ->and(CostCenterHierarchy::forCompany((int) $company->id)->path((int) $licenses->id))->toBe('Licenze');
});

it('rejects self two-node deep and cross-company parent assignments server-side', function (): void {
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $a = CostCenter::factory()->for($company)->create(['name' => 'A']);
    $b = CostCenter::factory()->for($company)->create(['name' => 'B', 'parent_id' => $a->id]);
    $c = CostCenter::factory()->for($company)->create(['name' => 'C', 'parent_id' => $b->id]);
    $other = CostCenter::factory()->for($otherCompany)->create(['name' => 'Altro']);

    expect(fn () => $a->update(['parent_id' => $a->id]))->toThrow(ValidationException::class)
        ->and(fn () => $a->update(['parent_id' => $b->id]))->toThrow(ValidationException::class)
        ->and(fn () => $a->update(['parent_id' => $c->id]))->toThrow(ValidationException::class)
        ->and(fn () => $a->update(['parent_id' => $other->id]))->toThrow(ValidationException::class);

    expect($a->refresh()->parent_id)->toBeNull()
        ->and($b->refresh()->parent_id)->toBe($a->id)
        ->and($c->refresh()->parent_id)->toBe($b->id);
});

it('keeps duplicate names and active parents selectable after adding children', function (): void {
    $company = Company::factory()->create();
    $first = CostCenter::factory()->for($company)->create(['name' => 'IT']);
    $second = CostCenter::factory()->for($company)->create(['name' => 'IT']);
    CostCenter::factory()->for($company)->create(['name' => 'Software', 'parent_id' => $first->id]);

    $options = CostCenterHierarchy::forCompany((int) $company->id)->options();

    expect($options)->toHaveKeys([$first->id, $second->id])
        ->and($options[$first->id])->toBe('IT')
        ->and($options[$second->id])->toBe('IT');
});

it('keeps one direct classification while contained expenses inherit their owner classification', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $parent = CostCenter::factory()->for($company)->create(['name' => 'IT']);
    CostCenter::factory()->for($company)->create(['name' => 'Software', 'parent_id' => $parent->id]);
    $standalone = Expense::factory()->forExercise($exercise)->create(['direct_cost_center_id' => $parent->id]);
    $project = Project::factory()->for($company)->create();
    $projectClassification = ProjectExerciseClassification::factory()->forProjectAndExercise($project, $exercise)->create(['cost_center_id' => $parent->id]);
    $projectExpense = Expense::factory()->forExercise($exercise)->create(['project_id' => $project->id, 'direct_cost_center_id' => null]);
    $contract = Contract::factory()->for($company)->create();
    $contractClassification = ContractExerciseClassification::factory()->forContractAndExercise($contract, $exercise)->create(['cost_center_id' => $parent->id]);
    $contractExpense = Expense::factory()->forExercise($exercise)->create(['contract_id' => $contract->id, 'direct_cost_center_id' => null]);

    expect($standalone->direct_cost_center_id)->toBe($parent->id)
        ->and($project->classifications()->count())->toBe(1)
        ->and($projectClassification->cost_center_id)->toBe($parent->id)
        ->and($contract->classifications()->count())->toBe(1)
        ->and($contractClassification->cost_center_id)->toBe($parent->id)
        ->and($projectExpense->costCenterLabel())->toContain('IT')->toContain('ereditata dal Progetto')
        ->and($contractExpense->costCenterLabel())->toContain('IT')->toContain('ereditata dal Contratto')
        ->and($projectExpense->direct_cost_center_id)->toBeNull()
        ->and($contractExpense->direct_cost_center_id)->toBeNull();
});

it('archives a parent without moving or archiving children and keeps its path readable', function (): void {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    grantCostCenterHierarchyManagement($actor, $company);
    $parent = CostCenter::factory()->for($company)->create(['name' => 'IT']);
    $child = CostCenter::factory()->for($company)->create(['name' => 'Software', 'parent_id' => $parent->id]);

    app(SetCostCenterArchived::class)->execute($actor, $parent, true, (string) Str::uuid());
    $hierarchy = CostCenterHierarchy::forCompany((int) $company->id);

    expect($parent->refresh()->isArchived())->toBeTrue()
        ->and($child->refresh()->isArchived())->toBeFalse()
        ->and($child->parent_id)->toBe($parent->id)
        ->and($hierarchy->path((int) $child->id))->toBe('IT / Software')
        ->and($hierarchy->options())->not->toHaveKey($parent->id)
        ->and($hierarchy->options())->toHaveKey($child->id);
});

it('moves a branch once audits zero economic deltas and realigns only affected draft sources', function (): void {
    $actor = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    grantCostCenterHierarchyManagement($actor, $company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $oldParent = CostCenter::factory()->for($company)->create(['name' => 'IT']);
    $newParent = CostCenter::factory()->for($company)->create(['name' => 'Digital']);
    $branch = CostCenter::factory()->for($company)->create(['name' => 'Software', 'parent_id' => $oldParent->id]);
    $unrelated = CostCenter::factory()->for($company)->create(['name' => 'Facilities']);
    $affectedExpense = Expense::factory()->forExercise($exercise)->create(['direct_cost_center_id' => $branch->id]);
    ExpenseLine::factory()->for($affectedExpense)->create(['amount' => '20.00']);
    $unrelatedExpense = Expense::factory()->forExercise($exercise)->create(['direct_cost_center_id' => $unrelated->id]);
    $proposal = Proposal::factory()->for($company)->for($exercise)->create();
    $affectedItem = ProposalItem::factory()->for($proposal)->create([
        'company_id' => $company->id,
        'expense_id' => $affectedExpense->id,
        'baseline_revision' => 0,
        'baseline_fingerprint' => str_repeat('a', 64),
    ]);
    $unrelatedItem = ProposalItem::factory()->for($proposal)->create([
        'company_id' => $company->id,
        'expense_id' => $unrelatedExpense->id,
        'baseline_revision' => 0,
        'baseline_fingerprint' => str_repeat('b', 64),
    ]);
    $operationId = (string) Str::uuid();
    $action = app(MoveCostCenter::class);
    $preview = $action->preview($actor, $branch, (int) $newParent->id);

    $moved = $action->confirm($actor, $branch, $preview, $operationId, 'Riorganizzazione');
    $retried = $action->confirm($actor, $branch, $preview, $operationId, 'Riorganizzazione');
    $event = AuditEvent::query()->sole();

    expect($moved->id)->toBe($branch->id)
        ->and($retried->id)->toBe($branch->id)
        ->and($branch->refresh()->parent_id)->toBe($newParent->id)
        ->and($affectedItem->refresh()->readiness_state->value)->toBe('to_realign')
        ->and($unrelatedItem->refresh()->readiness_state->value)->toBe('aligned')
        ->and($event->eventType())->toBe(AuditEventType::CostCenterMoved)
        ->and($event->previous_value['path'])->toBe('IT / Software')
        ->and($event->new_value['path'])->toBe('Digital / Software')
        ->and($event->affected_exercise_ids)->toBe([$exercise->id])
        ->and($event->allocated_impact_by_exercise)->toBe([(string) $exercise->id => '0.00'])
        ->and($event->actual_impact_by_exercise)->toBe([(string) $exercise->id => '0.00'])
        ->and(AuditEvent::query()->count())->toBe(1);
});

it('does not audit a no-op and rolls a move back when audit persistence fails', function (): void {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    grantCostCenterHierarchyManagement($actor, $company);
    $parent = CostCenter::factory()->for($company)->create();
    $target = CostCenter::factory()->for($company)->create();
    $child = CostCenter::factory()->for($company)->create(['parent_id' => $parent->id]);
    $action = app(MoveCostCenter::class);

    $noOp = $action->preview($actor, $child, (int) $parent->id);
    $action->confirm($actor, $child, $noOp, (string) Str::uuid());
    expect(AuditEvent::query()->count())->toBe(0);

    AuditEvent::creating(fn () => throw new RuntimeException('audit unavailable'));
    $preview = $action->preview($actor, $child, (int) $target->id);

    expect(fn () => $action->confirm($actor, $child, $preview, (string) Str::uuid()))
        ->toThrow(RuntimeException::class, 'audit unavailable');
    expect($child->refresh()->parent_id)->toBe($parent->id)
        ->and(AuditEvent::query()->count())->toBe(0);

    AuditEvent::flushEventListeners();
});
