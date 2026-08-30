<?php

namespace App\Filament\Platform\Pages;

use App\Actions\BusinessBackup\ImportBusinessBackup;
use App\BusinessBackup\BackupPreview;
use App\BusinessBackup\V1\BusinessBackupValidator;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

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
                ->afterStateUpdated(fn (): mixed => $this->previewData = null),
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
            Section::make('Anteprima')
                ->visible(fn (): bool => $this->previewData !== null)
                ->schema([
                    Text::make(fn (): string => $this->previewText()),
                    Actions::make([
                        Action::make('confirmImport')
                            ->label('Crea la nuova Azienda')
                            ->color('success')
                            ->requiresConfirmation()
                            ->action('confirmImport'),
                    ]),
                ]),
        ]);
    }

    public function validateBackup(): void
    {
        abort_unless(self::canAccess(), 403);
        $path = $this->uploadedPath();
        try {
            $validated = app(BusinessBackupValidator::class)->validate($path);
        } catch (ValidationException $exception) {
            $this->addError('data.backup', $exception->validator->errors()->first());
            $this->previewData = null;

            return;
        }
        $this->previewData = $this->serializePreview($validated['preview']);
        Notification::make()->success()->title('Backup valido')->body('Nessun dato è stato ancora scritto.')->send();
    }

    public function confirmImport(): void
    {
        abort_unless(self::canAccess(), 403);
        abort_unless($this->previewData !== null, 422);
        $path = $this->uploadedPath();
        $validated = app(BusinessBackupValidator::class)->validate($path);
        if ($validated['manifest']['package_id'] !== $this->previewData['package_id']) {
            throw ValidationException::withMessages(['backup' => 'Il file caricato è cambiato dopo l’anteprima.']);
        }
        $company = app(ImportBusinessBackup::class)->execute($this->actor(), $validated);
        Storage::disk('local')->delete($this->uploadedRelativePath());
        $this->form->fill();
        $this->previewData = null;

        Notification::make()->success()->title('Azienda ripristinata')->body($company->name)->send();
    }

    private function uploadedPath(): string
    {
        $relative = $this->uploadedRelativePath();
        if (! Storage::disk('local')->exists($relative)) {
            throw ValidationException::withMessages(['backup' => 'Caricare un workbook XLSX.']);
        }

        return Storage::disk('local')->path($relative);
    }

    private function uploadedRelativePath(): string
    {
        $state = $this->form->getState();
        $uploads = $state['backup'] ?? null;
        if (is_string($uploads) && $uploads !== '') {
            return $uploads;
        }
        if (! is_array($uploads) || count($uploads) !== 1) {
            throw ValidationException::withMessages(['backup' => 'Caricare un solo workbook XLSX.']);
        }
        $relative = array_values($uploads)[0];
        if (! is_string($relative) || $relative === '') {
            throw ValidationException::withMessages(['backup' => 'Caricare un workbook XLSX.']);
        }

        return $relative;
    }

    /** @return array<string, mixed> */
    private function serializePreview(BackupPreview $preview): array
    {
        return [
            'package_id' => $preview->packageId, 'company_name' => $preview->companyName,
            'company_timezone' => $preview->companyTimezone, 'exported_at' => $preview->exportedAt,
            'exercises' => $preview->exercises, 'counts' => $preview->counts,
            'budget_total' => $preview->budgetTotal, 'closing_actual_total' => $preview->closingActualTotal,
            'attachment_count' => $preview->attachmentCount, 'warnings' => $preview->warnings,
        ];
    }

    private function previewText(): string
    {
        $preview = $this->previewData ?? [];
        $exerciseLabels = [];
        $previewExercises = $preview['exercises'] ?? [];
        if (is_array($previewExercises)) {
            foreach ($previewExercises as $exercise) {
                if (is_array($exercise) && isset($exercise['year'], $exercise['status'])) {
                    $exerciseLabels[] = $exercise['year'].' ('.$exercise['status'].')';
                }
            }
        }
        $exercises = implode(', ', $exerciseLabels);
        $warnings = implode(' ', $preview['warnings'] ?? []);

        return sprintf(
            '%s · timezone %s · Esercizi: %s · Budget complessivi € %s · Effettivi di Chiusura € %s · Allegati non ripristinabili: %d. %s',
            $preview['company_name'] ?? '', $preview['company_timezone'] ?? '', $exercises ?: 'nessuno',
            $preview['budget_total'] ?? '0.00', $preview['closing_actual_total'] ?? '0.00',
            $preview['attachment_count'] ?? 0, $warnings,
        );
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
