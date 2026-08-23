# Quickstart and Verification: Closing

## Focused automated groups

Expected focused files may be consolidated if current structure makes that simpler.

```bash
php artisan test tests/Feature/Closing/ClosingReviewTest.php
php artisan test tests/Feature/Closing/CloseExerciseTest.php
php artisan test tests/Feature/Closing/ProjectClosingDecisionTest.php
php artisan test tests/Feature/Closing/ContractClosingTest.php
php artisan test tests/Feature/Closing/ClosingSnapshotTest.php
php artisan test tests/Feature/Closing/ClosedExerciseImmutabilityTest.php
php artisan test tests/Feature/Closing/ClosingUiTest.php
php artisan test tests/Feature/Closing/S9InvariantTest.php
```

Use the current repository test command conventions if paths differ.

## Required scenario matrix

### A. Time, chronology and authorization

1. User with only `chiude_esercizio` (plus required view access) can perform Closing.
2. User with only `modifica_operativita` cannot perform Closing.


1. Try to close current/future year before 31 December ends -> reject.
2. Close N while N-1 is Open -> reject.
3. Close N after all previous Exercises are Closed -> allowed.
4. After successful Closing try `Closed -> Open` -> reject.

### B. Budget independence

1. Close an Exercise with no Budget.
2. Snapshot has null/absent Budget v1/current references.
3. Final values are still complete.
4. Proposal/Revision creation/approval is impossible afterward.

### C. Same-year Draft

1. Create Draft Proposal for N.
2. Closing N -> reject.
3. Discard/approve Draft.
4. Closing review can proceed.

### D. Project explicit decisions

Cover:

- Planned -> Planned + None;
- Planned -> Planned + Carryover;
- Planned -> Planned + Reprogramming;
- Planned -> Cancelled + None;
- Open -> Open + None;
- Open -> Open + Carryover;
- Open -> Open + Reprogramming;
- Open -> Closed + None;
- Open -> Cancelled + None.

Verify state changes use 31 December and required reasons.

### E. Carryover consolidation

1. N Project allocation 10,000; Actual 6,000.
2. Existing provisional Carryover 3,000.
3. Closing maximum 4,000.
4. Explicitly consolidate 3,500.
5. Verify:
   - source Estimates unchanged;
   - N+1 live Carryover = 3,500;
   - state = consolidated;
   - N+1 allocation changes exactly by delta from provisional;
   - any N+1 Budget remains unchanged;
   - N+1 Draft Project item becomes `Da riallineare`;
   - Closing Snapshot stores 3,500.

Separate cases:

- attempt 4,001 -> reject;
- negative Actual cap;
- final maximum below provisional -> no automatic correction; explicit valid amount
  required.


### E2. Terminal Project after active Reprogramming

1. Start with an active executed Reprogramming.
2. At Closing choose Project `Chiuso` or `Cancellato`.
3. Final mode is `Nessuna`.
4. Verify exact S8 reversal runs first.
5. Verify the 31/12 terminal transition then succeeds.
6. Verify Closing Allocation/Residual-Saving values use the restored source plan.
7. Verify destination independent allocations remain untouched.

### F. Reprogramming

New at Closing:

1. explicit reducible source Estimates;
2. choose Reprogramming;
3. apply once;
4. source allocation down exactly;
5. destination Estimates up exactly;
6. no Actual copy;
7. Snapshot stores Reprogrammed amount.

Already executed:

1. execute before Closing via S8;
2. Closing with same final Reprogramming;
3. verify no second reduction/destination creation.

Corrupted effect:

1. independently change an involved persisted line;
2. Closing -> block;
3. verify no fuzzy reconciliation and no partial Closing.

### G. Future Project transition conflict

1. Project Open N.
2. Existing future transition in N+1 that assumes Open immediately before its date.
3. Closing decision tries to make Project Closed at 31/12/N without compatible later
   reopen.
4. Closing -> reject.

Positive case:

- add a compatible N+1 reopen/open transition;
- full timeline validates.

### H. Contract cutoff

Use a Contract whose old `next_expiry_date` requires multiple catch-up periods.

1. Include a stale automatic-renewal chain whose first missing due date may be in an
   earlier already-Closed year.
2. Close Exercise 2025 technically in 2026/2027.
3. Verify missing automatic renewal facts are appended chronologically through
   31/12/2025, while earlier Closed Exercise values/Snapshots are untouched.
