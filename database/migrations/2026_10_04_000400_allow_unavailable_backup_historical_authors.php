<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['proposals' => 'created_by_id', 'proposal_actions' => 'created_by_id', 'attachments' => 'uploaded_by_id'] as $table => $column) {
            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->unsignedBigInteger($column)->nullable()->change();
            });
        }
        DB::statement('ALTER TABLE attachments DROP CHECK attachments_detachment_complete');
        DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_detachment_complete CHECK (detached_at IS NOT NULL OR detached_by_id IS NULL)');
    }

    public function down(): void
    {
        throw new LogicException('Imported historical authors cannot be reconstructed.');
    }
};
