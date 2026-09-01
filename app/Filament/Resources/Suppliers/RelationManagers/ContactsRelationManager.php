<?php

namespace App\Filament\Resources\Suppliers\RelationManagers;

use App\Actions\MasterData\CreateSupplierContact;
use App\Actions\MasterData\UpdateSupplierContact;
use App\Models\Supplier;
use App\Models\SupplierContact;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ContactsRelationManager extends RelationManager
{
    protected static string $relationship = 'contacts';

    protected static ?string $title = 'Referenti';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $actor = auth()->user();

        return $ownerRecord instanceof Supplier
            && $actor instanceof User
            && $actor->can('view', $ownerRecord);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('first_name')
                ->label('Nome')
                ->maxLength(255),
            TextInput::make('last_name')
                ->label('Cognome')
                ->maxLength(255),
            TextInput::make('phone')
                ->label('Telefono')
                ->maxLength(64),
            TextInput::make('email')
                ->label('Email')
                ->email()
                ->maxLength(255),
            TagsInput::make('role_tags')
                ->label('Tag di Ruolo')
                ->placeholder('Aggiungi un tag')
                ->default([])
                ->columnSpanFull(),
            Textarea::make('notes')
                ->label('Note')
                ->rows(3)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('first_name')
            ->modelLabel('referente')
            ->pluralModelLabel('referenti')
            ->columns([
                TextColumn::make('first_name')
                    ->label('Nome')
                    ->placeholder('—'),
                TextColumn::make('last_name')
                    ->label('Cognome')
                    ->placeholder('—'),
                TextColumn::make('email')
                    ->label('Email')
                    ->placeholder('—'),
                TextColumn::make('phone')
                    ->label('Telefono')
                    ->placeholder('—'),
                TextColumn::make('role_tags')
                    ->label('Tag di Ruolo')
                    ->badge()
                    ->placeholder('—'),
            ])
            ->emptyStateHeading('Nessun Referente')
            ->emptyStateDescription('Aggiungi un referente solo quando sono disponibili informazioni reali.')
            ->headerActions([
                CreateAction::make()
                    ->createAnother(false)
                    ->using(function (array $data): SupplierContact {
                        $actor = auth()->user();
                        $supplier = $this->getOwnerRecord();
                        abort_unless($actor instanceof User && $supplier instanceof Supplier, 403);

                        return app(CreateSupplierContact::class)->execute(
                            $actor,
                            $supplier,
                            $data,
                            (string) Str::uuid(),
                        );
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(function (array $data, Model $record): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User && $record instanceof SupplierContact, 403);

                        app(UpdateSupplierContact::class)->execute(
                            $actor,
                            $record,
                            $data,
                            (string) Str::uuid(),
                        );
                    }),
            ]);
    }

    protected function getCreateAuthorizationResponse(): Response
    {
        $actor = auth()->user();
        $supplier = $this->getOwnerRecord();

        return $actor instanceof User
            && $supplier instanceof Supplier
            && $actor->can('update', $supplier)
                ? Response::allow()
                : Response::deny();
    }
}
