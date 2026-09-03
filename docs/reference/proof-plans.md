# Proof plans

This page is for the contributor or agent who writes `proofs/<ISSUE>.json` and reads its result. It states what the `apps/e2e` harness accepts, how it stages fixtures and prepares the runtime, what `prove` records, and what each equivalence outcome requires next. The commands that run a plan are on the [Incus topology registry](incus-topologies.md).

## Plan file

A plan is one JSON object at `proofs/<ISSUE>.json` in the worktree, or at the repository-relative path given with `--plan=PATH`. The harness refuses an absolute path, a `..` segment, and any key outside this table.

| Field | Type | Meaning | Default |
| --- | --- | --- | --- |
| `setup` | list of actions | Runs before acceptance, in order | Required; may be empty |
| `acceptance` | list of actions | One action per acceptance criterion, in order | Required; at least one |
| `mutates` | boolean | The plan changes reusable node state, so `promote` refuses the proved topology | `false` |
| `ends_with` | `{"nodes": [...]}` | The Nodes that remain registered when verification runs; a role left out is proved absent | Every role |
| `fixture_issues` | list of issue IDs | Other issues whose `proofs/<ID>/` directories the harness also stages; no duplicates | None |
| `inputs` | list of paths | Files or directories that actions read outside the runtime policy and the fixtures | None |
| `observed_inputs` | boolean | Collect file-level PHP observations with PCOV during setup and acceptance | `false` |

`ends_with.nodes` must name `gateway`, must not repeat a role, and implies `mutates: true`. The harness skips only the probes that run on a declared-absent Node. The fleet probes expect exactly the declared set, so `role.assignments` fails when a declared-absent Node is registered. The normalized plan has a SHA-256 fingerprint, recorded as `plan_sha256`.

### Actions

Each action has exactly these four keys, and the harness refuses a `stdin` key because a plan must not hold secrets.

| Key | Type | Rule |
| --- | --- | --- |
| `id` | string | 1 to 64 characters matching `[a-z0-9][a-z0-9-]*`, unique across setup and acceptance |
| `node` | string | `gateway`, `app-dev`, or `app-prod` |
| `argv` | list of strings | Non-empty, without NUL or newline; the first item is a program or absolute path and cannot start with `-` or carry `=` |
| `timeout_seconds` | integer | 1 through 900 |

A literal `/home/orbit/orbit/...` argument must resolve to a runtime path under the static policy or to a declared input; otherwise `prove` refuses before it creates an attempt. Actions run with the [Guest commands](incus-topologies.md#guest-commands) environment.

## Fixtures

Files under `proofs/<ISSUE>/` are proof-only fixtures beside the plan. The harness refuses a nested directory, a symlink, or a name outside `[a-z0-9][a-z0-9._-]{0,127}`. `prove` reads them from the exact candidate commit, never from the host working tree. It installs them root-owned, `0755` for an executable blob and `0644` otherwise, at `/var/lib/orbit-e2e/proof/<name>` on every role, including `app-prod`. A `fixture_issues` entry lands at `/var/lib/orbit-e2e/proof/<ID>/<name>` and must hold at least one file. A plan references a fixture by that guest path, for example `["/var/lib/orbit-e2e/proof/fixture-check.sh", "app-prod"]`.

The harness empties the guest directory before staging. Every role prints `name<TAB>mode<TAB>sha256` per file, and the digest must equal the host digest. An issue without a fixture directory stages an empty inventory.

## Proof phases

`prove` requires a clean worktree at a commit that contains `origin/main`, a promoted topology snapshot generation, and no existing proof attempt. A `diagnosis` error names the phase that failed.

| Phase | What the harness does |
| --- | --- |
| `sync.candidate` | Transfers exactly the candidate commit from Git to the checkout roles |
| `identity` | Proves each guest checkout holds the candidate SHA and tree |
| `fixtures` | Stages the fixtures |
| `sury-runtime` | Prepares the packaged PHP runtime on `gateway` and `app-dev` |
| `converge` | Runs the full convergence sequence, which ends with reproject; see [Refresh](topology-snapshot.md#refresh) |
| `setup`, `acceptance` | Runs each action in order; with `observed_inputs`, `pcov.*` phases surround them |
| `manifest` | Builds the immutable proof-input manifest |
| `verify` | Runs general topology verification against the declared end state |

A failure while the harness creates the network and clones rolls the attempt back. Every later failure records a `diagnosis` and keeps the topology alive. The operator inspects it with `shell --proof` or `exec --proof` and runs `release --proof` before the next proof. The first nonzero exit, including `124` and `137` from a timeout, stops later actions and makes the proof a `diagnosis`. Each action runs under `timeout --signal=TERM --kill-after=5s <timeout_seconds>s`: `TERM` at the deadline, five seconds for cleanup traps, then `KILL`, with seven seconds of transport headroom.

## Runtime preparation and PCOV

PCOV is a PHP extension that records which files a process executes, and the harness uses it only inside disposable proof roles. The `sury-runtime` phase runs `observe-php.sh prepare runtime` on `gateway` and `app-dev`. It installs or upgrades the `php8.5` packages from the pinned Sury apt source and requires identical versions on both roles. It removes `/usr/local/bin/php` only when it is the base-image link to `/opt/orbit/php/8.5/bin/php` and refuses any other file there, so every entrypoint shares `/usr/bin/php8.5`.

With `observed_inputs: true`, `pcov.prepare` also installs `php8.5-pcov` at matching versions. The harness enables collection separately for `setup` and `acceptance`. Each phase must produce records from `app-dev:cli`, `gateway:cli`, and `gateway:fpm` with tracked paths below `/home/orbit/orbit`. `pcov.cleanup` removes the prepend module, restores `pcov.enabled=0`, and restarts FPM before verification. A missing surface, an untracked path, empty coverage, malformed output, or a failed cleanup makes the proof a `diagnosis`.

## Proof result

`prove --json` prints one object and writes it to `<worktree>/.e2e/proof.json`. The exit code is `0` for `proved` and `1` for `diagnosis`.

| Field | Meaning |
| --- | --- |
| `status` | `proved` or `diagnosis` |
| `issue`, `attempt_id`, `candidate_sha`, `recorded_at` | The issue, the proof attempt, the proved commit, and the UTC time |
| `plan_sha256`, `manifest_sha256` | Fingerprints of the normalized plan and of the proof-input manifest; the latter only on `proved` |
| `actions` | One `{"id","node","exit_code"}` per action that ran |
| `ends_with`, `skipped_probes` | The declaration and the probes it skipped; present only with a declared end state |
| `failed_action` | The action that ended the proof, with `stdout_tail` and `stderr_tail` of the final 4096 bytes |
| `error` | `proof phase <phase> failed: <message>` |

A proved result also writes the manifest, whose content [ADR 0015](../decisions/0015-retain-incus-proof-by-recorded-input-equivalence.md) defines, to `<worktree>/.e2e/proof-inputs/<manifest_sha256>.json`. It pins the commit at `refs/orbit/e2e-proof/<issue-lowercase>/<attempt_id>` until release. The harness never overwrites immutable evidence.

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
