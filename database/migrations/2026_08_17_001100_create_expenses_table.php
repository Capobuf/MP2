<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->unsignedBigInteger('exercise_id');
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('direct_cost_center_id')->nullable();
            $table->string('description');
            $table->text('notes')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('revision')->default(0);
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['exercise_id', 'company_id'], 'expenses_exercise_company_foreign')
                ->references(['id', 'company_id'])->on('exercises')->restrictOnDelete();
            $table->foreign(['supplier_id', 'company_id'], 'expenses_supplier_company_foreign')
                ->references(['id', 'company_id'])->on('suppliers')->restrictOnDelete();
            $table->foreign(['direct_cost_center_id', 'company_id'], 'expenses_cost_center_company_foreign')
                ->references(['id', 'company_id'])->on('cost_centers')->restrictOnDelete();
            $table->unique(['id', 'company_id'], 'expenses_id_company_unique');
            $table->index(['company_id', 'exercise_id', 'reversed_at']);
            $table->index(['company_id', 'supplier_id']);
            $table->index(['company_id', 'direct_cost_center_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
