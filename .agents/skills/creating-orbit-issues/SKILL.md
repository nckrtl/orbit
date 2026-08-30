---
name: creating-orbit-issues
description: Use when turning an Orbit request or production GitHub report into a Linear issue before implementation.
---

# Creating Orbit Issues

Create one claimable Linear record in the team with identifier `NCK`. Linear
owns the outcome, scope, acceptance criteria, ADR decision, and the Incus proof
contract. Do not create a repository feature plan.

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

## Proof

Automated proof is mandatory and implicit. Linear does not repeat it.

When acceptance depends on a real OS, service manager, privilege boundary,
network, certificate, filesystem ownership, or multi-node behavior, add these
two lines after `ADR:`:

```text
Proof: incus
Composition: gateway + app-dev + app-prod
```

Omit both lines for automated-only work.

`Composition` names the expected physical nodes only. It does not contain
resource names, images, CPU, memory, disks, networks, attempt IDs, or a machine
manifest. Repository code owns those details. The supported composition is
`gateway + app-dev + app-prod` on Ubuntu 26.04
([ADR 0005](../../../docs/decisions/0005-rolling-incus-development-topology.md),
[ADR 0006](../../../docs/decisions/0006-topology-led-feature-development.md)).

A normal issue cannot use an unsupported operating system, role combination,
or topology. Incus availability alone does not make a node or topology
supported. An issue whose explicit outcome is to add official support must
include all of these in scope: Gateway support, Gateway tests, an E2E recipe,
harness support, and live acceptance for the new support. If a new topology
must become prepared and reusable, say so in scope or acceptance criteria.

## Production Reports

Create a Linear bug for a post-deploy defect. Keep the feature closed. Set
`Source` to the GitHub issue. Include the deployed commit and PR, environment,
deployment identity, expected and observed behavior, evidence, and containment.

## Ready Gate

Move the issue to Ready only when all fields are complete, linked ADRs are on
`main`, and criteria are verifiable. For `Proof: incus`, the composition must
be supported, or adding that support must be in scope. Otherwise, name the
missing item and use `Status: Preparation`.

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

Source: none
```

An Incus issue adds `Proof: incus` and `Composition: gateway + app-dev +
app-prod` after `ADR:`.

## Common Mistakes

- Good prose without the explicit ADR field is not Ready.
- `Proof: incus` without a supported `Composition` is not executable.
- `Composition` with resource names or sizes duplicates repository code.
- A GitHub URL without production evidence loses the feedback context.
