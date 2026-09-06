# Proof plans

This page is for the contributor or agent who writes `.loop/proof/<ISSUE>.json` and reads its result. A plan runs on the proof topology the harness builds for its issue. It states what the `apps/e2e` harness accepts, how it stages fixtures and prepares the runtime, what `prove` records, and what each equivalence outcome requires next. [ADR 0022](../decisions/0022-track-the-issue-workspace-and-delete-it-before-merge.md) governs the tracked issue workspace, and the commands that run a plan are on the [Incus topology registry](incus-topologies.md).

## Plan file

A plan is one JSON object at `.loop/proof/<ISSUE>.json` in the worktree. A `--plan=PATH` value must select the active issue's plan under `.loop/proof/`; the harness refuses an absolute path, a `.` or `..` segment, a path outside that directory, a plan for another issue, and any key outside this table. A refusal caused by the selected plan names that plan.

| Field | Type | Meaning | Default |
| --- | --- | --- | --- |
| `setup` | list of actions | Runs before acceptance, in order | Required; may be empty |
| `acceptance` | list of actions | One action per acceptance criterion, in order | Required; at least one |
| `extension` | string | Adds one temporary app-prod Node to discovery and proof | None; the only accepted value is `app-prod` |
| `mutates` | boolean | The plan changes reusable node state, so `promote` refuses the proved topology and closeout refreshes the snapshot from the current `main` | `false` |
| `ends_with` | `{"nodes": [...]}` | The physical Nodes that remain registered when verification runs; a Node left out is proved absent | Every Node in the selected topology |
| `inputs` | list of paths | Files or directories that actions read outside the runtime policy and the fixtures | None |
| `observed_inputs` | boolean | Collect file-level PHP observations with PCOV during setup and acceptance | `false` |

`ends_with.nodes` must name `gateway` and must not repeat a physical Node key. A declaration that leaves a Node out sets `mutates`; a declaration that names every Node counts as no declaration. An extended plan must keep `app-prod-2`; the harness refuses a declaration that removes only the extra Node. Declaring an extension also sets `mutates`, even when the input says `"mutates": false`.

The harness skips only the probes that run on a declared-absent Node. The fleet probes expect exactly the declared set, so `role.assignments` fails when a declared-absent Node is registered. The normalized plan has a SHA-256 fingerprint, recorded as `plan_sha256`; a plan without `extension` keeps its existing normalized form and fingerprint.

### Actions

Each action has exactly these four keys, and the harness refuses a `stdin` key because a plan must not hold secrets.

| Key | Type | Rule |
| --- | --- | --- |
| `id` | string | 1 to 64 characters matching `[a-z0-9][a-z0-9-]*`, unique across setup and acceptance |
| `node` | string | A physical Node key in the selected topology: `gateway`, `app-dev`, `app-prod`, and, for an extended plan, `app-prod-2` |
| `argv` | list of strings | Non-empty, without NUL or newline; the first item is a program or absolute path and cannot start with `-` or carry `=` |
| `timeout_seconds` | integer | 1 through 900 |

