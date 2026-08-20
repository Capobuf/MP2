<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_conditions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('contract_id');
            $table->enum('cycle', ['monthly', 'quarterly', 'semiannual', 'annual']);
            $table->enum('attribution_mode', ['cycle_start', 'cycle_end']);
            $table->decimal('amount', 19, 2);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('annulled_at')->nullable();
            $table->foreignId('annulled_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['contract_id', 'company_id'], 'contract_conditions_contract_company_foreign')
                ->references(['id', 'company_id'])->on('contracts')->restrictOnDelete();
            $table->index(['company_id', 'contract_id', 'valid_from', 'valid_to'], 'contract_conditions_validity_index');
        });

        DB::statement('ALTER TABLE contract_conditions ADD CONSTRAINT contract_conditions_amount_nonnegative CHECK (amount >= 0)');
        DB::statement('ALTER TABLE contract_conditions ADD CONSTRAINT contract_conditions_validity_order CHECK (valid_to IS NULL OR valid_to >= valid_from)');
        DB::statement("ALTER TABLE contract_conditions ADD CONSTRAINT contract_conditions_annulment_reason CHECK (annulled_at IS NULL OR (annulled_by_id IS NOT NULL AND CHAR_LENGTH(TRIM(COALESCE(reason, ''))) > 0))");
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_conditions');
    }
};
