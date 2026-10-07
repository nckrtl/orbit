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

`orbit self-update` updates one machine to the Gateway's release. It replaces the `orbit` binary with the CLI release that CI published for the Gateway's commit. On a managed Linux Node it also replaces `orbit-agent` when the binary differs from the Gateway's pin. The Gateway serves what to install as the **desired fleet state**. [ADR 0202](/decisions/0202-the-fleet-follows-the-gateway-through-orbit-self-update) describes the fleet rollout that runs this command on each Node.

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

`gateway:status` returns the same object as `desired_fleet_state` to an active WireGuard peer. For any other caller the field is null, and `gateway:status` still needs no WireGuard identity.

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

An available release is kept for its commit for 30 days, because a published release never changes. A `pending` or `unavailable` answer is kept for 60 seconds. The scheduler resolves the state every 5 minutes with `php artisan orbit:desired-fleet-state`, which prints it as JSON.

The expected footprint digest per Node joins this state when the footprint re-apply ships. A Gateway release record can store the state as it is served.

## What the command does

[`orbit self-update`](/cli/self-update) reads the desired fleet state with the machine's normal Gateway profile and runs two steps. It updates the agent first and the CLI last, for the reason in [Replace the CLI last](#replace-the-cli-last).

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
| The release is older than this `orbit`, or this `orbit` is not a release | Refused with `self_update.downgrade_refused` or `self_update.version_unknown`, unless `--allow-downgrade` is passed |
| Otherwise | The binary is replaced: `updated` |

Versions compare by `N`, the commit count. A binary with the release's version but another SHA-256 is replaced, so a damaged binary is repaired.

The replacement runs in this order. Nothing touches the binary until the last step.

1. Download the release's `SHA256SUMS` and require the line for this platform's binary to match the Gateway's `sha256`.
2. Remove a candidate that an interrupted run left behind, `<binary>.orbit-candidate`.
3. Download the binary with `curl` to that candidate, next to the binary.
4. Check the candidate's SHA-256. A mismatch deletes the candidate and fails with `self_update.checksum_mismatch`.
5. Give the candidate the binary's mode. As root, also give it the binary's owner. Flush it to disk.
6. Run the candidate with `--version`. It must print `Orbit <version>`. Otherwise the candidate is deleted, and the step fails with `self_update.candidate_invalid`.
7. Rename the candidate over the binary in one step, and flush the directory.

A failure or an interruption before step 7 leaves the old binary in place and working. After the rename, the command prints its result and exits at once. The command needs write access to the directory of the binary: run it with `sudo` for `/usr/local/bin/orbit`, or it fails with `self_update.not_writable`.

### Update the agent

The agent step runs only on a **managed Node**: a Linux host whose `/etc/systemd/system/orbit-agent.service` starts with `# Managed by Orbit: agent`. The Gateway's agent converge writes that line, and nothing else does. On any other machine the step is `skipped` with reason `not_managed_node`.

| Situation | Outcome |
| --- | --- |
| `/usr/local/bin/orbit-agent` has the pinned SHA-256 | `unchanged`. The agent is not restarted. |
| It differs or is missing, and the command does not run as root | `skipped`, reason `root_required` |
| It differs or is missing, and the command runs as root | Replaced with the steps of the [agent converge](/reference/node-agent#install-and-upgrade), then `systemctl restart orbit-agent.service`: `updated` |

The replacement downloads `orbit-agent.orbit-candidate`, checks its SHA-256, sets owner `root:root` and mode `0755`, and renames it over the binary. A mismatch fails with `agent.checksum_mismatch` and changes nothing. A failed restart after the rename fails with `agent.restart_failed`; the new binary is then in place. The agent's configuration, certificate, secret, and unit stay with the Gateway's agent converge.

When the agent step fails, the CLI step does not run. It reports `skipped` with reason `previous_step_failed`.

### Result

The command prints one result. The top-level `outcome` is `failed` when a step failed, else `pending` when the CLI release is pending, else `updated` when a step changed something, else `unchanged`. The command exits 1 only for `failed`. A failure before the first step, such as `gateway.unreachable` or `gateway.profile_missing`, prints the usual error envelope instead.

| Field | Meaning |
| --- | --- |
| `gateway` | The profile name |
| `commit` | The Gateway's commit |
| `outcome` | `updated`, `unchanged`, `pending`, or `failed` |
| `steps` | `agent`, then `cli`, in the order they ran, each with `outcome`, `reason`, `path`, `before`, `after`, and `error` |
| `request_id` | The Gateway request ID |

`before` and `after` each hold `version` and `sha256`. An agent `before.version` is null unless the binary matches the pin, because a binary does not report its own version. `error` holds `code` and `message`, or null.

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

The CLI prints it at most once every 24 hours. It keeps the time of the last notice in `$ORBIT_HOME/self-update-notice.json`, mode `0600`. It never prints the notice in JSON mode, from a source checkout or other build that is not a release, or when it cannot record the time. `self-update` prints no notice.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### The version comes from the Gateway's commit

The CLI release of a commit is named by the commit count, so the Gateway counts its own deployed commit. A version string from a source checkout comes from `git describe` and can name an unrelated tag. Counting the commit gives the one release that belongs to the code the Gateway runs.

### A pending release is not an error

The Gateway deploys before CI publishes the CLI release. Reporting that as a failure would fail every rollout for a few minutes after each release. `pending` lets a caller wait and try again, and it never installs another version instead.

### Every active peer may read the state

A managed Node updates itself from this state whether or not it has access to the Gateway. The state holds only public release names and checksums, so a peer needs no access edge to read it.

### Replace by rename after every check

A binary that is overwritten in place is broken while the write runs, and a crash leaves it broken. A candidate next to the binary is checked first, and one rename swaps it in. The old binary works until that rename, and the rename either happens whole or not at all.

### Run the candidate before the swap

A checksum proves the download matches the release. It does not prove the binary starts on this machine. Running `--version` before the swap keeps a binary that cannot start from replacing one that can.

### Replace the CLI last

A standalone binary is a static PHP with the CLI appended as a PHAR, and it reads its own code from its path as it runs. After the rename, that path holds the new binary, so the old process cannot load more code. The command therefore updates the agent first, loads what it still needs before the swap, and exits right after it prints the result. The same path trick explains why the CLI hashes its own binary through `<directory>/./orbit`: the binary reads its exact path as the appended PHAR, not as the whole file.

### Download with curl

The static PHP inside a release binary does not always find the system trust store. The system `curl` does, and the Gateway's agent converge already uses it on every Node.
