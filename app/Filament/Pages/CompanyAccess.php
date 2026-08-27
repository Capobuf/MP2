<?php

namespace App\Filament\Pages;

use App\Actions\SyncCompanyCapabilities;
use App\Domain\Company\Capability;
use App\Models\Company;
use App\Models\TenantCompany;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/** @property-read Schema $form */
class CompanyAccess extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $navigationLabel = 'Accessi';

    protected static string|\UnitEnum|null $navigationGroup = 'Amministrazione';

    protected static ?string $title = 'Accessi e capacità';

    protected static ?int $navigationSort = 10;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill([
            'beneficiary_id' => null,
            'capabilities' => [],
            'reason' => null,
        ]);
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;

        return $user instanceof User
            && $company instanceof Company
            && Gate::forUser($user)->allows('managePermissions', $company);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Select::make('beneficiary_id')
                    ->label('Beneficiario')
                    ->options(User::query()
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(fn (User $user): array => [
                            $user->id => "{$user->name} — {$user->email}",
                        ]))
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (mixed $state): void {
                        $user = User::query()->find($state);
                        $tenant = Filament::getTenant();
                        $company = $tenant instanceof TenantCompany ? $tenant->company : null;

                        $this->data['capabilities'] = ($user && $company instanceof Company)
                            ? $user->capabilities()
                                ->where('company_id', $company->id)
                                ->pluck('capability')
                                ->map(fn (Capability $capability): string => $capability->value)
                                ->all()
                            : [];
                        $this->data['reason'] = null;
                    }),
                CheckboxList::make('capabilities')
                    ->label('Capacità')
                    ->options(Capability::options())
                    ->columns(2),
                Textarea::make('reason')
                    ->label('Motivo (opzionale)')
                    ->maxLength(2000),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('company-access-form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label('Salva capacità')
                            ->submit('save'),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        /** @var array{beneficiary_id: int|string, capabilities?: array<int, string>, reason?: string|null} $data */
        $data = $this->form->getState();
        $beneficiary = User::query()->findOrFail($data['beneficiary_id']);
        /** @var User $actor */
        $actor = auth()->user();

        $changes = app(SyncCompanyCapabilities::class)->execute(
            $actor,
            $this->company(),
            $beneficiary,
            $data['capabilities'] ?? [],
            $data['reason'] ?? null,
        );

        Notification::make()
            ->success()
            ->title($changes === 0 ? 'Capacità già aggiornate' : 'Capacità aggiornate')
            ->send();
    }

    private function company(): Company
    {
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;
        abort_unless($company instanceof Company, 404);

        return $company;
    }
}
