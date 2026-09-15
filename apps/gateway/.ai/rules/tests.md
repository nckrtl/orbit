---
paths:
  - 'tests/**'
---

# Tests

Exercise authentication, validation, ownership, redaction, rollback, and API errors at the boundary that handles them. Use existing helpers, factories, external-service fakes, and the disposable test database setup.

## Run the Pest, Pint, and Larastan gates
Use Pest 5 TDD through `composer test:affected` during development. Before handoff, run the TIA tests and project quality checks, then git diff --check. Follow the root contributor guide for CI and independent review.
