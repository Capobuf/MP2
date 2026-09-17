<?php

namespace App\Filament\Platform\Pages;

use App\Models\PlatformLifecycleEvent;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class TenantLifecycleLog extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Registro Tenant';

    protected static ?string $title = 'Registro Operazioni Tenant';

    protected static ?int $navigationSort = 10;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasRole('super_admin');
    }

    public function getSubheading(): ?string
    {
        return 'La distruzione attesta l’eliminazione dei dati. La pulizia dei file è gestita separatamente e identificata dalla stessa operazione.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(PlatformLifecycleEvent::query()->orderByDesc('occurred_at')->orderByDesc('id'))
            ->columns([
                TextColumn::make('occurred_at')->label('Data e Ora UTC')->dateTime('d/m/Y H:i:s', timezone: 'UTC'),
                TextColumn::make('operation')->label('Operazione')->formatStateUsing(fn (string $state): string => match ($state) {
                    'archive' => 'Archivio', 'restore' => 'Ripristino', 'destroy' => 'Eliminazione Definitiva',
                    default => throw new \UnexpectedValueException('Unknown Platform lifecycle operation.'),
                }),
                TextColumn::make('tenant_id')->label('ID Tenant')->prefix('#'),
                TextColumn::make('actor_name')->label('Autore'),
                TextColumn::make('actor_id')->label('ID Autore')->prefix('#'),
                TextColumn::make('outcome')->label('Esito')->formatStateUsing(fn (string $state): string => match ($state) {
                    'completed' => 'Completato', 'data_deleted' => 'Dati Eliminati',
                    default => throw new \UnexpectedValueException('Unknown Platform lifecycle outcome.'),
                }),
                TextColumn::make('file_count')->label('File Coinvolti'),
                TextColumn::make('operation_id')->label('ID Operazione')->copyable()->searchable(),
            ])
            ->recordActions([])
            ->toolbarActions([])
            ->paginated([10, 25, 50]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }
}
