<header class="mp2-report-header">
    <div class="mp2-report-header-main">
        <p class="mp2-report-kicker">{{ $report['header']['company_name'] }} · Esercizio {{ $report['header']['exercise_year'] }}</p>
        <h2 id="report-result-title">{{ $report['header']['title'] }}</h2>
        <div class="mp2-report-header-references">
            @if ($report['header']['initial_reference_label'])
                <span>{{ $report['header']['initial_reference_label'] }}</span>
                <x-filament::icon icon="heroicon-m-arrow-right" />
            @endif
            <span>{{ $report['header']['final_reference_label'] ?? $report['header']['final_reference'] ?? 'Riferimento non applicabile' }}</span>
        </div>
    </div>

    @if ($definition)
        <x-filament::button
            color="gray"
            outlined
            icon="heroicon-m-arrow-down-tray"
            tag="a"
            :href="route('reports.pdf', ['definition' => $definition])"
        >
            Esporta PDF
        </x-filament::button>
    @endif

    <dl class="mp2-report-header-meta">
        <div><dt>Azienda</dt><dd>{{ $report['header']['company_name'] }}</dd></div>
        <div><dt>Esercizio</dt><dd>{{ $report['header']['exercise_year'] }} @if ($report['header']['comparison_exercise_year'])→ {{ $report['header']['comparison_exercise_year'] }}@endif</dd></div>
        @if ($report['header']['budget_version'])
            <div><dt>Budget</dt><dd>v{{ $report['header']['budget_version'] }} · {{ $report['header']['budget_purpose'] }}</dd></div>
        @endif
        @if ($report['header']['actual_reference'])
            <div><dt>Effettivo</dt><dd>{{ $report['header']['actual_reference'] }}</dd></div>
        @endif
        <div><dt>Data di riferimento</dt><dd>{{ $report['header']['reference_date'] }}</dd></div>
        @if ($report['header']['date_from'])
            <div><dt>Intervallo</dt><dd>{{ $report['header']['date_from'] }} – {{ $report['header']['date_to'] }}</dd></div>
        @endif
        <div><dt>Valuta</dt><dd>EUR · importi netti IVA</dd></div>
        <div><dt>Generato</dt><dd>{{ $report['header']['generated_at'] }}</dd></div>
    </dl>

    @if ($report['header']['filter_labels'] !== [])
        <div class="mp2-report-header-filters">
            @foreach ($report['header']['filter_labels'] as $label)
                <span>{{ $label }}</span>
            @endforeach
        </div>
    @endif
</header>
