---
paths:
  - 'tests/**'
---

# Tests

## Use Pest 5 parallel no-TIA and Mago delivery gates
Develop behavior with focused Pest 5 tests. Before delivery, run focused tests, Rector, Mago format/lint/analyze, and git diff --check; report exact test and assertion counts. Require the reviewer's passing root `composer check` receipt for the exact candidate.
