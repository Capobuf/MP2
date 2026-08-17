<?php

use App\Domain\Company\Capability;
use App\Domain\Company\ClosingUnclassifiedPolicy;

it('defines exactly the nine canonical company capabilities', function () {
    expect(array_column(Capability::cases(), 'value'))->toBe([
        'visualizza',
        'modifica_operativita',
        'gestisce_proposte',
        'approva_budget',
        'chiude_esercizio',
        'corregge_esercizio_chiuso',
        'gestisce_anagrafiche',
        'gestisce_impostazioni',
        'gestisce_permessi',
    ]);
});

it('defines only the canonical unclassified closing policies', function () {
    expect(array_column(ClosingUnclassifiedPolicy::cases(), 'value'))
        ->toBe(['warning', 'blocking']);
});
