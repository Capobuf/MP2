<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['package_id', 'format_version', 'company_id', 'imported_by_id', 'completed_at'])]
class BusinessBackupImport extends Model
{
    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'format_version' => 'integer',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
