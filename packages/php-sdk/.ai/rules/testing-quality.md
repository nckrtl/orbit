# Testing and quality

Use Pest 5 with `describe()` and `it()`. Use TDD for behavior changes. Run
focused tests for red, green, and refactor. Run the full Pest 5 suite in
parallel without TIA through `composer test` before handoff.

- Run `composer guidance:check` first. It must fail when the rule index, an
  indexed file, or material path coverage is missing. The failure must give the
  repository restoration command.
- Test exact methods, endpoints, query and body omission, explicit empty input,
  headers, DTO bounds, request IDs, error envelopes, redaction, object-state
  diagnostics, TLS verification, redirects, and root-CA one-shot behavior.
- Use Saloon fakes. Do not contact a live Gateway or node.
- Run `composer validate --strict` and `composer check`.
- Run Mago format check, lint, and analysis with zero findings. Run Rector in
  dry-run mode and `git diff --check -- packages/php-sdk` from the monorepo
  root.
- Run the full parallel no-TIA suite. Do not use a partial focused result as
  the final suite result.
- Review the diff, staged files, branch, and upstream state before handoff.
  Keep authorized cross-component changes in the same feature branch. Never
  stage, commit, push, or deploy without authorization.
