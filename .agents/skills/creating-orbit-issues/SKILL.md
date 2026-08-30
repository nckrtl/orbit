---
name: creating-orbit-issues
description: Use when turning an Orbit request or production GitHub report into a Linear issue before implementation.
---

# Creating Orbit Issues

Create one Linear issue in team `NCK`. Linear owns the outcome, scope,
acceptance criteria, and ADR decision. Do not create a repository plan.

## Issue contract

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

Proof: incus        # only when a real machine is needed; omit otherwise

Source: none
```

- Use verifiable behavior. One acceptance criterion becomes one proof action.
- Name the smallest affected components (`apps/cli`, `apps/gateway`,
  `packages/php-sdk`). Name `apps/e2e` only for a harness issue; a feature
  issue never lists it.
- Add `Proof: incus` when acceptance depends on a real OS, service manager,
  privilege boundary, network, certificate, filesystem ownership, or
  multi-node behavior. Omit it for automated-only work. The topology is
  always `gateway + app-dev + app-prod` on Ubuntu 26.04. Do not describe it.

## ADR

Use `ADR: none` only when no ADR applies. List every governing decision as a
`docs/decisions/` URL. Create an ADR for a cross-component contract, a durable
boundary, a security or ownership model, or a costly-to-reverse choice. A
required ADR is merged into `main` before the issue enters Ready; otherwise
keep the issue in Preparation.

## Production reports

Create a Linear bug for a post-deploy defect. Keep the feature closed. Set
`Source` to the GitHub issue and include the deployed commit, environment,
expected and observed behavior, and evidence.

## Ready gate

Ready when every field is complete, linked ADRs are on `main`, and the
criteria are verifiable.
