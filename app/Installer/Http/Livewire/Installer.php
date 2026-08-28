<?php

namespace App\Installer\Http\Livewire;

class Installer extends \RelayerCore\LaravelInstaller\Http\Livewire\Installer
{
    /** @var array<string, mixed> */
    public array $state = [
        'host' => '127.0.0.1',
        'port' => '3306',
        'connection' => 'mysql',
        'database' => '',
        'username' => '',
        'password' => '',
        'php_cli' => 'php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
    ];

    public function next(): void
    {
        $completedStep = $this->currentStepId;

        parent::next();

        if ($this->currentStepId === $completedStep) {
            return;
        }

        if (in_array($completedStep, ['environment', 'admin'], true)) {
            $this->state['password'] = '';
            unset($this->state['password_confirmation']);
        }
    }
}
