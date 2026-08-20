<?php

namespace App\Providers;

use App\Models\ContractCondition;
use App\Models\ContractExerciseClassification;
use App\Models\ContractLifecycleFact;
use App\Models\ContractRenewalConfiguration;
use App\Policies\ContractPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(ContractCondition::class, ContractPolicy::class);
        Gate::policy(ContractExerciseClassification::class, ContractPolicy::class);
        Gate::policy(ContractLifecycleFact::class, ContractPolicy::class);
        Gate::policy(ContractRenewalConfiguration::class, ContractPolicy::class);
    }
}
