# Redaction and security

Treat all remote values and caller-provided connection values as untrusted.

- Preserve a valid structured error code, safe message, safe details, and valid
  request ID. Use the central bounded validators. Invalid values become the
  established null or empty representation.
- Redact credentials from URL userinfo and sensitive query values, nested
  payloads, defaults, environment values, logs, exception and previous text,
  debug output, SDK-owned trace arguments, and serialization. Do not expose raw
  response bodies. Do not claim that the SDK can scrub caller-owned frames.
- Mark narrow credential-bearing ingress parameters with
  `SensitiveParameter`. Deny serialization and return class-only debug state
  for request and connector objects while preserving the transport payload.
- Accept only a safe HTTPS Gateway origin. Disable redirects. Keep the root-CA
  client one-shot after each valid-origin fetch attempt.
- Keep `verify=false` only in the one root-CA bootstrap invocation. A root-CA
  request through a normal connector must use its configured CA path, and
  ordinary connectors must never downgrade TLS verification. Keep redirects
  disabled in both paths.
- Generate and validate `X-Orbit-Request-Id` through the central UUID boundary.
  Never retain or emit an invalid injected value.
- Use unique credential-shaped sentinels in tests. Assert their absence from
  messages, getters, arrays, debug output, serialization, and SDK-owned trace
  frames.
