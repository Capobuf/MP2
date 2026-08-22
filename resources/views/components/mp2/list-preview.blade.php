@props(['hasMore' => false])

<div {{ $attributes->class(['mp2-list-preview', 'mp2-list-preview-has-more' => $hasMore]) }}>
    <div class="mp2-list-preview-content">
        {{ $slot }}
    </div>

    @if ($hasMore)
        <div class="mp2-list-preview-action">
            {{ $action }}
        </div>
    @endif
</div>
