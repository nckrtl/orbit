# Feature plan

Plan format: 1
Issue: ORB-323
Flow: discovery
Review verdict: PASS

## Outcome

`orbit node:remove` refuses a Node that owns a Process or a Herdr session, restores public SSH, removes Gateway-side projections and the record, and leaves the machine's units, containers, and checkouts untouched.

Incus: required. Discovery is acquired after independent plan review. Proof instrumentation is not required.

## Code boundaries

In:
- `apps/gateway/app/Actions/Nodes/RemoveNodeAction.php`: turn online Herdr and Process cleanup into guards `node.has_herdr_sessions` and `node.has_processes` before any Gateway or machine mutation; keep `firewall-recovery` and every other Gateway-side step; on `--offline --force` for an unreachable Node, drop role, Process, and Herdr session records without remote retract or runtime cleanup and keep the `retained_on_node` report.
- `apps/gateway/app/Actions/Herdr/CascadeNodeHerdrSessionsAction.php` and `apps/gateway/app/Actions/Processes/CascadeNodeProcessesAction.php`: stop calling them from online removal; delete them if they have no remaining callers.
- `apps/gateway/tests/Feature/Api/RemoveNodeTest.php`: replace cleanup and cleanup-failure assertions with guard tests; keep firewall-recovery, offline `--force` record-drop, and retained-state coverage.

Out:
- The `node:add` rename: this branch still carries `node:provision`; ORB-328 owns the rename.
- Role removal: `RemoveNodeRoleAction` and `node:role:remove` stay unchanged. Discovery may call them to make `app-prod` eligible.
- Process and Herdr session destruction: `process:destroy` and `herdr:session:destroy` stay the operator path; removal does not stop, delete, or reconfigure them on the machine.
- `node:create` and `node:destroy`.
- The machine's roles, checkouts, units, and containers: online removal issues no Process, Herdr, role, or checkout command to the machine.
- Harness code under `apps/e2e` except Feature and Unit tests, and `bin/e2e-*`.
- Accepted ADRs, including ADR 0072.

## Documentation

- `docs/reference/node-provisioning.md` section "Remove a Node": the Gateway refuses removal while the Node owns Processes or Herdr sessions; the step table keeps Grafana, Metrics, public SSH recovery, WireGuard, DNS, and the record, and drops `herdr-cleanup` and `process-cleanup`; failure codes `node.has_processes` and `node.has_herdr_sessions` join the guard row; `node.herdr_cleanup_failed` and `node.process_cleanup_failed` are gone; `--offline --force` still drops those records without machine access and lists `retained_on_node`. The page keeps the title, file name, and `node:provision` because that is the CLI name on this branch.
- `docs/reference/herdr-sessions.md`: Node removal is a guard, not observer or Process cleanup; `node.herdr_cleanup_failed` is gone.
- `docs/reference/app-processes-and-schedules.md`: Node removal refuses while the Node owns a Process; offline decommissioning still drops those records without remote cleanup.
- `docs/generated/context.json`: rebuilt so the Node provisioning page records ADR 0072.

Reported:
- `docs/reference/node-provisioning.md`: the code still runs `router-lan-ingress` between WireGuard removal and DNS convergence and can fail with `router.lan_ingress_failed`, which this page does not list. Owner: a later docs issue; this issue's In bullet is the Process and Herdr guard change, not that existing omission.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Removal of a Node that owns a Process or a Herdr session is refused with a bounded code before any Gateway or machine change. | `RemoveNodeAction` guards in `guardRemoval` / the same pre-mutation block as AppInstance, role, and firewall guards. | `apps/gateway/tests/Feature/Api/RemoveNodeTest.php`: 409 `node.has_processes` and `node.has_herdr_sessions`; Node stays active; peers, DNS, Grafana, Process runtime, and Herdr observers are unchanged. |
| Removal of an eligible Node restores the public SSH recovery rule over WireGuard, then removes the peer, private DNS records, Metrics exporter state, Grafana access, and the record, and issues no Process, Herdr, role, or checkout command to the machine. | `RemoveNodeAction` online steps with `firewall-recovery` kept and cascade cleanup removed. | `apps/gateway/tests/Feature/Api/RemoveNodeTest.php` keeps restore-before-peer and no-runtime-cleanup assertions. After plan review, on discovery `app-prod`: destroy `e2e-prod`, `node:role:remove app-prod app-prod --force --json`, snapshot units with `bin/e2e-topology exec ORB-323 app-prod --argv='["sudo","sh","-c","systemctl list-units --type=service --all \| grep -i -E \"php\|caddy\|orbit\""]'` and `["sudo","docker","ps","-a"]`, then from `app-dev` `orbit node:list --json` and `orbit node:remove <id> --force --json`. Expect success, `node:list` without `app-prod`, the same units and containers on the machine, and public SSH from gateway via `ssh-keyscan` to the Node's Incus `public_ssh_host`. |
| Provisioning the removed machine again connects over public SSH and completes. | Gateway `orbit:node-provision` / CLI `node:provision` on this branch; do not edit `apps/e2e/resources/guest/converge-app-prod-internal-tls.sh`. | From `app-dev` or `gateway`, provision `app-prod` at its Incus public SSH host with `--user=orbit`, the same WireGuard address (`10.44.0.3` on the standard topology), observed architecture, and `ssh-keyscan` fingerprint, matching the console shape in that guest script. Expect the command to complete and `node:list --json` to name `app-prod` again. |
| `--offline --force` on an unreachable Node drops its role, Process, and Herdr session records without machine access and lists the retained state. | `RemoveNodeAction` unreachable `--offline --force` path: shed roles, delete Process and Herdr records without runtime or observer calls, keep `retained_on_node`. | `apps/gateway/tests/Feature/Api/RemoveNodeTest.php`: unreachable fixture drops those records, Process runtime and Herdr retract stay idle, `retained_on_node` lists leftovers. No Incus observation required. |
| The Node provisioning reference page describes the guards and steps, and generated context is current. | Pages listed in Documentation. | `composer docs-lint` (passed during planning). |

