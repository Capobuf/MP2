<aside
    class="mp2-expense-panel {{ $compact ? 'mp2-expense-panel-compact' : 'mp2-expense-panel-full' }}"
    aria-label="Dettaglio Spesa #{{ $expense->id }}"
    x-on:keydown.escape.window.capture="if (! document.querySelector('[role=dialog]')) $wire.close()"
>
    @if ($compact)
        <header class="mp2-expense-panel-header">
            <div class="mp2-expense-panel-title">
                <p class="mp2-eyebrow">Spesa #{{ $expense->id }}</p>
                <h2>{{ $expense->description }}</h2>
            </div>

            <x-filament::icon-button
                wire:click="close"
                icon="heroicon-m-x-mark"
                label="Chiudi Dettaglio"
                color="gray"
            />
        </header>

        <div class="mp2-expense-panel-toolbar">
            <span class="mp2-status-badge {{ $expense->isReversed() ? 'mp2-status-muted' : 'mp2-status-success' }}">
                {{ $expense->isReversed() ? 'Stornata' : 'Attiva' }}
            </span>

            <x-filament-actions::group
                :actions="[
                    $this->editExpenseAction,
                    $this->reverseExpenseAction,
                    $this->restoreExpenseAction,
                ]"
                label="Azioni"
                icon="heroicon-m-chevron-down"
                color="gray"
                size="sm"
                dropdown-placement="bottom-end"
                button
                outlined
            />
        </div>
    @endif

    @if ($compact)
        <section class="mp2-detail-section" aria-labelledby="expense-summary-{{ $expense->id }}">
            <h3 id="expense-summary-{{ $expense->id }}">Riepilogo Economico</h3>
            <dl class="mp2-economic-summary">
                <div>
                    <dt>Stima</dt>
                    <dd class="mp2-money-estimate">{{ $this->money($expense->allocation()) }}</dd>
                </div>
                <div>
                    <dt>Effettivo</dt>
                    <dd class="mp2-money-actual">{{ $this->money($expense->actual()) }}</dd>
                </div>
                <div>
                    <dt>Scostamento</dt>
                    <dd>{{ $this->money($expense->operationalVariance()) }}</dd>
                </div>
            </dl>
        </section>
    @endif

    <section class="mp2-detail-section" aria-labelledby="expense-data-{{ $expense->id }}">
        <h3 id="expense-data-{{ $expense->id }}">Dati Principali</h3>
        <dl class="mp2-detail-list">
            <div><dt>Esercizio</dt><dd>{{ $expense->exercise->year }}</dd></div>
            <div><dt>Contenitore</dt><dd>{{ $expense->containerLabel() }}</dd></div>
            <div><dt>Centro di Costo</dt><dd>{{ $expense->costCenterLabel() }}</dd></div>
            <div><dt>Fornitore</dt><dd>{{ $expense->supplier?->legal_name ?? '—' }}</dd></div>
        </dl>
    </section>

    <section class="mp2-detail-section" aria-labelledby="expense-lines-{{ $expense->id }}">
        <div class="mp2-section-heading">
            <h3 id="expense-lines-{{ $expense->id }}">Righe della Spesa</h3>
            {{ $this->addLineAction }}
        </div>

        <div class="mp2-lines-table-wrap">
            <table class="mp2-lines-table">
                <thead>
                    <tr>
                        <th>Tipo</th>
                        <th>Nota</th>
                        <th class="mp2-line-secondary">Q.tà</th>
                        <th class="mp2-number mp2-line-secondary">Unitario</th>
                        <th class="mp2-number">Importo</th>
                        <th><span class="sr-only">Azioni</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($expense->lines as $line)
                        <tr class="{{ $line->isAnnulled() ? 'mp2-line-annulled' : '' }}">
                            <td>
                                <span class="mp2-line-type {{ $line->lineType()->value === 'estimate' ? 'mp2-line-estimate' : 'mp2-line-actual' }}">
                                    {{ $line->lineType()->label() }}
                                </span>
                            </td>
                            <td class="mp2-line-note">{{ $line->note ?: '—' }}</td>
                            <td class="mp2-line-secondary">
                                {{ $line->quantity === null ? '—' : $this->quantity((string) $line->quantity) }}{{ $line->quantity !== null && $line->unit_of_measure ? ' '.$line->unit_of_measure : '' }}
                            </td>
                            <td class="mp2-number mp2-line-secondary">
                                {{ $line->unit_amount === null ? '—' : $this->money((string) $line->unit_amount) }}
                            </td>
                            <td class="mp2-number">{{ $this->money((string) $line->amount) }}</td>
                            <td class="mp2-line-actions">
                                <x-filament-actions::group
                                    :actions="[
                                        ($this->editLineAction)(['line' => $line->id]),
                                        $line->isAnnulled()
                                            ? ($this->restoreLineAction)(['line' => $line->id])
                                            : ($this->annulLineAction)(['line' => $line->id]),
                                    ]"
                                    icon="heroicon-m-ellipsis-vertical"
                                    label="Azioni Riga #{{ $line->id }}"
                                    color="gray"
                                    size="sm"
                                    dropdown-placement="bottom-end"
                                />
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="mp2-empty-row">Nessuna Riga presente.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="mp2-detail-section" aria-labelledby="expense-timeline-{{ $expense->id }}">
        <h3 id="expense-timeline-{{ $expense->id }}">Timeline Recente</h3>
        @include('components.expenses.timeline', [
            'events' => $events,
            'timezone' => $expense->company->timezone,
            'timelineUrl' => $this->timelineUrl(),
        ])
    </section>

    @if (filled($expense->notes))
        <section class="mp2-detail-section" aria-labelledby="expense-notes-{{ $expense->id }}">
            <h3 id="expense-notes-{{ $expense->id }}">Note</h3>
            <p class="mp2-expense-notes">{{ $expense->notes }}</p>
        </section>
    @endif

    @if ($compact)
        <footer class="mp2-expense-panel-footer">
            <x-filament::button :href="$this->fullDetailUrl()" tag="a" color="gray" icon="heroicon-m-arrow-top-right-on-square">
                Apri Dettaglio Completo
            </x-filament::button>
        </footer>
    @endif

    <x-filament-actions::modals />
</aside>
