<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\Authorization\RecordAccountChange;
use App\Actions\Authorization\RecordAuthorizationChange;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Str;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    public string $operationId;

    /** @var array<int, string> */
    private array $previousRoles = [];

    /** @var array<int, string> */
    private array $previousPermissions = [];

    private string $previousName;

    private string $previousEmail;

    public function mount(int|string $record): void
    {
        $this->operationId = (string) Str::uuid();
        parent::mount($record);
    }

    protected function beforeSave(): void
    {
        $beneficiary = $this->getRecord();
        abort_unless($beneficiary instanceof User, 404);

        $this->previousName = $beneficiary->name;
        $this->previousEmail = $beneficiary->email;
        $this->previousRoles = $beneficiary->roles()->pluck('name')->all();
        $this->previousPermissions = $beneficiary->getAllPermissions()->pluck('name')->all();
    }

    protected function afterSave(): void
    {
        $actor = auth()->user();
        $beneficiary = $this->getRecord();
        abort_unless($actor instanceof User && $beneficiary instanceof User, 403);

        $passwordChanged = $beneficiary->wasChanged('password');
        $beneficiary = User::query()->findOrFail($beneficiary->id);
        $authorizationEvent = app(RecordAuthorizationChange::class)->execute(
            $actor,
            $beneficiary,
            $this->previousRoles,
            $beneficiary->roles()->pluck('name')->all(),
            $this->previousPermissions,
            $beneficiary->getAllPermissions()->pluck('name')->all(),
            $this->operationId,
        );

        app(RecordAccountChange::class)->execute(
            $actor,
            $beneficiary,
            $this->previousName,
            $this->previousEmail,
            $passwordChanged,
            $this->operationId,
            $authorizationEvent === null ? 0 : 1,
        );

        $this->operationId = (string) Str::uuid();
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
