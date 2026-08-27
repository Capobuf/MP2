<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_file_deletions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_id')->index();
            $table->string('storage_disk', 64);
            $table->string('storage_path');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['storage_disk', 'storage_path']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('pending_file_deletions')
            && DB::table('pending_file_deletions')->exists()) {
            throw new RuntimeException('Cannot drop pending_file_deletions while file cleanup is pending.');
        }

        Schema::dropIfExists('pending_file_deletions');
    }
};
