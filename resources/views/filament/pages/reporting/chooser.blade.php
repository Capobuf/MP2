<section class="mp2-report-chooser" aria-labelledby="report-chooser-title">
    <div class="mp2-report-chooser-heading">
        <p class="mp2-report-kicker">Reportistica MP2</p>
        <h2 id="report-chooser-title">Scegli il Report</h2>
        <p>Seleziona una famiglia. Esercizio, Budget ed Effettivo resteranno scelte esplicite.</p>
    </div>

    <div class="mp2-report-choice-grid">
        @foreach ($this->reportChoices() as $value => $label)
            <button type="button" class="mp2-report-choice" wire:click="selectReport('{{ $value }}')">
                <span>{{ $label }}</span>
                <small>{{ $this->reportDescription($value) }}</small>
                <x-filament::icon icon="heroicon-m-arrow-up-right" />
            </button>
        @endforeach
    </div>
</section>
