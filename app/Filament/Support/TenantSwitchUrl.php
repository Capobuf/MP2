<?php

namespace App\Filament\Support;

use App\Filament\Pages\Dashboard;
use App\Models\TenantCompany;
use Filament\Facades\Filament;

use function Filament\Support\original_request;

final class TenantSwitchUrl
{
    public static function forTenant(TenantCompany $tenant): string
    {
        $panel = Filament::getPanel('admin');
        $request = original_request();
        $currentTenant = Filament::getTenant();
        Filament::setTenant($tenant, isQuiet: true);

        try {
            if ($request->routeIs('filament.admin.profile')) {
                return route('filament.admin.profile', ['tenant' => $tenant]);
            }

            foreach ($panel->getResources() as $resource) {
                if (! $request->routeIs($resource::getRouteBaseName($panel).'.*')) {
                    continue;
                }

                return isset($resource::getPages()['index']) && $resource::canAccess()
                    ? $resource::getUrl('index', panel: 'admin', tenant: $tenant)
                    : Dashboard::getUrl(panel: 'admin', tenant: $tenant);
            }

            foreach ($panel->getPages() as $page) {
                if (! $request->routeIs($page::getRouteName($panel))) {
                    continue;
                }

                // Pages outside navigation may require company-specific input (for example a PDF definition).
                if ($page::shouldRegisterNavigation() && $page::canAccess()) {
                    return $page::getUrl(panel: 'admin', tenant: $tenant);
                }

                break;
            }

            return Dashboard::getUrl(panel: 'admin', tenant: $tenant);
        } finally {
            Filament::setTenant($currentTenant, isQuiet: true);
        }
    }
}
