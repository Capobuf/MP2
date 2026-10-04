<?php

namespace App\BusinessBackup\V1;

use App\Domain\Proposals\ProposalActionType;
use Illuminate\Support\Str;

final class ProposalPortableCodec
{
    /** @var list<string> */
    public const ACTION_TYPES = [
        'create_expense', 'copy_expense', 'set_expense_estimates', 'set_expense_owner',
        'set_expense_supplier', 'set_expense_cost_center', 'reverse_expense', 'restore_expense',
        'exclude_expense', 'create_project', 'plan_project_child_expenses', 'set_project_cost_center',
        'plan_project_transition', 'plan_project_deferral', 'create_project_allocation',
        'plan_contract_child_expenses', 'create_contract', 'add_contract_condition',
        'change_contract_economics', 'plan_contract_lifecycle', 'set_contract_renewal',
        'set_contract_cost_center', 'link_project_contract',
    ];

    /** @var array<string, string> */
    private const REFERENCES = [
        'exercise_id' => 'exercise', 'source_exercise_id' => 'exercise', 'destination_exercise_id' => 'exercise',
        'target_exercise_id' => 'exercise', 'supplier_id' => 'supplier', 'destination_supplier_id' => 'supplier',
        'cost_center_id' => 'cost_center', 'expense_id' => 'expense', 'source_expense_id' => 'expense',
        'project_id' => 'project', 'contract_id' => 'contract', 'line_id' => 'expense_line',
        'expense_line_id' => 'expense_line', 'source_line_id' => 'expense_line', 'condition_id' => 'contract_condition',
        'restore_link_id' => 'project_contract_link',
        'project_item_id' => 'proposal_item_uuid', 'contract_item_id' => 'proposal_item_uuid',
        'proposal_item_id' => 'proposal_item_uuid',
    ];

    /** @var array<string, string> */
    private const ROW_IDS = [
        'estimate_lines' => 'expense_line', 'actual_lines' => 'expense_line',
        'transitions' => 'project_transition', 'conditions' => 'contract_condition',
        'lifecycle_facts' => 'contract_lifecycle', 'renewal_configurations' => 'contract_renewal',
        'contract_links' => 'project_contract_link', 'project_links' => 'project_contract_link',
    ];

    /** @var list<string> */
    private const ORIGINS = ['origin_key', 'copied_from_origin_key', 'source_expense_origin_key', 'project_origin_key', 'contract_origin_key'];

    /** @var list<string> */
    private const REVISIONS = ['revision', 'source_revision', 'project_revision', 'expense_revision', 'line_revision', 'source_expense_revision', 'source_line_revision'];

    /** @var list<string> */
    private const FINGERPRINTS = ['source_fingerprint', 'project_fingerprint', 'active_reprogramming_fingerprint'];

    /** @var list<string> */
    private const OPERATIONS = ['operation_id', 'reprogramming_operation_id', 'active_reprogramming_operation_id'];

    /** @var array<string, string> */
    private array $logical = [];

    /** @param array<string, mixed> $payload
     * @param  callable(string, mixed): mixed  $resolve
     * @return array<array-key, mixed>
     */
    public function encodeAction(ProposalActionType $type, array $payload, callable $resolve): array
    {
        $this->assertActionType($type->value);

        return $this->encode($payload, $resolve);
    }

    /** @param array<string, mixed> $payload
     * @param  callable(string, mixed): mixed  $resolve
     * @return array<array-key, mixed>
     */
    public function decodeAction(ProposalActionType $type, array $payload, callable $resolve): array
    {
        $this->assertActionType($type->value);

        return $this->decode($payload, $resolve);
    }

    /** @param array<array-key, mixed> $value
     * @param  callable(string, mixed): mixed  $resolve
     * @return array<array-key, mixed>
     */
    public function encode(array $value, callable $resolve, ?string $context = null, ?string $sourceType = null): array
    {
        return $this->transform($value, $resolve, true, $context, $sourceType);
    }

    /** @param array<array-key, mixed> $value
     * @param  callable(string, mixed): mixed  $resolve
     * @return array<array-key, mixed>
     */
    public function decode(array $value, callable $resolve, ?string $context = null, ?string $sourceType = null): array
    {
        return $this->transform($value, $resolve, false, $context, $sourceType);
    }

    private function assertActionType(string $type): void
    {
        if (! in_array($type, self::ACTION_TYPES, true)) {
            throw new \UnexpectedValueException("Unsupported portable Proposal action [$type].");
        }
    }

