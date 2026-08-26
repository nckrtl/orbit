---
paths:
  - 'app/**'
---

# App

## Preserve request IDs and redact credentials
Preserve the gateway request ID in human and JSON errors and outputs. Never echo credentials or secret-bearing input in output, error messages, or exception text; use bounded generic validation errors and keep gateway redaction as the primary response boundary.
Every JSON error envelope includes `error.request_id`; use `null` when no request ID is available.

## Review the legacy Orbit project before inventing behavior
Before inventing command or local OS-adapter behavior, review /home/nckrtl/orbit for proven validation, output, error, idempotency, request-ID, redaction, and adapter test invariants. Port useful behavior only; do not copy its Agent, hidden transport, generic executor, or retired infrastructure architecture.
