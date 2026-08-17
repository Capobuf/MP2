<?php

namespace App\Filament\Pages;

use App\Actions\UpdateCompanySettings;
use App\Domain\Company\ClosingUnclassifiedPolicy;
use App\Models\Company;
use App\Models\User;
use BackedEnum;
use DateTimeZone;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** @property-read Schema $form */
class CompanySettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Impostazioni';

    protected static ?string $title = 'Impostazioni Azienda';

    protected static ?int $navigationSort = 30;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public ?string $previewedTimezone = null;

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->fillSettings();
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $company = Filament::getTenant();

        return $user instanceof User
            && $company instanceof Company
            && Gate::forUser($user)->allows('manageSettings', $company);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Toggle::make('overspend_note_required')
                    ->label('Nota di sovraspesa obbligatoria'),
                Select::make('unclassified_closing_policy')
                    ->label('Policy Non classificato alla Chiusura')
                    ->options(ClosingUnclassifiedPolicy::options())
                    ->required(),
                Select::make('timezone')
                    ->label('Fuso orario aziendale')
                    ->options(array_combine(
                        DateTimeZone::listIdentifiers(),
                        DateTimeZone::listIdentifiers(),
                    ))
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (): mixed => $this->previewedTimezone = null),
                Placeholder::make('timezone_preview')
                    ->label('Anteprima impatto')
                    ->content(fn (): string => $this->previewedTimezone
                        ? 'Nessun evento pianificato rappresentabile in S1 è interessato nella data locale corrente.'
                        : 'Richiedi l’anteprima prima di confermare un nuovo fuso orario.'),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('company-settings-form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('previewTimezone')
                            ->label('Anteprima fuso orario')
                            ->action('previewTimezone'),
                        Action::make('save')
                            ->label('Salva impostazioni')
                            ->submit('save'),
                    ]),
                ]),
        ]);
    }

    public function previewTimezone(): void
    {
        abort_unless(static::canAccess(), 403);
        /** @var array{timezone: string} $data */
        $data = $this->form->getState();
        $this->previewedTimezone = $data['timezone'];

        Notification::make()
            ->info()
            ->title($data['timezone'] === $this->company()->timezone
                ? 'Il fuso orario non cambia'
                : 'Anteprima completata: nessun evento interessato')
            ->send();
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        /** @var array{overspend_note_required: bool, unclassified_closing_policy: string, timezone: string} $data */
        $data = $this->form->getState();
        /** @var User $actor */
        $actor = auth()->user();
        $company = $this->company();

        try {
            $changes = app(UpdateCompanySettings::class)->execute(
                $actor,
                $company,
                $data,
                timezonePreviewConfirmed: $data['timezone'] === $company->timezone
                    || $this->previewedTimezone === $data['timezone'],
            );
        } catch (ValidationException $exception) {
            $message = $exception->errors()['timezone'][0] ?? 'Impostazioni non valide.';
            $this->addError('data.timezone', $message);

            return;
        }

        $this->previewedTimezone = null;
        $company->refresh();
        $this->fillSettings();

        Notification::make()
            ->success()
            ->title($changes === 0 ? 'Impostazioni già aggiornate' : 'Impostazioni aggiornate')
            ->send();
    }

    private function fillSettings(): void
    {
        $company = $this->company();
        $this->form->fill([
            'overspend_note_required' => $company->overspend_note_required,
            'unclassified_closing_policy' => $company->closingUnclassifiedPolicy()->value,
            'timezone' => $company->timezone,
        ]);
    }

    private function company(): Company
    {
        $company = Filament::getTenant();
        abort_unless($company instanceof Company, 404);

        return $company;
    }
}
