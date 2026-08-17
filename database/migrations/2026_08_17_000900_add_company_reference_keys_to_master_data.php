<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->unique(['id', 'company_id'], 'suppliers_id_company_unique');
        });

        Schema::table('cost_centers', function (Blueprint $table): void {
            $table->unique(['id', 'company_id'], 'cost_centers_id_company_unique');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropUnique('suppliers_id_company_unique');
        });

        Schema::table('cost_centers', function (Blueprint $table): void {
            $table->dropUnique('cost_centers_id_company_unique');
        });
    }
};
