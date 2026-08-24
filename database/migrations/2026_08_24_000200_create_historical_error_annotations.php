<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historical_error_annotations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('exercise_id');
            $table->unsignedBigInteger('closing_snapshot_id');
            $table->foreignId('recorded_by_id')->constrained('users')->restrictOnDelete();
            $table->uuid('operation_id')->unique();
            $table->enum('kind', [
                'cost_center',
                'supplier',
                'project',
                'contract',
                'container',
                'exercise',
                'historical_state',
                'carryover',
                'accidental_closing',
            ]);
            $table->text('reason');
            $table->unsignedSmallInteger('recorded_facts_version')->default(1);
            $table->json('recorded_facts');
            $table->unsignedSmallInteger('believed_correct_facts_version')->default(1);
            $table->json('believed_correct_facts');
            $table->unsignedSmallInteger('affected_sources_version')->default(1);
            $table->json('affected_sources');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['exercise_id', 'company_id'], 'historical_annotations_exercise_company_foreign')
                ->references(['id', 'company_id'])->on('exercises')->restrictOnDelete();
            $table->foreign(['closing_snapshot_id', 'company_id'], 'historical_annotations_snapshot_company_foreign')
                ->references(['id', 'company_id'])->on('closing_snapshots')->restrictOnDelete();

            $table->unique(['id', 'company_id'], 'historical_annotations_id_company_unique');
            $table->index(['company_id', 'exercise_id', 'created_at'], 'historical_annotations_exercise_index');
            $table->index(['company_id', 'kind', 'created_at'], 'historical_annotations_kind_index');
        });

        DB::statement('ALTER TABLE attachments DROP CHECK attachments_exactly_one_owner');
        Schema::table('attachments', function (Blueprint $table): void {
            $table->unsignedBigInteger('historical_error_annotation_id')->nullable()->after('expense_line_id');
            $table->foreign(
                ['historical_error_annotation_id', 'company_id'],
                'attachments_historical_annotation_company_foreign',
            )->references(['id', 'company_id'])->on('historical_error_annotations')->restrictOnDelete();
            $table->index(['company_id', 'historical_error_annotation_id', 'detached_at'], 'attachments_historical_annotation_index');
        });
        DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_exactly_one_owner CHECK ((proposal_id IS NOT NULL) + (contract_id IS NOT NULL) + (expense_id IS NOT NULL) + (expense_line_id IS NOT NULL) + (historical_error_annotation_id IS NOT NULL) = 1)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE attachments DROP CHECK attachments_exactly_one_owner');
        Schema::table('attachments', function (Blueprint $table): void {
            $table->dropForeign('attachments_historical_annotation_company_foreign');
            $table->dropIndex('attachments_historical_annotation_index');
            $table->dropColumn('historical_error_annotation_id');
        });
        DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_exactly_one_owner CHECK ((proposal_id IS NOT NULL) + (contract_id IS NOT NULL) + (expense_id IS NOT NULL) + (expense_line_id IS NOT NULL) = 1)');
        Schema::dropIfExists('historical_error_annotations');
    }
};
