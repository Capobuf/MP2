<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_contract_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('contract_id');
            $table->text('note')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('revision')->default(0);
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['project_id', 'company_id'], 'project_contract_links_project_company_foreign')
                ->references(['id', 'company_id'])->on('projects')->restrictOnDelete();
            $table->foreign(['contract_id', 'company_id'], 'project_contract_links_contract_company_foreign')
                ->references(['id', 'company_id'])->on('contracts')->restrictOnDelete();
            $table->index(['company_id', 'archived_at']);
        });

        DB::statement('ALTER TABLE project_contract_links ADD COLUMN active_contract_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN archived_at IS NULL THEN contract_id ELSE NULL END) STORED');
        Schema::table('project_contract_links', function (Blueprint $table): void {
            $table->unique(['project_id', 'active_contract_id'], 'project_contract_links_active_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_contract_links');
    }
};
