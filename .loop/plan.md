# Feature plan

Issue: ORB-197
Review verdict: IMPLEMENTING

## Outcome

Create a standalone production AppInstance from an App repository, retain its initial source evidence, prepare only the source-selected runtime, and publish its sole private Route without taking ownership of later deployments.

## Code boundaries

In:
- Gateway AppInstance persistence, validation, production placement, source, runtime, private Route, retry, and removal compatibility.
- PHP SDK and CLI response transport for the recorded production user, home, and effective root.
- AppInstance, production placement, PHP runtime, Route, firewall, and focused regression tests.
- Maintained production provisioning documentation and generated context.

Out:
- Laravel production activation and URL changes.
- Cluster production creation, Router or Ingress projection, public publication, multi-target creation, Doctor, later deployment, and harness implementation.
- Production-removal coordinator behavior delivered by ORB-124 and its children.

## Documentation

Audit scope: `docs/architecture.md`, `docs/concepts.md`, `docs/domains/applications.md`, `docs/reference/apps.md`, `docs/reference/routes.md`, `docs/reference/php-runtime.md`, and `docs/reference/appinstance-removal.md`, plus attached accepted ADRs.

Fixed:
- `docs/concepts.md`: distinguish development-owned source from production placement and retained initial evidence.
- `docs/architecture.md`: describe standalone production placement and operator-owned later deployments.
- `docs/domains/applications.md`: document production creation, placement, branch selection, source checkpoint, retry, and removal retention.
- `docs/reference/routes.md`: apply hostname selection and private publication to standalone production creation.
- `docs/reference/php-runtime.md`: document source-driven production PHP selection and production error boundaries.

Unchanged after audit:
- `docs/reference/apps.md`: App source defaults already describe the required repository, branch, and root inputs.
- `docs/reference/appinstance-removal.md`: already describes final-target Route deletion, retained production content and user, and retry after Route deletion.

Reported: none.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Standalone production creation and complete response | Gateway production coordinator, API DTO, SDK DTO, CLI output | Gateway, SDK, CLI AppInstance suites; `app-prod-standalone-create` |
| Initial source and branch selection | Production source lifecycle | Production coordinator tests; `app-prod-initial-source` |
| Branch transport and refusal | Existing request transport plus production source resolver | Gateway and SDK tests; `app-prod-branch-refusal` |
| Explicit or generated hostname preflight | Create action and Route state resolver | Gateway API tests |
| Plain PHP and non-PHP runtime | Production source classifier and projector | PHP runtime selection tests; `app-prod-non-laravel` |
| Cluster and Laravel intermediate gates | Production placement preflight and durable source profile | API/coordinator tests; `app-prod-intermediate-gates` |
| Stable placement and cardinality | Stored production user/home and production uniqueness | Gateway API tests |
| Relative root | Existing validator plus production resolved-root response | Gateway API tests |
| User, home, source, root, and ownership safety | Remote production placement and source lifecycle | `app-prod-source-safety` |
| Failure and retry boundaries | Durable production coordinator checkpoints | Coordinator tests; `app-prod-standalone-retry` |
| Active creation idempotency | Active terminal gate before Git | Coordinator tests; `app-prod-creation-after-deployment` |
| Private-only exposure | Orbit-CA workload projection and app-prod firewall retirement | Firewall tests; `app-prod-standalone-exposure` |
| Removal compatibility | Existing removal coordinator with recorded production identity | API/removal tests; `app-prod-create-and-remove` |
| Maintained documentation | Scoped documentation pages and generated context | `composer docs-lint` |
| Repository quality | Gateway, SDK, CLI, root | Project checks and `bin/test` |

## Implementation order

1. Update scoped documentation and generated context.
2. Add stored production placement identity and response transport.
3. Add production user/home and initial-source lifecycle adapters.
4. Add durable standalone production provisioning checkpoints and private projection.
5. Retire public app-prod firewall rules.
6. Add focused Gateway, SDK, CLI, persistence, safety, runtime, retry, and removal regressions.
7. Run project checks and complete discovery diagnostics.
8. Coordinate current-main integration, root suite, and immutable Incus proof with the root orchestrator.

## Must preserve

- Development source placement, branch fallback, URL configuration, retry, and removal behavior.
- Production content and dedicated user retention on normal and forced removal.
- One private Route, Orbit-CA TLS, source and resolved-root containment, ownership checks, and bounded errors.
- Active production retries perform no Git or source-profile inspection.
- ORB-198, ORB-199, ORB-200, public Ingress, and harness implementation remain separate.

## Open questions

- Username format is an implementation detail absent from the contract. Use `orbit-app-<immutable app_id>` and persist it with `/home/orbit-app-<app_id>` unless the root orchestrator supplies a different closed requirement.

## Deviations

- None.

## Review findings

- None yet.