4. No 2026 renewal is materialized merely because technical today is later.
5. Retry does not duplicate renewal facts.
6. Final 2025 Estimate and state match 31/12/2025.
7. If the due renewal changes another Open Exercise, Closing review shows that
   Exercise's impact and confirmation locks/applies it atomically.
8. If a Draft Proposal in that Exercise contains the changed Contract, that whole
   Contract source becomes `Da riallineare`.
9. If that Exercise has an approved Budget, it remains byte-for-byte/row-for-row
   immutable while live Contract allocation changes.

### I. Contract warnings and blocks

- invalid overlapping/invalid condition -> block;
- provisional Carryover in the current approved N+1 Budget different from final consolidable maximum -> warning;
- Active/Planned Contract with no valid applicable condition -> warning;
- automatic renewal with no valid post-expiry condition -> warning;
- no invoice/payment inference in warning copy.

### J. Classification policy

Same unclassified first-level source:

- Company policy Warning -> warning, Closing can proceed after acknowledgement;
- Company policy Blocking -> Closing blocked.

Snapshot stores the policy applied.

### K. General no-Actual warning

For each first-level source type:

- allocation > 0;
- no active non-zero Actual line.

Verify warning.

Offset Actual case:

- +100 and -100 active Actuals;
- total Actual = 0;
- `HaEffettivi = true`;
- no `nessun Effettivo` warning.

### L. N+1 creation

Absent N+1 + continued management:

- create N+1 in same transaction;
- Open;
- classifications inherited;
- Contract Estimates initialized;
- no Budget;
- no autonomous Expense copies;
- no Actual copies;
- no Project Estimate copies;
- consolidated Carryover applied afterward.

Failure after N+1 creation rolls it back.

### M. Management terminated

N+1 absent:

- choose terminated;
- all transfer decisions None/zero;
- no N+1 created;
- Timeline records intentional non-creation.

Attempt with positive Carryover/Reprogramming -> reject.

### N. N+1 already exists

- Closing uses existing N+1;
- no creation/deletion;
- Snapshot says `already_existed`.

### O. Snapshot inclusion

Exercise with:

- normal first-level source with values;
- zero-net-but-HaEffettivi source;
- source present only in an approved Budget;
- Project Planned/Open at 31 Dec with zero values;
- state/lifecycle event in year;
- reversed Expense whose historical event requires inclusion.

Verify canonical §7.6.5.

### P. Snapshot autonomy

After Closing, in allowed later context:

- rename/archive live master/source objects;
- Snapshot labels remain unchanged.

S10 late correction tests are not part of S9.

### Q. Post-Closing immutability

Attempt ordinary:

- Estimate update/annul/restore;
- Expense move/container/year change;
- annual classification change;
- Project transition that rewrites the Closed-year 31 Dec state;
- Contract condition/lifecycle/renewal mutation affecting Closed year;
- Carryover mode/value change;
- Budget approval.

All reject without changing the Closing Snapshot.

## Atomic failure matrix

Force a failure:

- after due Contract events;
- after N+1 creation;
- after Project Closing effects;
- after Closing Snapshot creation;
- before Exercise status Closed.

Verify:

- target remains Open;
- no partial Snapshot/rows;
- no partial N+1;
- no partial Project transition/deferral;
- no partial Contract event/recalculation;
- failure audit is present without sensitive exception content.

## Browser verification

Use a dedicated Company and a **past** Exercise so the canonical end-of-year rule is
real rather than mocked in the browser environment.

Demonstrate:

1. Closing review with totals, blocks/warnings.
2. Project final state + Carryover decision.
3. warning acknowledgement.
4. N+1 existing or creation decision.
5. final irreversible confirmation.
6. Closed Exercise.
7. read-only immutable Closing Snapshot.
8. N+1 consolidated Carryover visible.
9. ordinary historical edit action unavailable/rejected.
10. no browser console or failed Livewire errors.

Do not leave the development database in an ambiguous shared demo state. If dedicated
demo data is retained, record its Company name in the evidence section.

## Final quality gate

Use current CI as executable source of truth. At minimum the current repository gate
contains:

```bash
composer validate --strict
composer audit --locked --no-interaction
npm ci --no-audit --no-fund
npm run build
php artisan migrate --force --no-interaction   # isolated testing DB only
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress
vendor/bin/pest
```

Also run:

```bash
git diff --check
```

## Evidence

Do not pre-mark this section.

Implementation agent records actual focused test counts, full suite result, quality
gate, browser journeys and any genuine limitation here.
