@if (is_array($value))
    @if ($value === [])
        <span class="muted">Nessun dato</span>
    @else
        <ul class="structured">
            @foreach ($value as $key => $item)
                <li>@if (! is_int($key))<strong>{{ str($key)->replace('_', ' ')->ucfirst() }}:</strong>@endif @include('reports.partials.structured-value', ['value' => $item])</li>
            @endforeach
        </ul>
    @endif
@elseif (is_bool($value))
    {{ $value ? 'Sì' : 'No' }}
@elseif ($value === null || $value === '')
    <span class="muted">Non applicabile</span>
@else
    {{ $value }}
@endif
