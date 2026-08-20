<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropUnique('audit_events_operation_id_unique');
            $table->unsignedInteger('event_sequence')->default(0)->after('operation_id');
            $table->unique(['operation_id', 'event_sequence'], 'audit_events_operation_sequence_unique');
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropUnique('audit_events_operation_sequence_unique');
            $table->dropColumn('event_sequence');
            $table->unique('operation_id');
        });
    }
};
