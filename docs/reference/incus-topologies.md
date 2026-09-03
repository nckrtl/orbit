# Incus topology registry

This page is for a contributor or agent who proves an issue on Incus. It answers which topologies an issue has, which state each one is in, and which `bin/e2e-topology` command from the `apps/e2e` harness moves it. The plan a proof runs is on [Proof plans](proof-plans.md). The persistent snapshot every topology is copied from is on [Topology snapshot](topology-snapshot.md).

## Discovery and proof

An issue with the `proof:incus` label gets disposable topologies, each a proof topology as [Concepts](../concepts.md) defines the term. [ADR 0005](../decisions/0005-rolling-incus-development-topology.md) governs the rolling topology snapshot they are copied from. [ADR 0006](../decisions/0006-topology-led-feature-development.md) separates discovery from proof and requires fresh proof, immutable proved attempts. Automated-only work does not use Incus.

## Registered profile

Orbit registers one profile, `gateway_app-dev_app-prod`, and every topology uses it.

| Field | Value |
| --- | --- |
| Ordered roles | `gateway`, `app-dev`, `app-prod` |
| Required assignments | `gateway`: `gateway`, `vpn`; `app-dev`: `app-dev`, `metrics`; `app-prod`: `app-prod` |
| Checkout roles | `gateway` and `app-dev` at `/home/orbit/orbit`; `app-prod` has no checkout |
| Network | `oe-<hash>` on `10.232.<slot>.0/24`; the hash is 12 hex characters of the SHA-256 of `<issue>:<attempt>` |
| Instances | `orbit-e2e-<issue-lowercase>-<attempt-prefix>-<role>`, with 8 characters of the attempt ID |
| Addresses | Incus `.10`, `.11`, `.12`; WireGuard `10.44.0.1`, `.2`, `.3`, fixed on every clone; `retarget-vpn.sh` on `app-dev` and `app-prod` rewrites only the WireGuard peer `Endpoint` to the cloned Gateway's Incus address |
| Issue ID | Matches `[A-Z][A-Z0-9]{1,9}-[1-9][0-9]{0,8}` and appears in the worktree branch name |

## Topology states

Each topology is one attempt with a purpose, a lease, and a record under `<worktree>/.e2e/`, which dies with the worktree.

| Purpose | Created by | Files | Ends with |
| --- | --- | --- | --- |
| `discovery` | `acquire` | `attempt.json`, `topology.json` | `release`, `promote`, or `bin/worktree-remove` |
| `proof` | `prove` | `proof-attempt.json`, `proof-topology.json`, `proof.json` | `release --proof`, `promote`, or `bin/worktree-remove` |
| `candidate-convergence` | `candidate` | `candidate-attempt.json`, `candidate-topology.json`, `candidate-convergence.json` | `release --candidate` or `promote` |

A lease names the issue, attempt ID, purpose, operation ID, and acquisition time. A proof result is `proved` or `diagnosis`; a candidate result is `converged` or `diagnosis`. A `diagnosis` topology stays alive for inspection and can never become proved. The results, `proof-inputs/`, `equivalence/`, and the `log` file survive release. `status` reports `absent`, one purpose, or the active purposes joined with `+`. An issue holds at most one attempt per purpose: `acquire` refuses a second discovery, `prove` refuses while a proof attempt exists, and `candidate` refuses while a candidate-convergence attempt exists.

## Capacity budget and leases

Capacity comes from `incus list`, never from a ledger: the harness counts the VMs that carry `user.orbit.e2e.owner=orbit-e2e` and the `10.232.<slot>.0/24` subnets in use.

| Setting | Value |
| --- | --- |
| VM size | 1 vCPU, 2 GiB memory, 16 GiB root disk (`e2e.incus.cpu`, `e2e.incus.memory`, `e2e.incus.root_size`) |
| VM budget | `e2e.incus.max_vms`, default 24, minimum nine; `ORBIT_E2E_INCUS_MAX_VMS` overrides it for one run |
| Network slots | Slot 1 belongs to the topology snapshot; disposable topologies take slots 2 through 200 |
| Incus scope | `e2e.incus.remote`, `e2e.incus.project`, and `e2e.incus.storage_pool`, set from `ORBIT_E2E_INCUS_REMOTE`, `ORBIT_E2E_INCUS_PROJECT`, and `ORBIT_E2E_INCUS_STORAGE_POOL`; defaults `local`, `default`, and `orbit-e2e` |

