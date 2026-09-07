<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\Authorization\RecordAuthorizationChange;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    public string $operationId;

    public function mount(): void
    {
        $this->operationId = (string) Str::uuid();
        parent::mount();
    }

    protected function afterCreate(): void
    {
        $actor = auth()->user();
        $beneficiary = $this->record;
        abort_unless($actor instanceof User && $beneficiary instanceof User, 403);

        app(RecordAuthorizationChange::class)->execute(
            $actor,
            $beneficiary,
            [],
            $beneficiary->roles()->pluck('name')->all(),
            [],
            $beneficiary->getAllPermissions()->pluck('name')->all(),
            $this->operationId,
        );

        $this->operationId = (string) Str::uuid();
    }
}
