<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_companies', function (Blueprint $table): void {
            $table->uuid('portable_uuid')->nullable();
        });
        DB::table('tenant_companies')->orderBy('company_id')->each(function (object $tenant): void {
            DB::table('tenant_companies')->where('company_id', $tenant->company_id)->update(['portable_uuid' => (string) Str::uuid()]);
        });
        Schema::table('tenant_companies', function (Blueprint $table): void {
            $table->uuid('portable_uuid')->nullable(false)->change();
            $table->unique('portable_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_companies', function (Blueprint $table): void {
            $table->dropColumn('portable_uuid');
        });
    }
};
