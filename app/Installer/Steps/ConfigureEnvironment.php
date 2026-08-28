<?php

namespace App\Installer\Steps;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PDO;
use RelayerCore\LaravelInstaller\Contracts\EnvironmentWriter;
use RelayerCore\LaravelInstaller\Contracts\InstallerStep;
use RuntimeException;
use Throwable;

class ConfigureEnvironment implements InstallerStep
{
    public function __construct(private readonly EnvironmentWriter $environment) {}

    public function id(): string
    {
        return 'environment';
    }

    public function label(): string
    {
        return __('installer::installer.step_environment');
    }

    public function view(): string
    {
        return 'installer::steps.environment';
    }

    public function isSkipped(): bool
    {
        return false;
    }

    /** @param array<string, mixed> $data */
    public function validate(array $data = []): bool
    {
        $this->validatedInput($data);

        return $this->testConnection($data);
    }

    /** @param array<string, mixed> $data */
    public function process(array $data = []): void
    {
        $values = $this->validatedInput($data);

        $this->environment->fill([
            'APP_URL' => $values['app_url'],
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $values['host'],
            'DB_PORT' => $values['port'],
            'DB_DATABASE' => $values['database'],
            'DB_USERNAME' => $values['username'],
            'DB_PASSWORD' => $values['password'],
        ]);

        if (! $this->environment->save()) {
            throw new RuntimeException(__('installer::installer.environment_error_write'));
        }

        config([
            'app.url' => $values['app_url'],
            'database.default' => 'mysql',
            'database.connections.mysql.host' => $values['host'],
            'database.connections.mysql.port' => $values['port'],
            'database.connections.mysql.database' => $values['database'],
            'database.connections.mysql.username' => $values['username'],
            'database.connections.mysql.password' => $values['password'],
        ]);

        DB::purge('mysql');
    }

    /** @param array<string, mixed> $data */
    public function testConnection(array $data): bool
    {
        $values = $this->validatedInput($data);
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $values['host'],
            $values['port'],
            $values['database'],
        );

        try {
            new PDO($dsn, $values['username'], $values['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                __('installer::installer.environment_error_connection'),
                previous: $exception,
            );
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{app_url: string, host: string, port: string, database: string, username: string, password: string}
     */
    private function validatedInput(array $data): array
    {
        if (($data['connection'] ?? 'mysql') !== 'mysql') {
            throw new InvalidArgumentException(__('installer::installer.environment_error_connection'));
        }

        $appUrl = trim((string) ($data['app_url'] ?? ''));
        $scheme = parse_url($appUrl, PHP_URL_SCHEME);

        if (filter_var($appUrl, FILTER_VALIDATE_URL) === false || ! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException(__('installer::installer.environment_error_url'));
        }

        $host = trim((string) ($data['host'] ?? ''));
        if ($host === '') {
            throw new InvalidArgumentException(__('installer::installer.environment_error_host'));
        }

        $port = filter_var($data['port'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        if ($port === false) {
            throw new InvalidArgumentException(__('installer::installer.environment_error_port'));
        }

        $database = trim((string) ($data['database'] ?? ''));
        if ($database === '') {
            throw new InvalidArgumentException(__('installer::installer.environment_error_database'));
        }

        $username = trim((string) ($data['username'] ?? ''));
        if ($username === '') {
            throw new InvalidArgumentException(__('installer::installer.environment_error_username'));
        }

        return [
            'app_url' => rtrim($appUrl, '/'),
            'host' => $host,
            'port' => (string) $port,
            'database' => $database,
            'username' => $username,
            'password' => (string) ($data['password'] ?? ''),
        ];
    }
}
