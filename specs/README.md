# Specification archive and product traceability

The material in this directory has two distinct roles.

- `000-product-roadmap/` is the current coverage and traceability index. It is not an
  implementation methodology and does not override the canonical domain.
- Numbered feature directories (`001-*` through `015-*`) are historical records of
  bounded implementation work. Their plans, research notes, checklists, contracts,
  quickstarts and completed task lists preserve rationale and delivery evidence; they
  are not active work queues and must not be used as the default source for current
  behavior.

For current work, use the source order defined in [`AGENTS.md`](../AGENTS.md): the
explicit task, the canonical domain specification, any active feature brief, then
current code, schema and tests. Consult a historical feature package only when it
contains rationale that cannot be established from those sources.

The historical packages remain intact because consolidating their useful rationale
requires a separate atomic pass that updates every inbound and cross-package
reference. Moving or deleting only part of that material would make the documentation
less reliable.
