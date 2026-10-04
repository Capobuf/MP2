<x-filament-panels::page>
    <div class="mp2-pdf-customizer">
        <aside class="mp2-pdf-controls" aria-label="Contenuti del PDF">
            <div class="mp2-pdf-controls-heading">
                <div><p class="mp2-report-kicker">Composizione</p><h2>Contenuti della Proposta</h2></div>
                <div class="mp2-pdf-selection-actions">
                    <button type="button" wire:click="selectAll">Tutti</button>
                    <button type="button" wire:click="selectNone">Nessuno</button>
                </div>
            </div>

            <fieldset>
                <legend>Orientamento pagina</legend>
                <div class="mp2-pdf-orientation">
                    <label><input type="radio" name="orientation" value="portrait" wire:model.live="orientation"><span><strong>Verticale</strong><small>A4 portrait</small></span></label>
                    <label><input type="radio" name="orientation" value="landscape" wire:model.live="orientation"><span><strong>Orizzontale</strong><small>A4 landscape</small></span></label>
                </div>
            </fieldset>

            @foreach (['identity' => 'Identità', 'summary' => 'Riepilogo', 'verification' => 'Verifiche', 'impact' => 'Impatti', 'source' => 'Sorgenti', 'detail' => 'Dettagli', 'decision' => 'Decisioni'] as $group => $label)
                @php($options = collect($availableBlocks)->where('group', $group))
                @if ($options->isNotEmpty())
                    <fieldset><legend>{{ $label }}</legend>
                        @foreach ($options as $option)
                            <label><input type="checkbox" value="{{ $option['id'] }}" wire:model.live="selectedBlocks"><span>{{ $option['label'] }}</span></label>
                        @endforeach
                    </fieldset>
                @endif
            @endforeach
        </aside>

        <section
            class="mp2-pdf-preview"
            aria-labelledby="proposal-pdf-preview-title"
            wire:key="proposal-pdf-{{ md5($this->previewUrl()) }}"
            x-data="{
                blobUrl: null,
                loading: true,
                error: null,
                controller: null,
                init() {
                    this.load();
                },
                async load() {
                    this.controller?.abort();
                    if (this.blobUrl !== null) URL.revokeObjectURL(this.blobUrl);
                    this.blobUrl = null;
                    this.error = null;
                    this.loading = true;
                    this.controller = new AbortController();
                    try {
                        const response = await fetch(@js($this->previewUrl()), {
                            credentials: 'same-origin',
                            headers: { Accept: 'application/pdf' },
                            signal: this.controller.signal,
                        });
                        if (! response.ok || ! response.headers.get('content-type')?.startsWith('application/pdf')) {
                            throw new Error(`HTTP ${response.status}`);
                        }
                        this.blobUrl = URL.createObjectURL(await response.blob());
                    } catch (error) {
                        if (error.name !== 'AbortError') {
                            this.error = 'Impossibile generare l’anteprima PDF.';
                        }
                    } finally {
                        this.loading = false;
                    }
                },
                destroy() {
                    this.controller?.abort();
                    if (this.blobUrl !== null) URL.revokeObjectURL(this.blobUrl);
                },
            }"
        >
            <div class="mp2-pdf-preview-heading">
                <div><p class="mp2-report-kicker">Anteprima reale</p><h2 id="proposal-pdf-preview-title">Documento PDF</h2></div>
                <x-filament::button
                    tag="a"
                    href="#"
                    x-bind:href="blobUrl ?? '#'"
                    x-bind:aria-disabled="blobUrl === null"
                    x-bind:class="{ 'pointer-events-none opacity-50': blobUrl === null }"
                    download="{{ $this->downloadFilename() }}"
                    icon="heroicon-m-arrow-down-tray"
                >Esporta PDF</x-filament::button>
            </div>
            <div class="mp2-pdf-frame-wrap" x-bind:class="{ 'is-loading': loading }">
                <div class="mp2-pdf-loading flex" x-show="loading" x-cloak><x-filament::loading-indicator class="h-5 w-5" /><span>Rigenerazione PDF…</span></div>
                <div class="p-6 text-sm text-danger-600" x-show="error !== null" x-cloak>
                    <p x-text="error"></p>
                    <button type="button" class="mt-2 font-semibold underline" x-on:click="load()">Riprova</button>
                </div>
                <iframe x-show="blobUrl !== null" x-bind:src="blobUrl" title="Anteprima PDF della Proposta"></iframe>
            </div>
        </section>
    </div>
</x-filament-panels::page>
