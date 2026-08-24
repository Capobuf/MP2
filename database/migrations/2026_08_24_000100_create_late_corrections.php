<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('late_corrections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('exercise_id');
            $table->unsignedBigInteger('closing_snapshot_id');
            $table->unsignedBigInteger('expense_id');
            $table->unsignedBigInteger('expense_line_id');
            $table->unsignedBigInteger('original_expense_line_id')->nullable();
            $table->foreignId('recorded_by_id')->constrained('users')->restrictOnDelete();
            $table->uuid('operation_id')->unique();
            $table->text('reason');
            $table->boolean('belongs_to_closed_exercise');
            $table->enum('source_type', ['expense', 'project', 'contract']);
            $table->unsignedBigInteger('source_origin_id');
            $table->string('source_origin_key');
            $table->string('source_label');
            $table->json('owner_context');
            $table->json('supplier_context')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['exercise_id', 'company_id'], 'late_corrections_exercise_company_foreign')
                ->references(['id', 'company_id'])->on('exercises')->restrictOnDelete();
            $table->foreign(['closing_snapshot_id', 'company_id'], 'late_corrections_snapshot_company_foreign')
                ->references(['id', 'company_id'])->on('closing_snapshots')->restrictOnDelete();
            $table->foreign(['expense_id', 'company_id'], 'late_corrections_expense_company_foreign')
                ->references(['id', 'company_id'])->on('expenses')->restrictOnDelete();
            $table->foreign('expense_line_id')->references('id')->on('expense_lines')->restrictOnDelete();
            $table->foreign('original_expense_line_id')->references('id')->on('expense_lines')->restrictOnDelete();

            $table->unique(['id', 'company_id'], 'late_corrections_id_company_unique');
            $table->unique('expense_line_id', 'late_corrections_expense_line_unique');
            $table->index(['company_id', 'exercise_id', 'created_at']);
            $table->index(['company_id', 'source_type', 'source_origin_id']);
        });

        DB::statement('ALTER TABLE late_corrections ADD CONSTRAINT late_corrections_closed_declaration CHECK (belongs_to_closed_exercise = 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('late_corrections');
    }
};
