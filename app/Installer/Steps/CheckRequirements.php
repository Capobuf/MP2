<?php

namespace App\Installer\Steps;

use App\Support\Reporting\WeasyPrintRuntime;

class CheckRequirements extends \RelayerCore\LaravelInstaller\Steps\CheckRequirements
{
    /** @return array<string, bool> */
    public function check(): array
    {
        $requirements = parent::check();
        $status = app(WeasyPrintRuntime::class)->status();
        $label = $status['available']
            ? 'WeasyPrint '.$status['version'].' (versione supportata)'
            : 'WeasyPrint 69.0 — '.$this->failureLabel($status['reason']);
        $requirements[$label] = $status['available'];

        return $requirements;
    }

    protected function memoryInBytes(string $value): int
    {
        if (trim($value) === '-1') {
            return PHP_INT_MAX;
        }

        return parent::memoryInBytes($value);
    }

    private function failureLabel(?string $reason): string
    {
        return match ($reason) {
            'not_executable' => 'binario non eseguibile',
            'unsupported_version' => 'versione installata non supportata',
            'timeout' => 'verifica scaduta',
            default => 'binario mancante',
        };
    }
}
