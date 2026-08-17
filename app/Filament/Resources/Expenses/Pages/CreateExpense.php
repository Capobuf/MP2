<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Actions\Operations\CreateExpense as CreateExpenseAction;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Company;
use App\Models\Expense;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CreateExpense extends CreateRecord
{
    protected static string $resource = ExpenseResource::class;

    protected static ?string $title = 'Nuova spesa';

    public string $operationId;

    public function mount(): void
    {
        $this->operationId = (string) Str::uuid();
        parent::mount();
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        $company = Filament::getTenant();
        abort_unless($actor instanceof User && $company instanceof Company, 403);

        return app(CreateExpenseAction::class)->execute($actor, $company, $data, $this->operationId);
    }

    protected function getRedirectUrl(): string
    {
        /** @var Expense $record */
        $record = $this->record;

        return ExpenseResource::getUrl('view', ['record' => $record]);
    }
}
