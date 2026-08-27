<?php

namespace App\Http\Middleware;

use App\Domain\Company\TenantCompanyStatus;
use App\Models\TenantCompany;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantCompanyIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();

        abort_unless(
            $tenant instanceof TenantCompany && $tenant->status() === TenantCompanyStatus::Active,
            404,
        );

        return $next($request);
    }
}
