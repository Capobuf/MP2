<div class="mp2-proposal-source-summary">
    <div class="mp2-budget-source-identity">
        <strong>{{ $item['label'] }}</strong>
        <span class="mp2-budget-source-context">
            {{ $item['cost_center'] }}
            @if ($item['supplier'] !== '—') · {{ $item['supplier'] }} @endif
        </span>
        @if ($item['excluded'])
            <span class="mp2-budget-muted">Esclusa dalla Proposta</span>
        @endif
    </div>
    <span class="mp2-budget-source-type">{{ $item['type_label'] }}</span>
    <div class="mp2-proposal-item-allocation">
        <small>Allocato Base → Risultante</small>
        <strong>{{ $item['allocation_before'] }} → {{ $item['allocation_after'] }}</strong>
    </div>
    <div class="mp2-proposal-item-allocation">
        <small>Variazione</small>
        <strong class="mp2-proposal-delta-{{ $item['allocation_delta_tone'] }}">{{ $item['allocation_delta'] }}</strong>
    </div>
    <div class="mp2-proposal-item-readiness">
        <small>Verifica</small>
        <strong data-state="{{ $item['readiness_value'] }}">{{ $item['readiness'] }}</strong>
    </div>
</div>
