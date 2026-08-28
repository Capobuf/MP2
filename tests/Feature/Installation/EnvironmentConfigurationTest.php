<?php

use App\Installer\Steps\ConfigureEnvironment;
use Illuminate\Support\Facades\DB;
use RelayerCore\LaravelInstaller\Contracts\EnvironmentWriter;

function installerEnvironmentState(array $overrides = []): array
{
    $connection = config('database.connections.mysql');

    return array_merge([
        'app_url' => 'https://mp2.example.test',
        'connection' => 'mysql',
        'host' => $connection['host'],
        'port' => (string) $connection['port'],
        'database' => $connection['database'],
        'username' => $connection['username'],
        'password' => $connection['password'],
    ], $overrides);
}

function installerArrayEnvironmentWriter(): EnvironmentWriter
{
    return new class implements EnvironmentWriter
    {
        public array $values = [];

        public bool $saved = false;

        public function get(string $key, $default = null): ?string
        {
            return $this->values[$key] ?? $default;
        }

        public function set(string $key, string $value): void
        {
            $this->values[$key] = $value;
        }

        public function fill(array $values): void
        {
            $this->values = array_merge($this->values, $values);
        }

        public function save(): bool
        {
            $this->saved = true;

            return true;
        }
    };
}

it('accepts only MySQL and connects to the configured existing schema', function () {
    $writer = installerArrayEnvironmentWriter();
    $step = new ConfigureEnvironment($writer);

    expect($step->testConnection(installerEnvironmentState()))->toBeTrue()
        ->and(fn () => $step->validate(installerEnvironmentState(['connection' => 'pgsql'])))
        ->toThrow(InvalidArgumentException::class);
});

it('does not create a missing database', function () {
    $database = 'missing_installer_'.bin2hex(random_bytes(6));
    $step = new ConfigureEnvironment(installerArrayEnvironmentWriter());

    expect(fn () => $step->testConnection(installerEnvironmentState(['database' => $database])))
        ->toThrow(RuntimeException::class);

    $exists = DB::connection('mysql')->table('information_schema.schemata')
        ->where('schema_name', $database)
        ->exists();

    expect($exists)->toBeFalse();
});

it('writes the public URL and current MySQL values including a non-empty password', function () {
    $writer = installerArrayEnvironmentWriter();
    $step = new ConfigureEnvironment($writer);
    $state = installerEnvironmentState(['password' => 'secret with spaces']);

    $step->process($state);

    expect($writer->saved)->toBeTrue()
        ->and($writer->values)->toMatchArray([
            'APP_URL' => 'https://mp2.example.test',
            'DB_CONNECTION' => 'mysql',
            'DB_PASSWORD' => 'secret with spaces',
        ]);
});

it('does not contain the package password submit rewrite', function () {
    $view = file_get_contents(resource_path('views/vendor/installer/installer.blade.php'));

    expect($view)->not->toContain("\$wire.set('state.password'");
});
