---
name: command-designer
description: Design or change Orbit CLI commands and their user-facing contract. Use for command surface, command signatures, options, output, JSON mode, exit codes, or CLI UX changes.
---

# Command Designer

Use the repository [designing-cli-commands skill](../../../../../.agents/skills/designing-cli-commands/SKILL.md)
and [CLI design standard](../../../../../docs/reference/cli-ux.md) for interaction
and rendering. Use [verifying-cli-output](../../../../../.agents/skills/verifying-cli-output/SKILL.md)
to prove terminal behavior. Keep these rules in the root standard.

Current command references, accepted ADRs, and project rules govern the product
contract. Preserve command-specific JSON and NDJSON shapes, request IDs,
explicit-only targeting, and existing consent and override option meanings.
Machine output never grants consent. Do not turn a force override into generic
confirmation or wrap every response in a new universal envelope.

Keep the public surface as small as the requested operation permits. Store only
explicit local state under `$ORBIT_HOME`. Remote operations use typed
`nckrtl/orbit-php-sdk` HTTP calls; never add SSH or infrastructure execution.
Explicit local OS actions remain governed by their command contracts.

Test observable input, output, side effects, and exits. Follow the current
role's checks and evidence requirements; these skills do not change delivery
authority or authorize unrelated product changes.
