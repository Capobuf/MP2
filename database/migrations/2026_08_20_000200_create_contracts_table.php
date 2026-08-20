<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('supplier_id');
            $table->string('title');
            $table->text('notes')->nullable();
            $table->date('contractual_start_date');
            $table->date('next_expiry_date')->nullable();
            $table->date('renewal_anchor_date')->nullable();
            $table->boolean('automatic_renewal')->default(true);
            $table->unsignedInteger('renewal_duration_months')->nullable();
            $table->unsignedInteger('notice_days')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('revision')->default(0);
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['supplier_id', 'company_id'], 'contracts_supplier_company_foreign')
                ->references(['id', 'company_id'])->on('suppliers')->restrictOnDelete();
            $table->unique(['id', 'company_id'], 'contracts_id_company_unique');
            $table->index(['company_id', 'archived_at', 'title']);
            $table->index(['company_id', 'supplier_id']);
            $table->index(['company_id', 'next_expiry_date']);
            $table->index(['company_id', 'automatic_renewal', 'next_expiry_date'], 'contracts_company_renewal_expiry_index');
        });

        DB::statement('ALTER TABLE contracts ADD CONSTRAINT contracts_expiry_after_start CHECK (next_expiry_date IS NULL OR next_expiry_date >= contractual_start_date)');
        DB::statement('ALTER TABLE contracts ADD CONSTRAINT contracts_renewal_duration_rules CHECK (automatic_renewal = 0 OR next_expiry_date IS NULL OR renewal_duration_months > 0)');
        DB::statement('ALTER TABLE contracts ADD CONSTRAINT contracts_anchor_expiry_coherence CHECK ((next_expiry_date IS NULL AND renewal_anchor_date IS NULL) OR (next_expiry_date IS NOT NULL AND renewal_anchor_date IS NOT NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
