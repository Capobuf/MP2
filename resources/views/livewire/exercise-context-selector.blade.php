<div class="mp2-operational-context">
    @if ($company)
        @if ($exercises->isNotEmpty())
            <x-filament::dropdown placement="bottom-start" width="xs">
                <x-slot name="trigger">
                    <button type="button" class="mp2-context-control mp2-context-exercise" aria-label="Seleziona Esercizio">
                        <x-filament::icon icon="heroicon-m-calendar-days" class="mp2-context-icon" />
                        <span class="mp2-context-copy">
                            <span class="mp2-context-label">Esercizio</span>
                            <span class="mp2-context-value">
                                {{ $exercises->firstWhere('id', $exerciseId)?->year }} · {{ $exercises->firstWhere('id', $exerciseId)?->status()->label() }}
                            </span>
                        </span>
                        <x-filament::icon icon="heroicon-m-chevron-down" class="mp2-context-chevron" />
                    </button>
                </x-slot>

                <x-filament::dropdown.list>
                    @foreach ($exercises as $exercise)
                        <x-filament::dropdown.list.item
                            wire:click="selectExercise({{ $exercise->id }})"
                            :icon="$exercise->id === $exerciseId ? 'heroicon-m-check' : null"
                            :color="$exercise->id === $exerciseId ? 'primary' : 'gray'"
                        >
                            {{ $exercise->year }} · {{ $exercise->status()->label() }}
                        </x-filament::dropdown.list.item>
                    @endforeach
                </x-filament::dropdown.list>
            </x-filament::dropdown>
        @else
            <div class="mp2-context-control mp2-context-exercise" aria-label="Esercizio Corrente">
                <x-filament::icon icon="heroicon-m-calendar-days" class="mp2-context-icon" />
                <span class="mp2-context-copy">
                    <span class="mp2-context-label">Esercizio</span>
                    <span class="mp2-context-value">Nessun Esercizio</span>
                </span>
            </div>
        @endif

        @if ($exerciseId)
            <x-filament::dropdown placement="bottom-start" width="xs">
                <x-slot name="trigger">
                    <button type="button" class="mp2-context-control mp2-context-budget" aria-label="Seleziona Budget">
                        <x-filament::icon icon="heroicon-m-banknotes" class="mp2-context-icon" />
                        <span class="mp2-context-copy">
                            <span class="mp2-context-label">Budget</span>
                            <span class="mp2-context-value">
                                @if ($selectedBudget = $budgets->firstWhere('id', $budgetId))
                                    v{{ $selectedBudget->version }} · {{ $selectedBudget->purpose->label() }}
                                @else
                                    Non selezionato
                                @endif
                            </span>
                        </span>
                        @if ($budgets->isNotEmpty())
                            <x-filament::icon icon="heroicon-m-chevron-down" class="mp2-context-chevron" />
                        @endif
                    </button>
                </x-slot>

                <x-filament::dropdown.list>
                    <x-filament::dropdown.list.item
                        wire:click="clearBudget"
                        :icon="$budgetId === null ? 'heroicon-m-check' : null"
                        :color="$budgetId === null ? 'primary' : 'gray'"
                    >
                        Nessun Budget selezionato
                    </x-filament::dropdown.list.item>
                    @foreach ($budgets as $budget)
                        <x-filament::dropdown.list.item
                            wire:click="selectBudget({{ $budget->id }})"
                            :icon="$budget->id === $budgetId ? 'heroicon-m-check' : null"
                            :color="$budget->id === $budgetId ? 'primary' : 'gray'"
                        >
                            Budget v{{ $budget->version }} · {{ $budget->purpose->label() }}
                        </x-filament::dropdown.list.item>
                    @endforeach
                </x-filament::dropdown.list>
            </x-filament::dropdown>
        @endif
    @endif
</div>
