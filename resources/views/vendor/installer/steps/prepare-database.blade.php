@php
    $databaseName = $step->databaseName();
    $databaseIsEmpty = $step->isEmpty();
@endphp

<div class="mb-6">
    <h3 class="section-title">{{ __('installer::installer.database_title') }}</h3>
    <p class="section-subtitle">{{ __('installer::installer.database_subtitle') }}</p>
</div>

@if($databaseIsEmpty)
    <div class="msg-box msg-box--success">
        <strong>{{ __('installer::installer.database_empty') }}</strong>
    </div>
@else
    <div class="msg-box msg-box--error" role="alert">
        <strong>{{ __('installer::installer.database_non_empty') }}</strong>
        <p>{{ __('installer::installer.database_destructive_warning', ['database' => $databaseName]) }}</p>
    </div>

    <div class="mt-6">
        <label class="form-label" for="database-confirmation">
            {{ __('installer::installer.database_confirmation_label', ['database' => $databaseName]) }}
        </label>
        <input
            id="database-confirmation"
            type="text"
            wire:model="state.database_confirmation"
            class="form-input"
            autocomplete="off"
        >
    </div>
@endif
