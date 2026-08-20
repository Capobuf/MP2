<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_renewal_configurations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('contract_id');
            $table->date('effective_from');
            $table->boolean('automatic_renewal');
            $table->date('expiry_anchor_date')->nullable();
            $table->unsignedInteger('renewal_duration_months')->nullable();
            $table->unsignedInteger('notice_days')->nullable();
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['contract_id', 'company_id'], 'contract_renewal_configurations_contract_company_foreign')
                ->references(['id', 'company_id'])->on('contracts')->restrictOnDelete();
            $table->unique(['id', 'company_id'], 'contract_renewal_configurations_id_company_unique');
            $table->unique(['contract_id', 'effective_from'], 'contract_renewal_configurations_effective_unique');
            $table->index(['company_id', 'effective_from']);
        });

        DB::statement('ALTER TABLE contract_renewal_configurations ADD CONSTRAINT contract_renewal_configurations_duration_rules CHECK (automatic_renewal = 0 OR expiry_anchor_date IS NULL OR renewal_duration_months > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_renewal_configurations');
    }
};
