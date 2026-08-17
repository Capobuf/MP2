<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_capabilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('capability', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['company_id', 'user_id', 'capability']);
            $table->index(['user_id', 'capability', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_capabilities');
    }
};
