# Optional Incus diagnostic registry

Incus is optional diagnostic tooling. It never gates issue readiness, review,
merge, or proof. No registered profiles is acceptable.

The monorepo foundation does not register a profile. This document may list
diagnostic profiles, but live proof uses active nodes selected with
`orbit node:list --json`.

A profile becomes registered only when the repository provides and verifies
all of these exact-ID operations:

- create or safely resume a topology for one Linear issue;
- synchronize the selected worktree roles;
- execute from a VM-local runtime checkout;
- record diagnostic evidence against the candidate commit;
- release its instances, networks, storage, source paths, and manifest; and
- verify that release completed.

Each registry entry must name the profile ID, ordered roles, checkout roles,
prepared image, create command, synchronize command, release command, manifest
location, evidence location, and maximum lifetime. Cleanup must be idempotent.
A TTL reaper is only a fallback for abandoned resources.

## Registered profiles

### `gateway_app-dev_app-prod`

Registered on 2026-08-29 after live acceptance (ADR 0005).

| Field | Value |
| --- | --- |
| Profile ID | `gateway_app-dev_app-prod` |
| Ordered roles | `gateway` (roles `gateway`, `vpn`), `app-dev`, `app-prod` |
| Checkout roles | `gateway`, `app-dev` (VM-local `/home/orbit/orbit`) |
| Prepared image | Base image `orbit-base-ubuntu-26.04-runtime`; promoted standby snapshots `main-<generation-id>` on `orbit-e2e-standby-{gateway,app-dev,app-prod}` |
| Addresses | Incus `.10/.11/.12` on `oe-<issue-hash>`; WireGuard `10.44.0.1/.2/.3` |
| Create | `bin/e2e-topology acquire ISSUE WORKTREE --json` (about 35 s from the promoted generation) |
| Synchronize | `bin/e2e-topology sync ISSUE WORKTREE --json` (exact SHA plus dirty overlay, no host mount) |
| Verify | `bin/e2e-topology verify ISSUE --json` |
| Prove | `bin/e2e-topology prove ISSUE WORKTREE --candidate-sha=SHA --json` (clean worktree, exact tree, guest `bin/test`, proof probes) |
| Release | `bin/e2e-topology release ISSUE --json`; `bin/e2e-topology reap` is the TTL fallback |
| Manifest | `$XDG_STATE_HOME/orbit/e2e/topologies/ISSUE.json` and `leases/ISSUE.json` |
| Evidence | `$XDG_STATE_HOME/orbit/e2e/journals/<operation>.jsonl`, `proof/ISSUE.json`, `standby/failures/<evidence>.json` |
| Maximum lifetime | 7 days per lease; release is idempotent and verifies exact absence |

Issue IDs match `[A-Z][A-Z0-9]{1,9}-[1-9][0-9]{0,8}`. Every command refuses a
stale promoted standby; refresh the standby with `bin/e2e-standby refresh
--main-sha=SHA` after a merge changes the prepared-state fingerprint
(`bin/e2e-standby fingerprint --main-sha=SHA`). A rolling refresh restores the
promoted snapshots, converges, and re-snapshots in about one minute.

Guests are reachable from the Gateway only over WireGuard after role
provisioning; the harness repairs cloned WireGuard endpoints through root
`incus exec` (`retarget-vpn.sh`) and never depends on public SSH. After that
repair, `orbit:node-retarget` updates the public record over WireGuard; see
[node retarget](node-retarget.md).

`bin/e2e-standby refresh --allow-cold` permits only initial construction when
no promoted generation or standby resources exist. It never replaces a
promoted generation. An operating-system, base-image, cold-epoch, or corrupt
standby change requires a separate reviewed disaster-recovery procedure before
the harness mutates Incus resources.
