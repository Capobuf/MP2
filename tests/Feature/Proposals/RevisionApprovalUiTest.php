<?php

use App\Actions\Proposals\InitializeProposal;
use App\Filament\Resources\Proposals\Pages\ViewProposal;
use App\Models\BudgetSnapshot;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Proposal;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('shows next-version approval and required revision reason', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $actor = User::factory()->create();
    foreach ([TestPermissions::MANAGE_PROPOSALS, TestPermissions::APPROVE_BUDGET] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
    }
    $initial = Proposal::factory()->for($company)->for($exercise)->for($actor, 'creator')->create();
    $initial->update(['status' => 'approved', 'approved_by_id' => $actor->id, 'approved_at' => now(), 'approval_operation_id' => (string) Str::uuid()]);
    BudgetSnapshot::factory()->for($initial)->create(['company_id' => $company->id, 'exercise_id' => $exercise->id, 'approved_by_id' => $actor->id]);
    $revision = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());
    grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => TestPermissions::VIEW]);
    $this->actingAs($actor);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(ViewProposal::class, ['record' => $revision->id])
        ->assertActionExists('approveBudget')
        ->mountAction('approveBudget')
        ->assertSchemaComponentExists('reason')
        ->assertSee('Budget v2');
});
