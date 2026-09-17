@php
    use Filament\Widgets\View\Components\ChartWidgetComponent;
    use Illuminate\Contracts\Support\Htmlable;

    $color = $this->getColor();
    $heading = $this->getHeading();
    $description = $this->getDescription();
    $filters = $this->getFilters();
    $isCollapsible = $this->isCollapsible();
    $type = $this->getType();
    $maxHeight = $this->getMaxHeight();
    $hasMaxHeight = filled($maxHeight) && $maxHeight !== '100%';
    $isEmpty = $this->isEmpty();
    $chartAccessibleLabel = trim(implode('. ', array_filter([
        $heading instanceof Htmlable ? strip_tags($heading->toHtml()) : $heading,
        $description instanceof Htmlable ? strip_tags($description->toHtml()) : $description,
    ], fn ($value): bool => filled($value))));
@endphp

<x-filament-widgets::widget class="fi-wi-chart {{ $this->chartSurfaceClass() }}">
    <x-filament::section
        :description="$description"
        :heading="$heading"
        :collapsible="$isCollapsible"
    >
        @if ($filters)
            <x-slot name="afterHeader">
                <x-filament::dropdown placement="bottom-end" width="none" :offset="6" class="mp2-economic-chart-filter" wire:key="economic-chart-filter-{{ $this->getId() }}">
                    <x-slot name="trigger">
                        <button type="button" class="mp2-economic-chart-filter-trigger" aria-label="Raggruppa Allocato per" wire:loading.attr="disabled" wire:target="filter">
                            <span>{{ $filters[$this->filter] }}</span>
                            <x-filament::icon icon="heroicon-m-chevron-down" />
                        </button>
                    </x-slot>

                    <x-filament::dropdown.list role="menu" aria-label="Raggruppa Allocato per">
                        @foreach ($filters as $value => $label)
                            <x-filament::dropdown.list.item
                                x-on:click="close(); $wire.set('filter', {{ \Illuminate\Support\Js::from($value) }})"
                                :icon="$value === $this->filter ? 'heroicon-m-check' : null"
                                :color="$value === $this->filter ? 'primary' : 'gray'"
                                role="menuitemradio"
                                aria-checked="{{ $value === $this->filter ? 'true' : 'false' }}"
                                wire:loading.attr="disabled"
                                wire:target="filter"
                            >
                                {{ $label }}
                            </x-filament::dropdown.list.item>
                        @endforeach
                    </x-filament::dropdown.list>
                </x-filament::dropdown>
            </x-slot>
        @endif

        <div wire:key="economic-chart-{{ $this->getId() }}-{{ $type }}-{{ $this->filter }}" @if ($isEmpty) style="display: none" @endif>
            <div
                x-load
                x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('chart', 'filament/widgets') }}"
                wire:ignore
                data-chart-type="{{ $type }}"
                x-data="chart({
                    cachedData: @js($this->getCachedData()),
                    options: @js($this->getOptions()),
                    type: @js($type),
                })"
                {{
                    (new \Filament\Support\View\ComponentAttributeBag)
                        ->color(ChartWidgetComponent::class, $color)
                        ->class([
                            'fi-wi-chart-frame',
                            'fi-wi-chart-canvas-ctn',
                            'fi-wi-chart-frame-no-aspect-ratio' => $hasMaxHeight,
                        ])
                }}
            >
                <canvas
                    x-ref="canvas"
                    @if (filled($chartAccessibleLabel))
                        role="img"
                        aria-label="{{ $chartAccessibleLabel }}"
                    @endif
                    @style([
                        'width: 100%',
                        'height: 100%; max-height: 100%' => ! $hasMaxHeight,
                        ('max-height: ' . e($maxHeight)) => $hasMaxHeight,
                    ])
                ></canvas>

                <span x-ref="backgroundColorElement" class="fi-wi-chart-bg-color"></span>
                <span x-ref="borderColorElement" class="fi-wi-chart-border-color"></span>
                <span x-ref="gridColorElement" class="fi-wi-chart-grid-color"></span>
                <span x-ref="textColorElement" class="fi-wi-chart-text-color"></span>
            </div>
        </div>

        @if ($isEmpty)
            <div
                @class([
                    'fi-wi-chart-frame',
                    'fi-wi-chart-frame-no-aspect-ratio' => $hasMaxHeight,
                ])
                @style([('min-height: ' . e($maxHeight)) => $hasMaxHeight])
            >
                <x-filament::empty-state
                    :contained="false"
                    :description="$this->getEmptyStateDescription()"
                    :heading="$this->getEmptyStateHeading()"
                    :icon="$this->getEmptyStateIcon()"
                    icon-color="gray"
                />
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
