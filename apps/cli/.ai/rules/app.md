---
paths:
  - 'app/**'
---

# App

## Preserve request IDs and redact credentials
Preserve the gateway request ID in human and JSON errors and outputs. Never echo secret-bearing input in errors or exception text. Use bounded generic validation errors and keep gateway redaction as the primary response boundary. Only an explicit credential-delivery contract, such as Metrics credentials or a Herdr observer URL, may render its intended credential fields; unrelated output stays redacted.
Every JSON error envelope includes `error.request_id`; use `null` when no request ID is available.

## Review proven behavior before inventing behavior
Before inventing command or local OS-adapter behavior, review matching repository code and tests for proven validation, output, error, idempotency, request-ID, redaction, and adapter-test invariants. The legacy project is optional research, not a checkout dependency. Port useful behavior only; do not copy its Agent, hidden transport, generic executor, or retired infrastructure architecture.