## Implementation order

1. Independent plan review, then acquire discovery on beast from `/fast/worktrees/orbit/orb-323` with `bin/e2e-topology acquire ORB-323 /fast/worktrees/orbit/orb-323 --json`. Do not acquire before that review.
2. In `RemoveNodeAction`, after the existing AppInstance, role, and firewall guards and before Grafana or other mutations, refuse online removal when `$node->herdrSessions()->exists()` (`node.has_herdr_sessions`) or `$node->processes()->exists()` (`node.has_processes`). Skip those two guards on the unreachable `--offline --force` path so records can still drop.
3. Delete the online `herdr-cleanup` and `process-cleanup` steps and their `node.herdr_cleanup_failed` / `node.process_cleanup_failed` codes. Keep `firewall-recovery` exactly as it is.
4. On unreachable `--offline --force`, delete Herdr session and Node-owned Process records without calling observer retract or Process runtime. Keep role shed and the existing `retained_on_node` residue report.
5. Replace `RemoveNodeTest` cleanup success and cleanup-failure cases with the new guards; add the matching Herdr offline record-drop case; keep public SSH recovery, peer/DNS/Metrics/Grafana, rollback, and protected-node tests.
6. On discovery, run the Incus observations in the acceptance map on `app-prod` (it is disposable). Sync the beast worktree before each observation that must see new commits.
7. Run `composer test:affected` in `apps/gateway` on beast; run `composer docs-lint` if docs change again; Builder gate on the exact pushed head.

## Must preserve

- ADR 0072: `node:add` is provision or converge; `node:remove` refuses while the Node owns AppInstances, roles, firewall rules, Processes, or Herdr sessions; removal does not stop, delete, or reconfigure a Process, Herdr session, role, or checkout on the machine; public SSH recovery runs over WireGuard before the peer is removed; removal still drops the WireGuard peer, private DNS records, Metrics exporter state, Grafana access, and the Node record; unreachable `--offline --force` may drop role, Process, and Herdr session records without machine access and must report retained state.
- ADR 0069 Process targeting (AppInstance or managed Node), except the superseded decommissioning-cleanup bullet.
- ADR 0071 command names: this issue does not rename `node:provision` to `node:add`.
- Existing removal guards: AppInstances (including `--offline --force`), instances, firewall rules, roles on a reachable Node, self, Gateway, VPN, Schedule targets, and Route guards.
- `firewall-recovery` restores `orbit:public-ssh-recovery` over WireGuard before the peer goes; a restore failure keeps the peer and returns `node.firewall_recovery_failed`.
- A reachable Node with `--offline` still takes the ordinary guards.
- Grafana revocation, Metrics retire, WireGuard peer removal, Router LAN ingress prune, DNS converge, persistence, and their rollbacks.
- Last-role public SSH recovery on `node:role:remove` (owned by Node retarget, not this change).

## Open questions

none

## Deviations

- Discovery snapshot had no `e2e-prod` AppInstance (`instance:list` returned only `e2e-dev` on `app-dev`). Eligibility used `node:role:remove app-prod app-prod --force` only.
- Re-add omitted `--role` (roleless `node:provision`) as the plan-review note requested; that is enough to prove public SSH connect and complete.
- First `node:remove` attempts failed `node.dns_projection_failed` because `orbit-private-dns.service` CHDIR'd to configured `/home/orbit/orbit-gateway`, which is absent on this mounted discovery worktree. A symlink from that path to `/home/orbit/orbit/apps/gateway` unblocked DNS converge. Product removal code was unchanged.

## Review findings
