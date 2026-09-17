<?php

use App\Filament\Pages\BusinessDataBackup;
use App\Filament\Pages\CompanyAudit;
use App\Filament\Pages\CompanySettings;
use App\Filament\Pages\ContractDeadlines;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Reports;
use App\Filament\Resources\Proposals\ProposalResource;
use App\Filament\Support\TenantSwitchUrl;
use App\Models\Company;
use App\Models\Proposal;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->companyA = Company::factory()->create();
    $this->companyB = Company::factory()->create();
    $this->actingAs(User::factory()->platformAdmin()->create());
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->companyA->tenantCompany);
});

function tenantSwitchFrom(string $url): void
{
    $request = Request::create($url);
    $route = Route::getRoutes()->match($request);
    $request->setRouteResolver(fn () => $route);
    app()->instance('originalRequest', $request);
}

it('returns to the resource list from every registered resource page without carrying records or filters', function (): void {
    $panel = Filament::getPanel('admin');

    foreach ($panel->getResources() as $resource) {
        foreach ($resource::getPages() as $name => $page) {
            tenantSwitchFrom($resource::getUrl($name, [
                'record' => 123,
                'tableFilters' => ['exercise_id' => ['value' => 456]],
                'tableSearch' => 'Azienda A',
            ], tenant: $this->companyA->tenantCompany));

            $expected = isset($resource::getPages()['index'])
                ? $resource::getUrl('index', tenant: $this->companyB->tenantCompany)
                : Dashboard::getUrl(tenant: $this->companyB->tenantCompany);

            expect(TenantSwitchUrl::forTenant($this->companyB->tenantCompany))->toBe($expected)
                ->and(Filament::getTenant()->is($this->companyA->tenantCompany))->toBeTrue();
        }
    }
});

it('keeps standalone pages available in the new company', function (string $page): void {
    tenantSwitchFrom($page::getUrl(tenant: $this->companyA->tenantCompany));

    $target = TenantSwitchUrl::forTenant($this->companyB->tenantCompany);

    expect($target)->toBe($page::getUrl(tenant: $this->companyB->tenantCompany));
    $this->get($target)->assertOk();
})->with([
    Dashboard::class,
    Reports::class,
    CompanyAudit::class,
    ContractDeadlines::class,
    CompanySettings::class,
    BusinessDataBackup::class,
]);

it('keeps the custom profile page in the new company', function (): void {
    tenantSwitchFrom(route('filament.admin.profile', ['tenant' => $this->companyA->tenantCompany]));

    $target = TenantSwitchUrl::forTenant($this->companyB->tenantCompany);

    expect($target)->toBe(route('filament.admin.profile', ['tenant' => $this->companyB->tenantCompany]));
    $this->get($target)->assertOk();
});

it('falls back to the overview for pages requiring company-specific input or an unrelated route', function (string $source): void {
    tenantSwitchFrom(route($source, ['tenant' => $this->companyA->tenantCompany]));

    expect(TenantSwitchUrl::forTenant($this->companyB->tenantCompany))
        ->toBe(Dashboard::getUrl(tenant: $this->companyB->tenantCompany));
})->with([
    'PDF customizer' => 'filament.admin.pages.reports.pdf.customize',
    'login' => 'filament.admin.auth.login',
]);

it('falls back to the overview when the destination section is not authorized', function (string $page): void {
    $this->actingAs(User::factory()->for($this->companyB)->create());
    tenantSwitchFrom($page::getUrl(tenant: $this->companyA->tenantCompany));

    expect(TenantSwitchUrl::forTenant($this->companyB->tenantCompany))
        ->toBe(Dashboard::getUrl(tenant: $this->companyB->tenantCompany));
})->with([[ProposalResource::class], [CompanySettings::class]]);

it('renders the destination proposal list in the company switcher from both list and detail pages', function (): void {
    $proposal = Proposal::factory()->for($this->companyA)->create();
    $target = ProposalResource::getUrl('index', tenant: $this->companyB->tenantCompany);

    foreach (['index', 'view'] as $page) {
        $this->get(ProposalResource::getUrl($page, ['record' => $proposal], tenant: $this->companyA->tenantCompany))
            ->assertOk()
            ->assertSeeHtml('href="'.$target.'"')
            ->assertDontSeeHtml('href="'.ProposalResource::getUrl('view', ['record' => $proposal], tenant: $this->companyB->tenantCompany).'"');
    }

    $this->get($target)->assertOk();
    $this->get(ProposalResource::getUrl('view', ['record' => $proposal], tenant: $this->companyB->tenantCompany))
        ->assertNotFound();
});
