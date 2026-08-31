<?php

namespace App\Filament\Platform\Resources\TenantCompanies\Tables;

use App\Actions\Tenancy\ArchiveTenantCompany;
use App\Actions\Tenancy\DestroyTenantCompany;
use App\Actions\Tenancy\RestoreTenantCompany;
use App\Domain\Company\TenantCompanyStatus;
use App\Models\TenantCompany;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TenantCompaniesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('company_id')
            ->columns([
                TextColumn::make('company.name')->label('Azienda')->searchable()->sortable(),
                TextColumn::make('company_id')->label('ID')->formatStateUsing(fn (int $state): string => '#'.$state)->sortable(),
                TextColumn::make('status')->label('Stato')
                    ->formatStateUsing(fn (TenantCompany $record): string => $record->status()->label())
                    ->badge()
                    ->color(fn (TenantCompany $record): string => $record->status() === TenantCompanyStatus::Active ? 'success' : 'gray'),
                TextColumn::make('updated_at')->label('Ultimo aggiornamento tecnico')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Stato')->options([
                    TenantCompanyStatus::Active->value => 'Attivo',
                    TenantCompanyStatus::Archived->value => 'Archiviato',
                ]),
            ])
            ->recordUrl(null)
            ->recordActions([
                Action::make('openTenant')
                    ->label('Apri tenant')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->url(fn (TenantCompany $record): ?string => Filament::getPanel('admin')->getUrl($record))
                    ->visible(fn (TenantCompany $record): bool => $record->status() === TenantCompanyStatus::Active),
                Action::make('archive')
                    ->label('Archivia')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(fn (TenantCompany $record): string => "Archivia {$record->company->name}")
                    ->modalDescription('I dati saranno conservati integralmente, ma il Tenant non sarà più accessibile e i processi automatici saranno sospesi.')
                    ->modalSubmitActionLabel('Archivia')
                    ->successNotificationTitle('Tenant Azienda archiviato')
                    ->visible(fn (TenantCompany $record): bool => $record->status() === TenantCompanyStatus::Active)
                    ->action(function (TenantCompany $record): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);
                        app(ArchiveTenantCompany::class)->execute($actor, $record);
                    }),
                Action::make('restore')
                    ->label('Ripristina')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(fn (TenantCompany $record): string => "Ripristina {$record->company->name}")
                    ->modalDescription('Il Tenant tornerà accessibile secondo le capacità conservate. Date e stati del dominio non saranno modificati.')
                    ->modalSubmitActionLabel('Ripristina')
                    ->successNotificationTitle('Tenant Azienda ripristinato')
                    ->visible(fn (TenantCompany $record): bool => $record->status() === TenantCompanyStatus::Archived)
                    ->action(function (TenantCompany $record): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);
                        app(RestoreTenantCompany::class)->execute($actor, $record);
                    }),
                Action::make('destroy')
                    ->label('Elimina definitivamente')
                    ->color('danger')
                    ->modalHeading(fn (TenantCompany $record): string => "Elimina definitivamente {$record->company->name}")
                    ->modalDescription('La cancellazione è irreversibile e rimuove Azienda, contratti, progetti, spese, snapshot, audit, allegati e ogni altro dato appartenente al Tenant. Il Tenant deve essere privo di utenti.')
                    ->modalSubmitActionLabel('Elimina definitivamente')
                    ->steps([
                        Step::make('Irreversibilità')->schema([
                            Checkbox::make('irreversibility_confirmed')
                                ->label('Confermo che dati, cronologia, audit e file saranno eliminati in modo irreversibile')
                                ->accepted()
                                ->validationMessages(['accepted' => 'È necessario accettare questa conferma per proseguire.'])
                                ->required(),
                        ]),
                        Step::make('Distruzione definitiva')->schema([
                            Checkbox::make('destruction_confirmed')
                                ->label('Confermo la distruzione definitiva del Tenant Azienda indicato')
                                ->accepted()
                                ->validationMessages(['accepted' => 'È necessario accettare questa conferma per proseguire.'])
                                ->required(),
                        ]),
                    ])
                    ->action(function (TenantCompany $record, array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);

                        $result = app(DestroyTenantCompany::class)->execute(
                            $actor,
                            $record,
                            ($data['irreversibility_confirmed'] ?? false) === true,
                            ($data['destruction_confirmed'] ?? false) === true,
                        );

                        $notification = Notification::make()->success();
                        if ($result->isComplete()) {
                            $notification->title('Cancellazione completata');
                        } else {
                            $notification
                                ->warning()
                                ->title('Dati eliminati; pulizia file in attesa')
                                ->body("File ancora da rimuovere: {$result->filesPending}.");
                        }

                        $notification->send();
                    }),
            ])
            ->bulkActions([]);
    }
}