A literal `/home/orbit/orbit/...` argument must resolve to a runtime path under the static policy or to a declared input; otherwise `prove` refuses before it creates an attempt. Actions run with the [Guest commands](incus-topologies.md#guest-commands) environment.

## Fixtures

Files beside the plan under `.loop/proof/` are the active issue's proof-only fixtures. The harness refuses a nested directory, a symlink, a name outside `[a-z0-9][a-z0-9._-]{0,127}`, and every declaration that names another issue's fixtures. `prove` reads the fixtures from the exact candidate commit, never from the host working tree. It installs them root-owned, `0755` for an executable blob and `0644` otherwise, at `/var/lib/orbit-e2e/proof/<name>` on every physical Node, including `app-prod-2` in an extended proof. A plan references a fixture by that guest path, for example `["/var/lib/orbit-e2e/proof/fixture-check.sh", "app-prod-2"]`.

The harness empties the guest directory before staging. Every role prints `name<TAB>mode<TAB>sha256` per file, and the digest must equal the host digest. An issue with no fixture files beside its plan stages an empty inventory.

## Proof phases

`prove` requires a clean worktree at a commit that contains `origin/main`, a promoted topology snapshot generation, and no existing proof attempt. A `diagnosis` error names the phase that failed.

| Phase | What the harness does |
| --- | --- |
| `construct` | Clones the three standard Nodes from the promoted generation and, for an extended plan, constructs `app-prod-2` from the recorded generic base image |
| `sync.candidate` | Transfers exactly the candidate commit from Git to the checkout roles |
| `identity` | Proves each guest checkout holds the candidate SHA and tree |
| `fixtures` | Stages the fixtures |
| `sury-runtime` | Prepares the packaged PHP runtime on `gateway` and `app-dev` |
| `converge` | Runs the full convergence sequence; see [Refresh](topology-snapshot.md#refresh) |
| `setup`, `acceptance` | Runs each action in order; with `observed_inputs`, `pcov.*` phases surround them |
| `manifest` | Builds the immutable proof-input manifest |
| `verify` | Runs general topology verification against the declared end state |

A failure while the harness creates the network and complete VM inventory rolls the attempt back. Every later failure records a `diagnosis` and keeps the complete topology alive. The operator addresses either app-prod Node by its physical key with `shell --proof` or `exec --proof` and runs `release --proof` before the next proof. The first nonzero exit, including `124` and `137` from a timeout, stops later actions and makes the proof a `diagnosis`. Each action runs under `timeout --signal=TERM --kill-after=5s <timeout_seconds>s`: `TERM` at the deadline, five seconds for cleanup traps, then `KILL`, with seven seconds of transport headroom.

## Runtime preparation and PCOV

PCOV is a PHP extension that records which files a process executes, and the harness uses it only inside disposable proof roles. The `sury-runtime` phase runs `observe-php.sh prepare runtime` on `gateway` and `app-dev`. It installs or upgrades the `php8.5` packages from the pinned Sury apt source and requires identical versions on both roles. It removes `/usr/local/bin/php` only when it is the base-image link to `/opt/orbit/php/8.5/bin/php` and refuses any other file there, so every entrypoint shares `/usr/bin/php8.5`. It writes the systemd drop-in `/etc/systemd/system/php8.5-fpm.service.d/orbit-e2e-sury.conf` with `ProtectSystem=false` under `[Service]` and reloads the unit definitions when that file changes.

With `observed_inputs: true`, `pcov.prepare` also installs `php8.5-pcov` at matching versions. The harness enables collection separately for `setup` and `acceptance`. Each phase must produce records from `app-dev:cli`, `gateway:cli`, and `gateway:fpm` with tracked paths below `/home/orbit/orbit`. Every observed process writes `<id>.start.json` when it starts and `<id>.result.json` at shutdown. The collector refuses a duplicate record, a start without its result, a result whose identity fields differ from its start, and a record from another attempt, issue, phase, or role. `pcov.cleanup` removes the prepend module, restores `pcov.enabled=0`, and restarts FPM before verification. A missing surface, an untracked path, empty coverage, malformed output, or a failed cleanup makes the proof a `diagnosis`.

## Proof result

`prove --json` prints one object and writes it to `<worktree>/.e2e/proof.json`. The exit code is `0` for `proved` and `1` for `diagnosis`.

| Field | Meaning |
| --- | --- |
| `status` | `proved` or `diagnosis` |
| `issue`, `attempt_id`, `candidate_sha`, `recorded_at` | The issue, the proof attempt, the proved commit, and the UTC time |
| `plan_sha256`, `manifest_sha256` | Fingerprints of the normalized plan and of the proof-input manifest; the latter binds the selected topology and exists only on `proved` |
| `actions` | One `{"id","node","exit_code"}` per action that ran |
| `ends_with`, `skipped_probes` | The declaration and the probes it skipped; present only when the declaration leaves a role out |
| `failed_action` | The action that ended the proof, with `stdout_tail` and `stderr_tail` of the final 4096 bytes |
| `error` | `proof phase <phase> failed: <message>` |

A proved result also writes the manifest, whose content [ADR 0015](../decisions/0015-retain-incus-proof-by-recorded-input-equivalence.md) defines, to `<worktree>/.e2e/proof-inputs/<manifest_sha256>.json`. Its topology input records the normalized extension, promoted source generation, ordered physical Node inventory and identities, and the extra Node's image alias and fingerprint. Equivalence is stale when the current extension or construction input differs. The harness pins the commit at `refs/orbit/e2e-proof/<issue-lowercase>/<attempt_id>` until release and never overwrites immutable evidence.

## Equivalence outcomes

After a later commit, `bin/e2e-topology equivalence ISSUE` compares the clean HEAD with the retained proof, and the head must contain current `origin/main`. The evaluator follows the rules in [ADR 0015](../decisions/0015-retain-incus-proof-by-recorded-input-equivalence.md) and classifies every changed path. The immutable report lands at `<worktree>/.e2e/equivalence/<fingerprint>.json`, and `equivalence.json` points at the latest one.

| Outcome | Meaning | Next command |
| --- | --- | --- |
| `exact` | The SHA or the complete Git tree is unchanged | Review the head, then `bin/e2e-topology-snapshot promote ISSUE` after merge |
| `equivalent`, `retained-proof` path | Every change is non-runtime | Review the head, then `promote` after merge |
| `equivalent`, `candidate-convergence` path | An observed-input proof has only unrelated runtime drift from `main` | `bin/e2e-topology candidate ISSUE`, then review and `promote` |
| `stale` | A runtime or proof-contract input changed | `bin/e2e-topology release ISSUE --proof`, then `prove` |
| `indeterminate` | A classification, identity, current-main, manifest, plan, or completeness gate failed | Resolve the listed errors, `release --proof`, then `prove` |

The report's `next_action` field names the same step, and the command exits `0` for `exact` and `equivalent`. `candidate` accepts only an `equivalent` report on the `candidate-convergence` path bound to the current HEAD with a complete observed-input manifest. It synchronizes the exact head into a fresh attempt, proves checkout identity, prepares the runtime, converges, and verifies. It never stages fixtures or reruns setup or acceptance. A `converged` topology stays immutable for review and is what `promote` installs; the operator releases a `diagnosis` with `--candidate` before a retry.
