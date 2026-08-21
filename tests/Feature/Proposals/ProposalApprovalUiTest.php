<?php

use App\Actions\Proposals\InitializeProposal;
use App\Domain\Company\Capability;
use App\Filament\Resources\Budgets\BudgetResource;
use App\Filament\Resources\Proposals\Pages\ViewProposal;
use App\Models\BudgetSnapshot;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('approves an aligned proposal with new evidence and redirects to budget', function (): void {
    Storage::fake('local');
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $user = User::factory()->create();
    foreach ([Capability::View, Capability::ManageProposals, Capability::ApproveBudget] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'capability' => $capability]);
    }
    Expense::factory()->forExercise($exercise)->create();
    $proposal = app(InitializeProposal::class)->execute($user, $company, $exercise, (string) Str::uuid());
    $this->actingAs($user);
    Filament::setTenant($company);
    $component = Livewire::test(ViewProposal::class, ['record' => $proposal->id])
        ->assertActionExists('approveBudget')->assertSee('Allocato base')->assertSee('Allocato risultante')->assertSee('Sorgenti interessate')->assertSee('Budget che restano invariati');
    $approvalOperationId = $component->get('approvalOperationId');
    $component->mountAction('approveBudget')->assertSchemaComponentExists('final_impact');
    expect($component->get('approvalOperationId'))->toBe($approvalOperationId)->and(Str::isUuid($approvalOperationId))->toBeTrue();
    $component->fillForm(['external_subject' => 'Direzione', 'external_venue' => 'Verbale', 'reason' => 'ok', 'new_evidence' => UploadedFile::fake()->createWithContent('delibera.txt', 'approvata'), 'attachment_ids' => []])->callMountedAction()->assertHasNoActionErrors();
    $budget = BudgetSnapshot::query()->sole();
    expect($budget->evidence)->toHaveCount(2);
    expect(BudgetResource::getUrl('view', ['record' => $budget], tenant: $company))->toContain('/budgets/'.$budget->id);
});

it('hides approval without capability and disables it when readiness is stale', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $manager = User::factory()->create();
    foreach ([Capability::View, Capability::ManageProposals] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $manager->id, 'capability' => $capability]);
    } $expense = Expense::factory()->forExercise($exercise)->create();
    $proposal = app(InitializeProposal::class)->execute($manager, $company, $exercise, (string) Str::uuid());
    $this->actingAs($manager);
    Filament::setTenant($company);
    Livewire::test(ViewProposal::class, ['record' => $proposal->id])->assertActionHidden('approveBudget');
    CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $manager->id, 'capability' => Capability::ApproveBudget]);
    $expense->increment('revision');
    Livewire::test(ViewProposal::class, ['record' => $proposal->id])->assertActionDisabled('approveBudget');
});
