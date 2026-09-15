# Approved CLI consent direction

The user said ‘proceed’ after the recommendation for default-No prompts and separate --yes consent while preserving force overrides. This authorizes that consent direction, including ownership transfer. Affected issue contracts must make the exact new CLI failures and flags reviewable; API/SDK behavior remains unchanged.

## Selected behavior

Require a default-No interactive confirmation before a destructive operation when the command has no independent consent mechanism. Add `--yes` to supply that consent in automation and JSON mode. Declining or cancelling makes no mutation. JSON and force-overrides do not imply this new consent. An ownership-transfer confirmation defaults to No too.

Keep existing consent flags where their current contract already defines them as consent. Keep `--force`, `--purge-data`, `--offline`, `--accept-ca-change`, `--accept-termination`, and `--handoff` meanings intact; do not rename or collapse independent decisions. The proposal does not authorize new SDK/API fields, permissions, transports, or target discovery.

Existing absent consent and default-Yes ownership transfer are migration gaps, not grandfathered exceptions.

## Confirmed affected surfaces

| Current command | Current behavior | Approved direction |
| --- | --- | --- |
| `instance:destroy` | No confirmation; `--force` allows dirty or unpublished source deletion after identity checks | Separate consent through a default-No prompt or `--yes`; force still separately permits source loss |
| `gateway:remove` | No confirmation; `--force` permits removing the active profile | Separate consent through a default-No prompt or `--yes`; force still permits active-profile removal |
| `instance:register` | Human ownership transfer defaults Yes; noninteractive confirmed inputs can proceed without that prompt | Default-No ownership confirmation and an explicit automated consent path |
| `instance:deploy-step:destroy` | Deletes a named step with no consent option or prompt | Default-No prompt or `--yes` before deletion |
| `app:destroy`, `route:destroy`, `route:target:unset`, `firewall:remove`, `process:destroy`, `schedule:destroy`, `tool:remove` | Inventory identifies no consent option/prompt | Default-No prompt or `--yes` on the destructive path, subject to each current target and ownership contract |

`instance:deploy-step:destroy` was confirmed during follow-up source inspection and must join the existing consent gap list; it was not marked by the earlier inventory's heuristic. This does not change the total command count or batch ownership.

## Verification implications

Each affected path needs a real interactive accept, default decline, Ctrl-C/EOF, explicit automated consent, and missing automated-consent case. The tests must distinguish consent from each safety override and verify actual resulting state. Resolve the subject before asking; read-only resolution may occur, but no destructive write may begin on refusal. Existing not-found, authorization, identity, and ownership failures remain distinct from missing consent.

New missing-consent paths exit 1 with `input.confirmation_required` and tell the caller to supply `--yes`. Decline, Ctrl-C and EOF before mutation exit 1 with `input.cancelled`; registration retains `instance.registration_cancelled`. Existing failures keep their codes and JSON shape. These are CLI-only errors, not API/SDK changes.

## Authority and scope

Inspected source at current main `25038b816423859874caa73e9c5dafba463ecb9e`: `apps/cli/app/Commands/Instances/DestroyInstanceCommand.php`, `RegisterInstanceCommand.php`, `DestroyDeployStepCommand.php`, and `apps/cli/app/Commands/Gateway/GatewayRemoveCommand.php`. Their command paths are unchanged from the prepared inventory baseline. The full matrix records the other affected families and tests. No product file has been edited.
