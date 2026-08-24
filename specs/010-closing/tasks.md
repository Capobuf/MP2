# Tasks: Closing

**Input**: `spec.md`, `plan.md`, `research.md`, `data-model.md`,
`contracts/ui.md`, `quickstart.md`

**Method**: implement one coherent phase at a time. Use focused tests during phases
and run the full repository gate only in the final phase.

Do not implement a task mechanically if the current repository already provides the
same behavior through a simpler path.

---

## Phase 1 — Closing persistence and immutable schema

**Goal**: make the canonical Closing Snapshot representable without implementing the
Closing mutation yet.

- [x] **T001 [P] [US7]** Add focused model/schema tests for one immutable Closing
  Snapshot per Exercise, immutable rows/evidence, same-company constraints and
  successful absence of Budget references in
  `tests/Feature/Closing/ClosingSnapshotTest.php`.
- [x] **T002 [P] [US3]** Add a migration regression test proving the existing S8
  `ProjectDeferral` closed shapes remain valid and `carryover + consolidated` becomes
  valid without weakening None/Reprogramming exclusivity.
- [x] **T003 [US7]** Add forward-only migrations for `closing_snapshots` and `closing_source_rows` according to
  `data-model.md`; do not edit committed S6/S8 migrations.
- [x] **T004 [US7]** Add `ClosingSnapshot` and `ClosingSourceRow` models plus Company/Exercise relationships and a read-only `ClosingSnapshotPolicy`; block update/delete
  on immutable Closing records.
- [x] **T005 [US3]** Add the forward corrective DB constraint for
  `ProjectDeferral.carryover_state = consolidated` while preserving the S8 mode
  invariant.
- [x] **T006 [US8]** Add a minimal `Exercise` invariant that prevents
  `Closed -> Open` and tests proving no ordinary physical delete/reopen route exists.
- [x] **T007** Run Phase 1 focused tests and Laravel boot only.

**Checkpoint**: Closing history can be stored immutably and a Closed Exercise cannot
be reopened.

---

## Phase 2 — Deterministic Closing review, blocks and warnings

**Goal**: implement a side-effect-free authoritative preflight before any Closing
write.

- [x] **T008 [P] [US1]** Add review tests for end-of-year timing, previous Open
  Exercise, same-year Draft Proposal, missing Budget, tenant/capability boundaries
  CloseExercise-vs-ManageOperations authorization in both directions (Close alone is
  sufficient; ManageOperations alone is insufficient) and side-effect-free execution
  in `tests/Feature/Closing/ClosingReviewTest.php`.
- [x] **T009 [P] [US2]** Add Project decision matrix tests for every allowed
  Planned/Open final state, explicit continuing mode, terminal None rule, required
  reasons and future-transition incompatibility in
  `tests/Feature/Closing/ProjectClosingDecisionTest.php`.
- [x] **T010 [P] [US1]** Add canonical warning/block tests for
  `HaEffettivi`, unclassified policy, Planned-never-Opened, approved provisional
  Carryover in N+1 differing from the final consolidable maximum,
  Contract-without-valid-condition and renewal-without-condition.
- [x] **T011 [US1]** Implement the smallest `ClosingReview` result vocabulary and
  `ReviewExerciseClosing` action. Keep it side-effect-free; simulate submitted
  Project/deferral decisions and the Contract 31-December cutoff; return exact
  block/warning codes, affected source references, resulting totals, Budget
  references, settings, Project decision requirements and exact per-Exercise impact
  for all affected Open Exercises.
- [x] **T012 [US2]** Implement Project Closing-decision validation using
  `ProjectStateTimeline` at 31 December and full future timeline compatibility; do
  not create transitions in review.
- [x] **T013 [US1]** Implement canonical first-level `HaEffettivi`, classification and
  warning predicates without child double counting or invoice/payment inference;
  validate required overspend Notes against the setting applicable to the originating
  operation rather than treating a later setting change as retroactive.
- [x] **T014 [US1]** Add a deterministic Closing review fingerprint covering the
  mutable facts and decisions that confirmation depends on.
