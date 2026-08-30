## Linear issue

- Issue:
- Outcome:
- Proof: automated or incus
- Composition (incus only): gateway + app-dev + app-prod

## Decision context

- ADR: none

All required ADRs must already exist on `main`.

## Candidate and proof

- Candidate SHA:
- Topology: `gateway_app-dev_app-prod` or none
- Attempt ID:
- Proof status: proved or not-applicable
- Acceptance evidence: one line per acceptance criterion with its observed
  result from the proof record or the automated check

## Checks

- [ ] Focused tests pass
- [ ] Full affected project suites pass without TIA
- [ ] Project quality checks pass
- Checks: command and result per check
- Live suites (harness-touching diff only): `bin/e2e-live <sha> --rolling`
  summary line per suite, or not-applicable
- CI: run URL and SHA, or pending

## Review

- Review: review ID, candidate SHA, `approved`

## Compound

- Decision or reference updates: none
- Reusable solution notes: none
- Why no durable documentation is needed, if none:

## Post-deployment actions

- Post-deployment actions: none, or one entry per action with `target`,
  `operation`, `reason`, `recovery`, and `verification`
- [ ] No credentials, runtime state, or generated artifacts are committed
