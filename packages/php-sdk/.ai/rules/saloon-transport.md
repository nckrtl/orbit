# Saloon transport

Use typed Saloon 4 requests and typed response DTOs. Each concrete request must
select the exact HTTP method and endpoint from the current Gateway contract.

- Preserve every representable caller value. Do not add business logic or
  runtime policy. Omit a field only when its value is `null` and the contract
  defines null as absence. Preserve explicit empty strings, empty arrays,
  `false`, and zero.
- Put query values in the query. Use a JSON body only for endpoints that accept
  one. Keep bodyless lifecycle requests bodyless.
- Parse the standard success and error envelopes in the shared request base.
  Bound DTO fields by type. Do not expose a raw response body or create a
  generic escape hatch.
- Keep test fakes and pending-request assertions typed. Assert the method, URL,
  query, body, headers, connector configuration, DTO, and error envelope.
- Review the matching `/home/nckrtl/orbit-old` SDK request, response, exception,
  correlation, and fake tests before you invent transport behavior. Reuse
  proven transport invariants. The current Gateway design, not the retired
  package, is the surface authority.
