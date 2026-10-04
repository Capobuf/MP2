<x-filament-panels::page>
    <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-white/5">
        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Un File, Tutto il Patrimonio Business</h2>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-600 dark:text-gray-300">
            Il backup ZIP contiene i dati cliente, le impostazioni di dominio e tutte le Proposte.
            Utenti, accessi e Timeline/Audit non sono inclusi e non saranno ricreati.
        </p>
        <fieldset class="mt-5 space-y-3">
            <legend class="font-medium text-gray-950 dark:text-white">Contenuto del Backup</legend>
            <label class="flex items-center gap-3 text-sm text-gray-950 dark:text-white">
                <input type="checkbox" wire:model.live="includeLogo" class="rounded" />
                Includi Logo
            </label>
            <label class="flex items-center gap-3 text-sm text-gray-950 dark:text-white">
                <input type="checkbox" wire:model.live="includeFiles" class="rounded" />
                Includi Allegati ed Evidenze originali
            </label>
        </fieldset>
        <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">
            {{ $includeLogo && $includeFiles ? 'Completo (dati cliente)' : (!$includeLogo && !$includeFiles ? 'Solo dati' : 'Dati cliente con le opzioni selezionate') }}.
            Logo: {{ $includeLogo ? 'incluso se presente' : 'escluso' }}.
            Allegati ed Evidenze: {{ $includeFiles ? 'file originali inclusi' : 'solo inventario e metadati' }}.
        </p>
        <div class="mt-5 rounded-xl bg-amber-50 p-4 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-100">
            Consulta il file liberamente, ma svolgi analisi e modifiche su una copia: cambiare il workbook invalida il restore garantito.
        </div>
    </div>
</x-filament-panels::page>
