<?php

namespace App\Models;

use App\Domain\Company\ClosingUnclassifiedPolicy;
use Database\Factories\CompanyFactory;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'timezone', 'overspend_note_required', 'unclassified_closing_policy'])]
class Company extends Model implements HasName
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    /** @return HasOne<TenantCompany, $this> */
    public function tenantCompany(): HasOne
    {
        return $this->hasOne(TenantCompany::class, 'company_id');
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<AuditEvent, $this> */
    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class);
    }

    /** @return HasMany<Supplier, $this> */
    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class);
    }

    /** @return HasMany<CostCenter, $this> */
    public function costCenters(): HasMany
    {
        return $this->hasMany(CostCenter::class);
    }

    /** @return HasMany<Exercise, $this> */
    public function exercises(): HasMany
    {
        return $this->hasMany(Exercise::class);
    }

    /** @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** @return HasMany<Contract, $this> */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    /** @return HasMany<ProjectContractLink, $this> */
    public function projectContractLinks(): HasMany
    {
        return $this->hasMany(ProjectContractLink::class);
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /** @return HasMany<Proposal, $this> */
    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class);
    }

    /** @return HasMany<BudgetSnapshot, $this> */
    public function budgets(): HasMany
    {
        return $this->hasMany(BudgetSnapshot::class);
    }

    /** @return HasMany<ClosingSnapshot, $this> */
    public function closingSnapshots(): HasMany
    {
        return $this->hasMany(ClosingSnapshot::class);
    }

    /** @return HasMany<LateCorrection, $this> */
    public function lateCorrections(): HasMany
    {
        return $this->hasMany(LateCorrection::class);
    }

    /** @return HasMany<HistoricalErrorAnnotation, $this> */
    public function historicalErrorAnnotations(): HasMany
    {
        return $this->hasMany(HistoricalErrorAnnotation::class);
    }

    /** @return HasOne<BusinessBackupImport, $this> */
    public function businessBackupImport(): HasOne
    {
        return $this->hasOne(BusinessBackupImport::class);
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    public function closingUnclassifiedPolicy(): ClosingUnclassifiedPolicy
    {
        $value = $this->getRawOriginal('unclassified_closing_policy');

        if (! is_string($value)) {
            throw new \UnexpectedValueException('Invalid persisted unclassified closing policy.');
        }

        return ClosingUnclassifiedPolicy::from($value);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'overspend_note_required' => 'boolean',
            'unclassified_closing_policy' => ClosingUnclassifiedPolicy::class,
        ];
    }
}
