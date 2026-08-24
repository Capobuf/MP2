<x-filament-panels::page>
    <section class="rounded-xl border border-gray-700 bg-gray-900/50 p-6" aria-labelledby="report-selection-title">
        <div class="mb-5">
            <p class="text-xs font-semibold uppercase tracking-widest text-primary-400">Controllo economico</p>
            <h2 id="report-selection-title" class="mt-1 text-xl font-semibold text-white">Seleziona riferimenti espliciti</h2>
            <p class="mt-2 text-sm text-gray-400">Nessun Budget o tipo di Effettivo viene scelto automaticamente.</p>
        </div>
        <form wire:submit="generate" class="grid gap-4 md:grid-cols-2 xl:grid-cols-4" wire:loading.attr="aria-busy">
            <label class="text-sm text-gray-200">Esercizio
                <select wire:model.live="exerciseId" class="mt-1 block w-full rounded-lg border-gray-600 bg-gray-950" required>
                    <option value="">Seleziona</option>
                    @foreach ($this->exerciseOptions() as $id => $year)<option value="{{ $id }}">{{ $year }}</option>@endforeach
                </select>
            </label>
            <label class="text-sm text-gray-200">Famiglia report
                <select wire:model.live="kind" class="mt-1 block w-full rounded-lg border-gray-600 bg-gray-950" required>
                    <option value="">Seleziona</option>
                    @foreach ($this->kindOptions() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
            </label>
            @if (in_array($kind, ['annual_executive', 'budget_actual', 'budget_current_allocation', 'budget_versions'], true))
                <label class="text-sm text-gray-200">Budget iniziale
                    <select wire:model="budgetId" class="mt-1 block w-full rounded-lg border-gray-600 bg-gray-950" @if ($kind !== 'annual_executive') required @endif><option value="">{{ $kind === 'annual_executive' ? 'Nessun Budget' : 'Seleziona' }}</option>@foreach ($this->budgetOptions() as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select>
                </label>
            @endif
            @if ($kind === 'budget_versions')
                <label class="text-sm text-gray-200">Budget finale
                    <select wire:model="secondBudgetId" class="mt-1 block w-full rounded-lg border-gray-600 bg-gray-950" required><option value="">Seleziona</option>@foreach ($this->budgetOptions() as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select>
                </label>
            @endif
            @if (in_array($kind, ['annual_executive', 'budget_actual'], true))
                <label class="text-sm text-gray-200">Tipo di Effettivo
                    <select wire:model="actualReference" class="mt-1 block w-full rounded-lg border-gray-600 bg-gray-950" required><option value="">Seleziona</option>@foreach ($this->actualOptions() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                    @if ($kind === 'annual_executive')<span class="mt-1 block text-xs text-gray-500">Per un Esercizio chiuso, Conoscenza Corrente è confrontata esplicitamente con la Snapshot di Chiusura.</span>@endif
                </label>
            @endif
            @if ($kind === 'exercises')
                <label class="text-sm text-gray-200">Secondo Esercizio
                    <select wire:model="comparisonExerciseId" class="mt-1 block w-full rounded-lg border-gray-600 bg-gray-950" required><option value="">Seleziona</option>@foreach ($this->exerciseOptions() as $id => $year)<option value="{{ $id }}">{{ $year }}</option>@endforeach</select>
                </label>
                <label class="text-sm text-gray-200">Stessa misura
                    <select wire:model="exerciseMeasure" class="mt-1 block w-full rounded-lg border-gray-600 bg-gray-950" required><option value="">Seleziona</option><option value="current">Situazione Corrente</option><option value="closing">Chiusura</option><option value="current_knowledge">Conoscenza Corrente</option></select>
                </label>
            @endif
            @if ($kind === 'contracts')
                <label class="text-sm text-gray-200">Intervallo dal<input type="date" wire:model="dateFrom" class="mt-1 block w-full rounded-lg border-gray-600 bg-gray-950"></label>
                <label class="text-sm text-gray-200">Intervallo al<input type="date" wire:model="dateTo" class="mt-1 block w-full rounded-lg border-gray-600 bg-gray-950"></label>
            @endif
            <fieldset class="grid gap-4 md:col-span-2 md:grid-cols-2 xl:col-span-4 xl:grid-cols-5">
                <legend class="mb-2 text-sm font-semibold text-gray-300">Filtri applicabili (opzionali)</legend>
                <label class="text-sm text-gray-200">Centro di Costo<select wire:model="costCenterId" class="mt-1 block w-full rounded-lg border-gray-600 bg-gray-950"><option value="">Tutti</option>@foreach ($this->costCenterOptions() as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select></label>
                <label class="text-sm text-gray-200">Progetto<select wire:model="projectId" class="mt-1 block w-full rounded-lg border-gray-600 bg-gray-950"><option value="">Tutti</option>@foreach ($this->projectOptions() as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select></label>
                <label class="text-sm text-gray-200">Contratto<select wire:model="contractId" class="mt-1 block w-full rounded-lg border-gray-600 bg-gray-950"><option value="">Tutti</option>@foreach ($this->contractOptions() as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select></label>
                <label class="text-sm text-gray-200">Spesa autonoma<select wire:model="expenseId" class="mt-1 block w-full rounded-lg border-gray-600 bg-gray-950"><option value="">Tutte</option>@foreach ($this->expenseOptions() as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select></label>
                <label class="text-sm text-gray-200">Fornitore<select wire:model="supplierId" class="mt-1 block w-full rounded-lg border-gray-600 bg-gray-950"><option value="">Tutti</option>@foreach ($this->supplierOptions() as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select></label>
            </fieldset>
            <div class="flex items-end">
                <button type="submit" wire:loading.attr="disabled" class="w-full rounded-lg bg-primary-500 px-4 py-2 font-semibold text-gray-950 disabled:opacity-50">Genera report</button>
            </div>
        </form>
        <div wire:loading class="mt-4 text-sm text-primary-300" role="status">Generazione in corso…</div>
        @error('exerciseId') <p class="mt-3 text-sm text-danger-400" role="alert">{{ $message }}</p> @enderror
        @error('kind') <p class="mt-3 text-sm text-danger-400" role="alert">{{ $message }}</p> @enderror
        @if ($errors->any())
            <div class="mt-3 rounded-lg border border-danger-500/50 bg-danger-500/10 p-3 text-sm text-danger-300" role="alert">
                {{ $errors->first() }}
            </div>
        @endif
    </section>

    @if ($report)
        <section class="space-y-6" aria-labelledby="report-result-title">
            <div class="flex flex-wrap items-start justify-between gap-4 rounded-xl border border-gray-700 bg-gray-900/50 p-6">
                <div><p class="text-sm text-primary-400">{{ $report['header']['company_name'] }} · Esercizio {{ $report['header']['exercise_year'] }}</p><h2 id="report-result-title" class="text-2xl font-semibold text-white">{{ $report['header']['title'] }}</h2><p class="mt-1 text-sm text-gray-400">{{ $report['header']['initial_reference'] ?? 'Non applicabile' }} → {{ $report['header']['final_reference'] ?? 'Non applicabile' }} · Budget {{ $report['header']['budget_version'] ? 'v'.$report['header']['budget_version'] : 'Non applicabile' }} · {{ $report['header']['actual_reference'] ?? 'Effettivo non applicabile' }}</p><p class="mt-1 text-xs text-gray-500">Filtri: {{ $report['header']['filter_labels'] === [] ? 'Nessuno' : implode(' · ', $report['header']['filter_labels']) }} @if ($report['header']['date_from'])· Intervallo {{ $report['header']['date_from'] }} – {{ $report['header']['date_to'] }}@endif</p><p class="mt-1 text-xs text-gray-500">Generato il {{ $report['header']['generated_at'] }} · EUR · importi netti IVA</p></div>
                <a href="{{ route('reports.pdf', ['definition' => $definition]) }}" class="rounded-lg border border-primary-500 px-4 py-2 text-sm font-semibold text-primary-300">Esporta PDF</a>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($report['totals'] as $label => $value)<div class="rounded-lg border border-gray-800 bg-gray-950 p-4"><p class="text-xs uppercase text-gray-500">{{ $this->totalLabel($label) }}</p><p class="mt-1 text-lg font-semibold text-white">{{ is_numeric($value) ? number_format((float) $value, 2, ',', '.') : $value }}</p></div>@endforeach
            </div>
            @if ($report['sources'] === [])<p class="rounded-lg border border-gray-700 p-6">Nessuna sorgente per i riferimenti e filtri selezionati.</p>@endif
            <div class="overflow-x-auto rounded-xl border border-gray-700"><table class="w-full text-left text-sm"><thead class="bg-gray-900 text-gray-300"><tr><th class="p-3">Sorgente</th><th class="p-3">Allocato</th><th class="p-3">Effettivo</th><th class="p-3">Stato</th><th class="p-3">Dettaglio</th></tr></thead><tbody class="divide-y divide-gray-800">@foreach ($report['sources'] as $source)<tr><td class="p-3"><strong>{{ $source['label'] }}</strong><div class="text-xs text-gray-500">{{ $source['origin_key'] }} · {{ $source['cost_center'] ?? 'Non classificato' }}</div></td><td class="p-3">{{ $source['allocation'] }}</td><td class="p-3">{{ $source['actual'] }}</td><td class="p-3">{{ $source['state'] ?? '—' }}</td><td class="p-3"><details><summary class="cursor-pointer text-primary-300">Apri drill-down</summary><pre class="mt-2 max-w-xl whitespace-pre-wrap text-xs text-gray-400">{{ json_encode($source['detail'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>@foreach ($source['corrections'] as $correction)<p>Correzione {{ $correction['amount'] }} · {{ $correction['reason'] }}</p>@endforeach @foreach ($source['annotations'] as $annotation)<p>Annotazione: {{ $annotation['reason'] }} · Nessun impatto economico</p>@endforeach</details></td></tr>@endforeach</tbody></table></div>
            @if ($report['comparisons'] !== [])<div class="overflow-x-auto rounded-xl border border-gray-700"><table class="w-full text-left text-sm"><thead class="bg-gray-900"><tr><th class="p-3">Variazione</th><th class="p-3">Valori</th><th class="p-3">Categoria</th><th class="p-3">Dimensioni ed etichette</th></tr></thead><tbody>@foreach ($report['comparisons'] as $row)<tr class="border-t border-gray-800"><td class="p-3">{{ $row['label'] }}</td><td class="p-3">{{ $row['initial_value'] }} → {{ $row['final_value'] }} ({{ $row['delta'] }})</td><td class="p-3">{{ $row['category'] }}</td><td class="p-3">{{ implode(', ', [...$row['dimensions'], ...$row['labels']]) }} @if ($row['insufficiently_explained'])<p class="text-warning-400">Variazione non sufficientemente spiegata</p>@endif</td></tr>@endforeach</tbody></table></div>@endif
            @foreach ($report['sections'] as $section)
                <section class="rounded-xl border border-gray-700 bg-gray-900/40 p-5">
                    <h3 class="text-lg font-semibold text-white">{{ $section['title'] }}</h3>
                    @forelse ($section['rows'] as $row)
                        <details class="mt-3 rounded-lg border border-gray-800 p-3">
                            <summary class="cursor-pointer text-primary-300">{{ $row['label'] ?? $row['key'] ?? $row['origin_key'] ?? 'Dettaglio' }}</summary>
                            <pre class="mt-2 whitespace-pre-wrap text-xs text-gray-400">{{ json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                        </details>
                    @empty
                        <p class="mt-2 text-sm text-gray-400">Nessun dato applicabile.</p>
                    @endforelse
                </section>
            @endforeach
        </section>
    @else
        <p class="rounded-xl border border-dashed border-gray-700 p-8 text-center text-gray-400">Seleziona i riferimenti e genera il report. Nessun totale viene dedotto prima della selezione.</p>
    @endif
</x-filament-panels::page>
