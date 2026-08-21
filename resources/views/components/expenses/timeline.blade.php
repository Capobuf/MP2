<div class="mp2-timeline">
    @forelse ($events as $event)
        @php
            $rawType = (string) $event->getRawOriginal('event_type');
            $tone = match (true) {
                str_contains($rawType, 'annul'), str_contains($rawType, 'revers') => 'danger',
                str_contains($rawType, 'restor'), str_contains($rawType, 'approv') => 'success',
                str_contains($rawType, 'move'), str_contains($rawType, 'reclass') => 'warning',
                str_contains($rawType, 'creat'), str_contains($rawType, 'updat') => 'info',
                default => 'neutral',
            };
        @endphp
        <article class="mp2-timeline-item">
            <span class="mp2-timeline-marker mp2-timeline-marker-{{ $tone }}" aria-hidden="true"></span>
            <div class="mp2-timeline-content">
                <div class="mp2-timeline-title-row">
                    <h4>{{ $event->eventType()->label() }}</h4>
                    <time datetime="{{ $event->created_at->toIso8601String() }}">
                        {{ $event->created_at->timezone($timezone)->format('d/m/Y H:i') }}
                    </time>
                </div>
                <p class="mp2-timeline-author">{{ $event->actor?->name ?? 'Sistema' }}</p>
                @if (filled($event->reason))
                    <p class="mp2-timeline-reason">{{ $event->reason }}</p>
                @endif
            </div>
        </article>
    @empty
        <p class="mp2-empty-copy">Nessun evento recente.</p>
    @endforelse

    <a class="mp2-timeline-link" href="{{ $timelineUrl }}">Vedi Timeline completa</a>
</div>
