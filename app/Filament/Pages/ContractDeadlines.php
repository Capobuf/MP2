<?php

namespace App\Filament\Pages;

use App\Domain\Contracts\ContractDeadline;
use App\Domain\Contracts\ContractState;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\Company;
use App\Models\Contract;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Supplier;
use App\Models\TenantCompany;
use App\Support\ExerciseContext;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ContractDeadlines extends Page implements HasTable
{
    use HasPageShield;
    use InteractsWithTable;

    protected string $view = 'filament.pages.contract-deadlines';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Scadenze';

    protected static ?string $navigationParentItem = 'Contratti';

    protected static ?string $title = 'Scadenze Contratti';

    protected static ?int $navigationSort = 10;

    /** @var array<int, ContractDeadline> */
    protected array $deadlineCache = [];

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Contract::query()
                ->where('company_id', $this->company()->id)
                ->active()
                ->with([
                    'supplier', 'lifecycleFacts', 'renewalConfigurations', 'conditions',
                    'classifications.costCenter', 'company.exercises',
                ]))
            ->columns([
                TextColumn::make('title')->label('Contratto')->searchable()->url(fn (Contract $record): string => ContractResource::getUrl('view', ['record' => $record])),
                TextColumn::make('supplier.legal_name')->label('Fornitore')->searchable()
                    ->url(fn (Contract $record): string => SupplierResource::getUrl('view', ['record' => $record->supplier])),
                TextColumn::make('current_state')->label('Stato')->state(fn (Contract $record): string => $this->deadline($record)->state->label())->badge(),
                TextColumn::make('contractual_start_date')->label('Inizio')->date('d/m/Y'),
                TextColumn::make('next_expiry_date')->label('Prossima Scadenza')->date('d/m/Y')->placeholder('Scadenza non definita'),
                TextColumn::make('automatic_renewal')->label('Rinnovo Automatico')->formatStateUsing(fn (bool $state): string => $state ? 'Sì' : 'No'),
                TextColumn::make('renewal_duration_months')->label('Durata Rinnovo')->suffix(' mesi')->placeholder('—'),
                TextColumn::make('notice_days')->label('Preavviso')->suffix(' giorni')->placeholder('—'),
                TextColumn::make('notice_limit_date')->label('Limite Disdetta')->state(fn (Contract $record): ?string => $this->deadline($record)->noticeLimitDate)->date('d/m/Y')->placeholder('—'),
                TextColumn::make('planned_cessation_date')->label('Cessazione Pianificata')->state(fn (Contract $record): ?string => $this->deadline($record)->plannedCessationDate)->date('d/m/Y')->placeholder('—'),
                TextColumn::make('days_remaining')->label('Giorni alla Scadenza')->state(fn (Contract $record): ?int => $this->deadline($record)->daysUntilExpiry)->placeholder('—'),
                TextColumn::make('notice_days_remaining')->label('Giorni al Limite')->state(fn (Contract $record): ?int => $this->deadline($record)->daysUntilNoticeLimit)->placeholder('—'),
                TextColumn::make('cost_center')->label('Centro di Costo')->state(fn (Contract $record): string => $this->costCenterLabel($record)),
                TextColumn::make('renewal_warning')->label('Avviso')->state(fn (Contract $record): ?string => $this->deadline($record)->renewalWithoutCondition ? 'Rinnovo senza condizione economica' : null)->placeholder('—')->wrap(),
                TextColumn::make('timeline')->label('Timeline')->state('Apri Timeline')->url(fn (Contract $record): string => CompanyAudit::getUrl(['tenant' => $record->company, 'contract' => $record->id])),
            ])
            ->filters([
                Filter::make('expiry_interval')->label('Intervallo Scadenza')->schema([
                    DatePicker::make('from')->label('Scadenza dal'),
                    DatePicker::make('until')->label('Scadenza al'),
                ])->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['from'] ?? null, fn (Builder $query, mixed $date): Builder => $query->whereDate('next_expiry_date', '>=', $date))
                    ->when($data['until'] ?? null, fn (Builder $query, mixed $date): Builder => $query->whereDate('next_expiry_date', '<=', $date))),
                Filter::make('notice_interval')->label('Intervallo Limite Disdetta')->schema([
                    DatePicker::make('from')->label('Limite dal'),
                    DatePicker::make('until')->label('Limite al'),
                ])->query(function (Builder $query, array $data): Builder {
                    if (blank($data['from'] ?? null) && blank($data['until'] ?? null)) {
                        return $query;
                    }
                    $query->whereNotNull('next_expiry_date')->whereNotNull('notice_days');
                    if (filled($data['from'] ?? null)) {
                        $query->whereRaw('DATE_SUB(next_expiry_date, INTERVAL notice_days DAY) >= ?', [$data['from']]);
                    }
                    if (filled($data['until'] ?? null)) {
                        $query->whereRaw('DATE_SUB(next_expiry_date, INTERVAL notice_days DAY) <= ?', [$data['until']]);
                    }

                    return $query;
                }),
                TernaryFilter::make('automatic_renewal')->label('Rinnovo Automatico'),
                TernaryFilter::make('undefined_expiry')->label('Scadenza Non Definita')->queries(
                    true: fn (Builder $query): Builder => $query->whereNull('next_expiry_date'),
                    false: fn (Builder $query): Builder => $query->whereNotNull('next_expiry_date'),
                    blank: fn (Builder $query): Builder => $query,
                ),
                SelectFilter::make('lifecycle_state')->label('Stato')->options(ContractState::options())
                    ->query(function (Builder $query, array $data): Builder {
                        $state = $data['value'] ?? null;
                        if (! is_string($state) || $state === '') {
                            return $query;
                        }
                        $today = CarbonImmutable::now($this->company()->timezone)->toDateString();
                        $ids = (clone $query)->get()->filter(fn (Model $model): bool => $model instanceof Contract && $model->stateAtDate($today)->value === $state)->pluck('id');

                        return $query->whereIn('id', $ids);
                    }),
                SelectFilter::make('supplier')->label('Fornitore')->options(fn (): array => Supplier::query()->where('company_id', $this->company()->id)->orderBy('legal_name')->pluck('legal_name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                        ? $query
                        : $query->where('supplier_id', $data['value'])),
                SelectFilter::make('cost_center')->label('Centro di Costo')->options(fn (): array => CostCenter::query()->where('company_id', $this->company()->id)->orderBy('name')->pluck('name', 'id')->all())
                    ->query(function (Builder $query, array $data): Builder {
                        $costCenterId = $data['value'] ?? null;
                        $exercise = $this->exercise();
                        if (blank($costCenterId) || $exercise === null) {
                            return $query;
                        }

                        return $query->whereHas('classifications', fn (Builder $classification): Builder => $classification
                            ->where('exercise_id', $exercise->id)->where('cost_center_id', $costCenterId));
                    }),
            ])
            ->defaultSort('next_expiry_date')
            ->recordUrl(fn (Contract $record): string => ContractResource::getUrl('view', ['record' => $record]));
    }

    private function deadline(Contract $contract): ContractDeadline
    {
        return $this->deadlineCache[$contract->id] ??= ContractDeadline::fromContract(
            $contract,
            $this->exercise(),
            CarbonImmutable::now($this->company()->timezone)->startOfDay(),
        );
    }

    private function costCenterLabel(Contract $contract): string
    {
        $costCenterId = $this->deadline($contract)->costCenterId;
        if ($costCenterId === null) {
            return 'Non classificato';
        }
        $costCenter = $contract->classifications->firstWhere('exercise_id', $this->exercise()?->id)?->costCenter;

        return $costCenter === null ? 'Centro di Costo #'.$costCenterId : $costCenter->name.($costCenter->isArchived() ? ' · Archiviato' : '');
    }

    private function exercise(): ?Exercise
    {
        return app(ExerciseContext::class)->current($this->company());
    }

    private function company(): Company
    {
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;
        abort_unless($company instanceof Company, 404);

        return $company;
    }
}
