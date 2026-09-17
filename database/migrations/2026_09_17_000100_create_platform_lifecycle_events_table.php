<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_lifecycle_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_id')->unique();
            $table->enum('operation', ['archive', 'restore', 'destroy']);
            // Historical identifiers must survive deletion of their original records.
            $table->unsignedBigInteger('actor_id');
            $table->string('actor_name');
            $table->unsignedBigInteger('tenant_id')->index();
            $table->timestamp('occurred_at')->index();
            $table->enum('outcome', ['completed', 'data_deleted']);
            $table->unsignedInteger('file_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_lifecycle_events');
    }
};
