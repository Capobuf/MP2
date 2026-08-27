<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_companies', function (Blueprint $table): void {
            $table->foreignId('company_id')->primary()->constrained('companies')->cascadeOnDelete();
            $table->enum('status', ['active', 'archived'])->default('active');
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            INSERT INTO tenant_companies (company_id, status, created_at, updated_at)
            SELECT id, 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            FROM companies
            ORDER BY id
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_companies');
    }
};
