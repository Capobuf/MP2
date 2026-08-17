<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_transitions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('project_id');
            $table->enum('from_state', ['planned', 'open', 'closed', 'cancelled']);
            $table->enum('to_state', ['planned', 'open', 'closed', 'cancelled']);
            $table->date('effective_date');
            $table->text('reason')->nullable();
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('annulled_at')->nullable();
            $table->foreignId('annulled_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('annulment_reason')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['project_id', 'company_id'], 'project_transitions_project_company_foreign')
                ->references(['id', 'company_id'])->on('projects')->restrictOnDelete();
            $table->index(['company_id', 'project_id', 'effective_date']);
        });

        DB::statement('ALTER TABLE project_transitions ADD COLUMN active_effective_date DATE GENERATED ALWAYS AS (CASE WHEN annulled_at IS NULL THEN effective_date ELSE NULL END) STORED');
        Schema::table('project_transitions', function (Blueprint $table): void {
            $table->unique(['project_id', 'active_effective_date'], 'project_transitions_active_date_unique');
        });
        DB::statement("ALTER TABLE project_transitions ADD CONSTRAINT project_transitions_allowed_pair CHECK ((from_state = 'planned' AND to_state IN ('open', 'cancelled')) OR (from_state = 'open' AND to_state IN ('closed', 'cancelled')) OR (from_state = 'closed' AND to_state = 'open') OR (from_state = 'cancelled' AND to_state IN ('planned', 'open'))) ");
        DB::statement("ALTER TABLE project_transitions ADD CONSTRAINT project_transitions_reason_required CHECK ((to_state NOT IN ('closed', 'cancelled') AND NOT (from_state IN ('closed', 'cancelled') AND to_state = 'open')) OR CHAR_LENGTH(TRIM(COALESCE(reason, ''))) > 0)");
        DB::statement("ALTER TABLE project_transitions ADD CONSTRAINT project_transitions_annulment_complete CHECK ((annulled_at IS NULL AND annulled_by_id IS NULL AND annulment_reason IS NULL) OR (annulled_at IS NOT NULL AND annulled_by_id IS NOT NULL AND CHAR_LENGTH(TRIM(COALESCE(annulment_reason, ''))) > 0))");
    }

    public function down(): void
    {
        Schema::dropIfExists('project_transitions');
    }
};
