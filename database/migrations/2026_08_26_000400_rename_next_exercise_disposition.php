<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE closing_snapshots DROP CHECK closing_snapshots_next_exercise_shape');
        DB::statement(
            "ALTER TABLE closing_snapshots MODIFY next_exercise_disposition ENUM('created', 'already_existed', 'not_created_management_terminated', 'not_created') NOT NULL",
        );
        DB::table('closing_snapshots')
            ->where('next_exercise_disposition', 'not_created_management_terminated')
            ->update(['next_exercise_disposition' => 'not_created']);
        DB::statement(
            "ALTER TABLE closing_snapshots MODIFY next_exercise_disposition ENUM('created', 'already_existed', 'not_created') NOT NULL",
        );
        $this->addShapeCheck('not_created');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE closing_snapshots DROP CHECK closing_snapshots_next_exercise_shape');
        DB::statement(
            "ALTER TABLE closing_snapshots MODIFY next_exercise_disposition ENUM('created', 'already_existed', 'not_created', 'not_created_management_terminated') NOT NULL",
        );
        DB::table('closing_snapshots')
            ->where('next_exercise_disposition', 'not_created')
            ->update(['next_exercise_disposition' => 'not_created_management_terminated']);
        DB::statement(
            "ALTER TABLE closing_snapshots MODIFY next_exercise_disposition ENUM('created', 'already_existed', 'not_created_management_terminated') NOT NULL",
        );
        $this->addShapeCheck('not_created_management_terminated');
    }

    private function addShapeCheck(string $notCreatedValue): void
    {
        DB::statement(
            'ALTER TABLE closing_snapshots ADD CONSTRAINT closing_snapshots_next_exercise_shape CHECK ('
            ."(next_exercise_disposition IN ('created', 'already_existed') AND next_exercise_id IS NOT NULL)"
            ." OR (next_exercise_disposition = '{$notCreatedValue}' AND next_exercise_id IS NULL)"
            .')',
        );
    }
};
