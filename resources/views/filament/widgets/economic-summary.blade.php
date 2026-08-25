@php
    use Illuminate\Support\Number;

    $formatMoney = static fn (?string $amount): string => $amount === null
        ? 'Non selezionato'
        : Number::currency((float) $amount, in: 'EUR', locale: 'it');
@endphp

<x-filament-widgets::widget class="mp2-dashboard-economic-summary">
    @if ($dashboard === null)
        <section class="mp2-economic-empty" aria-labelledby="economic-empty-title">
            <div>
                <p class="mp2-economic-kicker">Quadro economico</p>
                <h2 id="economic-empty-title">Nessun Esercizio selezionato</h2>
                <p>Configura o seleziona un Esercizio nel contesto globale per visualizzare la Dashboard.</p>
            </div>
        </section>
    @else
        <section aria-labelledby="economic-summary-title">
            <div class="mp2-economic-section-heading">
                <div>
                    <p class="mp2-economic-kicker">Esercizio {{ $dashboard['exercise_year'] }}</p>
                    <h2 id="economic-summary-title">Quadro economico</h2>
                </div>
            </div>

            <dl class="mp2-economic-summary-grid">
                <div class="mp2-economic-stat mp2-economic-stat-budget">
                    <dt>Budget selezionato</dt>
                    <dd>{{ $formatMoney($dashboard['summary']['budget']) }}</dd>
                    <p>{{ $dashboard['budget_label'] ?? 'Seleziona una versione nell’header' }}</p>
                </div>
                <div class="mp2-economic-stat mp2-economic-stat-allocation">
                    <dt>Allocato Corrente</dt>
                    <dd>{{ $formatMoney($dashboard['summary']['allocation']) }}</dd>
                    <p>Situazione Corrente</p>
                </div>
                <div class="mp2-economic-stat mp2-economic-stat-actual">
                    <dt>Effettivo</dt>
                    <dd>{{ $formatMoney($dashboard['summary']['actual']) }}</dd>
                    <p>Effettivo Corrente</p>
                </div>
                <div
                    class="mp2-economic-stat mp2-economic-stat-variance"
                    data-tone="{{ (float) $dashboard['summary']['operational_variance'] > 0 ? 'positive' : ((float) $dashboard['summary']['operational_variance'] < 0 ? 'negative' : 'neutral') }}"
                >
                    <dt>Scostamento Operativo</dt>
                    <dd>{{ $formatMoney($dashboard['summary']['operational_variance']) }}</dd>
                    <p>Effettivo − Allocato Corrente</p>
                </div>
            </dl>
        </section>
    @endif
</x-filament-widgets::widget>
