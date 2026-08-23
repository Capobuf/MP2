<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE proposals MODIFY purpose ENUM('initial_budget', 'revision') NOT NULL DEFAULT 'initial_budget'");
        DB::statement("ALTER TABLE budget_snapshots MODIFY purpose ENUM('initial_budget', 'revision') NOT NULL DEFAULT 'initial_budget'");

        Schema::table('proposals', function (Blueprint $table): void {
            $table->unsignedBigInteger('reference_budget_id')->nullable()->after('exercise_id');
            $table->foreignId('discarded_by_id')->nullable()->after('approved_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('discarded_at')->nullable()->after('approved_at');
            $table->text('discard_reason')->nullable()->after('discarded_at');
            $table->uuid('discard_operation_id')->nullable()->unique()->after('approval_operation_id');
            $table->foreign('reference_budget_id')->references('id')->on('budget_snapshots')->restrictOnDelete();
        });

        Schema::table('proposal_actions', function (Blueprint $table): void {
            $table->enum('status', ['active', 'withdrawn'])->default('active')->after('reason');
            $table->foreignId('withdrawn_by_id')->nullable()->after('created_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('withdrawn_at')->nullable()->after('withdrawn_by_id');
            $table->uuid('withdraw_operation_id')->nullable()->after('withdrawn_at');
            $table->text('withdraw_reason')->nullable()->after('withdraw_operation_id');
            $table->index(['proposal_id', 'status', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::table('proposal_actions', function (Blueprint $table): void {
            $table->dropIndex(['proposal_id', 'status', 'sequence']);
            $table->dropForeign(['withdrawn_by_id']);
            $table->dropColumn(['status', 'withdrawn_by_id', 'withdrawn_at', 'withdraw_operation_id', 'withdraw_reason']);
        });

        Schema::table('proposals', function (Blueprint $table): void {
            $table->dropForeign(['reference_budget_id']);
            $table->dropForeign(['discarded_by_id']);
            $table->dropUnique(['discard_operation_id']);
            $table->dropColumn(['reference_budget_id', 'discarded_by_id', 'discarded_at', 'discard_reason', 'discard_operation_id']);
        });

        DB::statement("ALTER TABLE budget_snapshots MODIFY purpose ENUM('initial_budget') NOT NULL DEFAULT 'initial_budget'");
        DB::statement("ALTER TABLE proposals MODIFY purpose ENUM('initial_budget') NOT NULL DEFAULT 'initial_budget'");
    }
};