- [x] **T015** Run Phase 2 focused tests plus relevant S8 Project deferral and S7
  Draft-realignment regressions.

**Checkpoint**: the system can tell the user exactly why Closing is blocked or warned
without changing reality.

---

## Phase 3 — Contract cutoff and canonical Exercise initialization

**Goal**: ensure Closing-time Contract facts and a newly created `N+1` use the
canonical economic date rather than technical today.

- [x] **T016 [P] [US5]** Add Contract Closing tests where Closing is executed
  technically after the target year, including multiple missed renewal periods,
  non-renewal expiry, retry, affected future Draft Contract-source realignment, and
  the requirement that no event after `N-12-31` is materialized as part of N Closing.
- [x] **T017 [US5]** Refactor the smallest reusable Contract renewal planning/apply
  path so it accepts an explicit cutoff. Ordinary `ProcessContractRenewals` keeps
  Company-local today and its ordinary authorization; Closing uses `N-12-31` through
  an internal apply path after `chiude_esercizio` authorization. Preview/apply must
  share the same deterministic rule source.
- [x] **T018 [US5]** Extend Contract Estimate recalculation only as needed so state
  projection includes applicable renewal configurations as required by the canonical
  domain.
- [x] **T019 [P] [US6]** Add Exercise initialization tests for inherited
  Project/Contract classification, Contract Estimate creation, no Budget, no
  autonomous Expense copy, no Actual copy and no Project Estimate copy.
- [x] **T020 [US6]** Refactor `CreateExercise` minimally so direct creation and
  Closing-created `N+1` share canonical §11.8 initialization and can run inside an
  existing top-level transaction without requiring the Closing actor to also possess
  `modifica_operativita`.
- [x] **T021 [US5]** Add Closing review integration with the Contract cutoff
  projection so displayed finalizable Contract state/allocation matches what
  confirmation will apply.
- [x] **T022** Run Phase 3 focused Contract/CreateExercise tests plus S5 renewal and
  annual-allocation regressions.

**Checkpoint**: Contract finalization is anchored to 31 December and `N+1`
initialization is canonical.

---

## Phase 4 — Final Project effects and N+1 disposition

**Goal**: apply final Project state/deferral decisions exactly once before Snapshot
materialization.

- [x] **T023 [P] [US3]** Add Carryover consolidation tests: same/lower/higher-than-
  provisional choices, final maximum, negative Actual cap, destination allocation
  delta, consolidated state, immutable N+1 Budget and N+1 Draft Project realignment.
- [x] **T024 [P] [US4]** Add Closing Reprogramming tests for not-yet-executed apply,
  already-executed no-op verification with exact same amount/effects, rejection of a
  silent same-mode rewrite, exact balance, same-operation retry and independently
  modified persisted effects blocking without matching.
- [x] **T025 [US4]** Extract/reuse the minimum S8 exact Reprogramming integrity check
  required by Closing; do not create a new reprogramming engine or realignment
  operation.
- [x] **T026 [US2]** Implement application of state-changing Project Closing
  decisions as canonical Project transitions effective 31 December with required
  reason and existing audit/revision semantics, under Closing authorization rather
  than an additional ordinary Project-update capability. For terminal decisions,
  apply the validated final deferral-to-None/reversal before creating the terminal
  transition so the snapshot uses the restored final plan.
- [x] **T027 [US3/US4]** Implement final deferral application:
  - None;
  - explicit consolidated Carryover;
  - new Closing-time Reprogramming;
  - exact S8 reversal when the final mode leaves an active Reprogramming.
  Keep source/destination writes in the caller's Closing transaction.
- [x] **T028 [P] [US6]** Add tests for `N+1` already existing, absent+continued and
  absent+terminated, including rejection of transfer modes under management
  termination.
- [x] **T029 [US6]** Implement the final `N+1` disposition using the shared Exercise
  initialization path; never delete/recreate an existing `N+1`.
- [x] **T030** Run Phase 4 focused tests plus S8 exact reversal/idempotency and S7
  realignment regressions.

