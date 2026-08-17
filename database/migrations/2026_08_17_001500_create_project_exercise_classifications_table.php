<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_exercise_classifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('exercise_id');
            $table->unsignedBigInteger('cost_center_id')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['project_id', 'company_id'], 'project_classifications_project_company_foreign')
                ->references(['id', 'company_id'])->on('projects')->restrictOnDelete();
            $table->foreign(['exercise_id', 'company_id'], 'project_classifications_exercise_company_foreign')
                ->references(['id', 'company_id'])->on('exercises')->restrictOnDelete();
            $table->foreign(['cost_center_id', 'company_id'], 'project_classifications_cost_center_company_foreign')
                ->references(['id', 'company_id'])->on('cost_centers')->restrictOnDelete();
            $table->unique(['project_id', 'exercise_id'], 'project_classifications_project_exercise_unique');
            $table->index(['company_id', 'exercise_id', 'cost_center_id'], 'project_classifications_company_exercise_cost_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_exercise_classifications');
    }
};
