<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('logo_disk', 32)->nullable()->after('unclassified_closing_policy');
            $table->string('logo_path')->nullable()->after('logo_disk');
            $table->string('logo_media_type', 32)->nullable()->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn(['logo_disk', 'logo_path', 'logo_media_type']);
        });
    }
};
