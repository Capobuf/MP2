<?php

namespace App\BusinessBackup\V1;

use App\Domain\Proposals\ProposalActionPayload;
use App\Domain\Proposals\ProposalActionType;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProposalPackageRules
{
    /** @param array<string, array{columns: list<string>, rows: list<list<string>>}> $machine */
    public function validate(array $machine): void
    {
        try {
            $this->validatePackage($machine);
        } catch (\UnexpectedValueException|\TypeError $exception) {
            throw ValidationException::withMessages(['backup' => 'Payload Proposal non valido: '.$exception->getMessage()]);
        }
    }

    /** @param array<string, array{columns: list<string>, rows: list<list<string>>}> $machine */
    private function validatePackage(array $machine): void
    {
        $refs = [];
        foreach ($machine as $sheet => $data) {
            if ($sheet === BusinessBackupContract::LONG_PAYLOADS) {
                continue;
            }
            $type = BusinessBackupContract::SHEET_REFERENCE_TYPES[$sheet];
            foreach ($data['rows'] as $i => $row) {
                $refs[$type][$row[0]] = $i + 1;
            }
        }
        $resolve = function (string $type, mixed $ref) use ($refs): int|string {
            if ($type === 'origin') {
                foreach (['expense', 'project', 'contract'] as $originType) {
                    if (isset($refs[$originType][$ref])) {
                        return $originType.':'.$refs[$originType][$ref];
                    }
                }
                $this->assert(false, 'OriginKey portabile orfano.');
            }
            $lookup = $type === 'proposal_item_uuid' ? 'proposal_item' : $type;
            $this->assert(is_string($ref) && isset($refs[$lookup][$ref]), "Riferimento Proposal orfano o di tipo errato [$type].");

            return $type === 'proposal_item_uuid' ? (string) Str::uuid() : $refs[$lookup][$ref];
        };
        $rows = fn (string $sheet): array => array_column(array_map(fn (array $row): array => array_combine($machine[$sheet]['columns'], $row), $machine[$sheet]['rows']), null, $machine[$sheet]['columns'][0]);
        $proposals = $rows('_MP2_proposals');
        $items = $rows('_MP2_proposal_items');
        $budgets = $rows('_MP2_budgets');
        $drafts = [];
        $budgetByProposal = [];
        foreach ($budgets as $budget) {
            if ($budget['proposal_ref'] === '') {
                continue;
            }
            $proposal = $proposals[$budget['proposal_ref']] ?? null;
            $this->assert($proposal !== null && $proposal['status'] === 'approved' && $proposal['exercise_ref'] === $budget['exercise_ref'] && $proposal['purpose'] === $budget['purpose'], 'Legame Proposal/Budget non valido.');
            $this->assert(! isset($budgetByProposal[$budget['proposal_ref']]), 'Una Proposal ha più Budget.');
            $budgetByProposal[$budget['proposal_ref']] = $budget['budget_ref'];
        }
        foreach ($proposals as $proposal) {
            $resolve('exercise', $proposal['exercise_ref']);
            $this->assert(in_array($proposal['purpose'], ['initial_budget', 'revision'], true), 'Scopo Proposal non valido.');
            $this->assert(in_array($proposal['status'], ['draft', 'approved', 'discarded'], true), 'Stato Proposal non valido.');
            foreach (['created_at', 'updated_at'] as $date) {
                $this->timestamp($proposal[$date]);
            }
            if ($proposal['purpose'] === 'initial_budget') {
                $this->assert($proposal['reference_budget_ref'] === '', 'Il Budget iniziale non ha Budget di riferimento.');
            } else {
                $reference = $budgets[$proposal['reference_budget_ref']] ?? null;
                $this->assert($reference !== null && $reference['exercise_ref'] === $proposal['exercise_ref'], 'Budget di riferimento Proposal non valido.');
            }
            if ($proposal['status'] === 'draft') {
                $this->assert(! isset($drafts[$proposal['exercise_ref']]), 'Più Proposal Draft nello stesso Esercizio.');
                $drafts[$proposal['exercise_ref']] = true;
                $this->assert($proposal['approved_at'] === '' && $proposal['discarded_at'] === '' && $proposal['discard_reason'] === '', 'Date terminali presenti nella Draft.');
            } elseif ($proposal['status'] === 'approved') {
                $this->timestamp($proposal['approved_at']);
                $this->assert($proposal['discarded_at'] === '' && $proposal['discard_reason'] === '' && isset($budgetByProposal[$proposal['proposal_ref']]), 'Proposal Approved senza Budget o con dati di scarto.');
            } else {
                $this->timestamp($proposal['discarded_at']);
                $this->assert($proposal['approved_at'] === '' && trim($proposal['discard_reason']) !== '', 'Dati di scarto Proposal incompleti.');
            }
            $this->assert($proposal['status'] === 'approved' || ! isset($budgetByProposal[$proposal['proposal_ref']]), 'Draft/Discarded con Budget materializzato.');
        }
        $codec = new ProposalPortableCodec;
        $sourceKeys = [];
        foreach ($items as $item) {
            $this->assert(isset($proposals[$item['proposal_ref']]), 'ProposalItem senza Proposal.');
            $this->assert(in_array($item['source_type'], ['expense', 'project', 'contract'], true), 'Tipo sorgente ProposalItem non valido.');
            if ($item['source_ref'] !== '') {
                $resolve($item['source_type'], $item['source_ref']);
                $key = $item['proposal_ref'].':'.$item['source_ref'];
                $this->assert(! isset($sourceKeys[$key]), 'Sorgente ProposalItem duplicata.');
                $sourceKeys[$key] = true;
            }
            if ($item['copied_from_source_ref'] !== '') {
                $this->assert($item['source_type'] === 'expense', 'Solo una Spesa può avere una sorgente copiata.');
                $resolve('expense', $item['copied_from_source_ref']);
            }
            $this->assert(in_array($item['readiness_state'], ['aligned', 'to_review', 'to_realign', 'inconsistent'], true) && in_array($item['read_only_source'], ['0', '1'], true), 'Readiness ProposalItem non valida.');
            $itemResolve = function (string $refType, mixed $ref) use ($resolve, $items, $item): int|string {
                if ($refType === 'proposal_item_uuid') {
                    $this->assert(is_string($ref) && isset($items[$ref]) && $items[$ref]['proposal_ref'] === $item['proposal_ref'], 'Piano collegato a un item di un’altra Proposal.');
                }

                return $resolve($refType, $ref);
            };
            foreach (['baseline_json', 'result_json'] as $column) {
                $codec->decode($this->jsonObject($item[$column]), $itemResolve, null, $item['source_type']);
            }
            $this->jsonArray($item['readiness_reasons_json']);
            if ($item['last_aligned_at'] !== '') {
                $this->timestamp($item['last_aligned_at']);
            }
        }
        $sequences = [];
        $creations = [];
        $firstActive = [];
        foreach ($rows('_MP2_proposal_actions') as $action) {
            $this->assert(isset($proposals[$action['proposal_ref']]), 'Action senza Proposal.');
            $item = $items[$action['proposal_item_ref']] ?? null;
            $this->assert($action['proposal_item_ref'] === '' || ($item !== null && $item['proposal_ref'] === $action['proposal_ref']), 'Action di un’altra Proposal.');
            $key = $action['proposal_ref'].':'.$action['sequence'];
            $this->assert(ctype_digit($action['sequence']) && (int) $action['sequence'] > 0 && ! isset($sequences[$key]), 'Sequenza Action non valida o duplicata.');
            $sequences[$key] = true;
            $this->assert($action['payload_version'] === '1', 'Versione payload Proposal non supportata.');
            $type = ProposalActionType::tryFrom($action['action_type']);
            if ($type === null) {
                throw ValidationException::withMessages(['backup' => 'Tipo Action non supportato.']);
            }
            $sourceType = match ($type) {
                ProposalActionType::CreateExpense, ProposalActionType::CopyExpense, ProposalActionType::CreateProjectAllocation,
                ProposalActionType::SetExpenseEstimates, ProposalActionType::SetExpenseOwner, ProposalActionType::SetExpenseSupplier,
                ProposalActionType::SetExpenseCostCenter, ProposalActionType::ReverseExpense, ProposalActionType::RestoreExpense,
                ProposalActionType::ExcludeExpense => 'expense',
                ProposalActionType::CreateProject, ProposalActionType::PlanProjectChildExpenses, ProposalActionType::SetProjectCostCenter,
                ProposalActionType::PlanProjectTransition, ProposalActionType::PlanProjectDeferral => 'project',
                ProposalActionType::CreateContract, ProposalActionType::PlanContractChildExpenses, ProposalActionType::AddContractCondition,
                ProposalActionType::ChangeContractEconomics, ProposalActionType::PlanContractLifecycle,
                ProposalActionType::SetContractRenewal, ProposalActionType::SetContractCostCenter => 'contract',
                ProposalActionType::LinkProjectContract => null,
            };
            $this->assert($sourceType === null ? $item === null : ($item !== null && $item['source_type'] === $sourceType), 'Action incompatibile con la sorgente dell’item.');
            $actionResolve = function (string $refType, mixed $ref) use ($resolve, $items, $action): int|string {
                if ($refType === 'proposal_item_uuid') {
                    $this->assert(is_string($ref) && isset($items[$ref]) && $items[$ref]['proposal_ref'] === $action['proposal_ref'], 'Payload collegato a un item di un’altra Proposal.');
                }

                return $resolve($refType, $ref);
            };
            $payload = $codec->decodeAction($type, $this->jsonObject($action['payload_json']), $actionResolve);
            $validationPayload = $payload;
            if ($type === ProposalActionType::CopyExpense) {
                // Validate structure before rebuilding the local source fingerprint during import.
                $validationPayload['source_fingerprint'] = hash('sha256', $action['payload_json']);
            }
            ProposalActionPayload::validate($type, $validationPayload);
            $this->timestamp($action['created_at']);
            $this->assert(in_array($action['status'], ['active', 'withdrawn'], true), 'Stato Action non valido.');
            if ($action['status'] === 'active') {
                $this->assert($action['withdrawn_at'] === '' && $action['withdraw_reason'] === '', 'Action attiva con dati di ritiro.');
                if ($item !== null && (! isset($firstActive[$item['proposal_item_ref']]) || (int) $action['sequence'] < $firstActive[$item['proposal_item_ref']]['sequence'])) {
                    $firstActive[$item['proposal_item_ref']] = ['sequence' => (int) $action['sequence'], 'type' => $type];
                }
                if (in_array($type, [ProposalActionType::CreateExpense, ProposalActionType::CopyExpense, ProposalActionType::CreateProjectAllocation, ProposalActionType::CreateProject, ProposalActionType::CreateContract], true)) {
                    $this->assert($item !== null && $item['source_ref'] === '', 'Creazione su un item con sorgente viva.');
                    $creations[$action['proposal_item_ref']][] = $type;
                }
            } else {
                $this->timestamp($action['withdrawn_at']);
            }
        }
        foreach ($items as $item) {
            if ($item['source_ref'] === '' && $proposals[$item['proposal_ref']]['status'] === 'draft') {
                $this->assert(count($creations[$item['proposal_item_ref']] ?? []) === 1, 'Un item nuovo richiede esattamente una Action di creazione attiva.');
                $this->assert(($firstActive[$item['proposal_item_ref']]['type'] ?? null) === $creations[$item['proposal_item_ref']][0], 'La creazione deve precedere le modifiche dell’item nuovo.');
            }
        }
        foreach ($rows('_MP2_budget_rows') as $row) {
            if ($row['proposal_item_ref'] !== '') {
                $item = $items[$row['proposal_item_ref']] ?? null;
                $budget = $budgets[$row['budget_ref']];
                $this->assert($item !== null && $item['proposal_ref'] === $budget['proposal_ref'] && $item['source_type'] === $row['source_type'], 'BudgetRow collegata a un item di un’altra Proposal.');
            }
        }
    }

    /** @return array<string, mixed> */
    private function jsonObject(string $json): array
    {
        $value = $this->jsonArray($json);
        $this->assert($value === [] || ! array_is_list($value), 'Atteso un oggetto JSON Proposal.');

        return $value;
    }

    /** @return array<array-key, mixed> */
    private function jsonArray(string $json): array
    {
        try {
            $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['backup' => 'JSON Proposal non valido.']);
        }
        $this->assert(is_array($value), 'JSON Proposal non valido.');

        return $value;
    }

    private function timestamp(string $value): void
    {
        $this->assert((bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:[.]\d+)?(?:Z|[+]00:00)$/D', $value), 'Timestamp Proposal non valido.');
        $normalized = str_ends_with($value, 'Z') ? substr($value, 0, -1).'+00:00' : $value;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $normalized);
        $this->assert($date !== false && $date->format('Y-m-d\TH:i:sP') === $normalized, 'Timestamp Proposal non valido.');
    }

    private function assert(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['backup' => $message]);
        }
    }
}