Every `incus` call carries `--project` from `e2e.incus.project` and lists resources on `e2e.incus.remote`, so the capacity count, the topologies, and the orphan sweep stay inside that project. The harness refuses `acquire`, `prove`, and `candidate` when three more VMs would exceed the budget, naming the count and the limit. At the default, seven disposable topologies fit beside the topology snapshot. There is no reaper: a topology lives until the operator releases it. Every command except `status` and `shell` holds the lock `topology-<ISSUE>` under `<primary>/.e2e/locks/`. Topology creation holds the host lock `topology-create` from network creation until the VMs exist.

## Commands

`acquire` takes the worktree as a positional argument. Every other command finds it at `<primary>/.worktrees/<issue-lowercase>-*`, exactly one match, or takes `--worktree=PATH`. Every command accepts `--json`, and a failure prints `{"state":"failed","error":"..."}` with a nonzero exit.

| Command | What it does |
| --- | --- |
| `acquire ISSUE WORKTREE` | Creates discovery and verifies readiness; refuses a second discovery, a worktree without `vendor/`, or a generation whose fingerprint differs from `main` |
| `shell ISSUE ROLE [--proof]` | Opens a login shell as `orbit` on one role of discovery, or of a `diagnosis` proof |
| `exec ISSUE ROLE --argv=JSON [--proof]` | Runs one argument vector as `orbit` on one role; `--argv-file=PATH` replaces `--argv` |
| `sync ISSUE` | Proves the mount, re-verifies the mounted source identity, and verifies readiness |
| `verify ISSUE` | Verifies discovery readiness and records the report |
| `prove ISSUE [--plan=PATH]` | Proves the clean worktree HEAD on a fresh proof topology; the plan defaults to `proofs/ISSUE.json` |
| `equivalence ISSUE [--plan=PATH]` | Compares the clean HEAD with the retained proof and writes an immutable report; see [Equivalence outcomes](proof-plans.md#equivalence-outcomes) |
| `candidate ISSUE` | Converges and verifies the accepted head on a candidate-convergence topology after an `equivalent` report that requires it |
| `status ISSUE` | Reports the state files without touching Incus |
| `release ISSUE [--proof\|--candidate]` | Releases discovery, or the selected retained topology, verifies absence, and sweeps orphaned networks |

`bin/worktree-remove ISSUE slug` releases the proof topology, then discovery, then removes the worktree.

### Guest commands

`exec` prints `{"state":"executed","exit_code":N,"stdout":"...","stderr":"..."}` with `--json` and the guest stdout without it, and exits `0` only when the guest command does. `--argv='["orbit","doctor","--json"]'` is an inline JSON array of strings; `--argv-file=PATH` names a file holding `{"argv":[...],"stdin":null}` when the vector needs stdin. The harness refuses both at once.

The vector runs through `runuser -u orbit -- env -C /home/orbit HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit DB_DATABASE=/home/orbit/.orbit/gateway.sqlite PROGRAM ARGS`. No shell profile loads: `argv[0]` must resolve on the guest `PATH` or be absolute, and it cannot start with `-` or carry `=`. The harness links the checkout's `apps/cli/orbit` to `/usr/local/bin/orbit` on every checkout role, so `orbit` resolves by name. Wrap a pipeline in `["sh","-c","..."]` and root work in `["sudo","..."]`. `shell` opens the same environment with `bash -l`, in `/home/orbit/orbit` on a checkout role and in `/home/orbit` on `app-prod`; `exec` always runs in `/home/orbit`. `exec --proof` and `shell --proof` accept only a `diagnosis` proof; the harness refuses a proved topology.

## Discovery mount

`acquire` attaches the worktree to `gateway` and `app-dev` as the Incus disk device `orbit-source`, a virtiofs (virtual I/O filesystem) share mounted read-write at `/home/orbit/orbit`. Every host edit is live in both guests. Guests never run Composer: host `bin/bootstrap` owns `vendor/`, and `acquire` refuses a worktree without the Gateway, CLI, and SDK autoloaders. The harness places the preserved Gateway `.env` into the worktree when it is absent there. The mount device is part of the attempt inventory, so exact release removes it.

## Release and network ownership

`release` checks each VM against the attempt's ownership metadata, force-stops the running ones, deletes them, and verifies they are gone. It then checks the network's ownership immediately before it deletes the network, drops the lease and record, and unpins a proved commit's Git ref. The output lists `released`, `already_absent`, and `networks_reaped`.

Every Incus network named `oe-*` or `orbit-e2e-*` belongs to the harness and never outlives its topology. Every release ends with an orphan sweep that deletes each harness network in the configured Incus project with an empty `used_by`, except `oe-topo-snap` and `oe-standby`. The sweep holds the `topology-create` lock, so a network created moments before its first VM is never swept.
