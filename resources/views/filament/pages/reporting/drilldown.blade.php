@php
    use Illuminate\Support\Number;

    $money = static fn (string|int|float|null $value): string => $value === null
        ? '—'
        : Number::currency((float) $value, in: 'EUR', locale: 'it');
    $detail = is_array($source['detail'] ?? null) ? $source['detail'] : [];
    $expenses = is_array($detail['expenses'] ?? null) ? $detail['expenses'] : [];
    $lines = is_array($detail['lines'] ?? null) ? $detail['lines'] : [];
@endphp

<div class="mp2-report-drilldown-content">
    @if ($source['summary'] ?? null)
        <p class="mp2-report-drilldown-summary">{{ $source['summary'] }}</p>
    @endif

    @if ($source['source_type'] === 'expense')
        <dl class="mp2-report-detail-facts">
            <div><dt>Fornitore</dt><dd>{{ $source['supplier'] ?? data_get($detail, 'supplier.label') ?? $detail['supplier_label'] ?? '—' }}</dd></div>
            <div><dt>Allocato</dt><dd>{{ $money($source['allocation']) }}</dd></div>
            <div><dt>Effettivo</dt><dd>{{ $money($source['actual']) }}</dd></div>
            <div><dt>Stato</dt><dd>{{ $this->stateLabel($source['state']) }}</dd></div>
        </dl>
        @include('filament.pages.reporting.expense-lines', ['lines' => $lines])
        @php($knownKeys = ['id', 'expense_id', 'source', 'description', 'supplier_id', 'supplier_label', 'supplier', 'allocation', 'actual', 'has_actuals', 'lines', 'archived_or_reversed'])
    @elseif ($source['source_type'] === 'project')
        <dl class="mp2-report-detail-facts">
            <div><dt>Residuo</dt><dd>{{ $money($source['residual']) }}</dd></div>
            <div><dt>Risparmio</dt><dd>{{ $money($source['saving']) }}</dd></div>
            <div><dt>Allocato non utilizzato</dt><dd>{{ $money($source['unused']) }}</dd></div>
            <div><dt>Riporto</dt><dd>{{ $money($source['carryover']) }}</dd></div>
            <div><dt>Archivio</dt><dd>{{ ($detail['archived_or_reversed'] ?? false) ? 'Archiviato' : 'Non archiviato' }}</dd></div>
            <div><dt>Presenza di rinvio</dt><dd>{{ ($detail['deferred'] ?? false) ? 'Sì' : 'No' }}</dd></div>
        </dl>
        @if ($expenses !== [])
            <section class="mp2-report-detail-group">
                <h5>Spese figlie</h5>
                @foreach ($expenses as $expense)
                    <div class="mp2-report-child-expense">
                        <div><strong>{{ $expense['source'] ?? $expense['description'] ?? 'Spesa' }}</strong><span>{{ $expense['supplier_label'] ?? data_get($expense, 'supplier.label') ?? 'Nessun Fornitore' }}</span></div>
                        <div>{{ $money($expense['allocation'] ?? $expense['final_estimate_total'] ?? '0.00') }} · {{ $money($expense['actual'] ?? $expense['closing_actual_total'] ?? '0.00') }}</div>
                    </div>
                    @include('filament.pages.reporting.expense-lines', ['lines' => $expense['lines'] ?? []])
                @endforeach
            </section>
        @endif
        @foreach ([['transitions', 'Transizioni'], ['transitions_in_exercise', 'Transizioni'], ['deferrals', 'Rinvii']] as [$key, $title])
            @if (is_array($detail[$key] ?? null) && $detail[$key] !== [])
                <section class="mp2-report-detail-group"><h5>{{ $title }}</h5>@include('filament.pages.reporting.key-value', ['data' => $detail[$key]])</section>
            @endif
        @endforeach
        @php($knownKeys = ['expenses', 'transitions', 'transitions_in_exercise', 'deferrals', 'residual', 'saving', 'unused_allocation', 'consolidated_carryover', 'archived_or_reversed', 'deferred'])
    @else
        <dl class="mp2-report-detail-facts">
            <div><dt>Scadenza</dt><dd>{{ $detail['deadline'] ?? $detail['next_expiry_date'] ?? 'Non definita' }}</dd></div>
            <div><dt>Rinnovo automatico</dt><dd>{{ ($detail['automatic_renewal'] ?? false) ? 'Sì' : 'No' }}</dd></div>
            <div><dt>Data limite disdetta</dt><dd>{{ $detail['notice_limit_date'] ?? 'Non definita' }}</dd></div>
            <div><dt>Scostamento</dt><dd>{{ $money($source['operational_variance']) }}</dd></div>
            <div><dt>Archivio</dt><dd>{{ ($detail['archived_or_reversed'] ?? false) ? 'Archiviato' : 'Non archiviato' }}</dd></div>
        </dl>
        @if ($expenses !== [])
            <section class="mp2-report-detail-group">
                <h5>Spese</h5>
                @foreach ($expenses as $expense)
                    <div class="mp2-report-child-expense">
                        <div><strong>{{ $expense['source'] ?? $expense['description'] ?? 'Spesa' }}</strong><span>{{ $expense['supplier_label'] ?? data_get($expense, 'supplier.label') ?? 'Nessun Fornitore' }}</span></div>
                        <div>{{ $money($expense['allocation'] ?? $expense['final_estimate_total'] ?? '0.00') }} · {{ $money($expense['actual'] ?? $expense['closing_actual_total'] ?? '0.00') }}</div>
                    </div>
                    @include('filament.pages.reporting.expense-lines', ['lines' => $expense['lines'] ?? []])
                @endforeach
            </section>
        @endif
        @foreach ([['conditions', 'Condizioni'], ['cycles', 'Cicli'], ['annual_composition', 'Composizione annuale'], ['events', 'Eventi'], ['lifecycle_events', 'Eventi']] as [$key, $title])
            @if (is_array($detail[$key] ?? null) && $detail[$key] !== [])
                <section class="mp2-report-detail-group"><h5>{{ $title }}</h5>@include('filament.pages.reporting.key-value', ['data' => $detail[$key]])</section>
            @endif
        @endforeach
        @php($knownKeys = ['expenses', 'conditions', 'cycles', 'annual_composition', 'events', 'lifecycle_events', 'deadline', 'next_expiry_date', 'automatic_renewal', 'notice_limit_date', 'operational_variance', 'archived_or_reversed'])
    @endif

    @foreach ($source['corrections'] ?? [] as $correction)
        <section class="mp2-report-correction">
            <h5>Correzione</h5>
            <p><strong>{{ $money($correction['amount']) }}</strong> · {{ $correction['reason'] }}</p>
            @if ($correction['created_at'] ?? null)<time>{{ $correction['created_at'] }}</time>@endif
        </section>
    @endforeach

    @foreach ($source['annotations'] ?? [] as $annotation)
        <section class="mp2-report-annotation">
            <h5>Annotazione di errore storico</h5>
            <p>{{ $annotation['reason'] }}</p>
            <strong>Impatto economico nullo</strong>
        </section>
    @endforeach

    @php($remaining = array_diff_key($detail, array_flip($knownKeys ?? [])))
    @if ($remaining !== [])
        <section class="mp2-report-detail-group">
            <h5>Altri dati registrati</h5>
            @include('filament.pages.reporting.key-value', ['data' => $remaining])
        </section>
    @endif
</div>