**Checkpoint**: Project year-end decisions and destination effects are correct before
the Exercise is frozen.

---

## Phase 5 — Atomic Close operation, Snapshot and audit

**Goal**: combine all final effects in one all-or-nothing Closing.

- [x] **T031 [P] [US7]** Add Snapshot inclusion/detail tests for canonical §7.6.5,
  zero-net `HaEffettivi`, archived/history-readable sources, Project closing values,
  Contract conditions/cycles/events and first-level total consistency.
- [x] **T032 [US7]** Implement `ClosingSnapshotPayload` (or equivalent smallest
  deterministic materializer) for §§23.8–23.11. It must not mutate live objects and
  must support deterministic `operation_id + event_sequence` references without a
  later Snapshot update.
- [x] **T033 [P] [US1/US7]** Add atomic failure tests after Contract due-event apply,
  N+1 creation, Project effects, Snapshot materialization and before Exercise status
  update.
- [x] **T034 [US1–US7]** Implement `CloseExercise` as one top-level logical
  transaction with deterministic locking/revalidation, authoritative review
  fingerprint check, warning acknowledgement, Contract cutoff apply, N+1 handling,
  Project effects, whole-source Draft realignment for every changed Project/Contract
  in all affected Open Exercises, Snapshot materialization and final status change.
- [x] **T035 [US7]** Materialize Closing evidence directly in the immutable
  Snapshot/audit payload: submitted Project decisions/reasons, accepted warnings,
  applied settings, actor/timestamp, `N+1` disposition and explanatory event
  references. Do not add a Closing attachment-upload workflow.
- [x] **T036 [US7]** Extend `AuditEventType` and Closing audit for canonical start,
  confirmation, completed, failure, Carryover consolidation and intentional
  non-creation of N+1. Add Project saving/unused-allocation explanatory events when
  those canonical cases occur.
- [x] **T037 [US1]** Implement successful-operation idempotency and non-sensitive
  failed-Closing audit. Retry must not duplicate Snapshot, N+1, renewals, transitions
  or deferral effects.
- [x] **T038** Run Phase 5 focused tests plus existing approval/operation transaction
  regressions.

**Checkpoint**: Closing is atomic, idempotent and leaves one autonomous Snapshot.

---

## Phase 6 — Post-Closing historical immutability

**Goal**: enforce §14.8 without introducing S10.

- [x] **T039 [P] [US8]** Add rejection tests for ordinary Estimate, Expense move,
  classification, Project-transition, Contract condition/lifecycle/renewal,
  deferral and Budget mutations that would rewrite a Closed Exercise in
  `tests/Feature/Closing/ClosedExerciseImmutabilityTest.php`.
- [x] **T040 [US8]** Verify existing Expense/Line/classification/Proposal paths already
  reject Closed Exercises; do not duplicate guards that are already authoritative.
- [x] **T041 [US8]** Add the smallest guard(s) to Project transition
  create/replace/annul paths so they cannot alter a materialized Closed-year
  31-December state.
- [x] **T042 [US8]** Add the smallest guard(s) to Contract condition, lifecycle and
  renewal mutation paths that could otherwise alter facts materialized in a Closed
  year. Preserve the canonical §27.39 exception for append-only materialization of
  previously missed automatic renewal facts, while recalculating only Open Exercises.
  Future-only ordinary changes that do not rewrite Closed history remain allowed where
  the canonical domain permits them.
- [x] **T043 [US8]** Verify S8 direct deferral changes reject once source Exercise is
  Closed and Budget approval/Revision remains impossible.
- [x] **T044** Run Phase 6 tests plus focused S3–S8 regressions for the touched
  ordinary operation paths.

**Checkpoint**: Closing history cannot be rewritten by ordinary operations.

---

## Phase 7 — Exercise Closing and Snapshot UI

**Goal**: make the canonical review/decision/confirmation usable in MP2 without
turning Filament into a second domain layer.

