<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ProvisionUser
{
    /** @param array{name?: mixed, email?: mixed, password?: mixed} $input */
    public function execute(array $input): User
    {
        if (is_string($input['name'] ?? null)) {
            $input['name'] = trim($input['name']);
        }

        /** @var array{name: string, email: string, password: string} $validated */
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique(User::class, 'email')],
            'password' => ['required', 'string', 'min:12'],
        ])->validate();

        return User::query()->create([
            ...$validated,
            'is_platform_admin' => false,
        ]);
    }
}
