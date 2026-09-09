# Feature plan

Issue: ORB-164
Review verdict: DIRECT IMPLEMENTATION

## Outcome

Per-node Metrics firewall diagnostics derive only the requested active Node's exporter projection. Fleet projection callers keep the existing ordered result.

## Code boundaries

In:
- `apps/gateway/app/Domain/Metrics/MetricsExporterProjection.php`
- `apps/gateway/app/Infrastructure/Metrics/NativeMetricsExporterProjection.php`
- `apps/gateway/app/Infrastructure/Metrics/NativeMetricsFirewallExpectationProvider.php`
- Focused projection, firewall expectation, and selector tests

Out:
- Metrics architecture or selection-policy changes
- Other subsystems and generic workflow abstractions
- Incus harness implementation

## Documentation

Audit scope: `apps/gateway`, Node, Doctor, the Metrics reference, and governing ADRs 0003 and 0004.

Fixed:
- None.

Reported:
- None.

Unchanged:
- `docs/reference/metrics.md` already states the active-Node filter, authoritative active or provisioning roles, preference precedence, and Metrics-node precedence. Direct projection is an internal query optimization with no new operator contract.
- `docs/concepts.md` and ADR 0004 already state Doctor's per-Node read-only behavior. The implementation retains that behavior.

Verification: `composer docs-build` and `composer docs-lint` passed before product edits.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Per-node equals fleet member for all preferences and role states | Projection contract and native projection | `NativeMetricsExporterProjectionTest.php`; `ExporterSelectorTest.php` |
| Inactive or missing Nodes and Metrics precedence stay unchanged | Native projection and selector | `NativeMetricsExporterProjectionTest.php`; `NativeMetricsFirewallExpectationProviderTest.php`; `ExporterSelectorTest.php` |
| Unrelated Nodes do not increase per-node preference work; Doctor gets the same expectations | Direct projection query and firewall expectation provider | `NativeMetricsExporterProjectionTest.php`; `NativeMetricsFirewallExpectationProviderTest.php` |
| Gateway checks pass | Gateway project | `cd apps/gateway && composer check` |
| Repository tests pass | Repository | `PHPRC=/dev/null bin/test` in the serialized final window |

## Implementation order

1. Add failing focused coverage for direct equivalence, inactive or missing Nodes, constant preference-query work, and direct firewall projection.
2. Add `forNode` to the projection contract and implement it through the same item builder as fleet projection.
3. Make the Metrics firewall expectation provider request only its Node's projection item.
4. Run focused tests and the Gateway project check, then commit a clean checkpoint.
5. Await the serialized current-main integration and root-suite window.

## Must preserve

- Fleet projection filtering and ID order.
- Fresh database role state, including only active and provisioning assignments.
- Exporter preference and Metrics-node selection precedence.
- Existing firewall expectation shapes and order.
- No cache across operations.

## Open questions

- None.

## Deviations

- None.

## Review findings

- Pending independent review.
