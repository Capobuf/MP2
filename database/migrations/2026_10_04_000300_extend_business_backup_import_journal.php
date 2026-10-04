<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_backup_imports', function (Blueprint $table): void {
            $table->uuid('import_operation_id')->nullable();
            $table->uuid('source_tenant_uuid')->nullable();
            $table->uuid('target_tenant_uuid')->nullable();
            $table->string('operation', 16)->nullable();
        });
        DB::table('business_backup_imports')->orderBy('id')->each(function (object $receipt): void {
            $uuid = DB::table('tenant_companies')->where('company_id', $receipt->company_id)->value('portable_uuid');
            if (! is_string($uuid)) {
                throw new RuntimeException('A legacy backup receipt has no Tenant identity.');
            }
            DB::table('business_backup_imports')->where('id', $receipt->id)->update([
                'import_operation_id' => (string) Str::uuid(), 'target_tenant_uuid' => $uuid, 'operation' => 'create',
            ]);
        });
        Schema::table('business_backup_imports', function (Blueprint $table): void {
            $table->dropForeign(['company_id']);
            $table->dropUnique(['package_id']);
            $table->index('package_id');
            $table->unsignedBigInteger('company_id')->nullable()->change();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->uuid('import_operation_id')->nullable(false)->change();
            $table->unique('import_operation_id');
            $table->uuid('target_tenant_uuid')->nullable(false)->change();
            $table->string('operation', 16)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        throw new LogicException('Portable backup receipts cannot be reverted without losing import history.');
    }
};
