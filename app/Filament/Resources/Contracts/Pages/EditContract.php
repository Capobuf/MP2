<?php

namespace App\Filament\Resources\Contracts\Pages;

use App\Actions\Operations\SaveContractEdits;
use App\Filament\Forms\DateInput;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Contracts\Schemas\ContractForm;
use App\Models\Contract;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class EditContract extends EditRecord
{
    protected static string $resource = ContractResource::class;

    protected static ?string $title = 'Modifica Contratto';

    #[Locked]
    public string $operationId;

    /** @var array<string, mixed> */
    #[Locked]
    public array $original = [];

    #[Locked]
    public int $originalRevision;

    /** @var array<string, mixed> */
    #[Locked]
    public array $changes = [];

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $review = null;

    private bool $reviewConfirmed = false;

    public function mount(int|string $record): void
    {
        $this->operationId = (string) Str::uuid();
        parent::mount($record);
    }

    public function form(Schema $schema): Schema
    {
        return ContractForm::configure($schema, $this->contractRecord());
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $contract = $this->contractRecord();
        $this->original = app(SaveContractEdits::class)->state($contract);
        $this->originalRevision = $contract->revision;

        return $this->original + [
            'duration_type' => $contract->nextExpiryDate() !== null ? 'fixed' : ($contract->automatic_renewal ? 'undefined' : 'indefinite'),
            'attachments' => [],
        ] + $contract->attachments()->attached()->get()
            ->mapWithKeys(fn ($attachment): array => ['attachment_'.$attachment->id => $attachment->storage_path])->all();
    }

    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        $this->authorizeAccess();
        try {
            $data = $this->form->getState();
            $this->changes = app(SaveContractEdits::class)->changes($this->original, $data);
            if ($this->changes['conditions'] !== [] || $this->changes['classifications'] !== [] || $this->changes['renewal'] !== []) {
                $this->review = null;
                if ($this->changes['conditions'] !== [] || $this->changes['renewal'] !== []) {
                    $this->mountAction('interpretChanges');
                } else {
                    $this->prepareReview(['reason' => $data['reason'] ?? null]);
                    $this->mountAction('confirmChanges');
                }

                return;
            }
            parent::save($shouldRedirect, $shouldSendSavedNotification);
        } catch (ValidationException $exception) {
            $this->formErrors($exception);
        }
    }

    public function interpretChangesAction(): Action
    {
        return Action::make('interpretChanges')
            ->modalHeading($this->changes['conditions'] !== [] ? 'Hai modificato le condizioni economiche' : 'Hai modificato i termini contrattuali')
            ->modalSubmitActionLabel('Rivedi le modifiche')
            ->fillForm(fn (): array => [
                'requested_date' => now($this->contractRecord()->company->timezone)->addMonthNoOverflow()->startOfMonth()->toDateString(),
                'effective_from' => now($this->contractRecord()->company->timezone)->toDateString(),
                'reason' => $this->data['reason'] ?? null,
            ])
            ->schema([
                Radio::make('meaning')->label('Come deve essere interpretata la modifica?')
                    ->options(['correction' => 'Il dato precedente era errato', 'change' => 'L’accordo è cambiato'])
                    ->descriptions(['correction' => 'Corregge i valori originari, senza una nuova decorrenza.', 'change' => 'Registra nuovi termini dalla prima decorrenza consentita.'])
                    ->required()->live()->visible(fn (): bool => $this->changes['conditions'] !== []),
                DateInput::make('requested_date')->label('Da quando è richiesto il nuovo accordo?')
                    ->required()->visible(fn (Get $get): bool => $this->changes['conditions'] !== [] && $get('meaning') === 'change'),
                Checkbox::make('declared_input_error')->label('Il valore precedente non rappresentava l’accordo reale')
                    ->accepted()->visible(fn (Get $get): bool => $get('meaning') === 'correction'),
                Checkbox::make('declared_no_new_agreement')->label('La correzione non introduce una nuova decorrenza contrattuale')
                    ->accepted()->visible(fn (Get $get): bool => $get('meaning') === 'correction'),
                DateInput::make('effective_from')->label('Da quando valgono i nuovi termini contrattuali?')
                    ->helperText('È supportata la configurazione corrente, successiva alle scadenze già elaborate. Le configurazioni con efficacia futura non sono modificabili da questa schermata.')
                    ->required()->visible(fn (): bool => $this->changes['renewal'] !== []),
                Textarea::make('reason')->label('Motivo della modifica')
                    ->required(fn (Get $get): bool => $get('meaning') === 'correction' || ($this->changes['renewal'] !== [] && $this->contractRecord()->company->exercises()->open()->whereHas('budgets')->exists()))
                    ->helperText('Obbligatorio per una correzione e quando la modifica interessa un Budget approvato.'),
            ])
            ->action(function (array $data): void {
                try {
                    $this->prepareReview($data);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages([$this->getMountedActionSchema()->getStatePath().'.reason' => implode(' ', collect($exception->errors())->flatten()->all())]);
                }
                $this->replaceMountedAction('confirmChanges');
            });
    }

    public function confirmChangesAction(): Action
    {
        return Action::make('confirmChanges')
            ->modalHeading('Modifiche rilevate')
            ->modalWidth(Width::FourExtraLarge)
            ->modalContent(fn () => view('filament.resources.contracts.components.edit-review', [
                'review' => $this->review,
                'contract' => $this->contractRecord(),
                'original' => $this->original,
            ]))
            ->modalSubmitActionLabel('Conferma modifiche')
            ->fillForm(fn (): array => ['reason' => $this->review['reason'] ?? null])
            ->schema([
                Textarea::make('reason')->label('Motivo della riclassificazione')
                    ->visible(fn (): bool => ($this->review['kind'] ?? null) === 'classification')
                    ->helperText('Obbligatorio se sono presenti Effettivi o un Budget approvato.'),
                Checkbox::make('confirmed')->label(fn (): string => ($this->review['kind'] ?? null) === 'change'
                    ? 'Confermo la decorrenza effettiva mostrata e l’assenza di prorata'
                    : 'Confermo l’impatto mostrato sugli Esercizi Aperti')
                    ->accepted()->required(),
            ])
            ->action(function (array $data): void {
                if ($this->review === null) {
                    throw ValidationException::withMessages(['confirmed' => 'Calcola prima l’anteprima.']);
                }
                if ($this->review['kind'] === 'classification') {
                    $this->review['reason'] = trim((string) ($data['reason'] ?? '')) ?: null;
                }
                $this->reviewConfirmed = true;
                try {
                    parent::save();
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages([$this->getMountedActionSchema()->getStatePath().'.confirmed' => implode(' ', collect($exception->errors())->flatten()->all())]);
                } finally {
                    $this->reviewConfirmed = false;
                }
            });
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof Contract, 403);

        return app(SaveContractEdits::class)->execute(
            $this->actor(), $record, $this->original, $this->originalRevision, $data,
            $this->reviewConfirmed ? $this->review : null, $this->operationId,
        );
    }

    /** @param array<string, mixed> $decisions */
    private function prepareReview(array $decisions): void
    {
        $contract = $this->contractRecord()->fresh();
        if ($contract->revision !== $this->originalRevision) {
            throw ValidationException::withMessages(['reason' => 'Il Contratto è cambiato. Ricarica la pagina prima di salvare.']);
        }
        $this->review = app(SaveContractEdits::class)->preview($this->actor(), $contract, $this->changes, $decisions);
    }

    protected function afterSave(): void
    {
        $this->operationId = (string) Str::uuid();
        $this->record->refresh();
        $this->cacheSchema('form');
        $this->fillForm();
        $this->review = null;
    }

    protected function getRedirectUrl(): string
    {
        return ContractResource::getUrl('view', ['record' => $this->record]);
    }

    public function getRelationManagers(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }

    private function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function contractRecord(): Contract
    {
        $record = $this->getRecord();
        abort_unless($record instanceof Contract, 404);

        return $record;
    }

    private function formErrors(ValidationException $exception): never
    {
        throw ValidationException::withMessages(collect($exception->errors())
            ->mapWithKeys(fn (array $messages, string $key): array => [str_starts_with($key, 'data.') ? $key : 'data.'.$key => $messages])->all());
    }
}
