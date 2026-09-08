# Feature plan

Issue: ORB-152
Review verdict: implementation authorized

## Outcome

Ensuring one Orbit network compares its complete ordered owned FORWARD rule group and repairs any extra, altered, duplicate, or reordered rule without changing rules outside that exact ownership marker.

## Code boundaries

In:
- `apps/e2e/resources/host/reconcile-firewall.py`: desired-state comparison and its two ensure checks.
- `apps/e2e/tests/Unit/E2E/FirewallHelperTest.php`: focused rule-group, preservation, transaction, and idempotence regressions.
- `apps/e2e/tests/Unit/E2E/IncusNetworkLifecycleTest.php`: lifecycle wiring for repeated ensure observations.
- `.loop/proof/ORB-152.json` and its fixture: isolated real-iptables acceptance.

Out:
- Firewall architecture redesign or a generic firewall model.
- Rules without the exact `orbit-e2e:<network>:` marker.
- Product components outside `apps/e2e`.

## Documentation

Audit scope: ORB-152 and the pages returned by `composer docs-context -- --component=apps/e2e`.

Fixed:
- None.

Reported:
- None.

Documentation remains unchanged because the issue has no `docs` label, and the maintained topology and proof pages already state exact ownership, isolated networks, no-flush-safe lifecycle boundaries, and immutable proof. The per-network iptables rule comparison is internal harness behavior.

Verification: no maintained page changed; documentation build and lint are not required.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Complete exact-network group comparison | `current()` observes the full ordered exact marker group | Firewall helper dataset for extra, altered, duplicate, and reordered rules; lifecycle repeated ensure test |
| Preserve administrator, other-network, and shared-prefix rules | Exact marker selection and existing deletion policy | Firewall helper preservation tests and isolated namespace fixture |
| One no-flush transaction, administrator barrier, then no change | Existing restore transaction plus complete comparison | Transaction regression, lifecycle repeated observation, isolated namespace fixture |
| Real OS boundary | Disposable Gateway VM network namespace | Incus action `firewall-owned-group-repair` |
| Owning project | `apps/e2e` | `composer check` |
| Repository | All Composer projects | `PHPRC=/dev/null bin/test` |

## Implementation order

1. Replace per-desired-rule occurrence checks with one complete ordered exact-network owned projection.
2. Add focused regressions for all four drift forms and shared-prefix preservation.
3. Add repeated lifecycle observation coverage.
4. Exercise a real iptables repair in an isolated network namespace on discovery.
5. Commit and push the exact candidate, then prove it on a fresh topology.

## Must preserve

- Unmarked administrator rules and other-network rules, including shared-prefix names.
- The trailing-colon ownership boundary.
- The administrator ordering barrier before broad unmarked rules.
- One `iptables-restore --noflush` transaction for repair.
- Exact owned-rule removal during network deletion.

## Open questions

- None.

## Proof decisions

- The proof fixture will use a Linux network namespace, so real iptables state is isolated from the guest and host firewall.
- `observed_inputs` is false because the changed executable boundary is Python and iptables; PCOV cannot observe it.
- `mutates` is false because the namespace and all firewall state disappear with the proof action.

## Deviations

- None.

## Review findings

- None.
