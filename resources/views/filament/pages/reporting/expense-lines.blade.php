@php
    use Illuminate\Support\Number;
    $lineMoney = static fn (string|int|float $value): string => Number::currency((float) $value, in: 'EUR', locale: 'it');
@endphp

@if (is_array($lines) && $lines !== [])
    <div class="mp2-report-lines-wrap">
        <table class="mp2-report-lines">
            <thead><tr><th>Tipo Riga</th><th class="mp2-report-number">Importo</th><th>Nota</th><th>Stato Annullamento</th><th>Allegati</th></tr></thead>
            <tbody>
                @foreach ($lines as $line)
                    <tr>
                        <td>{{ ['estimate' => 'Stima', 'actual' => 'Effettivo'][$line['type'] ?? ''] ?? ($line['type'] ?? '—') }}</td>
                        <td class="mp2-report-number">{{ $lineMoney($line['amount'] ?? '0.00') }}</td>
                        <td>{{ $line['note'] ?? '—' }}</td>
                        <td>{{ ($line['annulled'] ?? (($line['state'] ?? null) === 'annulled')) ? 'Annullata' : 'Attiva' }}</td>
                        <td>
                            @forelse ($line['attachments'] ?? [] as $attachment)
                                <span class="mp2-report-chip">{{ $attachment['name'] ?? 'Allegato' }}</span>
                            @empty
                                —
                            @endforelse
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
