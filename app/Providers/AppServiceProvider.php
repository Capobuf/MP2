<?php

namespace App\Providers;

use App\Models\ContractCondition;
use App\Models\ContractExerciseClassification;
use App\Models\ContractLifecycleFact;
use App\Models\ContractRenewalConfiguration;
use App\Policies\ContractPolicy;
use App\Support\ExerciseContext;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(ExerciseContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        DatePicker::configureUsing(fn (DatePicker $picker): DatePicker => $picker
            ->native()
            ->extraInputAttributes(['lang' => 'it']));

        Select::configureUsing(fn (Select $select): Select => $select->native(false));

        Gate::policy(ContractCondition::class, ContractPolicy::class);
        Gate::policy(ContractExerciseClassification::class, ContractPolicy::class);
        Gate::policy(ContractLifecycleFact::class, ContractPolicy::class);
        Gate::policy(ContractRenewalConfiguration::class, ContractPolicy::class);
    }
}
