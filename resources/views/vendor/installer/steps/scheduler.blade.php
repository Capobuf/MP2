@php
    $commandOnly = $step->commandOnly($state);
    $crontab = $step->crontab($state);
@endphp

<div class="mb-6">
    <h3 class="section-title">{{ __('installer::installer.scheduler_title') }}</h3>
    <p class="section-subtitle">{{ __('installer::installer.scheduler_subtitle') }}</p>
</div>

<div class="form-grid">
    <div class="col-span-full">
        <label class="form-label">{{ __('installer::installer.scheduler_php_command') }}</label>
        <input
            type="text"
            wire:model.live.debounce.300ms="state.php_cli"
            class="form-input"
            autocomplete="off"
        >
    </div>

    <div class="col-span-full">
        <label class="form-label">{{ __('installer::installer.scheduler_crontab') }}</label>
        <textarea class="form-input" rows="3" readonly>{{ $crontab }}</textarea>
        <button type="button" class="test-connection-btn" x-on:click="navigator.clipboard.writeText(@js($crontab))">
            {{ __('installer::installer.scheduler_copy') }}
        </button>
    </div>

    <div class="col-span-full">
        <label class="form-label">{{ __('installer::installer.scheduler_command_only') }}</label>
        <textarea class="form-input" rows="3" readonly>{{ $commandOnly }}</textarea>
        <button type="button" class="test-connection-btn" x-on:click="navigator.clipboard.writeText(@js($commandOnly))">
            {{ __('installer::installer.scheduler_copy') }}
        </button>
    </div>

    <div class="col-span-full">
        <label class="toggle-label">
            <input type="checkbox" wire:model="state.scheduler_confirmed" class="toggle-input">
            <span class="toggle-text">{{ __('installer::installer.scheduler_confirmation') }}</span>
        </label>
    </div>
</div>
