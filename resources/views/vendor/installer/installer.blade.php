<div
    class="installer-page"
    x-data="{ isFinishing: false, isSuccess: false, redirectUrl: '/admin/login' }"
    x-on:installer-finishing.window="isFinishing = true"
    x-on:installation-success.window="isFinishing = false; isSuccess = true; redirectUrl = $event.detail[0].redirectUrl || '/admin/login'"
    x-cloak
>
    <div class="installer-card">
        <div class="installer-sidebar">
            @php
                $allStepIds = array_keys($steps);
                $currentIndex = array_search($step->id(), $allStepIds);
                $progressPercentage = $currentIndex !== false
                    ? ($currentIndex / max(count($allStepIds) - 1, 1)) * 100
                    : 0;
            @endphp

            <div class="installer-progress-bar">
                <div class="installer-progress-bar-fill" style="width: {{ $progressPercentage }}%"></div>
            </div>

            <div class="installer-sidebar-content">
                <div class="installer-brand">
                    <div class="installer-brand-icon" aria-hidden="true">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </div>
                    <span class="installer-brand-name">{{ config('installer.name') }}</span>
                </div>

                <h2 class="installer-step-title">{{ $step->label() }}</h2>
                <p class="installer-step-counter">
                    {{ __('installer::installer.step_of', ['current' => $currentIndex + 1, 'total' => count($steps)]) }}
                </p>
            </div>

            <div class="installer-progress">
                <div class="installer-progress-list">
                    @php $currentFound = false; @endphp
                    @foreach($steps as $registeredStep)
                        @php
                            $isCurrent = $registeredStep->id() === $step->id();
                            if ($isCurrent) {
                                $currentFound = true;
                            }
                            $isPast = ! $currentFound && ! $isCurrent;
                        @endphp

                        @if($isPast)
                            <button type="button" wire:click="goToStep('{{ $registeredStep->id() }}')" class="installer-progress-item installer-progress-item--past">
                                <div class="installer-progress-indicator installer-progress-indicator--past">✓</div>
                                <span class="installer-progress-label installer-progress-label--past">{{ $registeredStep->label() }}</span>
                            </button>
                        @else
                            <div class="installer-progress-item {{ $isCurrent ? 'installer-progress-item--current' : 'installer-progress-item--future' }}">
                                <div class="installer-progress-indicator {{ $isCurrent ? 'installer-progress-indicator--current' : 'installer-progress-indicator--future' }}">
                                    {{ $loop->iteration }}
                                </div>
                                <span class="installer-progress-label {{ $isCurrent ? 'installer-progress-label--current' : 'installer-progress-label--future' }}">
                                    {{ $registeredStep->label() }}
                                </span>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>

            <div class="installer-sidebar-footer">
                {{ __('installer::installer.copyright', ['year' => date('Y'), 'name' => config('installer.name')]) }}
            </div>
        </div>

        <div class="installer-content">
            @if($errors->has('global'))
                <div class="installer-alert" role="alert">
                    <div class="installer-alert-body">
                        <p class="installer-alert-text">{{ $errors->first('global') }}</p>
                    </div>
                </div>
            @endif

            <form class="installer-form" wire:submit="next" x-show="! isSuccess">
                <div class="installer-form-body" wire:key="installer-step-{{ $step->id() }}">
                    @include($step->view())
                </div>

                <div class="installer-actions" x-show="! isSuccess">
                    @if(! $isFirstStep)
                        <button type="button" wire:click="previous" wire:loading.attr="disabled" class="btn btn--back">
                            {{ __('installer::installer.btn_back') }}
                        </button>
                    @else
                        <div></div>
                    @endif

                    <button type="submit" wire:loading.attr="disabled" :disabled="isFinishing" class="btn btn--continue">
                        <span wire:loading.remove wire:target="next" x-show="! isFinishing">
                            {{ $isLastStep ? __('installer::installer.btn_complete') : __('installer::installer.btn_continue') }}
                        </span>
                        <span wire:loading wire:target="next" x-show="! isFinishing">
                            {{ __('installer::installer.btn_processing') }}
                        </span>
                        <span x-show="isFinishing" style="display: none;">
                            {{ __('installer::installer.btn_finalizing') }}
                        </span>
                    </button>
                </div>
            </form>

            <div x-show="isSuccess" x-cloak style="display: none; text-align: center; padding: 3rem 1rem;">
                <h2 class="section-title">{{ __('installer::installer.installation_success_title') }}</h2>
                <p class="section-subtitle">{{ __('installer::installer.installation_success_message') }}</p>
                <a :href="redirectUrl" class="btn btn--continue">
                    {{ __('installer::installer.installation_login') }}
                </a>
            </div>
        </div>
    </div>
</div>
