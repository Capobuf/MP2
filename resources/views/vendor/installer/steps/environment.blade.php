<div class="mb-6">
    <h3 class="section-title">{{ __('installer::installer.environment_title') }}</h3>
    <p class="section-subtitle">{{ __('installer::installer.environment_subtitle') }}</p>
</div>

<div class="form-grid">
    <input type="hidden" wire:model="state.connection">

    <div class="col-span-full">
        <label class="form-label">{{ __('installer::installer.environment_app_url') }}</label>
        <input type="url" wire:model="state.app_url" class="form-input" placeholder="https://example.com">
    </div>

    <div>
        <label class="form-label">{{ __('installer::installer.environment_host') }}</label>
        <input type="text" wire:model="state.host" class="form-input" placeholder="127.0.0.1">
    </div>
    <div>
        <label class="form-label">{{ __('installer::installer.environment_port') }}</label>
        <input type="text" inputmode="numeric" wire:model="state.port" class="form-input" placeholder="3306">
    </div>

    <div class="col-span-full">
        <label class="form-label">{{ __('installer::installer.environment_database_name') }}</label>
        <input type="text" wire:model="state.database" class="form-input" autocomplete="off">
        <p class="form-hint">{{ __('installer::installer.environment_database_hint') }}</p>
    </div>

    <div>
        <label class="form-label">{{ __('installer::installer.environment_username') }}</label>
        <input type="text" wire:model="state.username" class="form-input" autocomplete="username">
    </div>
    <div>
        <label class="form-label">{{ __('installer::installer.environment_password') }}</label>
        <input type="password" wire:model="state.password" class="form-input" autocomplete="current-password">
    </div>

    <div class="col-span-full" style="padding-top: 1rem;">
        <button type="button" wire:click="testDatabase" wire:loading.attr="disabled" class="test-connection-btn">
            <span wire:loading.remove wire:target="testDatabase">{{ __('installer::installer.environment_test_connection') }}</span>
            <span wire:loading wire:target="testDatabase">{{ __('installer::installer.environment_testing') }}</span>
        </button>

        @if($testConnectionResult)
            <div class="test-connection-result {{ $testConnectionResult['success'] ? 'msg-box--test-pass' : 'msg-box--test-fail' }}">
                <span>{{ $testConnectionResult['message'] }}</span>
            </div>
        @endif
    </div>
</div>
