<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_evidence', function (Blueprint $table): void {
            $table->dropForeign(['attachment_id']);
        });
        Schema::table('attachments', function (Blueprint $table): void {
            $table->unique(['id', 'company_id'], 'attachments_id_company_unique');
        });
        Schema::table('budget_evidence', function (Blueprint $table): void {
            $table->foreign(['attachment_id', 'company_id'], 'budget_evidence_attachment_company_foreign')
                ->references(['id', 'company_id'])->on('attachments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('budget_evidence', function (Blueprint $table): void {
            $table->dropForeign('budget_evidence_attachment_company_foreign');
            $table->foreign('attachment_id')->references('id')->on('attachments')->restrictOnDelete();
        });
        Schema::table('attachments', function (Blueprint $table): void {
            $table->dropUnique('attachments_id_company_unique');
        });
    }
};
