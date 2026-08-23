<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('closing_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('company_name');
            $table->unsignedBigInteger('exercise_id');
            $table->unsignedSmallInteger('exercise_year');
            $table->timestamp('closed_at');
            $table->foreignId('closed_by_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('initial_budget_id')->nullable();
            $table->unsignedBigInteger('current_budget_id')->nullable();
            $table->decimal('total_final_allocation', 19, 2);
            $table->decimal('total_closing_actual', 19, 2);
            $table->decimal('total_operational_variance', 19, 2);
            $table->decimal('total_consolidated_carryover', 19, 2)->default(0);
            $table->json('accepted_warnings');
            $table->json('applied_settings');
            $table->enum('next_exercise_disposition', [
                'created',
                'already_existed',
                'not_created_management_terminated',
            ]);
            $table->unsignedBigInteger('next_exercise_id')->nullable();
            $table->uuid('operation_id')->unique();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['exercise_id', 'company_id'], 'closing_snapshots_exercise_company_foreign')
                ->references(['id', 'company_id'])->on('exercises')->restrictOnDelete();
            $table->foreign(['initial_budget_id', 'company_id'], 'closing_snapshots_initial_budget_company_foreign')
                ->references(['id', 'company_id'])->on('budget_snapshots')->restrictOnDelete();
            $table->foreign(['current_budget_id', 'company_id'], 'closing_snapshots_current_budget_company_foreign')
                ->references(['id', 'company_id'])->on('budget_snapshots')->restrictOnDelete();
            $table->foreign(['next_exercise_id', 'company_id'], 'closing_snapshots_next_exercise_company_foreign')
                ->references(['id', 'company_id'])->on('exercises')->restrictOnDelete();

            $table->unique('exercise_id');
            $table->unique(['id', 'company_id'], 'closing_snapshots_id_company_unique');
        });

        Schema::create('closing_source_rows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('closing_snapshot_id');
            $table->enum('source_type', ['expense', 'project', 'contract']);
            $table->unsignedBigInteger('origin_id');
            $table->string('origin_key');
            $table->string('copied_from_origin_key')->nullable();
            $table->string('label');
            $table->text('summary')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->string('supplier_label')->nullable();
            $table->unsignedBigInteger('cost_center_id')->nullable();
            $table->string('cost_center_label');
            $table->string('end_state')->nullable();
            $table->boolean('has_actuals')->default(false);
            $table->decimal('final_estimates', 19, 2);
            $table->decimal('received_carryover', 19, 2)->default(0);
            $table->decimal('final_allocation', 19, 2);
            $table->decimal('closing_actual', 19, 2);
            $table->decimal('operational_variance', 19, 2);
            $table->unsignedSmallInteger('detail_version')->default(1);
            $table->json('detail');
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['closing_snapshot_id', 'company_id'], 'closing_rows_snapshot_company_foreign')
                ->references(['id', 'company_id'])->on('closing_snapshots')->restrictOnDelete();

            $table->unique(['closing_snapshot_id', 'origin_key'], 'closing_rows_origin_unique');
            $table->index(['company_id', 'source_type', 'origin_id'], 'closing_rows_source_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('closing_source_rows');
        Schema::dropIfExists('closing_snapshots');
    }
};
