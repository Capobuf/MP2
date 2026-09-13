<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cost_centers', function (Blueprint $table): void {
            $table->unsignedBigInteger('parent_id')->nullable()->after('company_id');
            $table->index(['company_id', 'parent_id'], 'cost_centers_company_parent_index');
            $table->foreign(['parent_id', 'company_id'], 'cost_centers_parent_company_foreign')
                ->references(['id', 'company_id'])
                ->on('cost_centers')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cost_centers', function (Blueprint $table): void {
            $table->dropForeign('cost_centers_parent_company_foreign');
            $table->dropIndex('cost_centers_company_parent_index');
            $table->dropColumn('parent_id');
        });
    }
};
