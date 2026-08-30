<?php

namespace App\Filament\Pages;

use App\Actions\BusinessBackup\ExportBusinessBackup;
use App\Actions\BusinessBackup\StoreBusinessBackupOnDrive;
use App\Models\Company;
use App\Models\TenantCompany;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class BusinessDataBackup extends Page
{
    protected string $view = 'filament.pages.business-data-backup';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static ?string $navigationLabel = 'Backup dati';

    protected static string|\UnitEnum|null $navigationGroup = 'Amministrazione';

    protected static ?string $title = 'Backup dati aziendali';

    protected static ?int $navigationSort = 30;

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;

        return $user instanceof User && $company instanceof Company && Gate::forUser($user)->allows('view', $company);
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('download')
                ->label('Scarica XLSX')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(function (): BinaryFileResponse {
                    $artifact = app(ExportBusinessBackup::class)->execute($this->company(), $this->actor());

                    return response()->download($artifact['path'], $artifact['filename'])->deleteFileAfterSend(true);
                }),
            Action::make('drive')
                ->label('Salva su Drive')
                ->icon(Heroicon::OutlinedCloudArrowUp)
                ->visible(StoreBusinessBackupOnDrive::configured())
                ->requiresConfirmation()
                ->action(function (): void {
                    $filename = app(StoreBusinessBackupOnDrive::class)->execute($this->company(), $this->actor());
                    Notification::make()->success()->title('Backup salvato su Drive')->body($filename)->send();
                }),
        ];
    }

    private function company(): Company
    {
        $tenant = Filament::getTenant();
        abort_unless($tenant instanceof TenantCompany && $tenant->company instanceof Company, 404);

        return $tenant->company;
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
