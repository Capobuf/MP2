<?php

namespace App\Installer\Steps;

use RelayerCore\LaravelInstaller\Contracts\InstallerStep;
use RuntimeException;

class ConfigureScheduler implements InstallerStep
{
    public function id(): string
    {
        return 'scheduler';
    }

    public function label(): string
    {
        return __('installer::installer.step_scheduler');
    }

    public function view(): string
    {
        return 'installer::steps.scheduler';
    }

    public function isSkipped(): bool
    {
        return false;
    }

    /** @param array<string, mixed> $data */
    public function validate(array $data = []): bool
    {
        $this->phpCommand($data);

        if (($data['scheduler_confirmed'] ?? false) !== true) {
            throw new RuntimeException(__('installer::installer.scheduler_confirmation_error'));
        }

        return true;
    }

    /** @param array<string, mixed> $data */
    public function process(array $data = []): void {}

    public function defaultPhpCommand(): string
    {
        return 'php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
    }

    public function artisanPath(): string
    {
        return realpath(base_path('artisan')) ?: base_path('artisan');
    }

    /** @param array<string, mixed> $data */
    public function commandOnly(array $data = []): string
    {
        return sprintf(
            "%s '%s' schedule:run >> /dev/null 2>&1",
            $this->phpCommand($data),
            str_replace("'", "'\\''", $this->artisanPath()),
        );
    }

    /** @param array<string, mixed> $data */
    public function crontab(array $data = []): string
    {
        return '* * * * * '.$this->commandOnly($data);
    }

    /** @param array<string, mixed> $data */
    private function phpCommand(array $data): string
    {
        $command = trim((string) ($data['php_cli'] ?? $this->defaultPhpCommand()));

        if ($command === '' || preg_match('/[\r\n]/', $command) === 1) {
            throw new RuntimeException(__('installer::installer.scheduler_command_error'));
        }

        return $command;
    }
}
