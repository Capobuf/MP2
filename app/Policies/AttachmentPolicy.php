<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\HistoricalErrorAnnotation;
use App\Models\Proposal;
use App\Models\TenantCompany;
use App\Models\User;

class AttachmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Contract')
            || $user->can('ViewAny:Expense')
            || $user->can('ViewAny:Proposal');
    }

    public function view(User $user, Attachment $attachment): bool
    {
        $company = $attachment->company;
        if (! $this->ownerBelongsToCompany($attachment, $company)) {
            return false;
        }

        return $this->canUse($user, $company, $this->permissionForAttachment($attachment, false));
    }

    public function create(User $user, ?Company $company = null, Contract|Expense|ExpenseLine|HistoricalErrorAnnotation|Proposal|null $owner = null): bool
    {
        if ($company === null && $owner !== null) {
            $company = $this->ownerCompany($owner);
        }
        if ($company === null) {
            return $user->can('Update:Contract')
                || $user->can('Update:Expense')
                || $user->can('Update:Proposal');
        }
        if ($owner !== null && ! $this->ownerBelongsToCompany($owner, $company)) {
            return false;
        }
        if (($owner instanceof ExpenseLine && $owner->lateCorrection()->exists())
            || $owner instanceof HistoricalErrorAnnotation) {
            return $this->canUse($user, $company, 'AnnotateHistoricalError:Exercise');
        }

        return $owner !== null
            && $this->canUse($user, $company, $this->permissionForOwner($owner, true));
    }

    public function update(User $user, Attachment $attachment): bool
    {
        if (($attachment->expense_line_id !== null
                && $attachment->expenseLine?->lateCorrection()->exists())
            || $attachment->historical_error_annotation_id !== null) {
            return false;
        }

        return $this->canUse(
            $user,
            $attachment->company,
            $this->permissionForAttachment($attachment, true),
        );
    }

    public function delete(User $user, Attachment $attachment): bool
    {
        return false;
    }

    private function ownerCompany(Contract|Expense|ExpenseLine|HistoricalErrorAnnotation|Proposal $owner): Company
    {
        return match (true) {
            $owner instanceof ExpenseLine => $owner->expense->company,
            $owner instanceof HistoricalErrorAnnotation => $owner->company,
            $owner instanceof Expense => $owner->company,
            $owner instanceof Contract => $owner->company,
            default => $owner->company,
        };
    }

    private function ownerBelongsToCompany(Attachment|Contract|Expense|ExpenseLine|HistoricalErrorAnnotation|Proposal $owner, Company $company): bool
    {
        try {
            $ownerCompany = $owner instanceof Attachment
                ? $this->attachmentOwnerCompany($owner)
                : $this->ownerCompany($owner);
        } catch (\Throwable) {
            return false;
        }

        return $ownerCompany->id === $company->id;
    }

    private function attachmentOwnerCompany(Attachment $attachment): Company
    {
        if ($attachment->historical_error_annotation_id !== null) {
            return $attachment->historicalErrorAnnotation->company;
        }
        if ($attachment->expense_line_id !== null) {
            return $attachment->expenseLine->expense->company;
        }
        if ($attachment->expense_id !== null) {
            return $attachment->expense->company;
        }
        if ($attachment->contract_id !== null) {
            return $attachment->contract->company;
        }
        if ($attachment->proposal_id !== null) {
            return $attachment->proposal->company;
        }

        throw new \UnexpectedValueException('Allegato senza proprietario.');
    }

    private function canUse(User $user, Company $company, string $permission): bool
    {
        return $company->tenantCompany instanceof TenantCompany
            && $user->canAccessTenant($company->tenantCompany)
            && $user->can($permission);
    }

    private function permissionForAttachment(Attachment $attachment, bool $write): string
    {
        if ($attachment->historical_error_annotation_id !== null) {
            return $write ? 'AnnotateHistoricalError:Exercise' : 'View:Exercise';
        }
        if ($attachment->expense_line_id !== null || $attachment->expense_id !== null) {
            return $write ? 'Update:Expense' : 'View:Expense';
        }
        if ($attachment->contract_id !== null) {
            return $write ? 'Update:Contract' : 'View:Contract';
        }
        if ($attachment->proposal_id !== null) {
            return $write ? 'Update:Proposal' : 'View:Proposal';
        }

        throw new \UnexpectedValueException('Allegato senza proprietario.');
    }

    private function permissionForOwner(Contract|Expense|ExpenseLine|HistoricalErrorAnnotation|Proposal $owner, bool $write): string
    {
        return match (true) {
            $owner instanceof Contract => $write ? 'Update:Contract' : 'View:Contract',
            $owner instanceof Expense, $owner instanceof ExpenseLine => $write ? 'Update:Expense' : 'View:Expense',
            $owner instanceof HistoricalErrorAnnotation => $write ? 'AnnotateHistoricalError:Exercise' : 'View:Exercise',
            default => $write ? 'Update:Proposal' : 'View:Proposal',
        };
    }
}
