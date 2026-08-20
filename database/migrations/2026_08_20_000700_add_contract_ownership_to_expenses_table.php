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
            $table->unsignedBigInteger('contract_id')->nullable()->after('project_id');
            $table->enum('origin', ['manual', 'system'])->default('manual')->after('contract_id');
            $table->foreign(['contract_id', 'company_id'], 'expenses_contract_company_foreign')
                ->references(['id', 'company_id'])->on('contracts')->restrictOnDelete();
            $table->index(['company_id', 'contract_id', 'exercise_id', 'reversed_at'], 'expenses_company_contract_exercise_state_index');
        });

        DB::statement('ALTER TABLE expenses ADD COLUMN generated_contract_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN origin = \'system\' THEN contract_id ELSE NULL END) STORED');
        DB::statement('ALTER TABLE expenses ADD COLUMN generated_exercise_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN origin = \'system\' THEN exercise_id ELSE NULL END) STORED');
        Schema::table('expenses', function (Blueprint $table): void {
            $table->unique(['generated_contract_id', 'generated_exercise_id'], 'expenses_generated_contract_exercise_unique');
        });
        DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_owner_exclusive CHECK (project_id IS NULL OR contract_id IS NULL)');
        DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_container_direct_cost_center_exclusive CHECK ((project_id IS NULL AND contract_id IS NULL) OR direct_cost_center_id IS NULL)');
        DB::statement("ALTER TABLE expenses ADD CONSTRAINT expenses_system_origin_contract CHECK (origin = 'manual' OR contract_id IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropUnique('expenses_generated_contract_exercise_unique');
            $table->dropForeign('expenses_contract_company_foreign');
            $table->dropIndex('expenses_company_contract_exercise_state_index');
            $table->dropColumn(['generated_contract_id', 'generated_exercise_id', 'origin', 'contract_id']);
        });
    }
};
