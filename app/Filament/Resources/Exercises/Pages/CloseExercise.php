<?php

namespace App\Filament\Resources\Exercises\Pages;

use App\Actions\Closing\CloseExercise as CloseExerciseAction;
use App\Actions\Closing\PrepareExerciseClosing;
use App\Domain\Projects\ProjectDeferralMode;
use App\Domain\Projects\ProjectState;
use App\Filament\Resources\Closings\ClosingResource;
use App\Filament\Resources\Exercises\ExerciseResource;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Project;
use App\Models\ProjectDeferral;
use App\Models\Supplier;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class CloseExercise extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ExerciseResource::class;

    protected string $view = 'filament.resources.exercises.pages.close-exercise';

    /** @var array<string, mixed> */
    public array $closing = [];

    /** @var array<string, mixed>|null */
    public ?array $review = null;

    /** @var array<string, mixed>|null */
    public ?array $preparedInput = null;

    public ?string $reviewFingerprint = null;

    public ?string $executionFingerprint = null;

    public string $operationId = '';

    /** @var array<int, list<array<string, mixed>>> */
    public array $reprogrammableLines = [];

    /** @var array<int, string> */
    public array $supplierOptions = [];

    public bool $nextExerciseExists = false;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $exercise = $this->exercise();
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->can('close', $exercise), 403);
        abort_if(! $exercise->isOpen(), 404);

        $this->operationId = (string) Str::uuid();
        $nextExercise = Exercise::query()
            ->where('company_id', $exercise->company_id)
            ->where('year', $exercise->year + 1)
            ->first();
        $this->nextExerciseExists = $nextExercise !== null;
        $this->supplierOptions = Supplier::query()
            ->where('company_id', $exercise->company_id)
            ->active()
            ->orderBy('legal_name')
            ->pluck('legal_name', 'id')
            ->mapWithKeys(fn (mixed $label, mixed $id): array => [(int) $id => (string) $label])
            ->all();

        $projects = Project::query()
            ->where('company_id', $exercise->company_id)
            ->with(['deferrals', 'expenses.lines', 'expenses.supplier'])
            ->orderBy('title')
            ->get();
        $projectState = [];
        foreach ($projects as $project) {
            $state = $project->stateAtDate($exercise->year.'-12-31');
            if (! in_array($state, [ProjectState::Planned, ProjectState::Open], true)) {
                continue;
            }
            /** @var ProjectDeferral|null $deferral */
            $deferral = $project->deferrals->firstWhere('source_exercise_id', $exercise->id);
            $mode = $deferral?->mode ?? ProjectDeferralMode::None;
            $projectState[$project->id] = [
                'project_id' => $project->id,
                'title' => $project->title,
                'current_state' => $state->value,
                'final_state' => $state->value,
                'current_mode' => $mode->value,
                'mode' => $mode === ProjectDeferralMode::None ? '' : $mode->value,
                'carryover_amount' => $mode === ProjectDeferralMode::Carryover ? (string) $deferral?->carryover_amount : '',
                'reprogrammed_amount' => $mode === ProjectDeferralMode::Reprogramming ? (string) $deferral?->reprogrammed_amount : '',
                'reason' => '',
                'reductions' => [],
            ];

            $lines = [];
            foreach ($project->expenses->where('exercise_id', $exercise->id)->sortBy('id') as $expense) {
                if ($expense->isReversed()) {
                    continue;
                }
                foreach ($expense->lines->sortBy('id') as $line) {
                    if ($line->isAnnulled() || $line->lineType()->value !== 'estimate') {
                        continue;
                    }
                    $supplierChoice = $expense->supplier_id === null
                        ? 'none'
                        : ($expense->supplier?->isArchived() ? '' : (string) $expense->supplier_id);
                    $lines[] = [
                        'source_line_id' => $line->id,
                        'expense_id' => $expense->id,
                        'expense_description' => $expense->description,
                        'amount' => (string) $line->amount,
                        'note' => $line->note,
                        'source_supplier_label' => $expense->supplier?->legal_name,
                        'source_supplier_archived' => $expense->supplier?->isArchived() ?? false,
                    ];
                    $projectState[$project->id]['reductions'][$line->id] = [
                        'selected' => false,
                        'reduction_amount' => (string) $line->amount,
                        'destination_supplier_id' => $supplierChoice,
                    ];
                }
            }
            $this->reprogrammableLines[$project->id] = $lines;
        }

        $this->closing = [
            'management_continues' => $this->nextExerciseExists ? true : null,
            'projects' => $projectState,
            'warnings_acknowledged' => false,
            'confirmed' => false,
        ];
    }

    public function getTitle(): string
    {
        return 'Chiusura Esercizio '.$this->exercise()->year;
    }

    public function updatedClosing(mixed $value = null, ?string $key = null): void
    {
        $this->review = null;
        $this->preparedInput = null;
        $this->reviewFingerprint = null;
        $this->executionFingerprint = null;
    }

    public function reviewClosing(): void
    {
        $this->resetErrorBag();
        try {
            $prepared = app(PrepareExerciseClosing::class)->execute(
                $this->actor(),
                $this->exercise(),
                $this->closingInput(),
            );
            $this->preparedInput = $prepared['input'];
            $this->review = $prepared['review']->toArray();
            $this->reviewFingerprint = $prepared['review']->fingerprint();
            $this->executionFingerprint = $prepared['execution_fingerprint'];
        } catch (ValidationException $exception) {
            $this->validationErrors($exception);
        }
    }

    public function closeExercise(): void
    {
        $this->resetErrorBag();
        if ($this->reviewFingerprint === null || $this->executionFingerprint === null) {
            $this->addError('closing', 'Ricalcolare il riepilogo prima di confermare la Chiusura.');

            return;
        }

        try {
            $prepared = app(PrepareExerciseClosing::class)->execute(
                $this->actor(),
                $this->exercise(),
                $this->closingInput(),
            );
            if (! hash_equals($this->reviewFingerprint, $prepared['review']->fingerprint())) {
                $this->review = null;
                $this->preparedInput = null;
                $this->reviewFingerprint = null;
                $this->executionFingerprint = null;
                $this->addError('closing', 'Le decisioni o i dati sono cambiati: ricalcolare il riepilogo.');

                return;
            }
            if (! $prepared['review']->canClose()) {
                $this->review = $prepared['review']->toArray();
                $this->addError('closing', 'Risolvere i controlli bloccanti prima di confermare.');

                return;
            }

            $input = [
                ...$prepared['input'],
                'review_fingerprint' => $prepared['execution_fingerprint'],
                'warnings_acknowledged' => (bool) ($this->closing['warnings_acknowledged'] ?? false),
                'confirmed' => (bool) ($this->closing['confirmed'] ?? false),
            ];
            $snapshot = app(CloseExerciseAction::class)->execute(
                $this->actor(),
                $this->exercise(),
                $input,
                $this->operationId,
            );
            Notification::make()
                ->success()
                ->title('Esercizio Chiuso')
                ->body('La Snapshot di Chiusura è stata materializzata e non può essere modificata.')
                ->send();
            $company = Filament::getTenant();
            $this->redirect(ClosingResource::getUrl('view', ['record' => $snapshot], tenant: $company));
        } catch (ValidationException $exception) {
            $this->validationErrors($exception);
        }
    }

    /** @return array<string, mixed> */
    private function closingInput(): array
    {
        $projects = [];
        foreach ((array) ($this->closing['projects'] ?? []) as $projectId => $state) {
            if (! is_array($state)) {
                continue;
            }
            $finalState = (string) ($state['final_state'] ?? '');
            $terminal = in_array($finalState, [ProjectState::Closed->value, ProjectState::Cancelled->value], true);
            $mode = $terminal ? ProjectDeferralMode::None->value : (string) ($state['mode'] ?? '');
            $decision = [
                'project_id' => (int) $projectId,
                'final_state' => $finalState,
                'mode' => $mode,
                'reason' => filled($state['reason'] ?? null) ? trim((string) $state['reason']) : null,
            ];
            if ($mode === ProjectDeferralMode::Carryover->value) {
                $decision['carryover_amount'] = $state['carryover_amount'] ?? null;
            }
            if ($mode === ProjectDeferralMode::Reprogramming->value) {
                $reductions = [];
                foreach ((array) ($state['reductions'] ?? []) as $lineId => $reduction) {
                    if (! is_array($reduction) || ! filter_var($reduction['selected'] ?? false, FILTER_VALIDATE_BOOL)) {
                        continue;
                    }
                    $row = [
                        'source_line_id' => (int) $lineId,
                        'reduction_amount' => $reduction['reduction_amount'] ?? null,
                    ];
                    $supplierChoice = $reduction['destination_supplier_id'] ?? '';
                    if ($supplierChoice !== '') {
                        $row['destination_supplier_id'] = $supplierChoice === 'none' ? null : (int) $supplierChoice;
                    }
                    $reductions[] = $row;
                }
                $decision['source_estimate_reductions'] = $reductions;
            }
            $projects[(int) $projectId] = $decision;
        }

        return [
            'management_continues' => $this->nextExerciseExists
                ? true
                : ($this->closing['management_continues'] ?? null),
            'projects' => $projects,
        ];
    }

    private function exercise(): Exercise
    {
        $record = $this->getRecord();
        if (! $record instanceof Exercise) {
            throw new \UnexpectedValueException('Invalid Exercise record.');
        }

        return $record;
    }

    private function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function validationErrors(ValidationException $exception): void
    {
        foreach ($exception->errors() as $key => $messages) {
            foreach ($messages as $message) {
                $this->addError((string) $key, $message);
            }
        }
    }
}
