<x-filament-panels::page>
    <div class="mp2-pdf-customizer">
        <aside class="mp2-pdf-controls" aria-label="Contenuti del PDF">
            <div class="mp2-pdf-controls-heading">
                <div><p class="mp2-report-kicker">Composizione</p><h2>Contenuti del report</h2></div>
                <div class="mp2-pdf-selection-actions">
                    <button type="button" wire:click="selectAll">Tutti</button>
                    <button type="button" wire:click="selectNone">Nessuno</button>
                </div>
            </div>

            @foreach (['logo' => 'Identità', 'kpi' => 'Indicatori', 'chart' => 'Grafici', 'table' => 'Tabelle', 'detail' => 'Approfondimenti', 'section' => 'Sezioni'] as $group => $label)
                @php($options = collect($availableBlocks)->where('group', $group))
                @if ($options->isNotEmpty())
                    <fieldset><legend>{{ $label }}</legend>
                        @foreach ($options as $option)
                            <label><input type="checkbox" value="{{ $option['id'] }}" wire:model.live="selectedBlocks"><span>{{ $option['label'] }}</span></label>
                        @endforeach
                    </fieldset>
                @endif
            @endforeach

            @foreach (['sources' => 'Colonne · Dettaglio', 'comparisons' => 'Colonne · Confronto'] as $group => $label)
                @php($options = collect($availableColumns)->where('group', $group))
                @if ($options->isNotEmpty())
                    <fieldset><legend>{{ $label }}</legend>
                        @foreach ($options as $option)
                            <label><input type="checkbox" value="{{ $option['id'] }}" wire:model.live="selectedColumns"><span>{{ $option['label'] }}</span></label>
                        @endforeach
                    </fieldset>
                @endif
            @endforeach
        </aside>

        <section class="mp2-pdf-preview" aria-labelledby="pdf-preview-title">
            <div class="mp2-pdf-preview-heading">
                <div><p class="mp2-report-kicker">Anteprima reale</p><h2 id="pdf-preview-title">Documento PDF</h2></div>
                <x-filament::button tag="a" :href="$this->downloadUrl()" icon="heroicon-m-arrow-down-tray">Esporta PDF</x-filament::button>
            </div>
            <div class="mp2-pdf-frame-wrap" wire:loading.class="is-loading">
                <div class="mp2-pdf-loading" wire:loading.flex><x-filament::loading-indicator class="h-5 w-5" /><span>Rigenerazione PDF…</span></div>
                <iframe wire:key="pdf-{{ md5($this->previewUrl()) }}" src="{{ $this->previewUrl() }}" title="Anteprima PDF"></iframe>
            </div>
        </section>
    </div>
</x-filament-panels::page>
