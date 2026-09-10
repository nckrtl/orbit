---
paths:
  - 'tests/**'
---

# Tests

## Use Pest 5 parallel no-TIA and Pint and Larastan delivery gates
Develop behavior with focused Pest 5 tests. Before delivery, run focused tests, Rector, Pint format checks and Larastan analysis, and git diff --check; report exact test and assertion counts. Require green CI full parallel suites without TIA.
