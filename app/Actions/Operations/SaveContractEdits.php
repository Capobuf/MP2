<?php

namespace App\Actions\Operations;

use App\Actions\Proposals\MarkProposalItemsToRealign;
use App\Domain\Contracts\ContractConditionRules;
use App\Domain\Contracts\ContractImpactFingerprint;
use App\Domain\Expenses\Decimal;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\Exercise;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

final class SaveContractEdits
{
    /** @return array<string, mixed> */
    public function state(Contract $contract): array
    {
        return [
            ...$contract->only(['title', 'notes', 'supplier_id', 'automatic_renewal', 'renewal_duration_months', 'notice_days']),
            'contractual_start_date' => $contract->contractualStartDate()->toDateString(),
            'next_expiry_date' => $contract->nextExpiryDate()?->toDateString(),
            'conditions' => $contract->conditions()->active()->get()->map(fn (ContractCondition $condition): array => [
                ...$condition->only(['id', 'amount', 'cycle', 'attribution_mode']),
                'valid_from' => $condition->validFrom()->toDateString(),
                'valid_to' => $condition->validTo()?->toDateString(),
            ])->all(),
            'classifications' => $contract->company->exercises()->open()->orderBy('year')->get()->map(fn (Exercise $exercise): array => [
                'exercise_id' => $exercise->id,
                'cost_center_selection' => (string) ($contract->classifications()->where('exercise_id', $exercise->id)->value('cost_center_id') ?? '__unclassified__'),
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function changes(array $original, array $data): array
    {
        $changes = ['details' => [], 'conditions' => [], 'classifications' => [], 'renewal' => []];
        foreach (['title', 'notes', 'supplier_id'] as $field) {
            $value = $field === 'supplier_id' ? (int) $data[$field] : (trim((string) ($data[$field] ?? '')) === '' ? null : trim((string) $data[$field]));
            if ($value !== $original[$field]) {
                $changes['details'][$field] = $value;
            }
        }
        if (($data['contractual_start_date'] ?? null) !== $original['contractual_start_date']) {
            throw ValidationException::withMessages(['contractual_start_date' => 'La data di inizio originaria non è correggibile. Gestisci gli eventi futuri dal Ciclo di Vita.']);
        }
        $conditions = collect($this->rows($data['conditions'] ?? [], 'conditions'))->keyBy('id');
        if ($conditions->count() !== count($original['conditions']) || count($data['conditions'] ?? []) !== $conditions->count()) {
            throw ValidationException::withMessages(['conditions' => 'Per aggiungere o annullare condizioni usa le operazioni nella scheda del Contratto.']);
        }
        foreach ($original['conditions'] as $before) {
            $after = $conditions->get($before['id']);
            if (! is_array($after) || ($after['valid_from'] ?? null) !== $before['valid_from'] || ($after['valid_to'] ?? null) !== $before['valid_to']) {
                throw ValidationException::withMessages(['conditions' => 'Le date delle condizioni esistenti non sono correggibili. La decorrenza di un nuovo accordo viene calcolata al salvataggio.']);
            }
            $terms = ['amount' => Decimal::money((string) $after['amount']), 'cycle' => $after['cycle'], 'attribution_mode' => $after['attribution_mode']];
            if ($terms !== array_intersect_key($before, $terms)) {
                $changes['conditions'][$before['id']] = $terms;
            }
        }
        $classifications = collect($this->rows($data['classifications'] ?? [], 'classifications'))->keyBy('exercise_id');
        if ($classifications->count() !== count($original['classifications']) || count($data['classifications'] ?? []) !== $classifications->count()) {
            throw ValidationException::withMessages(['classifications' => 'Modifica soltanto le classificazioni degli Esercizi Aperti mostrati.']);
        }
        foreach ($original['classifications'] as $before) {
            $after = $classifications->get($before['exercise_id']);
            $selection = $after['cost_center_selection'] ?? null;
            if ($selection !== '__unclassified__' && (! ctype_digit((string) $selection) || (int) $selection < 1)) {
                throw ValidationException::withMessages(['classifications' => 'Il Centro di Costo selezionato non è valido.']);
            }
            if ((string) $selection !== $before['cost_center_selection']) {
                $changes['classifications'][$before['exercise_id']] = $selection === '__unclassified__' ? null : (int) $selection;
            }
        }
        foreach (['next_expiry_date', 'automatic_renewal', 'renewal_duration_months', 'notice_days'] as $field) {
            $value = $data[$field] ?? null;
            $value = match ($field) {
                'automatic_renewal' => (bool) $value,
                'next_expiry_date' => $value ?: null,
                default => filled($value) ? (string) $value : null,
            };
            $before = in_array($field, ['renewal_duration_months', 'notice_days'], true) && $original[$field] !== null ? (string) $original[$field] : $original[$field];
            if ($value !== $before) {
                $changes['renewal'][$field] = $value;
            }
        }
        if (array_key_exists('supplier_id', $changes['details']) && ($changes['conditions'] !== [] || $changes['renewal'] !== [])) {
            throw ValidationException::withMessages(['supplier_id' => 'Salva prima il cambio di Fornitore, poi le modifiche economiche o contrattuali: queste possono determinare il primo utilizzo economico.']);
        }
        if (count($changes['conditions']) + count($changes['classifications']) + (int) ($changes['renewal'] !== []) > 1) {
            throw ValidationException::withMessages(['conditions' => 'Salva separatamente ogni condizione economica, la modifica dei termini contrattuali e ogni classificazione annuale: ciascuna richiede la propria anteprima. Titolo, note e allegati possono essere salvati insieme.']);
        }

        return $changes;
    }

    /** @return list<array<string, mixed>> */
    private function rows(mixed $value, string $field): array
    {
        if (! is_array($value)) {
            throw ValidationException::withMessages([$field => 'I dati del form non sono validi.']);
        }
        foreach ($value as $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages([$field => 'I dati del form non sono validi.']);
            }
        }

        return array_values($value);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $decisions
     * @return array<string, mixed>
     */
    public function preview(User $actor, Contract $contract, array $changes, array $decisions): array
    {
        Gate::forUser($actor)->authorize('update', $contract);
        if ($contract->isArchived()) {
            throw ValidationException::withMessages(['contract' => 'Ripristina il Contratto prima di modificarne condizioni, termini o classificazioni.']);
        }
        $reason = trim((string) ($decisions['reason'] ?? '')) ?: null;
        $review = ['kind' => 'details', 'input' => [], 'plan' => [], 'reason' => $reason];
        if ($changes['conditions'] !== []) {
            $id = array_key_first($changes['conditions']);
            $condition = $contract->conditions()->active()->findOrFail($id);
            $input = $changes['conditions'][$id] + $decisions + ['reason' => $reason];
            if (($decisions['meaning'] ?? null) === 'correction') {
                $plan = app(CorrectContractCondition::class)->preview($contract, $condition, $input);
            } elseif (($decisions['meaning'] ?? null) === 'change') {
                $plan = app(ChangeContractCondition::class)->preview($contract, $condition, $input);
                ContractConditionRules::assertMayPersist($contract, $plan->effectiveDate, $condition->validTo()?->toDateString(), $contract->conditions, $condition->id);
                ContractConditionRules::assertCurrentlyActive($contract, $plan->effectiveDate);
            } else {
                throw ValidationException::withMessages(['meaning' => 'Indica se il dato precedente era errato oppure se l’accordo è cambiato.']);
            }
            $affectedBudget = Exercise::query()->whereIn('id', array_keys($plan->exerciseImpacts))->whereHas('budgets')->exists();
            if ($affectedBudget && $reason === null) {
                throw ValidationException::withMessages(['reason' => 'La motivazione è obbligatoria dopo un Budget approvato.']);
            }
            $review = ['kind' => $plan->operationKind, 'input' => $input, 'plan' => $plan->toArray(), 'reason' => $reason];
        } elseif ($changes['classifications'] !== []) {
            $id = array_key_first($changes['classifications']);
            $exercise = $contract->company->exercises()->findOrFail($id);
            $plan = app(UpdateContractClassification::class)->preview($actor, $contract, $exercise, $changes['classifications'][$id]);
            $review = ['kind' => 'classification', 'input' => [], 'plan' => $plan->toArray(), 'reason' => $reason];
        } elseif ($changes['renewal'] !== []) {
            if ($contract->nextExpiryDate() !== null && $contract->nextExpiryDate()->toDateString() <= now($contract->company->timezone)->toDateString()) {
                throw ValidationException::withMessages(['renewal' => 'Sono presenti scadenze da elaborare. Attendi l’elaborazione automatica dei rinnovi e ricarica il Contratto prima di modificarne i termini.']);
            }
            $input = array_replace($contract->only(['automatic_renewal', 'renewal_duration_months', 'notice_days']), $changes['renewal']);
            $input['expiry_anchor_date'] = array_key_exists('next_expiry_date', $changes['renewal'])
                ? $changes['renewal']['next_expiry_date']
                : $contract->nextExpiryDate()?->toDateString();
            unset($input['next_expiry_date']);
            $input['effective_from'] = $decisions['effective_from'] ?? null;
            $input['reason'] = $reason;
            $input['expected_revision'] = $contract->revision;
            $review = ['kind' => 'renewal', 'input' => $input, 'plan' => app(UpdateContractRenewal::class)->preview($contract, $input), 'reason' => $reason];
            $today = now($contract->company->timezone)->toDateString();
            // The existing action immediately replaces current fields, even for a future configuration.
            $latestConfiguration = $contract->renewalConfigurations()->reorder()->latest('effective_from')->first();
            if ($input['effective_from'] > $today || $latestConfiguration?->effectiveFrom()->toDateString() > $input['effective_from']) {
                throw ValidationException::withMessages(['effective_from' => 'La gestione attuale non consente di salvare questi termini senza anticipare o sostituire una configurazione successiva. Da questa schermata puoi aggiungere soltanto la configurazione corrente.']);
            }
            $lastMaterializedExpiry = $contract->lifecycleFacts()->whereIn('type', ['renewal', 'expiry_cessation'])->max('declared_contractual_date');
            if ($lastMaterializedExpiry !== null && $input['effective_from'] <= $lastMaterializedExpiry) {
                throw ValidationException::withMessages(['effective_from' => 'La modifica deve essere successiva all’ultima scadenza già elaborata. I termini storici non sono correggibili da questa schermata.']);
            }
            if ($input['expiry_anchor_date'] !== null && $input['expiry_anchor_date'] <= $today) {
                throw ValidationException::withMessages(['next_expiry_date' => 'La nuova prossima scadenza deve essere futura. Le scadenze già trascorse non sono correggibili da questa schermata.']);
            }
        }
        // Keep the reviewed context, including closed years and concurrent proposals, bound to confirmation.
        $review['exercises'] = $contract->company->exercises()->orderBy('id')->get()->map(fn (Exercise $exercise): array => [
            'id' => $exercise->id, 'year' => $exercise->year, 'revision' => $exercise->revision,
            'open' => $exercise->isOpen(), 'budget' => $exercise->hasApprovedBudget(),
        ])->all();
        $review['proposals'] = $contract->company->proposals()->orderBy('id')->get(['id', 'revision', 'status'])->toArray();
        $review['changes'] = $changes;

        return $review;
    }

    /**
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $review
     */
    public function execute(User $actor, Contract $contract, array $original, int $revision, array $data, ?array $review, string $operationId): Contract
    {
        $uploads = [];
        try {
            return DB::transaction(function () use ($actor, $contract, $original, $revision, $data, $review, $operationId, &$uploads): Contract {
                Company::query()->lockForUpdate()->findOrFail($contract->company_id);
                $locked = Contract::query()->lockForUpdate()->findOrFail($contract->id);
                Gate::forUser($actor)->authorize('update', $locked);
                if ($locked->revision !== $revision) {
                    throw ValidationException::withMessages(['title' => 'Il Contratto è cambiato mentre lo modificavi. Ricarica la pagina prima di salvare.']);
                }
                $changes = $this->changes($original, $data);
                $hasImpact = $changes['conditions'] !== [] || $changes['classifications'] !== [] || $changes['renewal'] !== [];
                if ($hasImpact) {
                    if ($review === null) {
                        throw ValidationException::withMessages(['conditions' => 'Conferma prima l’anteprima delle modifiche.']);
                    }
                    $current = $this->preview($actor, $locked, $changes, $review['input'] + ['meaning' => $review['kind'], 'reason' => $review['reason']]);
                    if (! hash_equals(ContractImpactFingerprint::make($review), ContractImpactFingerprint::make($current))) {
                        throw ValidationException::withMessages(['conditions' => 'L’impatto è cambiato dopo l’anteprima. Annulla la conferma e rivedi le modifiche.']);
                    }
                    $id = Uuid::uuid5($operationId, 'impact')->toString();
                    if (in_array($review['kind'], ['change', 'correction'], true)) {
                        $condition = $locked->conditions()->findOrFail($review['plan']['conditionId']);
                        $action = app($review['kind'] === 'change' ? ChangeContractCondition::class : CorrectContractCondition::class);
                        $plan = $action->preview($locked, $condition, $review['input']);
                        if ($review['kind'] === 'change') {
                            $action->execute($actor, $locked, $condition, $review['input'], $plan->fingerprint(), $plan->effectiveDate, $id);
                        } else {
                            $action->execute($actor, $locked, $condition, $review['input'], $plan->fingerprint(), $id);
                        }
                    } elseif ($review['kind'] === 'classification') {
                        $exercise = Exercise::query()->findOrFail($review['plan']['exerciseId']);
                        $action = app(UpdateContractClassification::class);
                        $plan = $action->preview($actor, $locked, $exercise, $review['plan']['newCostCenterId']);
                        $action->confirm($actor, $locked, $plan, $id, $review['reason']);
                    } else {
                        app(UpdateContractRenewal::class)->execute($actor, $locked, $review['input'] + ['impact_confirmed' => true], $id);
                    }
                    $locked->refresh();
                }
                if ($changes['details'] !== []) {
                    $locked = app(UpdateContract::class)->execute($actor, $locked, [
                        ...$locked->only(['title', 'notes', 'supplier_id']), ...$changes['details'],
                        'reason' => $review['reason'] ?? $data['reason'] ?? null,
                    ], Uuid::uuid5($operationId, 'details')->toString());
                }
                if ($changes['conditions'] !== [] || $changes['classifications'] !== [] || array_key_exists('supplier_id', $changes['details'])) {
                    app(MarkProposalItemsToRealign::class)->execute($locked->company_id, contractIds: [$locked->id]);
                }
                foreach (array_values($data['attachments'] ?? []) as $index => $file) {
                    if (! $file instanceof UploadedFile) {
                        throw ValidationException::withMessages(['attachments' => 'Gli Allegati caricati non sono validi.']);
                    }
                    $uploads[] = app(UploadAttachment::class)->execute($actor, $locked, $file, Uuid::uuid5($operationId, "attachment:{$index}")->toString());
                }

                return $locked;
            });
        } catch (\Throwable $exception) {
            foreach ($uploads as $upload) {
                if (! Attachment::query()->whereKey($upload->id)->exists()) {
                    Storage::disk($upload->storage_disk)->delete($upload->storage_path);
                }
            }
            throw $exception;
        }
    }
}
