<?php

namespace App\Filament\Pages;

use App\Domain\Company\AuditEventType;
use App\Domain\Company\Capability;
use App\Domain\Company\ClosingUnclassifiedPolicy;
use App\Domain\Company\Setting;
use App\Domain\Projects\ProjectState;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;
use App\Models\ProjectTransition;
use App\Models\Supplier;
use App\Models\SupplierContact;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class CompanyAudit extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Timeline';

    protected static ?string $title = 'Timeline Azienda';

    protected static ?int $navigationSort = 40;

    public ?int $expense = null;

    public ?int $project = null;

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $requestedExpense = request()->integer('expense');
        $this->expense = $requestedExpense > 0 && Expense::query()
            ->where('company_id', $this->company()->id)
            ->whereKey($requestedExpense)
            ->exists()
                ? $requestedExpense
                : null;
        $requestedProject = request()->integer('project');
        $this->project = $requestedProject > 0 && Project::query()
            ->where('company_id', $this->company()->id)
            ->whereKey($requestedProject)
            ->exists()
                ? $requestedProject
                : null;
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $company = Filament::getTenant();

        return $user instanceof User
            && $company instanceof Company
            && Gate::forUser($user)->allows('view', $company);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function (): Builder {
                $query = AuditEvent::query()->where('company_id', $this->company()->id);

                if ($this->expense !== null) {
                    $query->where(fn (Builder $subject): Builder => $subject
                        ->where(fn (Builder $expense): Builder => $expense
                            ->where('subject_type', Expense::class)
                            ->where('subject_id', $this->expense))
                        ->orWhere(fn (Builder $line): Builder => $line
                            ->where('reference_type', Expense::class)
                            ->where('reference_id', $this->expense)));
                }
                if ($this->project !== null) {
                    $project = Project::query()
                        ->where('company_id', $this->company()->id)
                        ->findOrFail($this->project);
                    $query->forProject($project);
                }

                return $query->orderByDesc('created_at')->orderByDesc('id');
            })
            ->columns([
                TextColumn::make('created_at')
                    ->label('Data e ora')
                    ->dateTime('d/m/Y H:i:s', timezone: $this->company()->timezone),
                TextColumn::make('event_type')
                    ->label('Evento')
                    ->formatStateUsing(fn (mixed $state): string => $state instanceof AuditEventType
                        ? $state->label()
                        : AuditEventType::from($state)->label()),
                TextColumn::make('subject')
                    ->label('Oggetto')
                    ->state(fn (AuditEvent $record): string => self::formatSubject($record)),
                TextColumn::make('actor.name')->label('Autore')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('effective_from')
                    ->label('Decorrenza')
                    ->date('d/m/Y'),
                TextColumn::make('affected_exercise_ids')
                    ->label('Esercizi interessati')
                    ->state(fn (AuditEvent $record): string => self::formatExercises($record))
                    ->placeholder('—'),
                TextColumn::make('beneficiary.email')->label('Beneficiario')->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('capability')
                    ->label('Capacità')
                    ->formatStateUsing(fn (mixed $state): string => match (true) {
                        $state instanceof Capability => $state->label(),
                        is_string($state) => Capability::from($state)->label(),
                        default => '—',
                    })->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('setting')
                    ->label('Impostazione')
                    ->formatStateUsing(fn (mixed $state): string => match (true) {
                        $state instanceof Setting => $state->label(),
                        is_string($state) => Setting::from($state)->label(),
                        default => '—',
                    })->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('previous_value')
                    ->label('Valore precedente')
                    ->state(fn (AuditEvent $record): string => self::formatValue($record, $record->previous_value))
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('new_value')
                    ->label('Valore nuovo')
                    ->state(fn (AuditEvent $record): string => self::formatValue($record, $record->new_value))
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reason')->label('Motivo')->placeholder('—')->limit(40)
                    ->tooltip(fn (AuditEvent $record): ?string => $record->reason),
                TextColumn::make('reference')
                    ->label('Riferimento')
                    ->state(fn (AuditEvent $record): string => self::formatReference($record))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('overspend')
                    ->label('Sovraspesa')
                    ->state(fn (AuditEvent $record): string => self::formatOverspend($record))
                    ->placeholder('—')->wrap(),
                TextColumn::make('allocated_impact_by_exercise')
                    ->label('Impatto Allocato')
                    ->state(fn (AuditEvent $record): string => self::formatImpact($record->allocated_impact_by_exercise, $record))
                    ->placeholder('—')->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('actual_impact_by_exercise')
                    ->label('Impatto Effettivo')
                    ->state(fn (AuditEvent $record): string => self::formatImpact($record->actual_impact_by_exercise, $record))
                    ->placeholder('—')->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('operation_id')->label('Operazione')->placeholder('—')->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('details')
                    ->label('Dettagli')
                    ->modalHeading(fn (AuditEvent $record): string => $record->eventType()->label())
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Chiudi')
                    ->form([
                        Placeholder::make('detail_effective_from')->label('Decorrenza')
                            ->content(fn (AuditEvent $record): string => self::formatEffectiveDate($record)),
                        Placeholder::make('detail_exercises')->label('Esercizi interessati')
                            ->content(fn (AuditEvent $record): string => self::formatExercises($record)),
                        Placeholder::make('detail_actor')->label('Autore')
                            ->content(fn (AuditEvent $record): string => $record->actor->name),
                        Placeholder::make('detail_previous')->label('Valore precedente')
                            ->content(fn (AuditEvent $record): string => self::formatValue($record, $record->previous_value)),
                        Placeholder::make('detail_new')->label('Valore nuovo')
                            ->content(fn (AuditEvent $record): string => self::formatValue($record, $record->new_value)),
                        Placeholder::make('detail_allocated')->label('Impatto Allocato')
                            ->content(fn (AuditEvent $record): string => self::formatImpact($record->allocated_impact_by_exercise, $record)),
                        Placeholder::make('detail_actual')->label('Impatto Effettivo')
                            ->content(fn (AuditEvent $record): string => self::formatImpact($record->actual_impact_by_exercise, $record)),
                        Placeholder::make('detail_reason')->label('Motivo')
                            ->content(fn (AuditEvent $record): string => $record->reason ?? '—'),
                        Placeholder::make('detail_reference')->label('Riferimento')
                            ->content(fn (AuditEvent $record): string => self::formatReference($record)),
                        Placeholder::make('detail_overspend')->label('Sovraspesa')
                            ->content(fn (AuditEvent $record): string => self::formatOverspend($record)),
                        Placeholder::make('detail_operation')->label('Identità operazione')
                            ->content(fn (AuditEvent $record): string => $record->operation_id),
                    ]),
            ])
            ->paginated([10, 25, 50]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    private static function formatValue(AuditEvent $event, mixed $value): string
    {
        if (
            $event->getRawOriginal('setting') === Setting::UnclassifiedClosingPolicy->value
            && is_string($value)
        ) {
            return ClosingUnclassifiedPolicy::from($value)->label();
        }

        if (is_array($value)) {
            $formatted = self::formatMasterDataValue($event, $value);

            if ($formatted !== null) {
                return $formatted;
            }
        }

        return match (true) {
            $value === null => '—',
            $value === true => 'Sì',
            $value === false => 'No',
            is_scalar($value) => (string) $value,
            default => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        };
    }

    private static function formatSubject(AuditEvent $event): string
    {
        $label = match ($event->subject_type) {
            Supplier::class => 'Fornitore',
            SupplierContact::class => 'Referente',
            CostCenter::class => 'Centro di Costo',
            Exercise::class => 'Esercizio',
            Expense::class => 'Spesa',
            ExpenseLine::class => 'Riga',
            Project::class => 'Progetto',
            ProjectTransition::class => 'Transizione Progetto',
            ProjectExerciseClassification::class => 'Classificazione Progetto',
            Company::class => 'Azienda',
            User::class => 'Utente',
            default => class_basename($event->subject_type),
        };

        return $label.' #'.$event->subject_id;
    }

    /** @param array<string, mixed> $value */
    private static function formatMasterDataValue(AuditEvent $event, array $value): ?string
    {
        $fields = match ($event->subject_type) {
            Supplier::class => [
                'legal_name' => 'Ragione Sociale',
                'vat_number' => 'Partita IVA',
                'notes' => 'Note',
                'archived' => 'Stato',
            ],
            SupplierContact::class => [
                'first_name' => 'Nome',
                'last_name' => 'Cognome',
                'phone' => 'Telefono',
                'email' => 'Email',
                'notes' => 'Note',
                'role_tags' => 'Tag di ruolo',
            ],
            CostCenter::class => [
                'name' => 'Denominazione',
                'archived' => 'Stato',
            ],
            Exercise::class => [
                'year' => 'Anno',
                'status' => 'Stato',
                'revision' => 'Revisione',
            ],
            Expense::class => [
                'origin_key' => 'OriginKey',
                'exercise_id' => 'Esercizio',
                'project_id' => 'Progetto',
                'supplier_id' => 'Fornitore',
                'direct_cost_center_id' => 'Centro di Costo',
                'description' => 'Descrizione',
                'notes' => 'Note',
                'reversed' => 'Stornata',
                'allocation' => 'Allocato',
                'actual' => 'Effettivo',
                'operational_variance' => 'Scostamento',
                'has_actuals' => 'Ha Effettivi',
                'revision' => 'Revisione',
            ],
            ExpenseLine::class => [
                'expense_id' => 'Spesa',
                'type' => 'Tipo',
                'amount' => 'Importo',
                'quantity' => 'Quantità',
                'unit_amount' => 'Importo unitario',
                'unit_of_measure' => 'Unità di misura',
                'note' => 'Nota',
                'annulled' => 'Annullata',
            ],
            Project::class => [
                'origin_key' => 'OriginKey',
                'title' => 'Titolo',
                'description' => 'Descrizione',
                'notes' => 'Note',
                'initial_state' => 'Stato iniziale',
                'initial_effective_date' => 'Efficacia iniziale',
                'archived_at' => 'Archivio',
                'revision' => 'Revisione',
            ],
            ProjectTransition::class => [
                'project_id' => 'Progetto',
                'state' => 'Stato',
                'from_state' => 'Da',
                'to_state' => 'A',
                'effective_date' => 'Data efficacia',
                'reason' => 'Motivo',
                'annulled_at' => 'Annullata il',
                'annulment_reason' => 'Motivo annullamento',
            ],
            ProjectExerciseClassification::class => [
                'project_id' => 'Progetto',
                'exercise_id' => 'Esercizio',
                'cost_center_id' => 'Centro di Costo',
            ],
            default => null,
        };

        if ($fields === null) {
            return null;
        }

        $parts = [];

        foreach ($fields as $key => $label) {
            if (! array_key_exists($key, $value)) {
                continue;
            }

            $fieldValue = $value[$key];

            if (in_array($key, ['initial_state', 'state', 'from_state', 'to_state'], true) && is_string($fieldValue)) {
                $fieldValue = ProjectState::tryFrom($fieldValue)?->label() ?? $fieldValue;
            } elseif ($key === 'archived_at') {
                $fieldValue = $fieldValue === null ? 'Attivo' : 'Archiviato';
            } elseif ($key === 'archived' && is_bool($fieldValue)) {
                $fieldValue = $fieldValue ? 'Archiviato' : 'Attivo';
            } elseif (is_array($fieldValue)) {
                $fieldValue = $fieldValue === [] ? '—' : implode(', ', $fieldValue);
            } elseif ($fieldValue === null || $fieldValue === '') {
                $fieldValue = '—';
            } elseif (is_bool($fieldValue)) {
                $fieldValue = $fieldValue ? 'Sì' : 'No';
            }

            $parts[] = $label.': '.$fieldValue;
        }

        return implode(' · ', $parts);
    }

    private function company(): Company
    {
        $company = Filament::getTenant();
        abort_unless($company instanceof Company, 404);

        return $company;
    }

    private static function formatExercises(AuditEvent $event): string
    {
        $ids = array_map('intval', $event->affected_exercise_ids ?? []);
        if ($ids === []) {
            return '—';
        }
        $years = Exercise::query()->where('company_id', $event->company_id)->whereIn('id', $ids)->pluck('year', 'id');

        return collect($ids)->map(fn (int $id): string => isset($years[$id]) ? (string) $years[$id] : '#'.$id)->implode(', ');
    }

    private static function formatImpact(mixed $impact, AuditEvent $event): string
    {
        if (! is_array($impact) || $impact === []) {
            return '—';
        }
        $ids = array_map('intval', array_keys($impact));
        $years = Exercise::query()->where('company_id', $event->company_id)->whereIn('id', $ids)->pluck('year', 'id');

        return collect($impact)->map(function (mixed $amount, int|string $id) use ($years): string {
            $numericId = (int) $id;
            $label = isset($years[$numericId]) ? (string) $years[$numericId] : '#'.$numericId;

            return $label.': € '.(string) $amount;
        })->implode(' · ');
    }

    private static function formatReference(AuditEvent $event): string
    {
        if ($event->reference_type === null || $event->reference_id === null) {
            return '—';
        }

        $label = match ($event->reference_type) {
            Project::class => 'Progetto',
            Expense::class => 'Spesa',
            CostCenter::class => 'Centro di Costo',
            Supplier::class => 'Fornitore',
            default => class_basename($event->reference_type),
        };

        return $label.' #'.$event->reference_id;
    }

    private static function formatOverspend(AuditEvent $event): string
    {
        $occurrences = $event->overspendOccurrences();
        if ($occurrences === []) {
            return '—';
        }

        return collect($occurrences)->map(function (array $occurrence) use ($occurrences): string {
            $label = $occurrence['result'] === 'increased' ? 'Sovraspesa aumentata' : 'Sovraspesa creata';
            $project = count($occurrences) > 1 && $occurrence['project_id'] !== null
                ? ' · Progetto #'.$occurrence['project_id']
                : '';

            return $label.$project.': € '.$occurrence['variance_before'].' → € '.$occurrence['variance_after'];
        })->implode(' · ');
    }

    private static function formatEffectiveDate(AuditEvent $event): string
    {
        $date = $event->getRawOriginal('effective_from');

        return is_string($date) && $date !== ''
            ? CarbonImmutable::parse($date)->format('d/m/Y')
            : '—';
    }
}
