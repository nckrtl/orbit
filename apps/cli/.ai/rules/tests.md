---
paths:
  - 'tests/**'
---

# Tests

## Use Pest 5 and project quality checks
Develop behavior with Pest 5 through `composer test:affected`. Before delivery, run the TIA tests, Rector, Pint format checks and Larastan analysis, and git diff --check; report exact test and assertion counts. Require the reviewer's passing root `composer check` receipt for the exact candidate.
