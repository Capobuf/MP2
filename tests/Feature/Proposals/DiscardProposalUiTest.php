<?php

use App\Actions\Proposals\InitializeProposal;
use App\Domain\Company\Capability;
use App\Filament\Resources\Proposals\Pages\ViewProposal;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Exercise;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('discards from the proposal page and leaves terminal history readable', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $actor = User::factory()->create();
    foreach ([Capability::View, Capability::ManageProposals] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => $capability]);
    }
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());
    $this->actingAs($actor);
    Filament::setTenant($company);

    Livewire::test(ViewProposal::class, ['record' => $proposal->id])
        ->assertActionExists('discardProposal')
        ->callAction('discardProposal', ['reason' => 'Non più necessaria'])
        ->assertHasNoActionErrors();

    expect($proposal->fresh()->status->value)->toBe('discarded');
    Livewire::test(ViewProposal::class, ['record' => $proposal->id])
        ->assertActionHidden('discardProposal')
        ->assertSee('Scartata')
        ->assertSee('Storico decisioni');
});
