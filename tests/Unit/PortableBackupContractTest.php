<?php

use App\BusinessBackup\V1\BusinessBackupContract;
use App\BusinessBackup\V1\PortablePayload;
use App\BusinessBackup\V1\ProposalPortableCodec;
use App\Domain\Proposals\ProposalActionType;

it('freezes the legacy schemas and keeps visible sheets version aware', function (): void {
    expect(hash('sha256', PortablePayload::json(BusinessBackupContract::schemasForVersion('1'))))->toBe('88afab789465ca0441eb9bee3bef5cacaa06c3b9585fb91d8813c064e96a4a9a')
        ->and(hash('sha256', PortablePayload::json(BusinessBackupContract::schemasForVersion('2'))))->toBe('eca35f1a39bb2af9609bc0ebaf9b8207bf462e9b380ac76a287e95ae459b26c1')
        ->and(hash('sha256', json_encode(BusinessBackupContract::VISIBLE_SHEETS)))->toBe('6ac9c45850b4574b200af3e7415b9521ed85e25a59b08f25f81b917ae79a848a')
        ->and(hash('sha256', json_encode(BusinessBackupContract::ENUMS)))->toBe('91b549a1e1f00e71c40d882d8ea4e9d1d48fcc1b91f49e6b3ab38d6ffca85909')
        ->and(BusinessBackupContract::visibleSheetsForVersion('1'))->toBe(BusinessBackupContract::VISIBLE_SHEETS)
        ->and(BusinessBackupContract::visibleSheetsForVersion('2'))->toBe(BusinessBackupContract::VISIBLE_SHEETS)
        ->and(BusinessBackupContract::visibleSheetsForVersion('3'))->toBe([...BusinessBackupContract::VISIBLE_SHEETS, 'Proposte']);
});

it('requires explicit portable support for every Proposal action', function (): void {
    $types = array_map(fn (ProposalActionType $type): string => $type->value, ProposalActionType::cases());
    expect(ProposalPortableCodec::ACTION_TYPES)->toBe($types);
});

it('ports keyed Contract exercise impacts and the unpersisted condition projection explicitly', function (): void {
    $payload = ['condition_id' => 5, 'exercise_impacts' => [7 => ['composition_before' => [['condition_id' => 5]], 'composition_after' => [['condition_id' => 0]]]]];
    $encode = fn (string $type, mixed $id): string => ['exercise' => [7 => 'EXE-0000000001'], 'contract_condition' => [5 => 'CCN-0000000001']][$type][$id];
    $portable = (new ProposalPortableCodec)->encodeAction(ProposalActionType::ChangeContractEconomics, $payload, $encode);
    expect(array_keys($portable['exercise_impacts']))->toBe(['EXE-0000000001'])
        ->and($portable['exercise_impacts']['EXE-0000000001']['composition_after'][0]['condition_ref'])->toBe('planned_condition');
    $decode = fn (string $type, mixed $ref): int => ['exercise' => ['EXE-0000000001' => 107], 'contract_condition' => ['CCN-0000000001' => 105]][$type][$ref];
    $local = (new ProposalPortableCodec)->decodeAction(ProposalActionType::ChangeContractEconomics, $portable, $decode);
    expect(array_keys($local['exercise_impacts']))->toBe([107])
        ->and($local['exercise_impacts'][107]['composition_before'][0]['condition_id'])->toBe(105)
        ->and($local['exercise_impacts'][107]['composition_after'][0]['condition_id'])->toBe(0);
});

it('remaps nested Proposal identities explicitly and regenerates correlated logical UUIDs', function (): void {
    $source = [
        'plan_baseline' => [
            'origin_key' => 'project:7', 'classification' => [['id' => 9, 'cost_center_id' => 3]],
            'expense_plan' => [['expense_id' => 11, 'origin_key' => 'expense:11', 'estimate_lines' => [['id' => 4, 'amount' => '12.00']]]],
            'contract_links' => [['id' => 5, 'contract_id' => 8]],
            'cost_center_lineage' => [['cost_center_id' => 3, 'cost_center_label' => 'IT']],
            'proposal_line_id' => '00000000-0000-4000-8000-000000000001',
        ],
    ];
    $refs = ['project' => [7 => 'PRJ-0000000001'], 'project_classification' => [9 => 'PCL-0000000001'], 'expense' => [11 => 'EXP-0000000001'], 'expense_line' => [4 => 'LIN-0000000001'], 'project_contract_link' => [5 => 'PCLN-0000000001'], 'contract' => [8 => 'CTR-0000000001'], 'cost_center' => [3 => 'CDC-0000000001']];
    $encoder = new ProposalPortableCodec;
    $encode = function (string $type, mixed $id) use ($refs): string {
        if ($type === 'origin') {
            [$type, $id] = explode(':', $id);
        }

        return $refs[$type][$id];
    };
    $portable = $encoder->encode($source, $encode, sourceType: 'project');
    expect($portable['plan_baseline']['origin_ref'])->toBe('PRJ-0000000001')
        ->and($portable['plan_baseline']['cost_center_lineage'][0]['cost_center_ref'])->toBe('CDC-0000000001');
    $decoder = new ProposalPortableCodec;
    $decode = function (string $type, mixed $ref) use ($refs): int|string {
        if ($type === 'origin') {
            foreach (['project', 'expense', 'contract'] as $candidate) {
                if (in_array($ref, $refs[$candidate], true)) {
                    return $candidate.':'.(array_search($ref, $refs[$candidate], true) + 100);
                }
            }
        }

        return array_search($ref, $refs[$type], true) + 100;
    };
    $local = $decoder->decode($portable, $decode, sourceType: 'project');
    expect($local['plan_baseline']['origin_key'])->toBe('project:107')
        ->and($local['plan_baseline']['expense_plan'][0]['estimate_lines'][0]['id'])->toBe(104)
        ->and($local['plan_baseline']['proposal_line_id'])->not->toBe($source['plan_baseline']['proposal_line_id']);
});
