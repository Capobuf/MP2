<?php

use App\Installer\Callbacks\FinalizeInstallation;
use App\Installer\Callbacks\PromotePlatformAdmin;
use App\Installer\Support\Mp2InstallationStateManager;
use App\Models\User;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use RelayerCore\LaravelInstaller\Contracts\EnvironmentWriter;
use RelayerCore\LaravelInstaller\Contracts\InstallationStateManager;
use RelayerCore\LaravelInstaller\Http\Livewire\Installer;
use RelayerCore\LaravelInstaller\Services\StepManager;

uses(RefreshDatabase::class);

it('promotes the native installer user to platform administrator', function () {
    $user = User::factory()->create(['is_platform_admin' => false]);

    app(PromotePlatformAdmin::class)($user);

    expect($user->refresh()->is_platform_admin)->toBeTrue();
});

it('keeps the native password validation without advertising an invented policy', function () {
    $view = file_get_contents(resource_path('views/vendor/installer/steps/admin.blade.php'));

    expect($view)
        ->not->toContain('Min. 8')
        ->not->toContain('score')
        ->not->toContain('Very Strong');
});

it('rejects a direct finish call before the configured pipeline is complete', function () {
    $state = new class implements InstallationStateManager
    {
        public bool $marked = false;

        public function isInstalled(): bool
        {
            return false;
        }

        public function markInstalled(): void
        {
            $this->marked = true;
        }
    };
    $this->app->instance(InstallationStateManager::class, $state);

    expect(fn () => Livewire::test(Installer::class)->call('finish'))
        ->toThrow(RuntimeException::class, 'pipeline')
        ->and($state->marked)->toBeFalse();
});

it('generates and verifies a new application key after all server-side guards pass', function () {
    User::factory()->platformAdmin()->create();

    $steps = array_keys(app(StepManager::class)->getSteps(false));
    session()->put('installer.progress', $steps);

    $originalKey = (string) config('app.key');
    $environmentFile = 'storage/framework/installer-finalization-test.env';
    $environmentPath = base_path($environmentFile);
    $database = (string) config('database.connections.mysql.database');

    File::put($environmentPath, implode("\n", [
        'APP_KEY='.$originalKey,
        'DB_CONNECTION=mysql',
        'DB_HOST='.(string) config('database.connections.mysql.host'),
        'DB_PORT='.(string) config('database.connections.mysql.port'),
        'DB_DATABASE='.$database,
        'DB_USERNAME='.(string) config('database.connections.mysql.username'),
        'DB_PASSWORD='.(string) config('database.connections.mysql.password'),
        '',
    ]));
    $this->app->loadEnvironmentFrom($environmentFile);
    $this->app->instance(EnvironmentWriter::class, new class($database) implements EnvironmentWriter
    {
        public function __construct(private readonly string $database) {}

        public function get(string $key, $default = null): ?string
        {
            return match ($key) {
                'DB_CONNECTION' => 'mysql',
                'DB_HOST' => (string) config('database.connections.mysql.host'),
                'DB_PORT' => (string) config('database.connections.mysql.port'),
                'DB_DATABASE' => $this->database,
                'DB_USERNAME' => (string) config('database.connections.mysql.username'),
                default => $default,
            };
        }

        public function set(string $key, string $value): void {}

        public function fill(array $values): void {}

        public function save(): bool
        {
            return true;
        }
    });

    try {
        app(FinalizeInstallation::class)(['scheduler_confirmed' => true]);

        $finalKey = (string) config('app.key');
        $decodedKey = base64_decode(substr($finalKey, 7), true);

        expect($finalKey)->not->toBe($originalKey)
            ->and(str_starts_with($finalKey, 'base64:'))->toBeTrue()
            ->and($decodedKey)->not->toBeFalse()
            ->and(Encrypter::supported($decodedKey, (string) config('app.cipher')))->toBeTrue()
            ->and(File::get($environmentPath))->toContain('APP_KEY='.$finalKey);
    } finally {
        $this->app->loadEnvironmentFrom('.env');
        config(['app.key' => $originalKey]);
        File::delete($environmentPath);
    }
});

it('fails explicitly when the production marker cannot be written', function () {
    $this->app->detectEnvironment(fn (): string => 'production');
    config(['installer.installed_file' => storage_path('missing-directory/installed')]);

    expect(fn () => (new Mp2InstallationStateManager)->markInstalled())
        ->toThrow(RuntimeException::class, 'directory');
});

it('creates and recognizes a verified production marker', function () {
    $this->app->detectEnvironment(fn (): string => 'production');
    $marker = storage_path('framework/installer-marker-test');
    config(['installer.installed_file' => $marker]);
    File::delete($marker);

    try {
        $manager = new Mp2InstallationStateManager;
        $manager->markInstalled();

        expect(File::isFile($marker))->toBeTrue()
            ->and($manager->isInstalled())->toBeTrue();
    } finally {
        File::delete($marker);
    }
});
