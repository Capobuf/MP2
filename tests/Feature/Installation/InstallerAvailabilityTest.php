<?php

use App\Installer\Http\Livewire\Installer;
use App\Installer\Steps\CheckRequirements;
use App\Installer\Support\Mp2InstallationStateManager;
use App\Providers\AppServiceProvider;
use Illuminate\Process\Factory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use RelayerCore\LaravelInstaller\Contracts\InstallationStateManager;
use RelayerCore\LaravelInstaller\Contracts\InstallerStep;
use RelayerCore\LaravelInstaller\Services\StepManager;

it('does not intercept testing requests without an installation marker', function () {
    expect(app(InstallationStateManager::class))
        ->toBeInstanceOf(Mp2InstallationStateManager::class)
        ->and(app(InstallationStateManager::class)->isInstalled())->toBeTrue();

    $this->get('/admin/login')->assertOk();
});

it('redirects ordinary requests to the installer when uninstalled', function () {
    $this->app->instance(InstallationStateManager::class, new class implements InstallationStateManager
    {
        public function isInstalled(): bool
        {
            return false;
        }

        public function markInstalled(): void {}
    });

    $this->get('/admin/login')->assertRedirect('/install');
    $this->get('/install')
        ->assertOk()
        ->assertSee('Master Plan IT')
        ->assertDontSee('bookflow', false)
        ->assertDontSee('data-csrf=""', false);

    expect(app('router')->getRoutes()->getByName('installer.index')->getActionName())
        ->toBe(Installer::class);
});

it('blocks the installer when the instance is installed', function () {
    $this->app->instance(InstallationStateManager::class, new class implements InstallationStateManager
    {
        public function isInstalled(): bool
        {
            return true;
        }

        public function markInstalled(): void {}
    });

    $this->get('/install')->assertRedirect('/');
});

it('keeps debug disabled when the application boots as production', function () {
    $this->app->detectEnvironment(fn (): string => 'production');
    config(['app.debug' => true]);

    (new AppServiceProvider($this->app))->boot();

    expect(config('app.debug'))->toBeFalse();
});

it('accepts an unlimited memory limit', function () {
    $step = new class extends CheckRequirements
    {
        public function convertMemory(string $value): int
        {
            return $this->memoryInBytes($value);
        }
    };

    expect($step->convertMemory('-1'))->toBe(PHP_INT_MAX);
});

it('blocks installation when the WeasyPrint version is unsupported', function () {
    config(['reporting.weasyprint_binary' => 'weasyprint']);
    Process::fake(fn (PendingProcess $process) => $process->command === ['weasyprint', '--version']
        ? Process::result('WeasyPrint version 68.0')
        : Process::result());

    try {
        $requirements = app(CheckRequirements::class)->check();

        expect($requirements)->toHaveKey('WeasyPrint 69.0 — versione installata non supportata', false);
    } finally {
        Process::swap(new Factory);
    }
});

it('clears a completed step password before rendering the next step', function (string $completed, string $next) {
    $this->app->instance(InstallationStateManager::class, new class implements InstallationStateManager
    {
        public function isInstalled(): bool
        {
            return false;
        }

        public function markInstalled(): void {}
    });

    $steps = new StepManager;
    foreach ([$completed, $next] as $id) {
        $steps->register(new class($id) implements InstallerStep
        {
            public function __construct(private readonly string $stepId) {}

            public function id(): string
            {
                return $this->stepId;
            }

            public function label(): string
            {
                return $this->stepId;
            }

            public function view(): string
            {
                return 'installer::steps.migrations';
            }

            public function isSkipped(): bool
            {
                return false;
            }

            public function validate(array $data = []): bool
            {
                return true;
            }

            public function process(array $data = []): void {}
        });
    }
    $this->app->instance(StepManager::class, $steps);

    Livewire::test(Installer::class)
        ->set('state.password', 'sensitive-value')
        ->set('state.password_confirmation', 'sensitive-value')
        ->call('next')
        ->assertSet('currentStepId', $next)
        ->assertSet('state.password', '')
        ->assertSet('state.password_confirmation', null);
})->with([
    ['environment', 'database'],
    ['admin', 'scheduler'],
]);
