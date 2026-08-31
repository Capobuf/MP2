<div class="mp2-context-selector" role="group" aria-label="Contesto globale">
    @if ($company)
        @php
            $hasCompanyManagementActions = filled($companySettingsUrl);
            $hasCompanyMenu = $hasCompanyManagementActions || filled($companyRegistrationUrl);
            $hasExerciseManagementActions = filled($exerciseManagementUrl);
            $hasExerciseMenu = $exercises->isNotEmpty() || $hasExerciseManagementActions || filled($exerciseCreationUrl);
        @endphp

        @if ($hasCompanyMenu)
            <x-filament::dropdown placement="bottom-start" width="xs">
                <x-slot name="trigger">
                    <button type="button" class="mp2-context-control mp2-context-company" aria-label="Apri selettore e azioni Azienda">
                        <x-filament::icon icon="heroicon-m-building-office-2" class="mp2-context-icon" />
                        <span class="mp2-context-copy">
                            <span class="mp2-context-label">Azienda</span>
                            <span class="mp2-context-value">{{ $company->name }}</span>
                        </span>
                        <x-filament::icon icon="heroicon-m-chevron-down" class="mp2-context-chevron" />
                    </button>
                </x-slot>

                @if ($hasCompanyManagementActions)
                    <div class="mp2-context-menu-group">
                        <x-filament::dropdown.list>
                            @if ($companySettingsUrl)
                                <x-filament::dropdown.list.item
                                    :href="$companySettingsUrl"
                                    icon="heroicon-m-cog-6-tooth"
                                    tag="a"
                                    wire:navigate
                                >
                                    Impostazioni Azienda
                                </x-filament::dropdown.list.item>
                            @endif

                        </x-filament::dropdown.list>
                    </div>
                @endif

                @if ($companyRegistrationUrl)
                    <div @class(['mp2-context-menu-group', 'mp2-context-menu-group-divided' => $hasCompanyManagementActions])>
                        <x-filament::dropdown.list>
                            <x-filament::dropdown.list.item
                                :href="$companyRegistrationUrl"
                                color="primary"
                                icon="heroicon-m-plus"
                                tag="a"
                                wire:navigate
                            >
                                Crea Azienda
                            </x-filament::dropdown.list.item>
                        </x-filament::dropdown.list>
                    </div>
                @endif
            </x-filament::dropdown>
        @else
            <div class="mp2-context-control mp2-context-company" aria-label="Azienda corrente">
                <x-filament::icon icon="heroicon-m-building-office-2" class="mp2-context-icon" />
                <span class="mp2-context-copy">
                    <span class="mp2-context-label">Azienda</span>
                    <span class="mp2-context-value">{{ $company->name }}</span>
                </span>
            </div>
        @endif

        @if ($hasExerciseMenu)
            <x-filament::dropdown placement="bottom-start" width="xs">
                <x-slot name="trigger">
                    <button type="button" class="mp2-context-control mp2-context-exercise" aria-label="Apri selettore e azioni Esercizio">
                        <x-filament::icon icon="heroicon-m-calendar-days" class="mp2-context-icon" />
                        <span class="mp2-context-copy">
                            <span class="mp2-context-label">Esercizio</span>
                            <span class="mp2-context-value">
                                @if ($exercises->isNotEmpty())
                                    {{ $exercises->firstWhere('id', $exerciseId)?->year }} · {{ $exercises->firstWhere('id', $exerciseId)?->status()->label() }}
                                @else
                                    Nessun Esercizio
                                @endif
                            </span>
                        </span>
                        <x-filament::icon icon="heroicon-m-chevron-down" class="mp2-context-chevron" />
                    </button>
                </x-slot>

                @if ($exercises->isNotEmpty())
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
                @endif

                @if ($hasExerciseManagementActions)
                    <div @class(['mp2-context-menu-group', 'mp2-context-menu-group-divided' => $exercises->isNotEmpty()])>
                        <x-filament::dropdown.list>
                            <x-filament::dropdown.list.item
                                :href="$exerciseManagementUrl"
                                icon="heroicon-m-calendar-days"
                                tag="a"
                                wire:navigate
                            >
                                Gestisci Esercizi
                            </x-filament::dropdown.list.item>
                        </x-filament::dropdown.list>
                    </div>
                @endif

                @if ($exerciseCreationUrl)
                    <div @class(['mp2-context-menu-group', 'mp2-context-menu-group-divided' => $exercises->isNotEmpty() || $hasExerciseManagementActions])>
                        <x-filament::dropdown.list>
                            <x-filament::dropdown.list.item
                                :href="$exerciseCreationUrl"
                                color="primary"
                                icon="heroicon-m-plus"
                                tag="a"
                                wire:navigate
                            >
                                Crea Esercizio
                            </x-filament::dropdown.list.item>
                        </x-filament::dropdown.list>
                    </div>
                @endif
            </x-filament::dropdown>
        @else
            <div class="mp2-context-control mp2-context-exercise" aria-label="Esercizio corrente">
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
                    <button type="button" class="mp2-context-control mp2-context-budget" aria-label="Budget globale">
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
