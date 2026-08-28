<?php

namespace App\Installer\Steps;

class CheckRequirements extends \RelayerCore\LaravelInstaller\Steps\CheckRequirements
{
    protected function memoryInBytes(string $value): int
    {
        if (trim($value) === '-1') {
            return PHP_INT_MAX;
        }

        return parent::memoryInBytes($value);
    }
}
