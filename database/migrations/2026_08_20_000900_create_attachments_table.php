<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('contract_id')->nullable();
            $table->unsignedBigInteger('expense_id')->nullable();
            $table->foreignId('expense_line_id')->nullable()->constrained('expense_lines')->restrictOnDelete();
            $table->string('storage_disk', 64)->default('local');
            $table->string('storage_path')->unique();
            $table->string('original_name');
            $table->string('media_type')->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('detached_at')->nullable();
            $table->foreignId('detached_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['contract_id', 'company_id'], 'attachments_contract_company_foreign')
                ->references(['id', 'company_id'])->on('contracts')->restrictOnDelete();
            $table->foreign(['expense_id', 'company_id'], 'attachments_expense_company_foreign')
                ->references(['id', 'company_id'])->on('expenses')->restrictOnDelete();
            $table->index(['company_id', 'contract_id', 'detached_at']);
            $table->index(['company_id', 'expense_id', 'detached_at']);
            $table->index(['expense_line_id', 'detached_at']);
        });

        DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_exactly_one_owner CHECK ((contract_id IS NOT NULL) + (expense_id IS NOT NULL) + (expense_line_id IS NOT NULL) = 1)');
        DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_detachment_complete CHECK ((detached_at IS NULL AND detached_by_id IS NULL) OR (detached_at IS NOT NULL AND detached_by_id IS NOT NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
