<?php

namespace App\Filament\Platform\Resources\Roles\Pages;

use App\Actions\Authorization\RecordAuthorizationChange;
use App\Filament\Platform\Resources\Roles\RoleResource;
use App\Models\User;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\EditRole as ShieldEditRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class EditRole extends ShieldEditRole
{
    protected static string $resource = RoleResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    public string $operationId;

    /**
     * @var array<int, array{roles: array<int, string>, permissions: array<int, string>}>
     */
    private array $previousAuthorizations = [];

    public function mount(int|string $record): void
    {
        $this->operationId = (string) Str::uuid();
        parent::mount($record);
    }

    protected function beforeSave(): void
    {
        $role = $this->getRecord();
        abort_unless($role instanceof Role, 404);

        $this->previousAuthorizations = User::query()
            ->whereNotNull('company_id')
            ->whereHas('roles', fn (Builder $query): Builder => $query->whereKey($role->id))
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (User $user): array => [
                $user->id => [
                    'roles' => $user->roles()->pluck('name')->all(),
                    'permissions' => $user->getAllPermissions()->pluck('name')->all(),
                ],
            ])
            ->all();
    }

    protected function afterSave(): void
    {
        parent::afterSave();

        $actor = auth()->user();
        $role = $this->getRecord();
        abort_unless($actor instanceof User && $role instanceof Role, 403);

        $eventSequence = 0;
        foreach ($this->previousAuthorizations as $userId => $previous) {
            $beneficiary = User::query()->findOrFail($userId);
            $event = app(RecordAuthorizationChange::class)->execute(
                $actor,
                $beneficiary,
                $previous['roles'],
                $beneficiary->roles()->pluck('name')->all(),
                $previous['permissions'],
                $beneficiary->getAllPermissions()->pluck('name')->all(),
                $this->operationId,
                $eventSequence,
                'Modifica dei permessi del ruolo globale '.$role->name.'.',
            );

            if ($event !== null) {
                $eventSequence++;
            }
        }

        $this->operationId = (string) Str::uuid();
    }
}
