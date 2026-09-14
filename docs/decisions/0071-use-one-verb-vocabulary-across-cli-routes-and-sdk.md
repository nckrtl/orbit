# ADR 0071: Use one verb vocabulary across the CLI, route names, and SDK

In the context of a CLI whose families create resources with new, add, install, provision, and attach and remove them with remove, detach, disable, and clear, facing agents and operators that compose a command from a family name and a verb, we decided for one verb vocabulary applied to CLI commands, Gateway route names, and SDK request classes and against a single universal verb pair or per-family verbs, to make every command predictable from its family and the ownership of its resource, accepting one hard rename with no aliases.

## Status

Accepted on 2026-09-14. Extends [ADR 0036](0036-support-only-appinstances.md), [ADR 0048](0048-copy-app-process-and-schedule-definitions-into-appinstances.md), and [ADR 0069](0069-allow-node-process-targets.md).

## Context

The CLI exposes 106 commands in 21 families. Creation uses five verbs and removal uses four, cluster membership uses attach and detach while Node roles and access use add and remove, and the two App definition commands multiplex show, create, replace, and remove behind flags. The Gateway records each route name as the activity command, and that name differs from the CLI command for the metrics, doctor, release, deploy, rollback, definition, environment, and layout routes. The PHP SDK still carries request classes for the legacy Instance model that ADR 0036 retired, with no route and no caller. Old Orbit used create and destroy for owned resources and add and remove for attachments, and Heroku's colon-separated CLI makes the same split.

## Decision

- The CLI must name every command as one noun family followed by one verb.
- The CLI must use create and destroy for a resource that the Gateway brings into existence and tears down.
- The CLI must use add and remove for an association between things that exist independently, including a Node in the fleet, a role or access grant on a Node, a Node in a Cluster, and a gateway profile in the CLI configuration.
- The CLI must use install and remove for tools, enable and disable for toggles, set and unset for single-valued slots, update for a partial change, and list and show for reads.
- The CLI must reserve node:create and node:destroy for a decision that creates and destroys machines at a hosting provider.
- The process and schedule families must expose the App-owned definitions of ADR 0048 as the App target of the same verbs that serve AppInstance and Node targets.
- An App target must select a definition and must not create a Process or Schedule under ADR 0069.
- A Gateway route that serves a CLI command must carry that command's name.
- An SDK request class that serves a CLI command must carry that command's verb.
- The CLI must not keep an alias for a renamed command.
- The PHP SDK and the Gateway must not carry a request surface for the legacy Instance model retired by ADR 0036.

## Rejected alternatives

- Add and remove for every family: rejected because add would name the creation of an AppInstance, a Cluster, and a Route, which the Gateway brings into existence rather than attaches.
- Create and destroy for every family: rejected because destroy would name Node removal, which leaves the machine intact, and the removal of a role, a grant, or a gateway profile.
- Per-family verbs with aliases for the old names: rejected because the consumers of the CLI are this repository's agents, harness, and operator, and an alias doubles the surface the command-surface test must cover.
- Definitions under the app family: rejected because a definition is created, listed, shown, updated, and destroyed with the same fields as the Process or Schedule it produces, and the app family would keep four verbs behind flags.

## Consequences

- An agent derives a command from a family, the ownership of its resource, and this vocabulary without reading a command list.
- A renamed route changes the command that activity records, so activity rows written before the rename keep the old name.
- The gateway family gains a remove command for a profile.
- The CLI, Gateway, SDK, documentation, and e2e harness change per family in one landing, and the command-surface test asserts the vocabulary and the route-name equality.
- Three commands keep a noun as their last segment, node:settings, workspace:php, and metrics:credentials, and stay outside this record.
- Notes and checkouts that name node:new, node:provision, or app:new no longer match the CLI.

## Affects

- Components: apps/cli, apps/docs, apps/e2e, apps/gateway, packages/php-sdk
- ADRs: extends [ADR 0036](0036-support-only-appinstances.md), [ADR 0048](0048-copy-app-process-and-schedule-definitions-into-appinstances.md), and [ADR 0069](0069-allow-node-process-targets.md)
- Detail: docs/reference/cli-command-vocabulary.md
- Verify: `composer docs-lint`; the CLI command-surface test that asserts every command verb belongs to the vocabulary and every Gateway route that serves a command carries its name
