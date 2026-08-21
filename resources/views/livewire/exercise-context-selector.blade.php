<div class="mp2-context-selector" role="group" aria-label="Contesto globale">
    @if ($company)
        @php
            $canSwitchCompany = $companies->count() > 1;
            $hasCompanyManagementActions = filled($companySettingsUrl) || filled($companyAccessUrl);
            $hasCompanyMenu = $canSwitchCompany || $hasCompanyManagementActions || filled($companyRegistrationUrl);
        @endphp

        @if ($hasCompanyMenu)
            <x-filament::dropdown placement="bottom-start" width="xs">
                <x-slot name="trigger">
                    <button type="button" class="mp2-context-control mp2-context-company" aria-label="Apri selettore e azioni Azienda">
                        <span class="mp2-context-dot" aria-hidden="true"></span>
                        <span class="mp2-context-copy">
                            <span class="mp2-context-label">Azienda</span>
                            <span class="mp2-context-value">{{ $company->name }}</span>
                        </span>
                        <x-filament::icon icon="heroicon-m-chevron-down" class="mp2-context-chevron" />
                    </button>
                </x-slot>

                @if ($canSwitchCompany)
                    <x-filament::dropdown.list>
                        @foreach ($companies as $availableCompany)
                            <x-filament::dropdown.list.item
                                wire:click="selectCompany({{ $availableCompany->id }})"
                                :icon="$availableCompany->is($company) ? 'heroicon-m-check' : null"
                                :color="$availableCompany->is($company) ? 'primary' : 'gray'"
                            >
                                {{ $availableCompany->name }}
                            </x-filament::dropdown.list.item>
                        @endforeach
                    </x-filament::dropdown.list>
                @endif

                @if ($hasCompanyManagementActions)
                    <div @class(['mp2-context-menu-group', 'mp2-context-menu-group-divided' => $canSwitchCompany])>
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

                            @if ($companyAccessUrl)
                                <x-filament::dropdown.list.item
                                    :href="$companyAccessUrl"
                                    icon="heroicon-m-key"
                                    tag="a"
                                    wire:navigate
                                >
                                    Accessi e capacità
                                </x-filament::dropdown.list.item>
                            @endif
                        </x-filament::dropdown.list>
                    </div>
                @endif

                @if ($companyRegistrationUrl)
                    <div @class(['mp2-context-menu-group', 'mp2-context-menu-group-divided' => $canSwitchCompany || $hasCompanyManagementActions])>
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
                <span class="mp2-context-dot" aria-hidden="true"></span>
                <span class="mp2-context-copy">
                    <span class="mp2-context-label">Azienda</span>
                    <span class="mp2-context-value">{{ $company->name }}</span>
                </span>
            </div>
        @endif

        <x-filament::dropdown placement="bottom-start" width="xs">
            <x-slot name="trigger">
                <button type="button" class="mp2-context-control mp2-context-exercise" aria-label="Esercizio globale">
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
                    @if ($exercises->isNotEmpty())
                        <x-filament::icon icon="heroicon-m-chevron-down" class="mp2-context-chevron" />
                    @endif
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
        </x-filament::dropdown>
    @endif
</div>
