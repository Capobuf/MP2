<?php

namespace App\Filament\Platform\Pages;

use App\Actions\BusinessBackup\ImportBusinessBackup;
use App\Actions\BusinessBackup\ReplaceBusinessBackup;
use App\BusinessBackup\BackupPreview;
use App\BusinessBackup\BusinessBackupBundle;
use App\Filament\Platform\Resources\TenantCompanies\TenantCompanyResource;
use App\Models\TenantCompany;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
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
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/** @property-read Schema $form */
final class ImportCompanyBackup extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?string $navigationLabel = 'Importa Azienda da Backup';

    protected static ?string $title = 'Importa Azienda da Backup';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $previewData = null;

    #[Locked]
    public ?string $validatedPackageId = null;

    #[Locked]
    public ?string $validatedHash = null;

    #[Locked]
    public ?string $importOperationId = null;

    #[Locked]
    public string $importChoice = 'create';

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->form->fill();
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasRole('super_admin');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            FileUpload::make('backup')
                ->label('Backup MP2 (.zip) o XLSX legacy')
                ->disk('local')
                ->directory('business-backup-uploads')
                ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/x-zip-compressed'])
                ->getUploadedFileNameForStorageUsing(fn (TemporaryUploadedFile $file): string => Str::uuid().'.'.$file->getClientOriginalExtension())
                ->required()
                ->validationMessages([
                    'required' => 'Caricare un backup ZIP o XLSX legacy.',
                    'mimetypes' => 'Il file deve essere un backup ZIP o XLSX legacy.',
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
                    Action::make('validate')->label('Valida e Mostra Anteprima')->action('validateBackup'),
                ])]),
            Section::make('Anteprima del Ripristino')
                ->description('Dati validati. Utenti, accessi e Timeline/Audit non vengono ricreati.')
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
                        TextEntry::make('backup_timezone')->label('Fuso Orario')->state(fn (): string => $this->previewString('company_timezone')),
                    ])->columns(['sm' => 2, 'lg' => 4]),
                    Section::make('Identità e File')->schema([
                        TextEntry::make('source_uuid')->label('Tenant UUID sorgente')->state(fn (): string => $this->previewString('source_tenant_uuid', 'Non presente nel formato legacy')),
                        TextEntry::make('target')->label('Tenant con la stessa identità')->state(fn (): string => $this->previewString('target_label', 'Nessuno')),
                        TextEntry::make('files')->label('File originali')->state(fn (): string => $this->previewBool('files_included') ? 'Inclusi' : 'Esclusi'),
                        TextEntry::make('logo')->label('Logo')->state(fn (): string => $this->previewBool('logo_included') ? 'Incluso se presente' : 'Escluso'),
                        TextEntry::make('file_count')->label('Numero binari')->state(fn (): int => $this->previewInt('file_count')),
                        TextEntry::make('file_bytes')->label('Byte binari')->state(fn (): int => $this->previewInt('file_bytes')),
                        TextEntry::make('total_bytes')->label('Byte totali dichiarati')->state(fn (): int => $this->previewInt('total_bytes')),
                        TextEntry::make('package_size')->label('Byte package')->state(fn (): int => $this->previewInt('package_size')),
                        TextEntry::make('upload_limit')->label('Limite tecnico upload web')->state(fn (): string => $this->uploadLimits()),
                        TextEntry::make('bundle_version')->label('Versione bundle')->state(fn (): string => $this->previewString('bundle_version', 'XLSX legacy')),
                    ])->columns(2),
                    Section::make('Proposte')->schema([
                        TextEntry::make('draft_count')->label('Bozza')->state(fn (): int => $this->previewInt('draft_count')),
                        TextEntry::make('approved_count')->label('Approvata')->state(fn (): int => $this->previewInt('approved_count')),
                        TextEntry::make('discarded_count')->label('Scartata')->state(fn (): int => $this->previewInt('discarded_count')),
                    ])->columns(3),
                    Callout::make('Utenti, accessi e Timeline/Audit non sono portabili e non verranno ricreati.')->warning(),
                    Section::make('Esercizi')->schema([
                        RepeatableEntry::make('preview_exercises')
                            ->label('Esercizi Inclusi')
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
                    Section::make('Valori Economici Inclusi')->schema([
                        TextEntry::make('budget_total')->label('Totale degli Snapshot Budget Inclusi')
                            ->state(fn (): string => $this->previewString('budget_total', '0.00'))->money('EUR', locale: 'it'),
                        TextEntry::make('closing_actual_total')->label('Effettivi delle Chiusure Incluse')
                            ->state(fn (): string => $this->previewString('closing_actual_total', '0.00'))->money('EUR', locale: 'it'),
                    ])->columns(2),
                    Callout::make(fn (): string => $this->attachmentWarning())
                        ->warning()
                        ->visible(fn (): bool => $this->previewInt('attachment_count') > 0 && ! $this->previewBool('files_included')),
                    Callout::make(fn (): string => 'Esiste già un’Azienda denominata “'.$this->previewString('company_name').'”.')
                        ->description(fn (): string => $this->previewInt('target_company_id') > 0
                            ? 'La stessa UUID è già presente: scegli se creare una copia indipendente o sostituire completamente il Tenant indicato.'
                            : 'Il ripristino creerà una nuova Azienda indipendente. L’Azienda esistente non verrà modificata né unita ai dati importati.')
                        ->warning()
                        ->visible(fn (): bool => $this->previewBool('name_collision')),
                    Actions::make([
                        Action::make('confirmImport')
                            ->label('Importa')
                            ->mountUsing(fn (?Schema $schema) => $this->prepareImportChoice('create', $schema))
                            ->visible(fn (): bool => $this->previewInt('target_company_id') === 0)
                            ->color('success')
                            ->requiresConfirmation()
                            ->modalHeading(fn (): string => 'Ripristinare “'.$this->previewString('company_name').'” come nuova Azienda?')
                            ->modalDescription(fn (): string => $this->confirmationDescription())
                            ->modalSubmitActionLabel('Ripristina Azienda')
                            ->action(function (): void {
                                $this->confirmImport();
                            }),
                        Action::make('copy')
                            ->label('Crea come Nuova Copia')
                            ->mountUsing(fn (?Schema $schema) => $this->prepareImportChoice('copy', $schema))
                            ->visible(fn (): bool => $this->previewInt('target_company_id') > 0)
                            ->requiresConfirmation()
                            ->modalDescription('La copia avrà una nuova identità Tenant. Il target esistente resterà integro. Utenti, accessi e Timeline/Audit non verranno ricreati.')
                            ->action(fn () => $this->confirmImport('copy')),
                        Action::make('replace')
                            ->label('Sostituisci Completamente')
                            ->mountUsing(fn (?Schema $schema) => $this->prepareImportChoice('replace', $schema))
                            ->color('danger')
                            ->visible(fn (): bool => $this->previewInt('target_company_id') > 0)
                            ->modalHeading(fn (): string => 'Sostituisci '.$this->previewString('target_label'))
                            ->modalDescription(fn (): string => $this->replaceDescription())
                            ->modalSubmitActionLabel('Sostituisci Completamente')
                            ->steps([
                                Step::make('Irreversibilità')->schema([
                                    Checkbox::make('irreversibility_confirmed')
                                        ->label('Confermo la cancellazione irreversibile di dati, file, utenti, accessi e Timeline/Audit del target; utenti/accessi e Timeline/Audit non saranno ricreati')
                                        ->accepted()->required(),
                                ]),
                                Step::make('Distruzione Definitiva')->schema([
                                    Callout::make(fn (): string => $this->replaceDescription())->danger(),
                                    Checkbox::make('destruction_confirmed')
                                        ->label(fn (): string => 'Confermo la distruzione definitiva e sostituzione di '.$this->previewString('target_label').' · UUID '.$this->previewString('source_tenant_uuid'))
                                        ->accepted()->required(),
                                ]),
                            ])
                            ->action(fn (array $data) => $this->confirmImport('replace', ($data['irreversibility_confirmed'] ?? false) === true, ($data['destruction_confirmed'] ?? false) === true)),
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
            $validated = app(BusinessBackupBundle::class)->validate($path, strtolower(pathinfo($path, PATHINFO_EXTENSION)));
        } catch (ValidationException $exception) {
            $this->addValidationError($exception);

            return;
        }
        $this->previewData = $this->serializePreview($validated['preview']);
        $this->validatedPackageId = $validated['manifest']['package_id'];
        $this->validatedHash = $validated['upload_sha256'];
        $this->importOperationId = (string) Str::uuid();
        $this->previewData += $this->portablePreview($validated);
        Notification::make()->success()->title('Backup Valido')->body('Nessun dato è stato ancora scritto.')->send();
    }

    public function confirmImport(string $choice = 'create', bool $irreversibilityConfirmed = false, bool $destructionConfirmed = false): void
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
            if (! hash_equals($this->validatedHash ?? '', hash_file('sha256', $path))) {
                $this->invalidateValidatedBackup();
                $this->addError('data.backup', 'Il file caricato è cambiato dopo la validazione. Validalo nuovamente prima di procedere.');

                return;
            }
            $validated = app(BusinessBackupBundle::class)->validate($path, strtolower(pathinfo($path, PATHINFO_EXTENSION)));
        } catch (ValidationException $exception) {
            $this->addValidationError($exception);

            return;
        }

        if ($validated['manifest']['package_id'] !== $expectedPackageId || ! hash_equals($this->validatedHash ?? '', $validated['upload_sha256'])) {
            $this->invalidateValidatedBackup();
            $this->addError('data.backup', 'Il file caricato è cambiato dopo la validazione. Validalo nuovamente prima di procedere.');

            return;
        }

        try {
            if ($this->importChoice !== $choice) {
                throw ValidationException::withMessages(['backup' => 'Aprire la conferma della scelta desiderata prima di procedere.']);
            }
            if ($choice === 'replace') {
                $company = app(ReplaceBusinessBackup::class)->execute($this->actor(), $validated, $this->importOperationId, $this->previewInt('target_company_id'), $irreversibilityConfirmed, $destructionConfirmed);
            } else {
                $company = app(ImportBusinessBackup::class)->execute($this->actor(), $validated, $this->importOperationId, $choice);
            }
        } catch (ValidationException $exception) {
            $this->addValidationError($exception);

            return;
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()
                ->danger()
                ->title('Ripristino Non Riuscito')
                ->body('La mutazione non è stata completata. Il Tenant target resta integro. Puoi riprovare oppure consultare i log applicativi.')
                ->send();

            return;
        }

        Storage::disk('local')->delete($relativePath);
        $this->form->fill();
        $this->invalidateValidatedBackup();

        if (($company->getAttribute('backup_cleanup_pending') ?? 0) > 0) {
            Notification::make()->warning()->title('Ripristino Completato; Pulizia File in Attesa')->body('Il nuovo Tenant è valido. File precedenti ancora da rimuovere: '.$company->getAttribute('backup_cleanup_pending').'.')->send();
        } else {
            Notification::make()->success()->title('Azienda Ripristinata')->body($company->name)->send();
        }
        $this->redirect(TenantCompanyResource::getUrl('index', panel: 'platform'));
    }

    private function uploadedPath(?string $relative = null): string
    {
        $relative ??= $this->uploadedRelativePath();
        if (! Storage::disk('local')->exists($relative)) {
            throw ValidationException::withMessages(['data.backup' => 'Caricare un backup ZIP o XLSX legacy.']);
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
            throw ValidationException::withMessages(['data.backup' => 'Caricare un solo backup ZIP o XLSX legacy.']);
        }
        $relative = array_values($uploads)[0];
        if (! is_string($relative) || $relative === '') {
            throw ValidationException::withMessages(['data.backup' => 'Caricare un backup ZIP o XLSX legacy.']);
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
        $this->validatedHash = null;
        $this->importOperationId = null;
        $this->importChoice = 'create';
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

        return "{$count} allegati non saranno ripristinati. Il backup ne conserva l’inventario; i file originali sono esclusi.";
    }

    private function confirmationDescription(): string
    {
        $description = 'Verrà creata una nuova Azienda indipendente utilizzando i dati del backup. Nessuna Azienda esistente verrà modificata.';
        if ($this->previewBool('name_collision')) {
            $description .= ' Esiste già un’Azienda con questa denominazione. Verrà comunque creata una nuova identità.';
        }
        if ($this->previewInt('attachment_count') > 0 && ! $this->previewBool('files_included')) {
            $description .= ' I file allegati originali sono esclusi e non verranno ripristinati.';
        }

        return $description;
    }

    /** @param array<string, mixed> $package
     * @return array<string, mixed>
     */
    private function portablePreview(array $package): array
    {
        $uuid = $package['manifest']['source_tenant_uuid'] ?? null;
        $target = $uuid === null ? null : TenantCompany::query()->with('company')->where('portable_uuid', $uuid)->first();
        $files = $package['bundle']['files'] ?? [];
        $proposals = $package['machine']['_MP2_proposals']['rows'] ?? [];

        return [
            'source_tenant_uuid' => $uuid,
            'target_company_id' => $target === null ? 0 : $target->company_id,
            'target_label' => $target === null ? 'Nessuno' : $target->company->name.' · '.($target->status()->value === 'active' ? 'Attivo' : 'Archiviato'),
            'files_included' => $package['bundle']['included']['files'] ?? false,
            'logo_included' => $package['bundle']['included']['logo'] ?? false,
            'file_count' => count($files), 'file_bytes' => array_sum(array_column($files, 'size')),
            'total_bytes' => $package['declared_total_bytes'] ?? 0,
            'package_size' => $package['package_size'] ?? 0,
            'bundle_version' => isset($package['bundle']) ? (string) $package['bundle']['bundle_version'] : 'XLSX legacy',
            'draft_count' => count(array_filter($proposals, fn (array $row): bool => $row[4] === 'draft')),
            'approved_count' => count(array_filter($proposals, fn (array $row): bool => $row[4] === 'approved')),
            'discarded_count' => count(array_filter($proposals, fn (array $row): bool => $row[4] === 'discarded')),
        ];
    }

    private function replaceDescription(): string
    {
        $description = 'Il target sarà distrutto e ricreato Attivo con la stessa UUID. Dati e file, utenti interni, accessi e Timeline/Audit del target saranno eliminati. Utenti/accessi e Timeline/Audit non saranno ricreati. I Super Admin sopravvivono.';
        if (! $this->previewBool('files_included')) {
            $description .= ' I vecchi Allegati ed Evidenze saranno persi e non ricreati: il package esclude i file.';
        }
        if (! $this->previewBool('logo_included')) {
            $description .= ' Il vecchio logo sarà perso e non ricreato.';
        }

        return $description;
    }

    private function prepareImportChoice(string $choice, ?Schema $schema): void
    {
        if ($this->importChoice !== $choice) {
            $this->importChoice = $choice;
            $this->importOperationId = (string) Str::uuid();
        }
        $schema?->fill();
    }

    private function uploadLimits(): string
    {
        $limits = ['PHP upload_max_filesize: '.ini_get('upload_max_filesize'), 'post_max_size: '.ini_get('post_max_size')];
        foreach (FileUploadConfiguration::rules() as $rule) {
            if (is_string($rule) && str_starts_with($rule, 'max:')) {
                $limits[] = 'Upload applicativo: '.substr($rule, 4).' KiB';
            }
        }

        return implode(' · ', $limits);
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
