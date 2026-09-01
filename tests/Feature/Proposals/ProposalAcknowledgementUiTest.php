<?php

use App\Actions\Proposals\InitializeProposal;
use App\Actions\Proposals\ReviewProposalReadiness;
use App\Filament\Resources\Proposals\Pages\ViewProposal;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('shows explicit acknowledgement and exact new-source guidance', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $actor = User::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_PROPOSALS] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
    }
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());
    $expense = Expense::factory()->forExercise($exercise)->create();
    ExpenseLine::factory()->for($expense)->create(['type' => 'actual', 'amount' => '2.00']);
    $proposal = app(ReviewProposalReadiness::class)->execute($actor, $proposal->refresh(), (string) Str::uuid());
    $this->actingAs($actor);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(ViewProposal::class, ['record' => $proposal->getRouteKey()])
        ->assertActionExists('acknowledgeSource')
        ->assertSee('Da Prendere in Visione')
        ->assertSee('Nuova fonte da prendere in visione');
});