- [x] **T045 [P] [US1/US7]** Add Livewire/Filament tests for Close capability,
  eligible/ineligible Exercise action, block/warning presentation, warning
  acknowledgement, irreversible confirmation and Closed Snapshot navigation in
  `tests/Feature/Closing/ClosingUiTest.php`.
- [x] **T046 [US1/US2]** Add the dedicated `CloseExercise` page under the Exercise
  resource and connect it to `ReviewExerciseClosing`; keep mutation logic out of UI
  callbacks.
- [x] **T047 [US2–US6]** Implement Project decision, mode/amount/reprogramming input
  and `N+1` disposition controls exactly as `contracts/ui.md`, with impossible states
  disabled rather than accepted then silently repaired.
- [x] **T048 [US1/US7]** Implement blocks, canonical warnings, final totals and
  confirmation copy including `L'Esercizio non potrà essere riaperto.`
- [x] **T049 [US7]** Add a read-only Closing Snapshot resource/view and expose
  materialized header/rows/details without S11 comparison/report UI.
- [x] **T050 [US8]** Update the Closed Exercise page so ordinary historical mutation
  controls are absent/disabled and `Apri Chiusura` is available.
- [x] **T051** Run focused UI tests, Laravel boot and frontend build; perform the
  authenticated browser journeys from `quickstart.md`.

**Checkpoint**: a user can review, decide, close and inspect history without hidden
domain behavior.

---

## Phase 8 — Canonical verification and delivery

**Goal**: prove S9 and only S9.

- [x] **T052 [P]** Add authoritative invariant coverage for 28.25, 28.26, 28.27,
  28.28, 28.49 and 28.58 in `tests/Feature/Closing/S9InvariantTest.php`.
- [x] **T053 [P]** Add explicit exclusion tests proving S9 has not created a reopen
  path, late-correction path, Historical Annotation workflow, Forecast, automatic
  Carryover maximization or automatic Project continuation decision.
- [x] **T054** Reconcile every S9-FR-001..041 against automated or browser evidence;
  fix uncovered behavior rather than marking it complete by inspection.
- [x] **T055** Run all focused S9 tests and regressions of touched S3–S8 paths.
- [x] **T056** Run the full current CI-equivalent quality gate once at the end,
  including isolated testing migrations, Composer validation/audit, frontend build,
  Pint, PHPStan and full Pest suite.
- [x] **T057** Run `git diff --check` and inspect the complete S9 diff for unrelated
  changes or accidental future-slice implementation.
- [x] **T058** Record actual evidence in `quickstart.md`; update S9 roadmap,
  FR-034–FR-041 and invariants 28.25–28.28/28.49/28.58 to `implemented`/`verified`
  only to the level genuinely demonstrated. Do not change unrelated S7 metadata as a
  side effect of S9.
- [x] **T059** Final browser verification: Closed status, immutable Closing Snapshot,
  consolidated Carryover/N+1 effect, historical ordinary-edit rejection, no
  console/Livewire errors.

**Definition of done**: S9 can be marked `verified` only if its independent Closing
demonstration works, primary canonical FRs/invariants are proven, the full gate passes
and no S10/S11 behavior was introduced.

---

## Phase 9: Convergence

- [x] **T060 [US4]** Preserve the prepared Closing input across the Filament
  review-to-confirmation boundary and add a Livewire regression proving a new
  Closing-time Reprogramming can be reviewed and confirmed without a spurious
  fingerprint change per S9-FR-015 and S9-FR-031 (partial).
- [x] **T061 [US5/US7]** Materialize each Contract lifecycle fact in the Closing
  Snapshot when any of its canonical declared, state-change or renewal dates falls
  in the Exercise, with focused non-renewal expiry coverage, per S9-FR-037 (partial).
- [x] **T062 [US7]** Preserve overlapping annulled Contract conditions in the
  immutable Closing detail while keeping them excluded from economic calculations,
  with focused regression coverage, per S9-FR-037 (partial).
- [x] **T063 [US3]** Make the consolidated-Carryover corrective migration rollback
  fail before dropping the current check constraint when consolidated rows cannot
  satisfy the previous shape per plan: persistence decision (partial).
