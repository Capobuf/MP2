<?php

namespace Tests\Support;

final class TestPermissions
{
    public const VIEW = [
        'ViewAny:BudgetSnapshot', 'View:BudgetSnapshot',
        'ViewAny:ClosingSnapshot', 'View:ClosingSnapshot',
        'ViewAny:Contract', 'View:Contract',
        'ViewAny:CostCenter', 'View:CostCenter',
        'ViewAny:Exercise', 'View:Exercise',
        'ViewAny:Expense', 'View:Expense',
        'ViewAny:Project', 'View:Project',
        'ViewAny:Proposal', 'View:Proposal',
        'ViewAny:Supplier', 'View:Supplier',
        'View:BusinessDataBackup', 'View:CompanyAudit', 'View:ContractDeadlines', 'View:Reports',
    ];

    public const MANAGE_OPERATIONS = [
        'Create:Contract', 'Update:Contract',
        'Create:Exercise', 'Update:Exercise',
        'Create:Expense', 'Update:Expense',
        'Create:Project', 'Update:Project',
    ];

    public const MANAGE_MASTER_DATA = [
        'Create:CostCenter', 'Update:CostCenter',
        'Create:Supplier', 'Update:Supplier',
    ];

    public const MANAGE_PROPOSALS = [
        'Create:Proposal', 'Update:Proposal',
    ];

    public const APPROVE_BUDGET = ['Approve:Proposal'];

    public const CLOSE_EXERCISE = ['Close:Exercise'];

    public const CORRECT_CLOSED_EXERCISE = [
        'CorrectClosed:Exercise', 'AnnotateHistoricalError:Exercise',
    ];

    public const MANAGE_SETTINGS = ['View:CompanySettings'];

    public const MANAGE_PERMISSIONS = [
        'ViewAny:User', 'View:User', 'Create:User', 'Update:User',
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_values(array_unique(array_merge(
            self::VIEW,
            self::MANAGE_OPERATIONS,
            self::MANAGE_MASTER_DATA,
            self::MANAGE_PROPOSALS,
            self::APPROVE_BUDGET,
            self::CLOSE_EXERCISE,
            self::CORRECT_CLOSED_EXERCISE,
            self::MANAGE_SETTINGS,
            self::MANAGE_PERMISSIONS,
        )));
    }
}
