<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('revision')->default(0)->after('annulled_at');
        });
    }

    public function down(): void
    {
        Schema::table('expense_lines', function (Blueprint $table): void {
            $table->dropColumn('revision');
        });
    }
};
