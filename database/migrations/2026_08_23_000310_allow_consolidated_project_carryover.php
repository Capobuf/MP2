<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE project_deferrals DROP CHECK project_deferrals_closed_values');
        DB::statement(
            'ALTER TABLE project_deferrals ADD CONSTRAINT project_deferrals_closed_values CHECK ('
            ."(mode = 'none' AND carryover_amount = 0 AND carryover_state IS NULL AND reprogrammed_amount = 0 AND reprogramming_operation_id IS NULL AND reprogramming_effects IS NULL)"
            ." OR (mode = 'carryover' AND carryover_amount > 0 AND carryover_state IN ('provisional', 'consolidated') AND reprogrammed_amount = 0 AND reprogramming_operation_id IS NULL AND reprogramming_effects IS NULL)"
            ." OR (mode = 'reprogramming' AND carryover_amount = 0 AND carryover_state IS NULL AND reprogrammed_amount > 0 AND reprogramming_operation_id IS NOT NULL AND reprogramming_effects IS NOT NULL)"
            .')'
        );
    }

    public function down(): void
    {
        if (DB::table('project_deferrals')
            ->where('mode', 'carryover')
            ->where('carryover_state', 'consolidated')
            ->exists()) {
            throw new RuntimeException('Cannot restore the previous constraint while consolidated Project Carryovers exist.');
        }

        DB::statement('ALTER TABLE project_deferrals DROP CHECK project_deferrals_closed_values');
        DB::statement(
            'ALTER TABLE project_deferrals ADD CONSTRAINT project_deferrals_closed_values CHECK ('
            ."(mode = 'none' AND carryover_amount = 0 AND carryover_state IS NULL AND reprogrammed_amount = 0 AND reprogramming_operation_id IS NULL AND reprogramming_effects IS NULL)"
            ." OR (mode = 'carryover' AND carryover_amount > 0 AND carryover_state = 'provisional' AND reprogrammed_amount = 0 AND reprogramming_operation_id IS NULL AND reprogramming_effects IS NULL)"
            ." OR (mode = 'reprogramming' AND carryover_amount = 0 AND carryover_state IS NULL AND reprogrammed_amount > 0 AND reprogramming_operation_id IS NOT NULL AND reprogramming_effects IS NOT NULL)"
            .')'
        );
    }
};
