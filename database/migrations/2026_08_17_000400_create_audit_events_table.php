<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('event_type', 64);
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('beneficiary_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('capability', 64)->nullable();
            $table->string('setting', 64)->nullable();
            $table->json('affected_exercise_ids');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->json('previous_value')->nullable();
            $table->json('new_value')->nullable();
            $table->json('allocated_impact_by_exercise');
            $table->json('actual_impact_by_exercise');
            $table->text('reason')->nullable();
            $table->string('reference_type', 64)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
