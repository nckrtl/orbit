---
name: creating-orbit-issues
description: Use when turning an Orbit request or production GitHub report into a Linear issue before implementation.
---

# Creating Orbit Issues

Create one claimable Linear record in the team with identifier `NCK`. Linear
owns the outcome, scope, acceptance criteria, ADR decision, and proof venue. Do
not create a repository feature plan.

## Issue Contract

Return or create the issue with these fields in this order:

```text
Status: Ready

Outcome:

In scope:
- ...

Out of scope:
- ...

Acceptance criteria:
- ...

Components:
- ...

ADR: none

Proof venue: automated
Incus topology: none
Checkout roles: none

Source: none
```

Use verifiable behavior. Name the smallest affected components. Preserve
relevant evidence from a GitHub issue.

## ADR Decision

Use `ADR: none` only when no ADR applies. List every governing decision as a
canonical `docs/decisions/` URL. Create an ADR for a cross-component contract,
durable boundary, security or ownership model, or costly-to-reverse choice.

A required ADR must be merged into `main` before the issue enters Ready. If no
merged ADR exists, keep the issue in preparation and create the ADR work first.
Implementation agents do not introduce an ADR inside the feature PR.

## Proof Venue

Use `automated` when tests can prove the behavior. Set both Incus fields to
`none`.

Use `incus` when acceptance depends on a real OS, service manager, privilege
boundary, network, certificate, filesystem ownership, or multi-node behavior.
Use only the registered topology profile ID
`gateway_app-dev_app-prod`. Copy its checkout roles exactly; do not infer them
from changed components. A prose description or role list is not a profile ID.
Do not invent or expand a profile. Keep the issue in preparation until this
profile and its roles are registered. The closed registry and lifecycle
requirements are in `docs/reference/incus-topologies.md`.

The full `gateway_app-dev_app-prod` profile is Incus-only and remains pending
live registration. Its ordered roles are `gateway`, `app-dev`, `app-prod`;
checkout roles are exactly `gateway` and `app-dev`. Automated issues always
use `Incus topology: none` and `Checkout roles: none`.

## Production Reports

Create a Linear bug for a post-deploy defect. Keep the feature closed. Set
`Source` to the GitHub issue. Include the deployed commit and PR, environment,
deployment identity, expected and observed behavior, evidence, and containment.

## Ready Gate

Move the issue to Ready only when all fields are complete, linked ADRs are on
`main`, and criteria are verifiable. For `Proof venue: incus`, the selected
profile and exact checkout roles must also exist in the registry. Otherwise,
name the missing item and use `Status: Preparation`.

## Example

```text
Status: Ready

Outcome:
The PHP SDK exposes the existing Gateway health response without policy logic.

In scope:
- Typed request and response transport.
- Unit coverage for success and Gateway errors.

Out of scope:
- Gateway, CLI, and runtime changes.

Acceptance criteria:
- The request uses the documented method and endpoint.
- Callers receive the typed health response.
- Existing structured errors remain intact.

Components:
- packages/php-sdk

ADR: none

Proof venue: automated
Incus topology: none
Checkout roles: none

Source: none
```

## Common Mistakes

- Good prose without the explicit ADR and proof fields is not Ready.
- `Incus required` without a profile and checkout roles is not executable.
- A GitHub URL without production evidence loses the feedback context.
