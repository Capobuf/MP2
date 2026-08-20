<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_lifecycle_facts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('contract_id');
            $table->enum('type', ['activation', 'cessation', 'expiry_cessation', 'reactivation', 'cancellation', 'renewal']);
            $table->date('declared_contractual_date');
            $table->date('state_change_date')->nullable();
            $table->date('renewed_expiry_date')->nullable();
            $table->unsignedBigInteger('renewal_configuration_id')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('annulled_at')->nullable();
            $table->foreignId('annulled_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('annulment_reason')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['contract_id', 'company_id'], 'contract_lifecycle_facts_contract_company_foreign')
                ->references(['id', 'company_id'])->on('contracts')->restrictOnDelete();
            $table->foreign(['renewal_configuration_id', 'company_id'], 'contract_lifecycle_facts_configuration_company_foreign')
                ->references(['id', 'company_id'])->on('contract_renewal_configurations')->restrictOnDelete();
            $table->index(['company_id', 'contract_id', 'state_change_date'], 'contract_lifecycle_facts_state_index');
            $table->index(['contract_id', 'renewed_expiry_date'], 'contract_lifecycle_facts_renewal_index');
        });

        DB::statement('ALTER TABLE contract_lifecycle_facts ADD COLUMN active_state_change_date DATE GENERATED ALWAYS AS (CASE WHEN annulled_at IS NULL THEN state_change_date ELSE NULL END) STORED');
        DB::statement('ALTER TABLE contract_lifecycle_facts ADD COLUMN active_renewed_expiry_date DATE GENERATED ALWAYS AS (CASE WHEN annulled_at IS NULL THEN renewed_expiry_date ELSE NULL END) STORED');
        Schema::table('contract_lifecycle_facts', function (Blueprint $table): void {
            $table->unique(['contract_id', 'active_state_change_date'], 'contract_lifecycle_facts_active_state_unique');
            $table->unique(['contract_id', 'active_renewed_expiry_date'], 'contract_lifecycle_facts_active_renewal_unique');
        });
        DB::statement("ALTER TABLE contract_lifecycle_facts ADD CONSTRAINT contract_lifecycle_facts_shape CHECK ((type = 'renewal' AND state_change_date IS NULL AND renewed_expiry_date IS NOT NULL AND renewal_configuration_id IS NOT NULL) OR (type <> 'renewal' AND state_change_date IS NOT NULL AND renewed_expiry_date IS NULL))");
        DB::statement("ALTER TABLE contract_lifecycle_facts ADD CONSTRAINT contract_lifecycle_facts_reason_required CHECK (type NOT IN ('cessation', 'reactivation', 'cancellation') OR CHAR_LENGTH(TRIM(COALESCE(reason, ''))) > 0)");
        DB::statement("ALTER TABLE contract_lifecycle_facts ADD CONSTRAINT contract_lifecycle_facts_annulment_complete CHECK ((annulled_at IS NULL AND annulled_by_id IS NULL AND annulment_reason IS NULL) OR (annulled_at IS NOT NULL AND annulled_by_id IS NOT NULL AND CHAR_LENGTH(TRIM(COALESCE(annulment_reason, ''))) > 0))");
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_lifecycle_facts');
    }
};
