# ADR 0073: Store deploy steps as named AppInstance records

In the context of configuring production deployments, facing one replace-all document for the branch and steps and a layout conversion with no producer, we decided for deploy steps as named AppInstance-owned records with their own lifecycle, the branch as an AppInstance update, and clone as the sole producer of production AppInstances and against a replace-all document, a repeatable step flag, or keeping the conversion, to give each step the same lifecycle as every other resource, accepting one request per step when configuring a pipeline.

## Status

Accepted on 2026-09-14. Extends [ADR 0046](0046-own-production-release-deployment-in-orbit.md) and [ADR 0071](0071-use-one-verb-vocabulary-across-cli-routes-and-sdk.md). Supersedes [ADR 0046](0046-own-production-release-deployment-in-orbit.md) for the adoption of existing production homes.

## Context

ADR 0046 gave a production AppInstance its branch and ordered steps, exposed as one document that the CLI replaces from a file, and an explicit conversion of a production home that still serves code from its checkout. Cloning is the only producer of production AppInstances, its result plus the first deployment yields the release layout, nothing in the Gateway reads the conversion record, and the Gateway reports no AppInstance in the fleet. Old Orbit configured deploy steps as records added one at a time, and its four setup-step families used the same shape. ADR 0071 gives every owned resource create and destroy.

## Decision

- A production AppInstance owns deploy steps as named records; each step must carry a name unique within the AppInstance, a command, a phase before or after activation, and a timeout.
- The CLI, Gateway, and SDK must expose deploy steps with create, update, destroy, and list under ADR 0071.
- The Gateway must order steps within a phase by explicit placement relative to a named step and must place an unplaced step at the end of its phase.
- The Gateway must enforce the step count and timeout limits on each step mutation.
- The Gateway must not mutate a step while a deployment or rollback of the same AppInstance runs.
- The branch must change through an AppInstance update.
- Orbit must not offer a replace-all deployment document.
- Orbit must not convert a production home that serves code from its checkout; cloning must remain the sole producer of a production AppInstance, and the first deployment must produce the release layout.

## Rejected alternatives

- Keep the replace-all document: rejected because it cannot change one step or the branch alone, and a document is the one command shape ADR 0071 excludes.
- A repeatable step flag on the update: rejected because a command with spaces, colons, or quotes breaks any flag separator, and two ways to write one list is how the definition commands got four verbs behind flags.
- Deploy steps in the repository checkout: rejected because ADR 0046 places deployment configuration ownership in the Gateway.
- Keep the layout conversion for an adopted production checkout: rejected because registration is development-only and no code path produces the conversion's input.

## Consequences

- An agent configures a pipeline with one request per step and edits one step without resending the others.
- The deployment-config endpoint, the conversion endpoint, the conversion action, its layout table, and their SDK requests are removed.
- Adopting a production checkout that already serves code needs a new decision; this record removes the path.
- A pipeline of many steps takes as many requests.

## Affects

- Components: apps/cli, apps/docs, apps/gateway, packages/php-sdk
- ADRs: extends [ADR 0046](0046-own-production-release-deployment-in-orbit.md) and [ADR 0071](0071-use-one-verb-vocabulary-across-cli-routes-and-sdk.md); supersedes [ADR 0046](0046-own-production-release-deployment-in-orbit.md) for the adoption of existing production homes
- Detail: [Production release layout](../reference/deployments.md)
- Verify: `composer docs-lint`; Gateway, PHP SDK, and CLI deploy-step tests
