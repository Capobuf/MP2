@php
    use Illuminate\Support\Number;

    $labels = [
        'allocation' => 'Allocato', 'actual' => 'Effettivo', 'amount' => 'Importo', 'reason' => 'Motivo',
        'state' => 'Stato', 'from_state' => 'Stato iniziale', 'to_state' => 'Stato finale',
        'effective_date' => 'Data efficace', 'valid_from' => 'Valida dal', 'valid_to' => 'Valida fino al',
        'cycle' => 'Ciclo', 'attribution_mode' => 'Attribuzione', 'carryover_amount' => 'Riporto',
        'reprogrammed_amount' => 'Importo Riprogrammato', 'mode' => 'Modalità', 'annulled_at' => 'Annullata il',
        'automatic_renewal' => 'Rinnovo automatico', 'notice_days' => 'Preavviso (giorni)',
        'event_references' => 'Riferimenti eventi', 'relations' => 'Relazioni informative',
    ];
    $isList = array_is_list($data);
    $moneyKeys = ['allocation', 'actual', 'amount', 'operational_variance', 'carryover', 'carryover_amount', 'reprogrammed_amount', 'residual', 'saving', 'unused_allocation', 'final_allocation', 'closing_actual', 'final_estimates', 'received_carryover'];
@endphp

@if ($isList)
    <ul class="mp2-report-structured-list">
        @foreach ($data as $item)
            <li>
                @if (is_array($item))
                    @include('filament.pages.reporting.key-value', ['data' => $item])
                @elseif (is_bool($item))
                    {{ $item ? 'Sì' : 'No' }}
                @else
                    {{ $item ?? '—' }}
                @endif
            </li>
        @endforeach
    </ul>
@else
    <dl class="mp2-report-key-values">
        @foreach ($data as $key => $value)
            <div>
                <dt>{{ $labels[$key] ?? str($key)->replace('_', ' ')->ucfirst() }}</dt>
                <dd>
                    @if (is_array($value))
                        @include('filament.pages.reporting.key-value', ['data' => $value])
                    @elseif (is_bool($value))
                        {{ $value ? 'Sì' : 'No' }}
                    @elseif (in_array($key, $moneyKeys, true) && is_numeric($value))
                        {{ Number::currency((float) $value, in: 'EUR', locale: 'it') }}
                    @else
                        {{ $value ?? '—' }}
                    @endif
                </dd>
            </div>
        @endforeach
    </dl>
@endif
