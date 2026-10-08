---
title: "Orbit self-update"
description: "The desired fleet state the Gateway serves for its commit, how orbit self-update replaces the CLI and the Node agent from it, and how the CLI learns that a newer release exists."
covers:
  - apps/gateway/app/{Domain,Data,Infrastructure}/Fleet/**
  - apps/gateway/app/Actions/Gateway/ShowDesiredFleetStateAction.php
  - apps/gateway/app/Http/Middleware/AnnounceDesiredCliVersion.php
  - apps/gateway/app/Console/Commands/ResolveDesiredFleetStateCommand.php
  - apps/cli/app/Services/SelfUpdate/**
  - packages/php-sdk/src/Requests/Gateway/ShowDesiredFleetStateRequest.php
  - packages/php-sdk/src/Responses/Gateway/{DesiredFleetStateResponse,DesiredCliReleaseResponse,DesiredAgentResponse,FleetReleaseAssetResponse}.php
---

# Orbit self-update

`orbit self-update` updates one machine to the Gateway's release. It replaces the `orbit` binary with the CLI release that CI published for the Gateway's commit. On a managed Linux Node it also replaces `orbit-agent` when the binary differs from the Gateway's pin. The Gateway serves what to install as the **desired fleet state**. The [fleet rollout](/reference/gateway-recovery#fleet-rollout) runs this command on each managed Node, and an operator runs it on a Mac.

## Desired fleet state

The desired fleet state names what every machine should run for the Gateway's commit.

| Field | Contents |
| --- | --- |
| `commit` | The full SHA of the Gateway's commit, or null when the Gateway version is not a commit |
| `cli` | The [CLI release](/reference/cli-binaries#release-contract-for-clients) of that commit: `status`, `reason`, `version`, `tag`, `checksums_url`, and one asset per platform |
| `agent` | The pinned [`orbit-agent`](/reference/node-agent#install-and-upgrade) `version` and one asset per Linux architecture |

Each asset has `platform` (`linux-x86_64`, `linux-aarch64`, or `macos-arm64`), the release asset `name`, the HTTPS download `url`, and its lowercase hex `sha256`.

```json
{
  "commit": "1f0e4c5d6b7a8c9d0e1f2a3b4c5d6e7f8a9b0c1d",
  "cli": {
    "status": "available",
    "reason": null,
    "version": "0.4681.0",
    "tag": "cli-v0.4681.0",
    "checksums_url": "https://github.com/nckrtl/orbit/releases/download/cli-v0.4681.0/SHA256SUMS",
    "assets": [
      {
        "platform": "linux-x86_64",
        "name": "orbit-0.4681.0-linux-x86_64",
        "url": "https://github.com/nckrtl/orbit/releases/download/cli-v0.4681.0/orbit-0.4681.0-linux-x86_64",
        "sha256": "79d7424eeafdc38c773b8f0a7d1b67b21e38e6abb6b458f3e42b89e2cde7a0ce"
      }
    ]
  },
  "agent": {
    "version": "0.3.0",
    "assets": [
      {
        "platform": "linux-x86_64",
        "name": "orbit-agent-0.3.0-linux-x86_64",
        "url": "https://github.com/nckrtl/orbit/releases/download/agent-v0.3.0/orbit-agent-0.3.0-linux-x86_64",
        "sha256": "f5125b2ab36abd79882b3b11eb5d40f5e457fbf23cc8bf3ff4c096e2cab4618a"
      }
    ]
  }
}
```

The example shows one asset of each list. The Gateway returns the three CLI platforms and both agent architectures.

### Where it is served

`GET /api/v1/gateway/desired-fleet-state` returns the state to any active WireGuard peer: a managed Node or an operator machine. It needs no [Node access](/cli/node#orbit-nodeaccessadd), because every managed Node reads it to update itself. Any other caller gets `peer.identity_unknown` (HTTP 403). The MCP tool is `gateway-desired-fleet-state`.

`gateway:status` returns the same object as `desired_fleet_state` to an active WireGuard peer, from the cache only. It is null until the state is resolved, and always null for any other caller. `gateway:status` still needs no WireGuard identity, and it never waits for Git or GitHub, because release verification calls it.

### How the Gateway resolves it

The Gateway builds the state from its own commit, its Git history, GitHub, and its code.

1. The commit is the Gateway version: `APP_VERSION`, or the commit of the Gateway release. A version such as `dev` is not a commit.
2. The Gateway's own Git checkout counts the commit with `git rev-list --count`. That count `N` names the release `cli-v0.N.0`. A shallow clone has no valid count.
3. GitHub confirms the release. The tag must point at the commit. The release must be published with every binary and `SHA256SUMS`.
4. Each CLI asset's `sha256` is its line in that `SHA256SUMS`. Each binary must have one line.
5. The agent part comes from the pin in the Gateway code.

The Gateway asks GitHub with a read-only token of its [GitHub App](/reference/github-app) when the App is installed on the repository, and anonymously otherwise. `ORBIT_CLI_RELEASE_REPOSITORY` in the Gateway `.env` names another repository; the default is `https://github.com/nckrtl/orbit`.

### CLI release status

The Gateway usually deploys a commit a minute after its checks pass, minutes before CI publishes its CLI release. None of these states is an error.

| `cli.status` | `reason` | Meaning |
| --- | --- | --- |
| `available` | null | Every field is set. |
| `pending` | `release_missing` | The version and tag are set, but the release is not published yet. Check again in a few minutes. |
| `unavailable` | `gateway_commit_unknown` | The Gateway version is not a commit its Git history knows. |
| `unavailable` | `history_unavailable` | The Gateway checkout is shallow or Git failed, so the version is unknown. |
| `unavailable` | `release_mismatch` | The tag points at another commit. |
| `unavailable` | `release_incomplete` | The release lacks a binary, or `SHA256SUMS` lacks a valid line. |
| `unavailable` | `github_unavailable` | GitHub did not answer or refused the request, for example at its rate limit. |

An available release is kept for its commit for 30 days, because a published release never changes. A `pending` or `unavailable` answer is kept for 60 seconds. Only the desired-fleet-state endpoint and the scheduler resolve the state. The scheduler runs `php artisan orbit:desired-fleet-state` every 5 minutes, which prints it as JSON. One caller resolves a commit at a time under a cache lock; the others wait up to 30 seconds and read its answer.

The expected footprint digest per Node joins this state when the footprint re-apply ships. A Gateway release record can store the state as it is served.

## What the command does

[`orbit self-update`](/cli/self-update) reads the desired fleet state with the machine's normal Gateway profile and runs two steps under a lock. It updates the agent first and the CLI last, for the reason in [Replace the CLI last](#replace-the-cli-last).

### Where it downloads from

The Gateway names which release to install. It never names where to download it. The CLI builds every URL from its own release location, `https://github.com/nckrtl/orbit/releases/download`:

| Asset | URL |
| --- | --- |
| CLI binary | `<location>/cli-v<version>/orbit-<version>-<platform>` |
| CLI checksums | `<location>/cli-v<version>/SHA256SUMS` |
| Agent binary | `<location>/agent-v<version>/orbit-agent-<version>-<platform>` |
| Agent checksums | `<location>/agent-v<version>/SHA256SUMS` |

The Gateway's asset `name` and `url` must equal what the CLI builds, or the step fails with `self_update.release_mismatch` or `agent.release_mismatch` before any download. The CLI downloads `SHA256SUMS` itself and reads the binary's line. That digest must equal the Gateway's `sha256`, and the download must match both. A compromised Gateway can therefore only ask for a published Orbit release. `ORBIT_SELF_UPDATE_RELEASES` on the machine itself changes the location, for a mirror you trust.

Every download runs `curl --disable` with HTTPS only, at most 5 redirects, and a size limit: 256 MiB for a binary and 64 KiB for `SHA256SUMS`. `SHA256SUMS` lands in a private temporary directory and is deleted after it is read.

### One update at a time

The command holds a lock while it runs: `/run/lock/orbit-self-update.lock` as root on Linux, `/var/run/orbit-self-update.lock` as root on macOS, and `$ORBIT_HOME/self-update.lock` for any other user. A second run waits up to 120 seconds and then fails with `self_update.busy`. Gateway steps hold the same lock:

- the [agent converge](/reference/node-agent#install-and-upgrade), from its secret check through the agent restart;
- the [fleet rollout](/reference/gateway-recovery#one-node), for its CLI install and footprint steps;
- a release's [runtime handoff](/reference/gateway-recovery#gateway-node-agent), while it updates the Gateway Node's agent.

So a Gateway step never swaps or restarts the agent while a self-update replaces it or watches its health. The converge and the rollout wait up to 300 seconds for the lock, and the handoff waits up to 120 seconds.

Each candidate gets its own name, `.<file>.orbit-candidate-<random>`, created exclusively next to its target. A candidate with that pattern can only be left by an interrupted run, so the command deletes them before it downloads.

### Update the CLI

The CLI step decides from the running binary, the release status, and the versions.

| Situation | Outcome |
| --- | --- |
| `orbit` runs from a source checkout | `skipped`, reason `source_checkout`. Update the checkout with `git pull`. |
| `orbit` is a PHAR that `php` runs | `skipped`, reason `not_a_release_binary` |
| The CLI release is `pending` | `pending`. Nothing changes. Run the command again later. |
| The CLI release is `unavailable` | `skipped`, with the Gateway's reason |
| No release binary exists for this platform | `skipped`, reason `platform_unsupported` |
| The binary already has the release's SHA-256 | `unchanged` |
| This `orbit` is a pre-release build that reports its full 40-character commit instead of `0.N.0` | Updated like an older release. The old file is kept as `orbit.orbit-previous` |
| The release is older than this `orbit`, or this `orbit` is another build that is not a release | Refused with `self_update.downgrade_refused` or `self_update.version_unknown`, unless `--allow-downgrade` is passed, or `--allow-downgrade-to` names the release's exact version |
| Otherwise | The release is installed: `updated` |

Versions compare by `N`, the commit count. A binary with the release's version but another SHA-256 is replaced, so a damaged binary is repaired.

Releases live side by side. `orbit` is a link to a versioned file in the same directory:

```text
/usr/local/bin/orbit -> orbit-0.4681.0
/usr/local/bin/orbit-0.4681.0
/usr/local/bin/orbit-0.4600.0
```

The install runs in this order. Nothing changes the `orbit` link until the last step.

1. Download the release's `SHA256SUMS` and require its line for this platform's binary to equal the Gateway's `sha256`.
2. Download the binary with `curl` to a new candidate next to the binary.
3. Check the candidate's SHA-256. A mismatch deletes the candidate and fails with `self_update.checksum_mismatch`.
4. Give the candidate the binary's mode. As root, also give it the binary's owner. Flush it to disk.
5. Run the candidate with `--version`. It must print `Orbit <version>`. Otherwise the candidate is deleted, and the step fails with `self_update.candidate_invalid`.
6. Rename the candidate to `orbit-<version>`.
7. Point `orbit` at it: create a new link and rename it over `orbit` in one step. Flush the directory.
8. Keep the release that ran before, and delete older `orbit-0.N.0` files in that directory.

A failure or an interruption before step 7 leaves `orbit` on the old release. A process that runs while the link changes keeps reading its own versioned file, so it finishes normally. The command needs write access to the directory: run it with `sudo` for `/usr/local/bin/orbit`, or it fails with `self_update.not_writable`.

The first update of a plain `orbit` file turns it into the link and keeps the old file as `orbit-<old version>`. That one swap replaces the file that running processes read. The command itself prints its result and exits at once. Another `orbit` command that runs during that swap can fail; run it again. Later updates only switch the link. A process still running from a release older than the one kept loses its file when that release is deleted.

### Update the agent

The agent step runs only on a **managed Node**: a Linux host whose `/etc/systemd/system/orbit-agent.service` starts with `# Managed by Orbit: agent`. The Gateway's agent converge writes that line, and nothing else does. On any other machine the step is `skipped` with reason `not_managed_node`.

| Situation | Outcome |
| --- | --- |
| `/usr/local/bin/orbit-agent` has the pinned SHA-256 | `unchanged`. The agent is not restarted. |
| It differs or is missing, and the command does not run as root | `skipped`, reason `root_required` |
| It differs or is missing, and the command runs as root | Replaced and restarted: `updated` |

The replacement confirms the pin against the agent release's `SHA256SUMS`, as for the CLI. It keeps the current binary as `orbit-agent.orbit-previous`. It then downloads a candidate, checks its SHA-256, sets owner `root:root` and mode `0755`, and renames it over the binary. A mismatch fails with `agent.checksum_mismatch` and changes nothing.

After `systemctl restart orbit-agent.service`, the agent must stay `active` for 5 seconds without systemd restarting it: `NRestarts` must not change. When the restart fails or the agent does not stay up, the command moves the kept binary back, restarts the agent, and fails with `agent.restart_failed` or `agent.unhealthy`. The message says whether the previous agent runs again. The agent's configuration, certificate, secret, and unit stay with the Gateway's agent converge.

When the agent step fails, the CLI step does not run. It reports `skipped` with reason `previous_step_failed`.

### Result

The command prints one result. Its top-level `outcome` is the first that applies:

| `outcome` | When | Exit |
| --- | --- | --- |
| `failed` | A step failed. | 1 |
| `incomplete` | A step that applies here could not run: the CLI release is `unavailable`, or the agent needs root. Nothing failed, but the machine is not up to date. | 0 |
| `pending` | The CLI release is not published yet. Run the command again in a few minutes. | 0 |
| `updated` | A step changed something. | 0 |
| `unchanged` | Everything is already current, or a step does not apply here: a source checkout, a PHAR, an unsupported platform, or a machine that is not a managed Node. | 0 |

Human output ends with `Update failed.`, `Update incomplete.`, `Waiting for the CLI release.`, `Orbit updated.`, or `Orbit is up to date.` in the same order. A failure before the first step, such as `gateway.unreachable`, `gateway.profile_missing`, or `self_update.busy`, prints the usual error envelope instead.

| Field | Meaning |
| --- | --- |
| `gateway` | The profile name |
| `commit` | The Gateway's commit |
| `outcome` | `updated`, `unchanged`, `pending`, `incomplete`, or `failed` |
| `steps` | `agent`, then `cli`, in the order they ran, each with `outcome` (`updated`, `unchanged`, `pending`, `skipped`, or `failed`), `reason`, `path`, `before`, `after`, and `error` |
| `request_id` | The Gateway request ID |

`before` and `after` each hold `version` and `sha256`. An agent `before.version` is null unless the binary matches the pin, because a binary does not report its own version. The CLI `path` is the `orbit` link. `error` holds `code` and `message`, or null.

## Running it on a Node

On a managed Node, run `sudo orbit self-update --json`. Root reads its own Gateway profile from `$ORBIT_HOME/config.json`, which is `/root/.orbit/config.json` under `sudo`. The profile needs:

- a profile URL that reaches the Gateway over WireGuard, such as `https://10.44.0.1`;
- `ca_path` set to a file with the Orbit root certificate, such as `/etc/orbit/agent/ca.pem`, which the agent converge writes;
- the profile as the active profile.

The Gateway identifies the Node by the WireGuard address the request comes from, as it does for every CLI request. The file must belong to root with mode `0600`, as [Gateway trust](/reference/gateway-trust#profiles) requires.

## The newer-release notice

Every CLI request sends `X-Orbit-Client-Version` with the CLI version. When an active WireGuard peer sends it and the Gateway has resolved an available CLI release, the response carries `X-Orbit-Cli-Version` with that release. The Gateway reads it from the cached state only, so no request waits for Git or GitHub. The header is absent while the release is `pending` or `unavailable`.

After a command, when that version is newer than the running release binary, the CLI prints one line on standard error:

```text
Orbit 0.4681.0 is available (this is 0.4600.0). Run orbit self-update.
```

The CLI prints it at most once every 24 hours, and only when standard error is a terminal and `CI` is unset. It keeps the time of the last notice in `$ORBIT_HOME/self-update-notice.json`, mode `0600`. It never prints the notice in JSON mode, from a source checkout or other build that is not a release, or when it cannot record the time. `self-update` prints no notice.

## Limits

`orbit self-update` has these limits.

- It replaces only the CLI and, on a managed Linux Node, the agent. The fleet rollout re-applies the Gateway-rendered footprint over SSH.
- Nothing runs it on an operator machine automatically. The operator runs it after the notice.
- No agent signal triggers it.

[Update and recover a Gateway: Limits](/reference/gateway-recovery#limits) lists what is not built yet.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### The version comes from the Gateway's commit

The CLI release of a commit is named by the commit count, so the Gateway counts its own deployed commit. A version string from a source checkout comes from `git describe` and can name an unrelated tag. Counting the commit gives the one release that belongs to the code the Gateway runs.

### A pending release is not an error

The Gateway deploys before CI publishes the CLI release. Reporting that as a failure would fail every rollout for a few minutes after each release. `pending` lets a caller wait and try again, and it never installs another version instead.

### Every active peer may read the state

A managed Node updates itself from this state whether or not it has access to the Gateway. The state holds only public release names and checksums, so a peer needs no access edge to read it.

### Replace by rename after every check

A binary that is overwritten in place is broken while the write runs, and a crash leaves it broken. A candidate next to the binary is checked first, and one rename moves it into place. The old binary works until that rename, and the rename either happens whole or not at all.

### Build the download URLs on the machine

The Gateway decides which release the fleet runs, but a compromised Gateway must not choose what code a Node or an operator machine runs as root. The CLI therefore builds every URL from its own release location and reads the checksum from the release's own `SHA256SUMS`. The Gateway's checksum must agree. The worst a Gateway can then do is name another published Orbit release, which `--allow-downgrade` guards.

### Run the candidate before the swap

A checksum proves the download matches the release. It does not prove the binary starts on this machine. Running `--version` before the swap keeps a binary that cannot start from replacing one that can.

### Replace the CLI last

A standalone binary is a static PHP with the CLI appended as a PHAR, and it reads its own code from its path as it runs. A process whose file is replaced by a rename fails with exit 255 at its next class load. That is why releases live side by side behind a link: a running process keeps its versioned file, which the update does not touch. A test with real binaries confirmed it: an `orbit` command running across a link switch finished normally three times out of three, and across a rename it failed every time.

The first update of a plain file still replaces the running file. So the command updates the agent first, loads what it still needs before the swap, and exits right after it prints the result. The same path rule explains why the CLI hashes its own binary through `<directory>/./orbit`: the binary reads its exact path as the appended PHAR, not as the whole file.

### Keep the previous agent

A new agent that does not start would leave the Node without presence or log streams until someone acts. Keeping the previous binary lets the command put the working agent back at once, and `NRestarts` shows a crash loop that a single `is-active` check can miss.

### Download with curl

The static PHP inside a release binary does not always find the system trust store. The system `curl` does, and the Gateway's agent converge already uses it on every Node.
