<?php

use App\Filament\Pages\BusinessDataBackup;
use App\Filament\Pages\CompanyAudit;
use App\Filament\Pages\CompanySettings;
use App\Filament\Pages\ContractDeadlines;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Reports;
use App\Filament\Resources\Budgets\BudgetResource;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\CostCenters\CostCenterResource;
use App\Filament\Resources\Exercises\ExerciseResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Proposals\ProposalResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('defines the Admin navigation hierarchy without changing routes', function (): void {
    expect(Dashboard::getNavigationLabel())->toBe('Panoramica')
        ->and(Dashboard::getNavigationGroup())->toBeNull()
        ->and(Reports::getNavigationParentItem())->toBe('Panoramica')
        ->and(CompanyAudit::getNavigationParentItem())->toBe('Panoramica')
        ->and(ExpenseResource::getNavigationGroup())->toBeNull()
        ->and(ProjectResource::getNavigationGroup())->toBeNull()
        ->and(ContractResource::getNavigationGroup())->toBeNull()
        ->and(ContractDeadlines::getNavigationParentItem())->toBe('Contratti')
        ->and(ContractDeadlines::getNavigationLabel())->toBe('Scadenze')
        ->and(ExerciseResource::shouldRegisterNavigation())->toBeTrue()
        ->and(ExerciseResource::getNavigationGroup())->toBe('Pianificazione')
        ->and(BudgetResource::getNavigationGroup())->toBe('Pianificazione')
        ->and(ProposalResource::getNavigationGroup())->toBe('Pianificazione')
        ->and(SupplierResource::getNavigationGroup())->toBe('Pianificazione')
        ->and(CostCenterResource::getNavigationGroup())->toBe('Pianificazione')
        ->and(CompanySettings::getNavigationGroup())->toBe('Impostazioni')
        ->and(CompanySettings::getNavigationLabel())->toBe('Azienda')
        ->and(UserResource::getNavigationGroup())->toBe('Impostazioni')
        ->and(BusinessDataBackup::getNavigationGroup())->toBe('Impostazioni');
});

it('builds the authorized Admin navigation in the requested order', function (): void {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    grantTestPermissions([
        'company_id' => $company->id,
        'user' => $user,
        'permissions' => TestPermissions::all(),
    ]);

    $this->actingAs($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($company->tenantCompany);

    $navigation = collect(Filament::getNavigation());
    $rootItems = collect($navigation->first(fn ($group): bool => $group->getLabel() === null)?->getItems());
    $panoramica = $rootItems->first(fn ($item): bool => $item->getLabel() === 'Panoramica');
    $contracts = $rootItems->first(fn ($item): bool => $item->getLabel() === 'Contratti');
    $planning = $navigation->first(fn ($group): bool => $group->getLabel() === 'Pianificazione');
    $settings = $navigation->first(fn ($group): bool => $group->getLabel() === 'Impostazioni');

    expect($rootItems->map(fn ($item): string => $item->getLabel())->all())
        ->toBe(['Panoramica', 'Spese', 'Progetti', 'Contratti'])
        ->and(collect($panoramica?->getChildItems())->map(fn ($item): string => $item->getLabel())->all())
        ->toBe(['Report', 'Timeline'])
        ->and(collect($contracts?->getChildItems())->map(fn ($item): string => $item->getLabel())->all())
        ->toBe(['Scadenze'])
        ->and(collect($planning?->getItems())->map(fn ($item): string => $item->getLabel())->all())
        ->toBe(['Esercizi', 'Budget', 'Proposte', 'Fornitori', 'Centri di Costo'])
        ->and(collect($settings?->getItems())->map(fn ($item): string => $item->getLabel())->all())
        ->toBe(['Azienda', 'Utenti', 'Backup Dati']);
});

it('does not expose unauthorized Settings entries', function (): void {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    grantTestPermissions([
        'company_id' => $company->id,
        'user' => $user,
        'permissions' => TestPermissions::VIEW,
    ]);

    $this->actingAs($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($company->tenantCompany);

    $settings = collect(Filament::getNavigation())
        ->first(fn ($group): bool => $group->getLabel() === 'Impostazioni');

    expect(collect($settings?->getItems())->map(fn ($item): string => $item->getLabel())->all())
        ->toBe(['Backup Dati']);
});

it('keeps the Dashboard heading concise because context is already visible in the topbar', function (): void {
    expect((new Dashboard)->getSubheading())->toBeNull();
});

it('provides an Italian assistive label for breadcrumbs', function (): void {
    app()->setLocale('it');

    expect(__('filament::components/breadcrumbs.label'))->toBe('Percorso di Navigazione');
});