    /** @param array<array-key, mixed> $value
     * @param  callable(string, mixed): mixed  $resolve
     * @return array<array-key, mixed>
     */
    private function transform(array $value, callable $resolve, bool $encode, ?string $context, ?string $sourceType): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if ($context === 'exercise_impacts') {
                $result[$resolve('exercise', $key)] = $this->transform($item, $resolve, $encode, null, $sourceType);

                continue;
            }
            if (is_int($key)) {
                $result[$key] = is_array($item) ? $this->transform($item, $resolve, $encode, $context, $sourceType) : $item;

                continue;
            }
            $localKey = $encode ? $key : $this->localKey($key);
            $type = self::REFERENCES[$localKey] ?? null;
            if ($localKey === 'id') {
                $type = $context === 'classification' ? ($sourceType === 'project' ? 'project_classification' : 'contract_classification') : (self::ROW_IDS[$context ?? ''] ?? null);
                if ($type === null) {
                    throw new \UnexpectedValueException('Unknown Proposal snapshot row identity.');
                }
            }
            if ($type !== null) {
                if ($localKey === 'condition_id' && in_array($context, ['composition_before', 'composition_after'], true)
                    && ($encode ? $item === 0 : $item === 'planned_condition')) {
                    // Domain projections use zero for a condition that has not been persisted.
                    $mapped = $encode ? 'planned_condition' : 0;
                } else {
                    $mapped = $item === null || $item === '' ? null : $resolve($type, $item);
                }
                $result[$encode ? $this->portableKey($localKey) : $localKey] = $mapped;
            } elseif (in_array($localKey, self::ORIGINS, true)) {
                $result[$encode ? $this->portableKey($localKey) : $localKey] = $item === null || $item === '' ? null : $resolve('origin', $item);
            } elseif (in_array($localKey, ['child_item_ids', 'project_item_ids'], true)) {
                $type = 'proposal_item_uuid';
                $result[$encode ? $this->portableKey($localKey) : $localKey] = array_map(fn (mixed $ref): mixed => $resolve($type, $ref), $item);
            } elseif (in_array($localKey, ['proposal_line_id', 'proposal_destination_id'], true)) {
                $result[$encode ? $this->portableKey($localKey) : $localKey] = $item === null ? null : $this->logicalIdentity((string) $item, $encode);
            } elseif (in_array($localKey, self::REVISIONS, true)) {
                $result[$localKey] = $encode ? null : 0;
            } elseif (in_array($localKey, self::FINGERPRINTS, true)) {
                $result[$localKey] = null;
            } elseif (in_array($localKey, self::OPERATIONS, true)) {
                $result[$localKey] = $item === null ? null : ($encode ? $this->logicalIdentity((string) $item, true) : $this->logicalIdentity((string) $item, false));
            } else {
                if (str_ends_with($localKey, '_id') || str_ends_with($localKey, '_ids') || $localKey === 'company_id') {
                    throw new \UnexpectedValueException("Unknown Proposal identity [$localKey].");
                }
                $result[$key] = is_array($item) ? $this->transform($item, $resolve, $encode, $localKey, $sourceType) : $item;
            }
        }

        return $result;
    }

    private function logicalIdentity(string $value, bool $encode): string
    {
        if ($encode) {
            return $this->logical[$value] ??= sprintf('LOG-%010d', count($this->logical) + 1);
        }
        if (! preg_match('/^LOG-\d{10}$/D', $value)) {
            throw new \UnexpectedValueException('Invalid Proposal logical reference.');
        }

        return $this->logical[$value] ??= (string) Str::uuid();
    }

    private function portableKey(string $key): string
    {
        return match ($key) {
            'id' => 'row_ref', 'cost_center_lineage' => 'cost_center_lineage_refs',
            'origin_key' => 'origin_ref', 'copied_from_origin_key' => 'copied_from_origin_ref',
            'source_expense_origin_key' => 'source_expense_origin_ref', 'project_origin_key' => 'project_origin_ref',
            'contract_origin_key' => 'contract_origin_ref',
            default => str_ends_with($key, '_ids') ? substr($key, 0, -4).'_refs' : substr($key, 0, -3).'_ref',
        };
    }

    private function localKey(string $key): string
    {
        foreach (['id', ...array_keys(self::REFERENCES), ...self::ORIGINS, 'child_item_ids', 'project_item_ids', 'cost_center_lineage', 'proposal_line_id', 'proposal_destination_id'] as $local) {
            if ($this->portableKey($local) === $key) {
                return $local;
            }
        }

        return $key;
    }
}
