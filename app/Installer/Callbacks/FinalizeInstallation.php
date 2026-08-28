<?php

namespace App\Installer\Callbacks;

use App\Models\User;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use RelayerCore\LaravelInstaller\Contracts\EnvironmentWriter;
use RelayerCore\LaravelInstaller\Services\StepManager;
use RuntimeException;

class FinalizeInstallation
{
    /**
     * @param  array<string, mixed>  $state
     */
    public function __invoke(array $state = []): void
    {
        $this->assertPipelineCompleted();

        if (! User::query()->where('is_platform_admin', true)->exists()) {
            throw new RuntimeException(__('installer::installer.finalization_admin_error'));
        }

        if (($state['scheduler_confirmed'] ?? false) !== true) {
            throw new RuntimeException(__('installer::installer.scheduler_confirmation_error'));
        }

        $this->assertEnvironmentConfigured();
        $this->generateAndVerifyApplicationKey();
    }

    private function assertPipelineCompleted(): void
    {
        $requiredSteps = array_keys(app(StepManager::class)->getSteps(false));
        $progress = session()->get('installer.progress', []);

        if (
            ! is_array($progress)
            || array_diff($requiredSteps, $progress) !== []
            || end($progress) !== 'scheduler'
        ) {
            throw new RuntimeException(__('installer::installer.finalization_pipeline_error'));
        }
    }

    private function assertEnvironmentConfigured(): void
    {
        $environment = app(EnvironmentWriter::class);
        $database = trim((string) config('database.connections.mysql.database'));
        $requiredValues = [
            'DB_HOST',
            'DB_PORT',
            'DB_DATABASE',
            'DB_USERNAME',
        ];

        if (
            $environment->get('DB_CONNECTION') !== 'mysql'
            || $database === ''
            || $environment->get('DB_DATABASE') !== $database
        ) {
            throw new RuntimeException(__('installer::installer.finalization_environment_error'));
        }

        foreach ($requiredValues as $key) {
            if (trim((string) $environment->get($key)) === '') {
                throw new RuntimeException(__('installer::installer.finalization_environment_error'));
            }
        }
    }

    private function generateAndVerifyApplicationKey(): void
    {
        $previousKey = (string) config('app.key');
        $result = Artisan::call('key:generate', ['--force' => true]);
        $key = (string) config('app.key');
        $decodedKey = str_starts_with($key, 'base64:')
            ? base64_decode(substr($key, 7), true)
            : $key;

        if (
            $result !== 0
            || $key === ''
            || $key === $previousKey
            || $decodedKey === false
            || ! Encrypter::supported($decodedKey, (string) config('app.cipher'))
            || ! $this->environmentFileContains($key)
        ) {
            throw new RuntimeException(__('installer::installer.finalization_key_error'));
        }
    }

    private function environmentFileContains(string $key): bool
    {
        $contents = @file_get_contents(app()->environmentFilePath());

        return is_string($contents)
            && preg_match('/^APP_KEY='.preg_quote($key, '/').'$/m', $contents) === 1;
    }
}
