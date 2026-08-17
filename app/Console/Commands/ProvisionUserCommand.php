<?php

namespace App\Console\Commands;

use App\Actions\ProvisionUser;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class ProvisionUserCommand extends Command
{
    protected $signature = 'mp2:provision-user {name} {email}';

    protected $description = 'Crea un utente ordinario da abilitare nelle Aziende';

    public function handle(ProvisionUser $provisionUser): int
    {
        $password = $this->secret('Password');
        $confirmation = $this->secret('Conferma password');

        if ($password !== $confirmation) {
            $this->components->error('Le password non coincidono.');

            return self::FAILURE;
        }

        try {
            $user = $provisionUser->execute([
                'name' => $this->argument('name'),
                'email' => $this->argument('email'),
                'password' => $password,
            ]);
        } catch (ValidationException $exception) {
            $this->components->error($exception->validator->errors()->first());

            return self::FAILURE;
        }

        $this->components->info("Utente {$user->email} creato.");

        return self::SUCCESS;
    }
}
