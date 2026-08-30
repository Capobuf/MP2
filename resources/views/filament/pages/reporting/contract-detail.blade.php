@php
    use App\Domain\Contracts\ContractAttributionMode;
    use App\Domain\Contracts\ContractCycleType;
    use Carbon\CarbonImmutable;
    use Illuminate\Support\Number;

    $date = static fn (mixed $value): string => blank($value)
        ? '—'
        : CarbonImmutable::parse((string) $value)->format('d/m/Y');
    $conditions = is_array($detail['conditions'] ?? null) ? $detail['conditions'] : [];
    $renewals = is_array($detail['cycles'] ?? null) ? $detail['cycles'] : [];
    if ($renewals === [] && is_array($detail['renewal_configuration_at_31_december'] ?? null)) {
        $renewals = [$detail['renewal_configuration_at_31_december']];
    }
    $events = is_array($detail['events'] ?? null)
        ? $detail['events']
        : (is_array($detail['lifecycle_events'] ?? null)
            ? $detail['lifecycle_events']
            : (is_array($detail['approved_lifecycle'] ?? null) ? $detail['approved_lifecycle'] : []));
    $composition = is_array($detail['annual_composition'] ?? null) ? $detail['annual_composition'] : [];
    $eventLabels = [
        'activation' => 'Attivazione',
        'cessation' => 'Cessazione',
        'expiry_cessation' => 'Cessazione a scadenza',
        'reactivation' => 'Riattivazione',
        'cancellation' => 'Annullamento',
        'renewal' => 'Rinnovo',
    ];
@endphp

@if ($conditions !== [])
    <section class="mp2-report-detail-group">
        <div class="mp2-report-detail-group-heading">
            <h5>Condizioni economiche</h5>
            <span>{{ count($conditions) }}</span>
        </div>
        <div class="mp2-report-record-grid">
            @foreach ($conditions as $condition)
                <article class="mp2-report-record">
                    <div class="mp2-report-record-heading">
                        <strong>{{ Number::currency((float) ($condition['amount'] ?? 0), in: 'EUR', locale: 'it') }}</strong>
                        <span class="mp2-report-state">{{ ($condition['annulled_at'] ?? null) || ($condition['annulled'] ?? false) ? 'Annullata' : 'Attiva' }}</span>
                    </div>
                    <dl class="mp2-report-record-facts">
                        <div><dt>Ciclo</dt><dd>{{ ContractCycleType::tryFrom((string) ($condition['cycle'] ?? ''))?->label() ?? '—' }}</dd></div>
                        <div><dt>Attribuzione</dt><dd>{{ ContractAttributionMode::tryFrom((string) ($condition['attribution_mode'] ?? ''))?->label() ?? '—' }}</dd></div>
                        <div><dt>Validità</dt><dd>{{ $date($condition['valid_from'] ?? null) }} – {{ blank($condition['valid_to'] ?? null) ? 'senza termine' : $date($condition['valid_to']) }}</dd></div>
                        @if (filled($condition['reason'] ?? null))
                            <div class="mp2-report-record-note"><dt>Motivazione</dt><dd>{{ $condition['reason'] }}</dd></div>
                        @endif
                    </dl>
                </article>
            @endforeach
        </div>
    </section>
@endif

@if ($renewals !== [])
    <section class="mp2-report-detail-group">
        <div class="mp2-report-detail-group-heading">
            <h5>Configurazioni di rinnovo</h5>
            <span>{{ count($renewals) }}</span>
        </div>
        <div class="mp2-report-record-grid">
            @foreach ($renewals as $renewal)
                <article class="mp2-report-record">
                    <div class="mp2-report-record-heading">
                        <strong>Efficace dal {{ $date($renewal['effective_from'] ?? null) }}</strong>
                        <span class="mp2-report-state">{{ ($renewal['automatic_renewal'] ?? false) ? 'Automatico' : 'Manuale' }}</span>
                    </div>
                    <dl class="mp2-report-record-facts">
                        <div><dt>Scadenza di riferimento</dt><dd>{{ $date($renewal['expiry_anchor_date'] ?? null) }}</dd></div>
                        <div><dt>Durata rinnovo</dt><dd>{{ filled($renewal['renewal_duration_months'] ?? null) ? $renewal['renewal_duration_months'].' mesi' : '—' }}</dd></div>
                        <div><dt>Preavviso</dt><dd>{{ filled($renewal['notice_days'] ?? null) ? $renewal['notice_days'].' giorni' : '—' }}</dd></div>
                    </dl>
                </article>
            @endforeach
        </div>
    </section>
@endif

@if ($composition !== [])
    <section class="mp2-report-detail-group">
        <div class="mp2-report-detail-group-heading">
            <h5>Composizione annuale</h5>
            <span>{{ count($composition) }} quote</span>
        </div>
        <div class="mp2-report-lines-wrap">
            <table class="mp2-report-lines">
                <thead><tr><th>Inizio ciclo</th><th>Data di attribuzione</th><th class="mp2-report-number">Importo</th></tr></thead>
                <tbody>
                    @foreach ($composition as $item)
                        <tr>
                            <td>{{ $date($item['cycle_start'] ?? null) }}</td>
                            <td>{{ $date($item['attribution_date'] ?? null) }}</td>
                            <td class="mp2-report-number">{{ Number::currency((float) ($item['amount'] ?? 0), in: 'EUR', locale: 'it') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif

@if ($events !== [])
    <section class="mp2-report-detail-group">
        <div class="mp2-report-detail-group-heading">
            <h5>Eventi contrattuali</h5>
            <span>{{ count($events) }}</span>
        </div>
        <div class="mp2-report-timeline">
            @foreach ($events as $event)
                @php
                    $eventDate = $event['state_change_date'] ?? $event['renewed_expiry_date'] ?? $event['effective_date'] ?? $event['declared_contractual_date'] ?? null;
                    $annulled = filled($event['annulled_at'] ?? null);
                @endphp
                <article class="mp2-report-timeline-item">
                    <div class="mp2-report-timeline-marker" aria-hidden="true"></div>
                    <div>
                        <div class="mp2-report-record-heading">
                            <strong>{{ $eventLabels[$event['type'] ?? ''] ?? str((string) ($event['type'] ?? 'Evento'))->replace('_', ' ')->ucfirst() }}</strong>
                            <span class="mp2-report-state">{{ $annulled ? 'Annullato' : 'Registrato' }}</span>
                        </div>
                        <p>{{ $date($eventDate) }}</p>
                        @if (filled($event['reason'] ?? null))<p>{{ $event['reason'] }}</p>@endif
                        @if ($annulled && filled($event['annulment_reason'] ?? null))<p>Motivo annullamento: {{ $event['annulment_reason'] }}</p>@endif
                    </div>
                </article>
            @endforeach
        </div>
    </section>
@endif
