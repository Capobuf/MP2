<?php

namespace App\Filament\Resources\Proposals;

use App\Filament\Resources\Proposals\Pages\ListProposals;
use App\Filament\Resources\Proposals\Pages\ViewProposal;
use App\Filament\Resources\Proposals\Schemas\ProposalInfolist;
use App\Filament\Resources\Proposals\Tables\ProposalsTable;
use App\Models\Company;
use App\Models\Proposal;
use App\Models\TenantCompany;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** @extends resource<Proposal> */
class ProposalResource extends Resource
{
    protected static ?string $model = Proposal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Proposte';

    protected static string|\UnitEnum|null $navigationGroup = 'Pianificazione';

    protected static ?string $modelLabel = 'proposta';

    protected static ?string $pluralModelLabel = 'proposte';

    protected static ?int $navigationSort = 10;

    public static function infolist(Schema $schema): Schema
    {
        return ProposalInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProposalsTable::configure($table);
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;

        return $user instanceof User && $company instanceof Company && $user->can('viewAny', Proposal::class);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof Proposal && auth()->user() instanceof User && auth()->user()->can('view', $record);
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /** @return Builder<Proposal> */
    public static function getEloquentQuery(): Builder
    {
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;

        return $company instanceof Company ? parent::getEloquentQuery()->whereBelongsTo($company)->with(['exercise', 'creator', 'referenceBudget', 'items', 'actions', 'actionHistory.withdrawer']) : parent::getEloquentQuery()->whereRaw('1 = 0');
    }

    public static function getPages(): array
    {
        return ['index' => ListProposals::route('/'), 'view' => ViewProposal::route('/{record}')];
    }
}
