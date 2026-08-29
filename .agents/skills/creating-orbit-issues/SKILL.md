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
Live topology: none
Live nodes: none
Proof access: none
Checkout evidence: none

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

Use `automated` when tests can prove the behavior. Set all live fields to
`none`.

Use `live` when acceptance depends on a real OS, service manager, privilege
boundary, network, certificate, filesystem ownership, or multi-node behavior.
Select exact active applicable nodes with `orbit node:list --json`. Record each
numeric ID, name, role, and access method. Define the checkout identity
evidence to record during proof; candidate or deployed SHA and path do not yet
exist at issue creation. Prefer Orbit CLI or Gateway API; pinned direct SSH is
allowed for proof. Record the approved SSH SHA256 host-key fingerprint when
direct SSH is selected. Capture recovery, ownership, and cleanup evidence.
Never require Incus.

## Production Reports

Create a Linear bug for a post-deploy defect. Keep the feature closed. Set
`Source` to the GitHub issue. Include the deployed commit and PR, environment,
deployment identity, expected and observed behavior, evidence, and containment.

## Ready Gate

Move the issue to Ready only when all fields are complete, linked ADRs are on
`main`, and criteria are verifiable. For `live`, exact applicable node IDs,
names, roles, an access method, and required checkout identity evidence must be
recorded. Direct SSH also requires an approved SSH SHA256 host-key fingerprint.
Otherwise, name the missing item and use `Status: Preparation`.

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
Live topology: none
Live nodes: none
Proof access: none
Checkout evidence: none

Source: none
```

## Common Mistakes

- Good prose without the explicit ADR and proof fields is not Ready.
- `live` without exact nodes and an access method is not executable.
- A GitHub URL without production evidence loses the feedback context.
