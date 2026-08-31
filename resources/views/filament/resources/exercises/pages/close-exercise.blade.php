<x-filament-panels::page>
    <div class="mp2-closing-page space-y-6">
        <div class="fi-section border p-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">Chiusura annuale</p>
                    <h2 class="mt-2 text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">Esercizio {{ $this->getRecord()->year }}</h2>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-gray-600 dark:text-gray-300">
                        Lo stato economico viene valutato al 31 dicembre. La Chiusura crea una Snapshot immutabile. L’Esercizio non potrà essere riaperto.
                    </p>
                </div>
                <div class="mp2-closing-frame px-4 py-3 text-sm">
                    <span class="text-gray-500 dark:text-gray-400">Esercizio successivo</span>
                    <div class="mt-1 font-medium text-gray-950 dark:text-white">
                        {{ $nextExerciseExists ? 'Già esistente' : 'Non ancora creato' }}
                    </div>
                </div>
            </div>
        </div>

        @if (! $nextExerciseExists)
            <div class="fi-section border p-6">
                <h3 class="text-base font-semibold text-gray-950 dark:text-white">Esercizio successivo</h3>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">La scelta determina soltanto se creare N+1. Non crea Budget o copie di Effettivi.</p>
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <label class="mp2-closing-frame flex cursor-pointer gap-3 p-4">
                        <x-filament::input.radio wire:model.live="closing.create_next_exercise" value="1" class="mt-1" />
                        <span>
                            <span class="block font-medium text-gray-950 dark:text-white">Crea N+1</span>
                            <span class="mt-1 block text-sm text-gray-500 dark:text-gray-400">N+1 verrà creato Aperto secondo le regole canoniche.</span>
                        </span>
                    </label>
                    <label class="mp2-closing-frame flex cursor-pointer gap-3 p-4">
                        <x-filament::input.radio wire:model.live="closing.create_next_exercise" value="0" class="mt-1" />
                        <span>
                            <span class="block font-medium text-gray-950 dark:text-white">Non creare N+1</span>
                            <span class="mt-1 block text-sm text-gray-500 dark:text-gray-400">N+1 non viene creato e ogni trasferimento deve essere zero.</span>
                        </span>
                    </label>
                </div>
            </div>
        @endif

        <div class="space-y-4">
            @forelse (($closing['projects'] ?? []) as $projectId => $project)
                @php
                    $terminal = in_array($project['final_state'] ?? '', ['closed', 'cancelled'], true);
                    $mode = $terminal ? 'none' : ($project['mode'] ?? '');
                    $isExecutedReprogramming = ($project['current_mode'] ?? '') === 'reprogramming';
                    $finalStateOptions = ($project['current_state'] ?? null) === 'planned'
                        ? ['planned' => 'Pianificato', 'cancelled' => 'Cancellato']
                        : ['open' => 'Aperto', 'closed' => 'Chiuso', 'cancelled' => 'Cancellato'];
                    $modeOptions = $terminal
                        ? ['none' => 'Nessuna']
                        : ['' => 'Seleziona…', 'none' => 'Nessuna', 'carryover' => 'Riporto', 'reprogramming' => 'Riprogrammazione'];
                @endphp
                <div wire:key="closing-project-{{ $projectId }}" class="fi-section border p-6">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ $project['title'] }}</h3>
                            <div class="mt-2 flex flex-wrap gap-2 text-xs">
                                <span class="mp2-closing-badge">Stato attuale: {{ match($project['current_state']) { 'planned' => 'Pianificato', 'open' => 'Aperto', default => $project['current_state'] } }}</span>
                                <span class="mp2-closing-badge">Rinvio attuale: {{ match($project['current_mode']) { 'carryover' => 'Riporto', 'reprogramming' => 'Riprogrammazione', default => 'Nessuna' } }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="mt-5 grid gap-4 lg:grid-cols-2">
                        <label class="space-y-1.5">
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Stato al 31 dicembre</span>
                            @include('filament.resources.exercises.components.closing-select', [
                                'model' => "closing.projects.{$projectId}.final_state",
                                'value' => $project['final_state'],
                                'options' => $finalStateOptions,
                                'disabled' => false,
                            ])
                        </label>

                        <label class="space-y-1.5">
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Modalità di rinvio</span>
                            @include('filament.resources.exercises.components.closing-select', [
                                'model' => "closing.projects.{$projectId}.mode",
                                'value' => $mode,
                                'options' => $modeOptions,
                                'disabled' => $terminal,
                            ])
                            @if ($terminal)
                                <span class="block text-xs text-gray-500 dark:text-gray-400">Un Progetto terminale non può avere Riporto o Riprogrammazione.</span>
                            @endif
                        </label>
                    </div>

                    @if ($mode === 'carryover')
                        <label class="mt-4 block max-w-sm space-y-1.5">
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Riporto da consolidare</span>
                            <x-filament::input.wrapper>
                                <x-filament::input type="number" min="0" step="0.01" wire:model.live.debounce.400ms="closing.projects.{{ $projectId }}.carryover_amount" />
                            </x-filament::input.wrapper>
                            <span class="block text-xs text-gray-500 dark:text-gray-400">L’importo non viene impostato automaticamente al massimo disponibile.</span>
                        </label>
                    @endif

                    @if ($mode === 'reprogramming')
                        @if ($isExecutedReprogramming)
                            <div class="mp2-closing-muted mt-4 p-4 text-sm">
                                <span class="font-medium text-gray-950 dark:text-white">Riprogrammazione già eseguita</span>
                                <p class="mt-1 text-gray-600 dark:text-gray-300">Alla Chiusura verranno verificati gli esatti ID ed effetti già persistiti; non verrà applicata una seconda volta.</p>
                            </div>
                        @else
                            <div class="mt-5">
                                <div class="flex items-end justify-between gap-4">
                                    <div>
                                        <h4 class="text-sm font-semibold text-gray-950 dark:text-white">Stime origine da riprogrammare</h4>
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">La destinazione viene generata dal server con nuove identità e lineage.</p>
                                    </div>
                                </div>
                                <div class="mp2-closing-frame mt-3 overflow-hidden">
                                    @forelse (($reprogrammableLines[$projectId] ?? []) as $line)
                                        <div wire:key="closing-line-{{ $line['source_line_id'] }}" class="mp2-closing-row grid gap-3 border-b p-4 last:border-b-0 lg:grid-cols-[minmax(0,1fr)_10rem_14rem] lg:items-center">
                                            <label class="flex min-w-0 gap-3">
                                                <x-filament::input.checkbox wire:model.live="closing.projects.{{ $projectId }}.reductions.{{ $line['source_line_id'] }}.selected" class="mt-1" />
                                                <span class="min-w-0">
                                                    <span class="block truncate text-sm font-medium text-gray-950 dark:text-white">{{ $line['expense_description'] }}</span>
                                                    <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">Stima {{ number_format((float) $line['amount'], 2, ',', '.') }} €@if($line['source_supplier_label']) · {{ $line['source_supplier_label'] }}@endif</span>
                                                </span>
                                            </label>
                                            <x-filament::input.wrapper>
                                                <x-filament::input type="number" min="0.01" step="0.01" wire:model.live.debounce.400ms="closing.projects.{{ $projectId }}.reductions.{{ $line['source_line_id'] }}.reduction_amount" />
                                            </x-filament::input.wrapper>
                                            @php
                                                $destinationSupplierOptions = $line['source_supplier_archived'] ? ['' => 'Conferma fornitore…'] : [];
                                                $destinationSupplierOptions['none'] = 'Nessun Fornitore';
                                                foreach ($supplierOptions as $supplierId => $supplierLabel) {
                                                    $destinationSupplierOptions[(string) $supplierId] = $supplierLabel;
                                                }
                                            @endphp
                                            @include('filament.resources.exercises.components.closing-select', [
                                                'model' => "closing.projects.{$projectId}.reductions.{$line['source_line_id']}.destination_supplier_id",
                                                'value' => $project['reductions'][$line['source_line_id']]['destination_supplier_id'],
                                                'options' => $destinationSupplierOptions,
                                                'disabled' => false,
                                            ])
                                        </div>
                                    @empty
                                        <div class="p-4 text-sm text-gray-500 dark:text-gray-400">Nessuna Riga Stima attiva e riducibile.</div>
                                    @endforelse
                                </div>
                            </div>
                        @endif
                    @endif

                    <label class="mt-4 block space-y-1.5">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Nota della decisione</span>
                        <x-filament::input.wrapper class="mp2-closing-textarea">
                            <textarea rows="2" wire:model.live.debounce.400ms="closing.projects.{{ $projectId }}.reason" class="mp2-closing-textarea-input" placeholder="Obbligatoria per Chiusura/Cancellazione, rinvio o cambio modalità"></textarea>
                        </x-filament::input.wrapper>
                    </label>
                </div>
            @empty
                <div class="fi-section border p-6 text-sm text-gray-500 dark:text-gray-400">Nessun Progetto Pianificato o Aperto richiede una decisione di Chiusura.</div>
            @endforelse
        </div>

        @if ($errors->any())
            <div class="rounded-2xl border border-danger-300 bg-danger-50 p-5 text-sm text-danger-800 dark:border-danger-500/30 dark:bg-danger-500/10 dark:text-danger-200">
                <p class="font-semibold">La Chiusura non può proseguire.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="flex justify-end">
            <x-filament::button wire:click="reviewClosing" wire:loading.attr="disabled">
                Ricalcola riepilogo
            </x-filament::button>
        </div>

        @if ($review)
            <div class="fi-section space-y-5 border p-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">Riepilogo finale</p>
                        <h3 class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">Valori che verranno congelati</h3>
                    </div>
                    <div class="grid grid-cols-3 gap-4 text-right">
                        <div><span class="block text-xs text-gray-500">Allocato</span><span class="font-semibold text-gray-950 dark:text-white">{{ number_format((float) $review['totals']['final_allocation'], 2, ',', '.') }} €</span></div>
                        <div><span class="block text-xs text-gray-500">Effettivo</span><span class="font-semibold text-gray-950 dark:text-white">{{ number_format((float) $review['totals']['closing_actual'], 2, ',', '.') }} €</span></div>
                        <div><span class="block text-xs text-gray-500">Scostamento</span><span class="font-semibold text-gray-950 dark:text-white">{{ number_format((float) $review['totals']['operational_variance'], 2, ',', '.') }} €</span></div>
                    </div>
                </div>

                @if ($review['blocks'])
                    <div class="rounded-xl border border-danger-300 bg-danger-50 p-4 dark:border-danger-500/30 dark:bg-danger-500/10">
                        <h4 class="text-sm font-semibold text-danger-800 dark:text-danger-200">Controlli bloccanti</h4>
                        <ul class="mt-2 space-y-1 text-sm text-danger-700 dark:text-danger-200">
                            @foreach ($review['blocks'] as $block)
                                <li>• {{ $block['message'] }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($review['warnings'])
                    <div class="rounded-xl border border-warning-300 bg-warning-50 p-4 dark:border-warning-500/30 dark:bg-warning-500/10">
                        <h4 class="text-sm font-semibold text-warning-800 dark:text-warning-200">Avvisi non bloccanti</h4>
                        <ul class="mt-2 space-y-1 text-sm text-warning-700 dark:text-warning-200">
                            @foreach ($review['warnings'] as $warning)
                                <li>• {{ $warning['message'] }}</li>
                            @endforeach
                        </ul>
                        <label class="mt-4 flex gap-3 text-sm text-gray-800 dark:text-gray-200">
                            <x-filament::input.checkbox wire:model="closing.warnings_acknowledged" class="mt-1" />
                            <span>Ho preso visione degli avvisi di Chiusura.</span>
                        </label>
                    </div>
                @endif

                <div>
                    <h4 class="text-sm font-semibold text-gray-950 dark:text-white">Impatto sugli Esercizi Aperti</h4>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($review['affected_exercises'] as $impact)
                            <div class="mp2-closing-frame p-3 text-sm">
                                <span class="text-gray-500 dark:text-gray-400">{{ $impact['year'] }}</span>
                                <span class="mt-1 block font-medium text-gray-950 dark:text-white">Δ Allocato {{ number_format((float) $impact['allocation_delta'], 2, ',', '.') }} €</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                @if (! $review['blocks'])
                    <div class="border-t border-gray-200 pt-5 dark:border-white/10">
                        <label class="flex gap-3 rounded-xl border border-danger-200 bg-danger-50/60 p-4 dark:border-danger-500/20 dark:bg-danger-500/5">
                            <x-filament::input.checkbox wire:model="closing.confirmed" class="mt-1" />
                            <span class="text-sm text-gray-800 dark:text-gray-200">
                                Confermo la Chiusura dell’Esercizio {{ $this->getRecord()->year }}. <strong>L’Esercizio non potrà essere riaperto.</strong>
                            </span>
                        </label>
                        <div class="mt-4 flex justify-end">
                            <x-filament::button color="danger" wire:click="closeExercise" wire:loading.attr="disabled">
                                Chiudi definitivamente l’Esercizio
                            </x-filament::button>
                        </div>
                    </div>
                @endif
            </div>
        @endif
    </div>
</x-filament-panels::page>
