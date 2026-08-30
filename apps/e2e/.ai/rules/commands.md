---
paths:
  - 'app/Console/Commands/**'
---

# Command rules

Commands must use exact, validated resource IDs. Reject ambiguous names and
unsafe paths before invoking Incus. Report concise results, use non-zero exit
codes on failure, and avoid leaking credentials or command output.
