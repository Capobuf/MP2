<?php

namespace App\Providers;

use App\Installer\Http\Livewire\Installer as Mp2Installer;
use App\Installer\Support\Mp2InstallationStateManager;
use App\Models\ContractCondition;
use App\Models\ContractExerciseClassification;
use App\Models\ContractLifecycleFact;
use App\Models\ContractRenewalConfiguration;
use App\Policies\ContractPolicy;
use App\Support\BudgetContext;
use App\Support\ExerciseContext;
use App\Support\Reporting\EconomicDashboardReadModel;
use Filament\Forms\Components\Select;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use RelayerCore\LaravelInstaller\Contracts\InstallationStateManager;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(InstallationStateManager::class, Mp2InstallationStateManager::class);

        $this->app->scoped(ExerciseContext::class);
        $this->app->scoped(BudgetContext::class);
        $this->app->scoped(EconomicDashboardReadModel::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Livewire::component('installer', Mp2Installer::class);
        Route::get('/install', Mp2Installer::class)
            ->middleware('web')
            ->name('installer.index');

        Route::matched(function (RouteMatched $event): void {
            if ($event->route->getName() === 'installer.progress') {
                $event->route->middleware('web');
            }
        });

        if ($this->app->environment('production')) {
            config(['app.debug' => false]);
        }

        Select::configureUsing(fn (Select $select): Select => $select->native(false));

        Gate::policy(ContractCondition::class, ContractPolicy::class);
        Gate::policy(ContractExerciseClassification::class, ContractPolicy::class);
        Gate::policy(ContractLifecycleFact::class, ContractPolicy::class);
        Gate::policy(ContractRenewalConfiguration::class, ContractPolicy::class);
    }
}
