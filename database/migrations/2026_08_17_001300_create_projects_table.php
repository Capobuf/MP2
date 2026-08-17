<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->enum('initial_state', ['planned', 'open', 'closed', 'cancelled']);
            $table->date('initial_effective_date');
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('revision')->default(0);
            $table->timestamps();

            $table->unique(['id', 'company_id'], 'projects_id_company_unique');
            $table->index(['company_id', 'archived_at', 'title']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
