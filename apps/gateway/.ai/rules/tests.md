---
paths:
  - 'tests/**'
---

# Tests

## Run the Pest, Pint, and Larastan gates
Use Pest 5 TDD through `composer test:affected` during development. Before handoff, run the TIA tests and project quality checks, then git diff --check. The independent reviewer runs root `composer check` across all projects with TIA before approval.
