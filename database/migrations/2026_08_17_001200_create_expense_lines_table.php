<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('expense_id')->constrained()->restrictOnDelete();
            $table->enum('type', ['estimate', 'actual']);
            $table->decimal('amount', 19, 2);
            $table->decimal('quantity', 20, 6)->nullable();
            $table->decimal('unit_amount', 20, 6)->nullable();
            $table->string('unit_of_measure', 64)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('annulled_at')->nullable();
            $table->timestamps();

            $table->index(['expense_id', 'annulled_at', 'type']);
        });

        DB::statement("ALTER TABLE expense_lines ADD CONSTRAINT expense_lines_amount_rules CHECK ((type = 'estimate' AND amount >= 0) OR (type = 'actual' AND (amount >= 0 OR CHAR_LENGTH(TRIM(COALESCE(note, ''))) > 0)))");
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_lines');
    }
};
