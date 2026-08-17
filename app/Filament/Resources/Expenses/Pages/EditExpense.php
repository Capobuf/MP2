<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Actions\Operations\UpdateExpense;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use App\Models\User;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class EditExpense extends EditRecord
{
    protected static string $resource = ExpenseResource::class;

    public string $operationId;

    public function mount(int|string $record): void
    {
        $this->operationId = (string) Str::uuid();
        parent::mount($record);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('description')->label('Descrizione')->required()->maxLength(255),
            Textarea::make('notes')->label('Note'),
        ]);
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $record instanceof Expense, 403);

        return app(UpdateExpense::class)->updateDetails($actor, $record, $data, $this->operationId);
    }

    protected function getRedirectUrl(): string
    {
        return ExpenseResource::getUrl('view', ['record' => $this->record]);
    }
}
