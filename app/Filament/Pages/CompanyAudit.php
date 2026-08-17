<?php

namespace App\Filament\Pages;

use App\Domain\Company\AuditEventType;
use App\Domain\Company\Capability;
use App\Domain\Company\ClosingUnclassifiedPolicy;
use App\Domain\Company\Setting;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Supplier;
use App\Models\SupplierContact;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
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

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
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
            ->query(fn (): Builder => AuditEvent::query()
                ->where('company_id', $this->company()->id)
                ->orderByDesc('created_at')
                ->orderByDesc('id'))
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
                TextColumn::make('actor.name')->label('Autore'),
                TextColumn::make('effective_from')
                    ->label('Decorrenza')
                    ->date('d/m/Y'),
                TextColumn::make('beneficiary.email')->label('Beneficiario')->placeholder('—'),
                TextColumn::make('capability')
                    ->label('Capacità')
                    ->formatStateUsing(fn (mixed $state): string => match (true) {
                        $state instanceof Capability => $state->label(),
                        is_string($state) => Capability::from($state)->label(),
                        default => '—',
                    }),
                TextColumn::make('setting')
                    ->label('Impostazione')
                    ->formatStateUsing(fn (mixed $state): string => match (true) {
                        $state instanceof Setting => $state->label(),
                        is_string($state) => Setting::from($state)->label(),
                        default => '—',
                    }),
                TextColumn::make('previous_value')
                    ->label('Valore precedente')
                    ->state(fn (AuditEvent $record): string => self::formatValue($record, $record->previous_value))
                    ->wrap(),
                TextColumn::make('new_value')
                    ->label('Valore nuovo')
                    ->state(fn (AuditEvent $record): string => self::formatValue($record, $record->new_value))
                    ->wrap(),
                TextColumn::make('reason')->label('Motivo')->placeholder('—')->wrap(),
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

            if ($key === 'archived' && is_bool($fieldValue)) {
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
}
