---
paths:
  - 'app/**'
---

# App

## Preserve request IDs and redact credentials
Preserve the gateway request ID in human and JSON errors and outputs. Never echo credentials or secret-bearing input in output, error messages, or exception text; use bounded generic validation errors and keep gateway redaction as the primary response boundary.
Every JSON error envelope includes `error.request_id`; use `null` when no request ID is available.

## Review proven behavior before inventing behavior
Before inventing command or local OS-adapter behavior, review matching repository code and tests for proven validation, output, error, idempotency, request-ID, redaction, and adapter-test invariants. The legacy project is optional research, not a checkout dependency. Port useful behavior only; do not copy its Agent, hidden transport, generic executor, or retired infrastructure architecture.

## Keep registered-worktree discovery read-only

The `instance:register` command may execute only the fixed local command
`git rev-parse --show-toplevel` to discover and canonicalize the caller's Git
top level. It may not accept Git command input or inspect, create, update, or
remove refs, branches, worktrees, remotes, index state, or files. All source
identity and safety verification remains a Gateway responsibility.
