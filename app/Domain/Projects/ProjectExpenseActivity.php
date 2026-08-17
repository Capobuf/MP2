<?php

namespace App\Domain\Projects;

use App\Domain\Expenses\Decimal;
use App\Domain\Expenses\ExpenseLineType;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ProjectExpenseActivity
{
    /**
     * @param  iterable<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $context
     * @return array{actual_kind: ?ProjectActualKind, activity_note: ?string, open_project: bool, overspend_note: ?string, today: string}
     */
    public static function validate(Project $project, Exercise $exercise, Company $company, iterable $lines, array $context): array
    {
        /** @var array{actual_kind: ?string, activity_note: ?string, open_project: bool, overspend_note: ?string} $validated */
        $validated = Validator::make([
            'actual_kind' => $context['actual_kind'] ?? null,
            'activity_note' => self::nullableTrim($context['activity_note'] ?? null),
            'open_project' => filter_var($context['open_project'] ?? false, FILTER_VALIDATE_BOOL),
            'overspend_note' => self::nullableTrim($context['overspend_note'] ?? null),
        ], [
            'actual_kind' => ['nullable', Rule::enum(ProjectActualKind::class)],
            'activity_note' => ['nullable', 'string'],
            'open_project' => ['boolean'],
            'overspend_note' => ['nullable', 'string'],
        ])->validate();

        if ($project->isArchived()) {
            throw ValidationException::withMessages(['project_id' => 'Ripristinare il Progetto prima di registrare nuova attività.']);
        }

        $types = [];
        foreach ($lines as $line) {
            $type = $line['type'] ?? null;
            $types[] = $type instanceof ExpenseLineType ? $type : ExpenseLineType::from((string) $type);
        }
        $hasEstimates = in_array(ExpenseLineType::Estimate, $types, true);
        $hasActuals = in_array(ExpenseLineType::Actual, $types, true);
        $today = CarbonImmutable::now($company->timezone)->startOfDay();

        if ($hasEstimates) {
            $annualDate = ProjectAnnualReferenceDate::forYear($exercise->year, $today)->toDateString();
            $annualState = $project->stateAtDate($annualDate);
            if (! in_array($annualState, [ProjectState::Planned, ProjectState::Open], true)) {
                throw ValidationException::withMessages(['project_id' => 'Il Progetto deve essere Pianificato o Aperto nel contesto dell’Esercizio per ricevere Stime.']);
            }
        }

        $actualKind = $validated['actual_kind'] === null ? null : ProjectActualKind::from($validated['actual_kind']);
        if ($hasActuals) {
            $actualKind ??= ProjectActualKind::Ordinary;
            $todayState = $project->stateAtDate($today->toDateString());

            if ($actualKind === ProjectActualKind::Ordinary) {
                if ($todayState === ProjectState::Open && $validated['open_project']) {
                    throw ValidationException::withMessages(['open_project' => 'Il Progetto è già Aperto alla data aziendale.']);
                }
                if ($todayState === ProjectState::Planned && ! $validated['open_project']) {
                    throw ValidationException::withMessages(['open_project' => 'Confermare l’apertura atomica del Progetto per registrare un Effettivo ordinario.']);
                }
                if (! in_array($todayState, [ProjectState::Open, ProjectState::Planned], true)) {
                    throw ValidationException::withMessages(['actual_kind' => 'Un Effettivo ordinario richiede il Progetto Aperto alla data aziendale.']);
                }
            } else {
                if (! in_array($todayState, [ProjectState::Closed, ProjectState::Cancelled], true)) {
                    throw ValidationException::withMessages(['actual_kind' => 'La dichiarazione tardiva, di rimborso o correttiva è ammessa solo per un Progetto Chiuso o Cancellato.']);
                }
                if ($validated['activity_note'] === null) {
                    throw ValidationException::withMessages(['activity_note' => 'La Nota è obbligatoria per un Effettivo tardivo, un rimborso o una correzione.']);
                }
            }
        } elseif ($validated['open_project'] || $actualKind !== null || $validated['activity_note'] !== null) {
            throw ValidationException::withMessages(['actual_kind' => 'La dichiarazione Effettivo è disponibile solo quando l’operazione contiene Effettivi.']);
        }

        return [
            'actual_kind' => $actualKind,
            'activity_note' => $validated['activity_note'],
            'open_project' => $hasActuals && $actualKind === ProjectActualKind::Ordinary && $validated['open_project'],
            'overspend_note' => $validated['overspend_note'],
            'today' => $today->toDateString(),
        ];
    }

    public static function annualVariance(Project $project, Exercise $exercise): string
    {
        $totals = $project->annualTotals()[$exercise->id] ?? ['allocation' => '0.00', 'actual' => '0.00'];

        return Decimal::subtract($totals['actual'], $totals['allocation']);
    }

    /** @param array{actual_kind: ?ProjectActualKind, activity_note: ?string, open_project: bool, overspend_note: ?string, today: string} $context */
    public static function assertOverspendNote(Company $company, array $context, string $before, string $after): void
    {
        if ($company->overspend_note_required
            && ProjectOverspend::detect($before, $after) !== ProjectOverspendResult::None
            && $context['overspend_note'] === null) {
            throw ValidationException::withMessages(['overspend_note' => 'La Nota di sovraspesa è obbligatoria.']);
        }
    }

    private static function nullableTrim(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
