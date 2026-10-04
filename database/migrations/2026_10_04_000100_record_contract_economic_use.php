<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->boolean('economic_use_recorded')->default(false);
        });

        foreach (['budget_source_rows', 'closing_source_rows'] as $table) {
            DB::table('contracts')->whereIn('id', DB::table($table)->where('source_type', 'contract')->select('origin_id'))
                ->update(['economic_use_recorded' => true]);
        }
        DB::table('contracts')->whereIn('id', DB::table('expenses')
            ->join('expense_lines', 'expense_lines.expense_id', '=', 'expenses.id')
            ->whereNotNull('expenses.contract_id')
            ->where(function ($query): void {
                $query->where('expense_lines.type', 'actual')->orWhere(function ($query): void {
                    $query->where('expense_lines.type', 'estimate')->where('expense_lines.amount', '>', 0)
                        ->whereNull('expense_lines.annulled_at')->whereNull('expenses.reversed_at');
                });
            })->select('expenses.contract_id'))->update(['economic_use_recorded' => true]);

        DB::table('audit_events')->orderBy('id')->chunkById(500, function ($events): void {
            foreach ($events as $event) {
                $ids = [];
                $before = json_decode($event->previous_value ?? 'null', true);
                $after = json_decode($event->new_value ?? 'null', true);
                if ($event->subject_type === 'App\\Models\\Contract'
                    && $event->event_type === 'contract_estimate_recalculated'
                    && bccomp((string) ($after['allocation'] ?? '0.00'), '0.00', 2) > 0) {
                    $ids[] = $event->subject_id;
                }
                foreach ([$before, $after] as $value) {
                    if (! is_array($value)) {
                        continue;
                    }
                    $contractId = $value['contract_id'] ?? null;
                    if ($contractId !== null) {
                        foreach ($value['lines'] ?? [] as $line) {
                            if (($line['type'] ?? null) === 'actual'
                                || (($line['type'] ?? null) === 'estimate' && ! ($line['annulled'] ?? false)
                                    && ! ($value['reversed'] ?? false) && bccomp((string) $line['amount'], '0.00', 2) > 0)) {
                                $ids[] = $contractId;
                            }
                        }
                    }
                    if ($event->reference_type === 'App\\Models\\Contract'
                        && in_array($event->event_type, ['expense_line_created', 'expense_line_updated', 'expense_line_annulled', 'expense_line_restored'], true)
                        && (($value['type'] ?? null) === 'actual'
                            || (($value['type'] ?? null) === 'estimate' && ! ($value['annulled'] ?? false)
                                && bccomp((string) $value['amount'], '0.00', 2) > 0))) {
                        $ids[] = $event->reference_id;
                    }
                }
                if ($ids !== []) {
                    DB::table('contracts')->where('company_id', $event->company_id)->whereIn('id', array_unique($ids))
                        ->update(['economic_use_recorded' => true]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('contracts', fn (Blueprint $table) => $table->dropColumn('economic_use_recorded'));
    }
};
