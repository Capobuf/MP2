<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('company_capabilities');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_platform_admin');
        });

        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropColumn('capability');
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->string('capability', 64)->nullable()->after('beneficiary_id');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_platform_admin')->default(false)->after('password');
        });

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
};
