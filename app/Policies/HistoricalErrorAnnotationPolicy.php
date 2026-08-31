<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Exercise;
use App\Models\HistoricalErrorAnnotation;
use App\Models\TenantCompany;
use App\Models\User;

class HistoricalErrorAnnotationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('View:Exercise');
    }

    public function view(User $user, HistoricalErrorAnnotation $annotation): bool
    {
        return $this->canUse($user, $annotation->company, 'View:Exercise');
    }

    public function create(User $user, Company|Exercise|null $context = null): bool
    {
        $company = $context instanceof Exercise ? $context->company : $context;

        return $user->can('AnnotateHistoricalError:Exercise')
            && ($company === null || $this->canAccess($user, $company));
    }

    public function update(User $user, HistoricalErrorAnnotation $annotation): bool
    {
        return false;
    }

    public function delete(User $user, HistoricalErrorAnnotation $annotation): bool
    {
        return false;
    }

    private function canUse(User $user, Company $company, string $permission): bool
    {
        return $this->canAccess($user, $company) && $user->can($permission);
    }

    private function canAccess(User $user, Company $company): bool
    {
        return $company->tenantCompany instanceof TenantCompany
            && $user->canAccessTenant($company->tenantCompany);
    }
}
