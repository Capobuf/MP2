<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE project_transitions DROP CHECK project_transitions_annulment_complete');
        DB::statement('ALTER TABLE contract_lifecycle_facts DROP CHECK contract_lifecycle_facts_annulment_complete');
        DB::statement('ALTER TABLE contract_conditions DROP CHECK contract_conditions_annulment_reason');

        Schema::table('project_transitions', fn (Blueprint $table) => $table->unsignedBigInteger('created_by_id')->nullable()->change());
        Schema::table('contract_renewal_configurations', fn (Blueprint $table) => $table->unsignedBigInteger('created_by_id')->nullable()->change());
        Schema::table('contract_lifecycle_facts', fn (Blueprint $table) => $table->unsignedBigInteger('created_by_id')->nullable()->change());
        Schema::table('contract_conditions', fn (Blueprint $table) => $table->unsignedBigInteger('created_by_id')->nullable()->change());

        Schema::table('budget_snapshots', function (Blueprint $table): void {
            $table->dropForeign('budget_snapshots_proposal_company_foreign');
            $table->unsignedBigInteger('proposal_id')->nullable()->change();
            $table->unsignedBigInteger('approved_by_id')->nullable()->change();
            $table->foreign(['proposal_id', 'company_id'], 'budget_snapshots_proposal_company_foreign')
                ->references(['id', 'company_id'])->on('proposals')->cascadeOnDelete();
        });

        Schema::table('closing_snapshots', fn (Blueprint $table) => $table->unsignedBigInteger('closed_by_id')->nullable()->change());
        Schema::table('late_corrections', fn (Blueprint $table) => $table->unsignedBigInteger('recorded_by_id')->nullable()->change());
        Schema::table('historical_error_annotations', fn (Blueprint $table) => $table->unsignedBigInteger('recorded_by_id')->nullable()->change());

        DB::statement("ALTER TABLE project_transitions ADD CONSTRAINT project_transitions_annulment_complete CHECK ((annulled_at IS NULL AND annulled_by_id IS NULL AND annulment_reason IS NULL) OR (annulled_at IS NOT NULL AND CHAR_LENGTH(TRIM(COALESCE(annulment_reason, ''))) > 0))");
        DB::statement("ALTER TABLE contract_lifecycle_facts ADD CONSTRAINT contract_lifecycle_facts_annulment_complete CHECK ((annulled_at IS NULL AND annulled_by_id IS NULL AND annulment_reason IS NULL) OR (annulled_at IS NOT NULL AND CHAR_LENGTH(TRIM(COALESCE(annulment_reason, ''))) > 0))");
        DB::statement("ALTER TABLE contract_conditions ADD CONSTRAINT contract_conditions_annulment_reason CHECK ((annulled_at IS NULL AND annulled_by_id IS NULL) OR (annulled_at IS NOT NULL AND CHAR_LENGTH(TRIM(COALESCE(reason, ''))) > 0))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE project_transitions DROP CHECK project_transitions_annulment_complete');
        DB::statement('ALTER TABLE contract_lifecycle_facts DROP CHECK contract_lifecycle_facts_annulment_complete');
        DB::statement('ALTER TABLE contract_conditions DROP CHECK contract_conditions_annulment_reason');

        Schema::table('budget_snapshots', function (Blueprint $table): void {
            $table->dropForeign('budget_snapshots_proposal_company_foreign');
            $table->unsignedBigInteger('proposal_id')->nullable(false)->change();
            $table->unsignedBigInteger('approved_by_id')->nullable(false)->change();
            $table->foreign(['proposal_id', 'company_id'], 'budget_snapshots_proposal_company_foreign')
                ->references(['id', 'company_id'])->on('proposals')->cascadeOnDelete();
        });

        Schema::table('project_transitions', fn (Blueprint $table) => $table->unsignedBigInteger('created_by_id')->nullable(false)->change());
        Schema::table('contract_renewal_configurations', fn (Blueprint $table) => $table->unsignedBigInteger('created_by_id')->nullable(false)->change());
        Schema::table('contract_lifecycle_facts', fn (Blueprint $table) => $table->unsignedBigInteger('created_by_id')->nullable(false)->change());
        Schema::table('contract_conditions', fn (Blueprint $table) => $table->unsignedBigInteger('created_by_id')->nullable(false)->change());
        Schema::table('closing_snapshots', fn (Blueprint $table) => $table->unsignedBigInteger('closed_by_id')->nullable(false)->change());
        Schema::table('late_corrections', fn (Blueprint $table) => $table->unsignedBigInteger('recorded_by_id')->nullable(false)->change());
        Schema::table('historical_error_annotations', fn (Blueprint $table) => $table->unsignedBigInteger('recorded_by_id')->nullable(false)->change());

        DB::statement("ALTER TABLE project_transitions ADD CONSTRAINT project_transitions_annulment_complete CHECK ((annulled_at IS NULL AND annulled_by_id IS NULL AND annulment_reason IS NULL) OR (annulled_at IS NOT NULL AND annulled_by_id IS NOT NULL AND CHAR_LENGTH(TRIM(COALESCE(annulment_reason, ''))) > 0))");
        DB::statement("ALTER TABLE contract_lifecycle_facts ADD CONSTRAINT contract_lifecycle_facts_annulment_complete CHECK ((annulled_at IS NULL AND annulled_by_id IS NULL AND annulment_reason IS NULL) OR (annulled_at IS NOT NULL AND annulled_by_id IS NOT NULL AND CHAR_LENGTH(TRIM(COALESCE(annulment_reason, ''))) > 0))");
        DB::statement("ALTER TABLE contract_conditions ADD CONSTRAINT contract_conditions_annulment_reason CHECK (annulled_at IS NULL OR (annulled_by_id IS NOT NULL AND CHAR_LENGTH(TRIM(COALESCE(reason, ''))) > 0))");
    }
};
