<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->unsignedBigInteger('project_id')->nullable()->after('exercise_id');
            $table->foreign(['project_id', 'company_id'], 'expenses_project_company_foreign')
                ->references(['id', 'company_id'])->on('projects')->restrictOnDelete();
            $table->index(['company_id', 'project_id', 'exercise_id', 'reversed_at'], 'expenses_company_project_exercise_state_index');
        });

        DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_project_direct_cost_center_exclusive CHECK (project_id IS NULL OR direct_cost_center_id IS NULL)');
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropForeign('expenses_project_company_foreign');
            $table->dropIndex('expenses_company_project_exercise_state_index');
            $table->dropColumn('project_id');
        });
    }
};
