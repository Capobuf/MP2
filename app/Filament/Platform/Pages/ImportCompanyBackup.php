<?php

namespace App\Filament\Platform\Pages;

use App\Actions\BusinessBackup\ImportBusinessBackup;
use App\BusinessBackup\BackupPreview;
use App\BusinessBackup\V1\BusinessBackupValidator;
use App\Filament\Platform\Resources\TenantCompanies\TenantCompanyResource;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/** @property-read Schema $form */
final class ImportCompanyBackup extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?string $navigationLabel = 'Importa Azienda da backup';

    protected static ?string $title = 'Importa Azienda da backup';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array<string, mixed>|null */
    public ?array $previewData = null;

    public ?string $validatedPackageId = null;

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->form->fill();
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->is_platform_admin;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            FileUpload::make('backup')
                ->label('Workbook MP2 (.xlsx)')
                ->disk('local')
                ->directory('business-backup-uploads')
                ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                ->required()
                ->validationMessages([
                    'required' => 'Caricare un workbook XLSX.',
                    'mimetypes' => 'Il file deve essere un workbook XLSX.',
                ])
                ->live()
                ->afterStateUpdated(function (): void {
                    $this->invalidateValidatedBackup();
                }),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('business-backup-import-form')
                ->footer([Actions::make([
                    Action::make('validate')->label('Valida e mostra anteprima')->action('validateBackup'),
                ])]),
            Section::make('Anteprima del ripristino')
                ->description('Il backup è valido e può essere ripristinato come una nuova Azienda.')
                ->visible(fn (): bool => $this->previewData !== null)
                ->schema([
                    TextEntry::make('preview_company_name')
                        ->label('Azienda')
                        ->state(fn (): string => $this->previewString('company_name'))
                        ->size('lg')
                        ->weight('bold'),
                    Section::make('Backup')->schema([
                        TextEntry::make('backup_company_name')->label('Azienda')->state(fn (): string => $this->previewString('company_name')),
                        TextEntry::make('backup_exported_at')->label('Esportato il')->state(fn (): string => $this->formattedExportDate()),
                        TextEntry::make('backup_format')->label('Formato')->state(fn (): string => 'MP2 Business Data Backup · V'.$this->previewInt('format_version')),
                        TextEntry::make('backup_timezone')->label('Fuso orario')->state(fn (): string => $this->previewString('company_timezone')),
                    ])->columns(['sm' => 2, 'lg' => 4]),
                    Section::make('Esercizi')->schema([
                        RepeatableEntry::make('preview_exercises')
                            ->label('Esercizi inclusi')
                            ->hiddenLabel()
                            ->state(fn (): array => $this->previewArray('exercises'))
                            ->table([
                                TableColumn::make('Esercizio'),
                                TableColumn::make('Stato'),
                            ])
                            ->schema([
                                TextEntry::make('year')->label('Esercizio'),
                                TextEntry::make('status')->label('Stato')
                                    ->formatStateUsing(fn (mixed $state): string => match ((string) $state) {
                                        'open' => 'Aperto',
                                        'closed' => 'Chiuso',
                                        default => (string) $state,
                                    })
                                    ->badge()
                                    ->color(fn (mixed $state): string => (string) $state === 'open' ? 'success' : 'gray'),
                            ])
                            ->placeholder('Nessun Esercizio incluso.'),
                    ]),
                    Section::make('Contenuto')->schema([
                        TextEntry::make('supplier_count')->label('Fornitori')->state(fn (): int => $this->previewCount('_MP2_suppliers')),
                        TextEntry::make('project_count')->label('Progetti')->state(fn (): int => $this->previewCount('_MP2_projects')),
                        TextEntry::make('contract_count')->label('Contratti')->state(fn (): int => $this->previewCount('_MP2_contracts')),
                        TextEntry::make('expense_count')->label('Spese')->state(fn (): int => $this->previewCount('_MP2_expenses')),
                        TextEntry::make('budget_count')->label('Budget')->state(fn (): int => $this->previewCount('_MP2_budgets')),
                        TextEntry::make('closing_count')->label('Chiusure')->state(fn (): int => $this->previewCount('_MP2_closings')),
                    ])->columns(['sm' => 2, 'lg' => 3]),
                    Section::make('Valori economici inclusi')->schema([
                        TextEntry::make('budget_total')->label('Totale degli snapshot Budget inclusi')
                            ->state(fn (): string => $this->previewString('budget_total', '0.00'))->money('EUR', locale: 'it'),
                        TextEntry::make('closing_actual_total')->label('Effettivi delle Chiusure incluse')
                            ->state(fn (): string => $this->previewString('closing_actual_total', '0.00'))->money('EUR', locale: 'it'),
                    ])->columns(2),
                    Callout::make(fn (): string => $this->attachmentWarning())
                        ->warning()
                        ->visible(fn (): bool => $this->previewInt('attachment_count') > 0),
                    Callout::make(fn (): string => 'Esiste già un’Azienda denominata “'.$this->previewString('company_name').'”.')
                        ->description('Il ripristino creerà una nuova Azienda indipendente. L’Azienda esistente non verrà modificata né unita ai dati importati.')
                        ->warning()
                        ->visible(fn (): bool => $this->previewBool('name_collision')),
                    Actions::make([
                        Action::make('confirmImport')
                            ->label('Ripristina come nuova Azienda')
                            ->color('success')
                            ->requiresConfirmation()
                            ->modalHeading(fn (): string => 'Ripristinare “'.$this->previewString('company_name').'” come nuova Azienda?')
                            ->modalDescription(fn (): string => $this->confirmationDescription())
                            ->modalSubmitActionLabel('Ripristina Azienda')
                            ->action(function (): void {
                                $this->confirmImport();
                            }),
                    ]),
                ]),
        ]);
    }

    public function validateBackup(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->invalidateValidatedBackup();
        try {
            $state = $this->form->getState();
            $path = $this->uploadedPath($this->normalizeUploadedRelativePath($state['backup'] ?? null));
            $validated = app(BusinessBackupValidator::class)->validate($path);
        } catch (ValidationException $exception) {
            $this->addValidationError($exception);

            return;
        }
        $this->previewData = $this->serializePreview($validated['preview']);
        $this->validatedPackageId = $validated['manifest']['package_id'];
        Notification::make()->success()->title('Backup valido')->body('Nessun dato è stato ancora scritto.')->send();
    }

    public function confirmImport(): void
    {
        abort_unless(self::canAccess(), 403);
        $expectedPackageId = $this->validatedPackageId;
        if ($expectedPackageId === null) {
            $this->addError('data.backup', 'Valida nuovamente il backup prima di procedere con il ripristino.');

            return;
        }

        try {
            $relativePath = $this->uploadedRelativePath();
            $path = $this->uploadedPath($relativePath);
            $validated = app(BusinessBackupValidator::class)->validate($path);
        } catch (ValidationException $exception) {
            $this->addValidationError($exception);

            return;
        }

        if ($validated['manifest']['package_id'] !== $expectedPackageId) {
            $this->invalidateValidatedBackup();
            $this->addError('data.backup', 'Il file caricato è cambiato dopo la validazione. Validalo nuovamente prima di procedere.');

            return;
        }

        try {
            $company = app(ImportBusinessBackup::class)->execute($this->actor(), $validated);
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()
                ->danger()
                ->title('Ripristino non riuscito')
                ->body('Non è stata creata alcuna Azienda. Puoi riprovare oppure consultare i log applicativi.')
                ->send();

            return;
        }

        Storage::disk('local')->delete($relativePath);
        $this->form->fill();
        $this->invalidateValidatedBackup();

        Notification::make()->success()->title('Azienda ripristinata')->body($company->name)->send();
        $this->redirect(TenantCompanyResource::getUrl('index', panel: 'platform'));
    }

    private function uploadedPath(?string $relative = null): string
    {
        $relative ??= $this->uploadedRelativePath();
        if (! Storage::disk('local')->exists($relative)) {
            throw ValidationException::withMessages(['data.backup' => 'Caricare un workbook XLSX.']);
        }

        return Storage::disk('local')->path($relative);
    }

    private function uploadedRelativePath(): string
    {
        return $this->normalizeUploadedRelativePath($this->data['backup'] ?? null);
    }

    private function normalizeUploadedRelativePath(mixed $uploads): string
    {
        if (is_string($uploads) && $uploads !== '') {
            return $uploads;
        }
        if (! is_array($uploads) || count($uploads) !== 1) {
            throw ValidationException::withMessages(['data.backup' => 'Caricare un solo workbook XLSX.']);
        }
        $relative = array_values($uploads)[0];
        if (! is_string($relative) || $relative === '') {
            throw ValidationException::withMessages(['data.backup' => 'Caricare un workbook XLSX.']);
        }

        return $relative;
    }

    /** @return array<string, mixed> */
    private function serializePreview(BackupPreview $preview): array
    {
        return [
            'package_id' => $preview->packageId, 'company_name' => $preview->companyName,
            'format_version' => $preview->formatVersion,
            'company_timezone' => $preview->companyTimezone, 'exported_at' => $preview->exportedAt,
            'exercises' => $preview->exercises, 'counts' => $preview->counts,
            'budget_total' => $preview->budgetTotal, 'closing_actual_total' => $preview->closingActualTotal,
            'attachment_count' => $preview->attachmentCount, 'name_collision' => $preview->nameCollision,
            'warnings' => $preview->warnings,
        ];
    }

    private function invalidateValidatedBackup(): void
    {
        $this->previewData = null;
        $this->validatedPackageId = null;
    }

    private function addValidationError(ValidationException $exception): void
    {
        $this->addError('data.backup', $exception->validator->errors()->first() ?: 'Il backup non è valido.');
    }

    private function previewString(string $key, string $default = ''): string
    {
        $value = $this->previewData[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    private function previewInt(string $key): int
    {
        $value = $this->previewData[$key] ?? 0;

        return is_int($value) ? $value : 0;
    }

    private function previewBool(string $key): bool
    {
        return ($this->previewData[$key] ?? false) === true;
    }

    /** @return array<mixed> */
    private function previewArray(string $key): array
    {
        $value = $this->previewData[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    private function previewCount(string $sheet): int
    {
        $counts = $this->previewArray('counts');
        $count = $counts[$sheet] ?? 0;

        return is_int($count) ? $count : 0;
    }

    private function formattedExportDate(): string
    {
        return CarbonImmutable::parse($this->previewString('exported_at'))
            ->setTimezone($this->previewString('company_timezone', 'UTC'))
            ->format('d/m/Y H:i');
    }

    private function attachmentWarning(): string
    {
        $count = $this->previewInt('attachment_count');

        return "{$count} allegati non saranno ripristinati. Il backup ne conserva l’inventario, ma il formato V1 non contiene i file originali.";
    }

    private function confirmationDescription(): string
    {
        $description = 'Verrà creata una nuova Azienda indipendente utilizzando i dati del backup. Nessuna Azienda esistente verrà modificata.';
        if ($this->previewBool('name_collision')) {
            $description .= ' Esiste già un’Azienda con questa denominazione. Verrà comunque creata una nuova identità.';
        }
        if ($this->previewInt('attachment_count') > 0) {
            $description .= ' I file allegati originali non fanno parte del backup V1 e non verranno ripristinati.';
        }

        return $description;
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
