<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_deferrals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('source_exercise_id');
            $table->unsignedBigInteger('destination_exercise_id');
            $table->enum('mode', ['none', 'carryover', 'reprogramming'])->default('none');
            $table->decimal('carryover_amount', 19, 2)->default(0);
            $table->enum('carryover_state', ['provisional', 'consolidated'])->nullable();
            $table->decimal('reprogrammed_amount', 19, 2)->default(0);
            $table->uuid('reprogramming_operation_id')->nullable();
            $table->json('reprogramming_effects')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['project_id', 'company_id'], 'project_deferrals_project_company_foreign')
                ->references(['id', 'company_id'])->on('projects')->restrictOnDelete();
            $table->foreign(['source_exercise_id', 'company_id'], 'project_deferrals_source_company_foreign')
                ->references(['id', 'company_id'])->on('exercises')->restrictOnDelete();
            $table->foreign(['destination_exercise_id', 'company_id'], 'project_deferrals_destination_company_foreign')
                ->references(['id', 'company_id'])->on('exercises')->restrictOnDelete();
            $table->unique(['project_id', 'source_exercise_id', 'destination_exercise_id'], 'project_deferrals_passage_unique');
            $table->index(['company_id', 'destination_exercise_id', 'mode'], 'project_deferrals_incoming_index');
        });

        DB::statement("ALTER TABLE project_deferrals ADD CONSTRAINT project_deferrals_closed_values CHECK ((mode = 'none' AND carryover_amount = 0 AND carryover_state IS NULL AND reprogrammed_amount = 0 AND reprogramming_operation_id IS NULL AND reprogramming_effects IS NULL) OR (mode = 'carryover' AND carryover_amount > 0 AND carryover_state = 'provisional' AND reprogrammed_amount = 0 AND reprogramming_operation_id IS NULL AND reprogramming_effects IS NULL) OR (mode = 'reprogramming' AND carryover_amount = 0 AND carryover_state IS NULL AND reprogrammed_amount > 0 AND reprogramming_operation_id IS NOT NULL AND reprogramming_effects IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('project_deferrals');
    }
};
