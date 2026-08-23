<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_snapshots', function (Blueprint $table): void {
            $table->unique(
                ['id', 'exercise_id', 'company_id'],
                'budget_snapshots_id_exercise_company_unique',
            );
        });

        Schema::table('closing_snapshots', function (Blueprint $table): void {
            $table->foreign(
                ['initial_budget_id', 'exercise_id', 'company_id'],
                'closing_initial_budget_exercise_company_fk',
            )->references(['id', 'exercise_id', 'company_id'])->on('budget_snapshots')->restrictOnDelete();
            $table->foreign(
                ['current_budget_id', 'exercise_id', 'company_id'],
                'closing_current_budget_exercise_company_fk',
            )->references(['id', 'exercise_id', 'company_id'])->on('budget_snapshots')->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE closing_snapshots ADD CONSTRAINT closing_snapshots_next_exercise_shape CHECK ('
            ."(next_exercise_disposition IN ('created', 'already_existed') AND next_exercise_id IS NOT NULL)"
            ." OR (next_exercise_disposition = 'not_created_management_terminated' AND next_exercise_id IS NULL)"
            .')',
        );
    }

    public function down(): void
    {
        Schema::table('closing_snapshots', function (Blueprint $table): void {
            $table->dropForeign('closing_initial_budget_exercise_company_fk');
            $table->dropForeign('closing_current_budget_exercise_company_fk');
        });
        DB::statement('ALTER TABLE closing_snapshots DROP CHECK closing_snapshots_next_exercise_shape');
        Schema::table('budget_snapshots', function (Blueprint $table): void {
            $table->dropUnique('budget_snapshots_id_exercise_company_unique');
        });
    }
};
