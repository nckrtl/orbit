# Feature plan

Issue: ORB-158
Review verdict: DIRECT IMPLEMENTATION

## Outcome

Gateway bootstrap and peer configuration accept one WireGuard endpoint grammar. Generated endpoints bracket IPv6 hosts while preserving IPv4 and hostname bytes.

## Code boundaries

In:
- `apps/gateway/app/Domain/WireGuard/WireGuardEndpoint.php`: shared validation and host/port formatting.
- `apps/gateway/app/Actions/Gateway/GatewayBootstrapIdentityValidator.php`: reuse the shared endpoint grammar before effects.
- `apps/gateway/app/Console/Commands/BootstrapGatewayCommand.php`: format an omitted endpoint from the public host and port.
- `apps/gateway/app/Infrastructure/WireGuard/VpnConfigurationRepository.php`: format the final public-host fallback.
- The issue-named Gateway unit and feature tests, plus focused command integration coverage.

Out:
- Architecture changes, migrations, persisted-value reinterpretation, and unrelated subsystem behavior.
- Incus harness implementation changes.

## Documentation

Audit scope: ORB-158, the `apps/gateway` and Gateway documentation context, and the new endpoint reference required by the `docs` label.

Fixed:
- `docs/reference/wireguard-endpoints.md`: no maintained page owned accepted endpoint forms, generated defaults, early refusal, or recovery; add the observable contract.
- `docs/README.md`: route operators to the new endpoint reference.
- `docs/generated/context.json`: rebuild generated context for the new page and link.

Reported:
- None.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| IPv4, hostname, and bracketed IPv6 agree at bootstrap and peer configuration | Shared endpoint grammar wired into both boundaries | `WireGuardEndpointTest.php`, `BootstrapGatewayActionTest.php`, `WireGuardConfigurationTest.php` |
| Bare IPv6, invalid ports, whitespace, and controls fail before bootstrap effects | Shared validator runs before operating-system, persistence, and host work | Same three regression files, with action-level no-effect assertions |
| Implicit IPv6 defaults round-trip; IPv4 and hostname bytes stay unchanged | Shared formatter in bootstrap command and peer configuration fallback | Unit formatter matrix, action persistence, command integration, peer configuration fallback matrix |
| Real boundary works on an isolated topology | Bootstrap persists a bracketed IPv6 endpoint and generated peer configuration contains the same value | Incus action `wireguard-ipv6-endpoint` |
| Maintained reference is current | Endpoint reference and generated context | `composer docs-build`; `composer docs-lint` |
| Gateway checks pass | Owning Composer project | `cd apps/gateway && composer check` |
| Repository checks pass | Monorepo | `PHPRC=/dev/null bin/test` |

## Implementation order

1. Audit and write the endpoint reference, then rebuild and lint documentation.
2. Add focused failing tests for the shared grammar, formatter, early bootstrap refusal, persistence, and fallback rendering.
3. Add the formatter and replace both local parsers/builders with the shared domain boundary.
4. Run focused and owning-project checks, then commit the product and documentation checkpoint.
5. Build the issue proof plan, acquire discovery, and exercise the complete real-machine scenario.
6. Integrate current main, run all required checks, and prove the exact committed candidate.

## Must preserve

- Explicit IPv4 and hostname endpoint bytes remain unchanged.
- Existing persisted bare IPv6 remains invalid and receives no migration or reinterpretation.
- Explicit malformed input fails before database, settings, filesystem, or host effects.
- Product work does not change `apps/e2e` or `bin/e2e-*`.

## Open questions

- None.

## Deviations

- None.

## Review findings

-
