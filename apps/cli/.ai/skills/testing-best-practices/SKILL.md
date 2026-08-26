---
name: testing-best-practices
description: Design and review valuable Orbit CLI tests for contracts, security boundaries, failure modes, isolation, and deterministic output.
---

# Testing Best Practices

## Coverage

- Test observable contracts and security boundaries, not private methods or source text alone.
- Cover success, handled failure, malformed input, and transport failure where each path has distinct behavior.
- Assert exact JSON structures and deterministic human output when output is a public contract.
- Add sentinel-secret assertions for any input or response that can contain credentials.

## Isolation

- Use SDK mock transport for remote commands and assert the exact request type and payload.
- Use temporary directories for configuration and trust-store tests.
- Assert that validation failures send no HTTP request and make no local mutation.
- Keep fixtures small, explicit, and free of real credentials.

## Review

Reject tests that only repeat implementation details, cannot fail for the
reported regression, rely on execution order, or contact external systems.
