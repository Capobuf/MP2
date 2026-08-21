<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('exercise_id');
            $table->enum('purpose', ['initial_budget'])->default('initial_budget');
            $table->enum('status', ['draft', 'approved', 'discarded'])->default('draft');
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('approval_operation_id')->nullable()->unique();
            $table->unsignedBigInteger('revision')->default(0);
            $table->unsignedBigInteger('active_exercise_id')->nullable()
                ->virtualAs("CASE WHEN status = 'draft' THEN exercise_id ELSE NULL END");
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['exercise_id', 'company_id'], 'proposals_exercise_company_foreign')
                ->references(['id', 'company_id'])->on('exercises')->restrictOnDelete();
            $table->unique(['id', 'company_id'], 'proposals_id_company_unique');
            $table->unique(['company_id', 'active_exercise_id'], 'proposals_one_active_draft_unique');
            $table->index(['company_id', 'status', 'updated_at']);
        });

        Schema::create('proposal_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('proposal_item_id')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('proposal_id');
            $table->enum('source_type', ['expense', 'project', 'contract']);
            $table->unsignedBigInteger('expense_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('contract_id')->nullable();
            $table->string('copied_from_origin_key')->nullable();
            $table->unsignedBigInteger('baseline_revision')->nullable();
            $table->char('baseline_fingerprint', 64)->nullable();
            $table->json('baseline');
            $table->json('result');
            $table->enum('readiness_state', ['aligned', 'to_review', 'to_realign', 'inconsistent'])->default('aligned');
            $table->json('readiness_reasons')->nullable();
            $table->boolean('read_only_source')->default(false);
            $table->timestamp('last_aligned_at')->nullable();
            $table->foreignId('last_aligned_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->foreign(['proposal_id', 'company_id'], 'proposal_items_proposal_company_foreign')
                ->references(['id', 'company_id'])->on('proposals')->restrictOnDelete();
            $table->foreign(['expense_id', 'company_id'], 'proposal_items_expense_company_foreign')
                ->references(['id', 'company_id'])->on('expenses')->restrictOnDelete();
            $table->foreign(['project_id', 'company_id'], 'proposal_items_project_company_foreign')
                ->references(['id', 'company_id'])->on('projects')->restrictOnDelete();
            $table->foreign(['contract_id', 'company_id'], 'proposal_items_contract_company_foreign')
                ->references(['id', 'company_id'])->on('contracts')->restrictOnDelete();
            $table->unique(['id', 'company_id'], 'proposal_items_id_company_unique');
            $table->unique(['proposal_id', 'expense_id'], 'proposal_items_proposal_expense_unique');
            $table->unique(['proposal_id', 'project_id'], 'proposal_items_proposal_project_unique');
            $table->unique(['proposal_id', 'contract_id'], 'proposal_items_proposal_contract_unique');
            $table->index(['company_id', 'proposal_id', 'readiness_state']);
        });

        DB::statement("ALTER TABLE proposal_items ADD CONSTRAINT proposal_items_source_shape CHECK (((source_type = 'expense') AND project_id IS NULL AND contract_id IS NULL) OR ((source_type = 'project') AND expense_id IS NULL AND contract_id IS NULL) OR ((source_type = 'contract') AND expense_id IS NULL AND project_id IS NULL))");
        DB::statement('ALTER TABLE proposal_items ADD CONSTRAINT proposal_items_baseline_shape CHECK ((expense_id IS NULL AND project_id IS NULL AND contract_id IS NULL AND baseline_revision IS NULL AND baseline_fingerprint IS NULL) OR ((expense_id IS NOT NULL OR project_id IS NOT NULL OR contract_id IS NOT NULL) AND baseline_revision IS NOT NULL AND baseline_fingerprint IS NOT NULL))');

        Schema::create('proposal_actions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('proposal_id');
            $table->unsignedBigInteger('proposal_item_id')->nullable();
            $table->unsignedInteger('sequence');
            $table->string('action_type', 64);
            $table->unsignedSmallInteger('payload_version')->default(1);
            $table->json('payload');
            $table->text('reason')->nullable();
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->uuid('operation_id')->unique();
            $table->timestamps();

            $table->foreign(['proposal_id', 'company_id'], 'proposal_actions_proposal_company_foreign')
                ->references(['id', 'company_id'])->on('proposals')->restrictOnDelete();
            $table->foreign(['proposal_item_id', 'company_id'], 'proposal_actions_item_company_foreign')
                ->references(['id', 'company_id'])->on('proposal_items')->restrictOnDelete();
            $table->unique(['proposal_id', 'sequence']);
        });

        Schema::create('budget_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('exercise_id');
            $table->unsignedBigInteger('proposal_id');
            $table->unsignedInteger('version')->default(1);
            $table->enum('purpose', ['initial_budget'])->default('initial_budget');
            $table->timestamp('approved_at');
            $table->foreignId('approved_by_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('previous_budget_id')->nullable();
            $table->decimal('total_approved_allocation', 19, 2);
            $table->json('affected_exercises');
            $table->uuid('operation_id')->unique();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['exercise_id', 'company_id'], 'budget_snapshots_exercise_company_foreign')
                ->references(['id', 'company_id'])->on('exercises')->restrictOnDelete();
            $table->foreign(['proposal_id', 'company_id'], 'budget_snapshots_proposal_company_foreign')
                ->references(['id', 'company_id'])->on('proposals')->restrictOnDelete();
            $table->foreign('previous_budget_id')->references('id')->on('budget_snapshots')->restrictOnDelete();
            $table->unique('proposal_id');
            $table->unique(['exercise_id', 'version']);
            $table->unique(['id', 'company_id'], 'budget_snapshots_id_company_unique');
        });

        Schema::create('budget_source_rows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('budget_snapshot_id');
            $table->enum('source_type', ['expense', 'project', 'contract']);
            $table->unsignedBigInteger('origin_id');
            $table->string('origin_key');
            $table->uuid('proposal_item_id');
            $table->string('copied_from_origin_key')->nullable();
            $table->string('label');
            $table->text('summary')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->string('supplier_label')->nullable();
            $table->unsignedBigInteger('cost_center_id')->nullable();
            $table->string('cost_center_label');
            $table->decimal('approved_estimates', 19, 2);
            $table->decimal('approved_carryover', 19, 2)->default(0);
            $table->string('carryover_state')->nullable();
            $table->decimal('approved_allocation', 19, 2);
            $table->string('start_state')->nullable();
            $table->string('end_state')->nullable();
            $table->unsignedSmallInteger('detail_version')->default(1);
            $table->json('detail');
            $table->timestamps();

            $table->foreign(['budget_snapshot_id', 'company_id'], 'budget_rows_snapshot_company_foreign')
                ->references(['id', 'company_id'])->on('budget_snapshots')->restrictOnDelete();
            $table->unique(['budget_snapshot_id', 'proposal_item_id'], 'budget_rows_item_unique');
            $table->unique(['budget_snapshot_id', 'origin_key'], 'budget_rows_origin_unique');
        });

        Schema::create('budget_evidence', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('budget_snapshot_id');
            $table->string('external_subject')->nullable();
            $table->string('external_venue')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('attachment_id')->nullable()->constrained('attachments')->restrictOnDelete();
            $table->string('storage_disk', 64)->nullable();
            $table->string('storage_path')->nullable();
            $table->string('original_name')->nullable();
            $table->string('media_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->timestamps();

            $table->foreign(['budget_snapshot_id', 'company_id'], 'budget_evidence_snapshot_company_foreign')
                ->references(['id', 'company_id'])->on('budget_snapshots')->restrictOnDelete();
        });

        Schema::table('expenses', function (Blueprint $table): void {
            $table->string('copied_from_origin_key')->nullable()->after('origin');
        });

        DB::statement('ALTER TABLE attachments DROP CHECK attachments_exactly_one_owner');
        Schema::table('attachments', function (Blueprint $table): void {
            $table->unsignedBigInteger('proposal_id')->nullable()->after('company_id');
            $table->foreign(['proposal_id', 'company_id'], 'attachments_proposal_company_foreign')
                ->references(['id', 'company_id'])->on('proposals')->restrictOnDelete();
            $table->index(['company_id', 'proposal_id', 'detached_at']);
        });
        DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_exactly_one_owner CHECK ((proposal_id IS NOT NULL) + (contract_id IS NOT NULL) + (expense_id IS NOT NULL) + (expense_line_id IS NOT NULL) = 1)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE attachments DROP CHECK attachments_exactly_one_owner');
        Schema::table('attachments', function (Blueprint $table): void {
            $table->dropForeign('attachments_proposal_company_foreign');
            $table->dropIndex(['company_id', 'proposal_id', 'detached_at']);
            $table->dropColumn('proposal_id');
        });
        DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_exactly_one_owner CHECK ((contract_id IS NOT NULL) + (expense_id IS NOT NULL) + (expense_line_id IS NOT NULL) = 1)');

        Schema::table('expenses', fn (Blueprint $table) => $table->dropColumn('copied_from_origin_key'));
        Schema::dropIfExists('budget_evidence');
        Schema::dropIfExists('budget_source_rows');
        Schema::dropIfExists('budget_snapshots');
        Schema::dropIfExists('proposal_actions');
        Schema::dropIfExists('proposal_items');
        Schema::dropIfExists('proposals');
    }
};
