<div class="mb-6">
    <h3 class="section-title">{{ __('installer::installer.admin_title') }}</h3>
    <p class="section-subtitle">{{ __('installer::installer.admin_subtitle') }}</p>
</div>

<div class="form-grid">
    <div class="col-span-full">
        <label class="form-label">{{ __('installer::installer.admin_name') }}</label>
        <input type="text" wire:model="state.name" class="form-input" placeholder="{{ __('installer::installer.admin_name_placeholder') }}">
    </div>

    <div class="col-span-full">
        <label class="form-label">{{ __('installer::installer.admin_email') }}</label>
        <input type="email" wire:model="state.email" class="form-input" placeholder="{{ __('installer::installer.admin_email_placeholder') }}" autocomplete="username">
    </div>

    <div>
        <label class="form-label">{{ __('installer::installer.admin_password') }}</label>
        <input type="password" wire:model="state.password" class="form-input" placeholder="{{ __('installer::installer.admin_password_placeholder') }}" autocomplete="new-password">
    </div>

    <div>
        <label class="form-label">{{ __('installer::installer.admin_password_confirm') }}</label>
        <input type="password" wire:model="state.password_confirmation" class="form-input" placeholder="{{ __('installer::installer.admin_password_confirm_placeholder') }}" autocomplete="new-password">
    </div>
</div>
