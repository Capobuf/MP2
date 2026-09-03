<?php

namespace App\Filament\Pages;

use App\Actions\UpdateCompanySettings;
use App\Domain\Company\ClosingUnclassifiedPolicy;
use App\Models\Company;
use App\Models\TenantCompany;
use App\Models\User;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use DateTimeZone;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

/** @property-read Schema $form */
class CompanySettings extends Page
{
    use HasPageShield;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $navigationLabel = 'Azienda';

    protected static string|\UnitEnum|null $navigationGroup = 'Impostazioni';

    protected static ?string $title = 'Impostazioni Azienda';

    protected static ?int $navigationSort = 10;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public ?string $previewedTimezone = null;

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->fillSettings();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Toggle::make('overspend_note_required')
                    ->label('Nota di Sovraspesa Obbligatoria'),
                FileUpload::make('logo')
                    ->label('Logo Aziendale')
                    ->helperText('PNG o JPEG, massimo 2 MB. Il logo viene usato nei PDF aziendali.')
                    ->disk('local')
                    ->visibility('private')
                    ->image()
                    ->acceptedFileTypes(['image/png', 'image/jpeg'])
                    ->maxSize(2048)
                    ->storeFiles(false)
                    ->preventFilePathTampering(false),
                Select::make('unclassified_closing_policy')
                    ->label('Policy Non Classificato alla Chiusura')
                    ->options(ClosingUnclassifiedPolicy::options())
                    ->required(),
                Select::make('timezone')
                    ->label('Fuso Orario Aziendale')
                    ->options(array_combine(
                        DateTimeZone::listIdentifiers(),
                        DateTimeZone::listIdentifiers(),
                    ))
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (): mixed => $this->previewedTimezone = null),
                Placeholder::make('timezone_preview')
                    ->label('Anteprima Impatto')
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
                            ->label('Anteprima Fuso Orario')
                            ->action('previewTimezone'),
                        Action::make('save')
                            ->label('Salva Impostazioni')
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
                ? 'Il Fuso Orario Non Cambia'
                : 'Anteprima Completata: Nessun Evento Interessato')
            ->send();
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        /** @var array{overspend_note_required: bool, unclassified_closing_policy: string, timezone: string, logo?: mixed} $data */
        $data = $this->form->getState();
        /** @var User $actor */
        $actor = auth()->user();
        $company = $this->company();

        if (($data['logo'] ?? null) === $company->logo_path) {
            unset($data['logo']);
        }

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
            ->title($changes === 0 ? 'Impostazioni Già Aggiornate' : 'Impostazioni Aggiornate')
            ->send();
    }

    private function fillSettings(): void
    {
        $company = $this->company();
        $this->form->fill([
            'overspend_note_required' => $company->overspend_note_required,
            'unclassified_closing_policy' => $company->closingUnclassifiedPolicy()->value,
            'timezone' => $company->timezone,
            'logo' => $company->logo_path,
        ]);
    }

    private function company(): Company
    {
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;
        abort_unless($company instanceof Company, 404);

        return $company;
    }
}
