<header class="mp2-report-header">
    <div class="mp2-report-header-main">
        <p class="mp2-report-kicker">Controllo Economico</p>
        <x-filament::dropdown placement="bottom-start" width="md">
            <x-slot name="trigger">
                <button type="button" class="mp2-report-switcher" aria-label="Cambia Famiglia di Report">
                    <span id="report-kind-title">{{ $this->currentKindLabel() }}</span>
                    <x-filament::icon icon="heroicon-m-chevron-down" />
                </button>
            </x-slot>

            <x-filament::dropdown.list>
                @foreach ($this->reportChoices() as $value => $label)
                    <x-filament::dropdown.list.item
                        wire:click="switchReport('{{ $value }}')"
                        :icon="$value === $kind ? 'heroicon-m-check' : null"
                        :color="$value === $kind ? 'primary' : 'gray'"
                    >
                        <span class="mp2-report-switcher-option">
                            <strong>{{ $label }}</strong>
                            <small>{{ $this->reportDescription($value) }}</small>
                        </span>
                    </x-filament::dropdown.list.item>
                @endforeach
            </x-filament::dropdown.list>
        </x-filament::dropdown>
        <p class="mp2-report-description">{{ $this->reportDescription($kind) }}</p>
    </div>

    @if ($definition)
        <div class="mp2-report-header-actions">
            <x-filament::button
                color="gray"
                outlined
                icon="heroicon-m-adjustments-horizontal"
                tag="a"
                :href="App\Filament\Pages\ReportPdfCustomizer::getUrl(['definition' => $definition], tenant: Filament\Facades\Filament::getTenant())"
            >
                Personalizza PDF
            </x-filament::button>
        </div>
    @endif

    @include('filament.pages.reporting.context')

    @if ($report)
        <dl class="mp2-report-header-meta">
            <div><dt>Azienda</dt><dd>{{ $report['header']['company_name'] }}</dd></div>
            @if ($kind === 'annual_executive' && $budgetId === null && $report['header']['initial_reference_label'])
                <div><dt>Baseline Disponibile</dt><dd>{{ $report['header']['initial_reference_label'] }}</dd></div>
            @endif
            <div><dt>Data di Riferimento</dt><dd>{{ $report['header']['reference_date'] }}</dd></div>
            <div><dt>Valuta</dt><dd>EUR · importi netti IVA</dd></div>
            <div><dt>Generato</dt><dd>{{ $report['header']['generated_at'] }}</dd></div>
        </dl>
    @endif
</header>
