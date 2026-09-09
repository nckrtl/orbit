# Feature plan

Issue: ORB-174
Review verdict: IMPLEMENTATION AUTHORIZED

## Outcome

Firewall Doctor evaluates every active persisted rule and synthetic Metrics target for one Node from one bounded UFW status command. A later Doctor call performs a fresh command. Mutating firewall verification remains independent and fresh.

## Code boundaries

In:
- `apps/gateway/app/Domain/Firewall/FirewallInspector.php`: make one inspection accept one Node target batch.
- `apps/gateway/app/Infrastructure/Firewall/NativeUfwFirewallInspector.php`: execute and validate one bounded UFW observation, then evaluate the whole batch.
- `apps/gateway/app/Infrastructure/Firewall/UfwStatusParser.php`: index managed comments from one parse and resolve multiple expected shapes.
- `apps/gateway/app/Actions/Doctor/FirewallDoctorProbe.php`: batch active persisted and synthetic targets while preserving issue order and checked counts.
- Focused Gateway tests named by the issue.
- `docs/solutions/doctor-incus-proof.md`: correct the stale claim that only the role inspector runs UFW and state the fixture isolation requirement.
- `.loop/proof/ORB-174.json` and its self-checking fixture.

Out:
- Firewall mutation behavior and caching.
- Doctor report fields, codes, lifecycle policy, and family orchestration.
- Incus harness implementation.
- Unrelated subsystem behavior and architecture.

## Documentation

Audit scope: ORB-174; `composer docs-context -- --component=apps/gateway --concept=doctor --concept=firewall` plus the attached ADRs and pages that describe Doctor firewall proof.

Fixed:
- `docs/solutions/doctor-incus-proof.md`: two absolute claims said the role inspector alone runs `sudo ufw`; the firewall inspector also runs it when persisted or synthetic targets exist. The page now requires a role-only request or a node with no firewall targets for an isolated role failure.

Reported:
- none.

Verification: `composer docs-build` and `composer docs-lint` passed. The build left `docs/generated/context.json` unchanged.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| One UFW command for multiple stored and synthetic targets on one Node | FirewallInspector batch API, native adapter, parser batch, Doctor probe | Native inspector, parser, and Doctor probe focused tests |
| Fresh observations for other Nodes, later Doctor calls, and mutation verification | Stateless native adapter and unchanged manager verification barrier | Native inspector and Doctor probe repeated-call tests; existing manager verification test |
| Checked count, persisted-before-synthetic issue order, and lifecycle skips | FirewallDoctorProbe ordered entries | Doctor probe focused tests |
| Malformed, truncated, unreachable, and deadline failures make the target batch unverifiable without raw output | Native adapter group failure and Doctor bounded mapping | Native inspector and Doctor probe failure tests |
| Real OS/service/multi-node boundary | One self-checking Incus scenario | `firewall-doctor-single-observation` |
| Gateway checks | apps/gateway | `composer check` |
| Repository suites | repository | `PHPRC=/dev/null ORBIT_TEST_PROCESSES=80 bin/test` in root's final window |

## Implementation order

1. Correct and extend focused tests for batching, parser indexing, ordering, failure grouping, and freshness.
2. Change the narrow FirewallInspector contract and native implementation.
3. Batch targets in FirewallDoctorProbe without changing report semantics.
4. Run focused tests and the owning-project check.
5. Write and locally validate the Incus plan and fixture without acquiring topology.
6. Stop at a clean checkpoint until root grants the final integration and proof window.

## Must preserve

- Doctor stays verify-only and returns only bounded values.
- `checked` counts stored FirewallRule rows only.
- Persisted issues stay before synthetic Metrics issues; persisted rows stay ordered by ID.
- Non-active stored rules emit lifecycle drift and are never inspected.
- Empty and unreachable family behavior remains unchanged.
- Output, stderr, exception text, connection data, and commands never enter reports.
- Firewall manager post-mutation status reads remain fresh and independent.

## Proof decision

- Use the standard three-Node topology with one acceptance action named `firewall-doctor-single-observation`.
- Set `mutates: true` because the fixture temporarily changes UFW and Gateway database state, even though it restores both before exit.
- Set `observed_inputs: true`. Both proof phases execute the Gateway PHP fixture for `gateway:cli` and run the Doctor CLI on `app-dev` for `app-dev:cli` and `gateway:fpm` observations.
- The fixture logs the exact `status numbered` argument vector through a temporary UFW shim, exercises every declared fault mode, and refuses to pass unless each Doctor call adds exactly one observation.

## Open questions

- none.

## Deviations

- none.

## Review findings

- none.

## Verification results

- Focused Gateway tests: 38 passed, 88 assertions.
- Gateway `composer check`: 2,710 passed, 15,281 assertions; guidance, Rector, formatting, lint, and analysis passed.
- Documentation: `composer docs-build` and `composer docs-lint` passed with no generated-context change.
- Repository `bin/test`: pending root's final serialized integration window.
- Incus discovery: setup, all acceptance scenarios, cleanup, and general topology verification passed on attempt `46e315d0b8068c1e0be21aa99177ad94` from snapshot `49bbb41f79da-46482c65401e`.
- Immutable proof: pending root execution in the final serialized window.
