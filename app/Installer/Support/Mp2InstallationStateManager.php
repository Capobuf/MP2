<?php

namespace App\Installer\Support;

use RelayerCore\LaravelInstaller\Contracts\InstallationStateManager;
use RuntimeException;

class Mp2InstallationStateManager implements InstallationStateManager
{
    public function isInstalled(): bool
    {
        if (! app()->environment('production')) {
            return true;
        }

        return is_file($this->installedFile());
    }

    public function markInstalled(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        $file = $this->installedFile();

        if (! is_dir(dirname($file))) {
            throw new RuntimeException('La directory del marker di installazione non esiste.');
        }

        $written = @file_put_contents($file, now()->toIso8601String(), LOCK_EX);

        if ($written === false || ! is_file($file)) {
            throw new RuntimeException('Impossibile creare il marker di installazione.');
        }
    }

    private function installedFile(): string
    {
        return (string) (config('installer.installed_file') ?? storage_path('installed'));
    }
}
