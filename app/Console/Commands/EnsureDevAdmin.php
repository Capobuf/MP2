<?php

namespace App\Console\Commands;

use App\Actions\Authorization\SyncDefaultRoles;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Validator;

class EnsureDevAdmin extends Command
{
    protected $signature = 'mp2:ensure-dev-admin';

    protected $description = 'Create or synchronize the stable local Filament administrator';

    public function handle(): int
    {
        if ($this->laravel->environment('production')) {
            $this->components->error('Il comando non può essere eseguito in production.');

            return self::FAILURE;
        }

        /** @var array{name: mixed, email: mixed, password: mixed} $credentials */
        $credentials = config('mp2.dev_admin');

        $validator = Validator::make($credentials, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'min:12'],
        ]);

        if ($validator->fails()) {
            $this->components->error('Configurazione DEV_ADMIN non valida: '.$validator->errors()->first());

            return self::FAILURE;
        }

        /** @var array{name: string, email: string, password: string} $validated */
        $validated = $validator->validated();

        $user = User::query()->updateOrCreate(
            ['email' => $validated['email']],
            [
                'name' => $validated['name'],
                'password' => $validated['password'],
                'company_id' => null,
            ],
        );

        Artisan::call('shield:generate', [
            '--all' => true,
            '--option' => 'permissions',
            '--panel' => 'admin',
            '--no-interaction' => true,
        ]);

        app(SyncDefaultRoles::class)();

        Artisan::call('shield:super-admin', [
            '--user' => (string) $user->getKey(),
            '--panel' => 'platform',
            '--no-interaction' => true,
        ]);

        $this->components->info('Amministratore di sviluppo pronto.');

        return self::SUCCESS;
    }
}
