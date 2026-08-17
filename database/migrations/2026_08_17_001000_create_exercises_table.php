<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exercises', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->unsignedBigInteger('revision')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'year']);
            $table->unique(['id', 'company_id'], 'exercises_id_company_unique');
            $table->index(['company_id', 'status', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercises');
    }
};
