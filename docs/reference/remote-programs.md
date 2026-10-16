---
title: "Remote programs"
description: "How the Gateway ships a program to a Node: one file per program, a JSON contract with named outcomes, one runner interface, lint, direct tests, and the ratchet on inline scripts."
covers:
  - apps/gateway/app/Domain/RemotePrograms/**
  - apps/gateway/app/Infrastructure/RemotePrograms/**
  - apps/gateway/resources/remote/{bootstrap.py,lib/program.py,lib/production-shared-paths.sh,instances/refresh-environment-config.py}
  - apps/gateway/ruff.toml
  - apps/gateway/tests/Programs/{harness,test_program}.py
  - apps/gateway/tests/Unit/Architecture/InlineRemoteScriptTest.php
  - bin/remote-programs-check
---

# Remote programs

A remote program is a file that the Gateway runs on a Node to inspect or change it. Each program has a stable name, a documented input, and a result with a named outcome. The Gateway sends the program with every call, so nothing is installed on the Node. [ADR 0211](/decisions/0211-ship-node-changes-as-remote-programs) moves the Gateway's inline scripts into this form, one area at a time.

## Program files

Each program is one file under `apps/gateway/resources/remote/<area>/<name>.py` or `<name>.sh`. Its name is `<area>.<name>`, such as `instances.inspect-source`. Shared code lives in `apps/gateway/resources/remote/lib/`.

A header of comment lines opens each program:

```python
# Program: instances.check-publication
# Uses: lib/program.py lib/source-removal.py
# Outcomes: published unpublished failed
# Timeout: 900
# Input: {"origin": "...", "commit": "...", "git_read": {...}}
# Result: no data.
# Checks whether a current branch or tag of the origin contains the commit.
```

| Line | Meaning |
| --- | --- |
| `Program` | The program's name. It must match the file path. |
| `Uses` | Libraries from `lib/`, in order. Optional. |
| `Outcomes` | Every outcome the program can return. Python programs only. |
| `Timeout` | The longest run in seconds. A caller can ask for less. |
| `Input`, `Result` | The contract, for a reader. |

The loader, `RemoteProgramCatalog`, reads the header and puts the listed libraries in front of the program. The result is the assembled source. Its SHA-256 is the program's digest. A header that names another program, an unknown library, or no timeout fails to load.

## Python programs

A program with branching or a structured result is Python. Programs need Python 3.10 or newer, because they use `os.path.realpath(strict=True)`. Every supported Ubuntu Node has it. It reads one JSON object from standard input. It prints one JSON result on one line of standard output:

```json
{"outcome": "path_mismatch", "data": {}}
```

`outcome` is one of the names on the `Outcomes` line. `data` is an object whose fields the program's header describes. Log lines go to standard error. A refusal is an outcome, so the program exits 0 whenever it prints a result. An exception or a signal ends the program without a result.

`lib/program.py` holds this runtime. A program defines `main(payload)`, which returns an outcome and its data or raises `Outcome`, and ends with `run_program(main)`. `SIGTERM`, `SIGHUP`, and `SIGINT` end the program at once, so a cancelled call stops on the Node too.

The Gateway starts a Python program as `python3 -I -c <bootstrap> <name>`. The bootstrap, `resources/remote/bootstrap.py`, reads the assembled source, one NUL byte, and the JSON input from standard input. It compiles the source under the program's name, so a traceback names the program. `-I` keeps environment variables, user site packages, and the current directory out of the interpreter. On an older Python, the bootstrap exits 1 before it runs the program and prints `<name>: Orbit remote programs need Python 3.10 or newer`, so the call fails with `no_result` and that log.

## Bash programs

A short pass/fail script stays Bash. Its input is a list of strings, its positional arguments. The Gateway runs it as `bash -seu -- <arguments>` with the assembled source on standard input. Exit status 0 is the outcome `succeeded`, with standard output in `data.output`. Any other status is `failed`, with `data.exit_code` and `data.output`.

## The runner

`RemoteProgramRunner` is the only way to run a program:

```php
$result = $runner->run($node, $catalog->program('instances.inspect-source'), [...]);
```

It returns a `ProgramResult` with `outcome`, `data`, and `log`, the program's standard error. These cases raise `RemoteProgramFailed`, with a `reason`:

| Reason | Cause |
| --- | --- |
| `unreachable` | The Node has no WireGuard address. |
| `no_result` | The program exited with another status than 0, or the connection failed. |
| `invalid_result` | Standard output is not one JSON object with a string `outcome` and an object `data`. |
| `undeclared_outcome` | The outcome is not on the program's `Outcomes` line. |
| `timed_out` | The program ran past its timeout. |
| `cancelled` | The caller's cancellation check returned true. |

A caller matches the outcome and maps it to its own error codes. It never sees the transport. When an outcome or a failure fails the caller's step, the caller keeps the program's log as the step's command output, so the command's Activity shows it in `stderr`.

`SshProgramRunner` implements the runner over SSH. It connects to the Node's WireGuard address as the Node's user, with the Gateway's key and pinned host keys, and sends the program on standard input. A call can pass a shorter timeout than the program's and a cancellation check.

An internal caller may select a validated Unix account through the catalog's optional `user` argument. The runner prefixes that program's command with `sudo -n -u <user> -H`; SSH still connects as the enrolled account. This is not a caller-supplied API option. Project metadata inspection uses the Instance account on production, so version files are read with application permissions rather than as the store owner.

### Git read credentials

A program that reads a private repository receives the [Git read environment](/reference/github-app#the-checkout-cannot-inherit-the-token) in its input under `git_read`, a map of environment variables. The program sets them only for the command that reads the origin. Input with a credential travels as protected input and is marked as a Git credential, so it never reaches a task VM ([Git on a task VM](/reference/compute-drivers#git-on-a-task-vm)). Bash programs take no credential.

## Lint and direct tests

`bin/remote-programs-check` runs from the repository root. It checks every program under `apps/gateway/resources`, the older files outside `remote/` included:

- shellcheck checks each Bash file.
- ruff 0.17.0 checks each Python file and the direct tests with the rules in `apps/gateway/ruff.toml`. A program with libraries is checked as assembled, and a finding names the file and line where it occurs.
- `python3 -m unittest` runs the direct tests in `apps/gateway/tests/Programs`. They run programs with the same assembly and bootstrap against temporary directories.

A finding is fixed, or suppressed on its line with a comment that gives the reason. The `Remote programs` CI job installs shellcheck and ruff and runs the check. The [candidate gate](/reference/implementation-loop#the-candidate-gate) runs it when a candidate changes `apps/gateway/resources`, `apps/gateway/tests/Programs`, `apps/gateway/ruff.toml`, or the check itself. A missing tool fails the check.

## Inline scripts

`tests/Unit/Architecture/InlineRemoteScriptTest.php` lists every file under `apps/gateway/app` and `apps/gateway/extensions` that still holds a nowdoc or heredoc labelled `BASH`, `PY`, `PYTHON`, `SH`, `SHELL`, or `AWK`. A file outside the list fails the test. A listed file without such a script fails too, so a migration removes its file from the list. The list only shrinks. Caddy, configuration, and text templates are configuration, not programs, and are out of scope.

The existing production deployment program loads `lib/production-shared-paths.sh` to prepare [shared directories](/reference/deployments#shared-paths). This Bash library takes explicit arguments and runs within the existing deployment command while that command awaits migration. Shellcheck and the deployment filesystem tests check it.

## Programs

These programs run on Nodes now. Each one replaced an inline script, except `database-servers.write-valkey-acl`, which is new. The PHP step of an Instance sends `php.install-build` through its own SSH executor and reads the result with the runner's result contract, `SshProgramRunner::pythonResult`. Gateway PHP-FPM convergence runs `gateway.install-start-hooks` on the Gateway itself, as `sudo bash -seu -- <arguments>`.

| Program | Purpose |
| --- | --- |
| `instances.inspect-source` | Reads a development checkout's Git evidence for [Instance removal](/reference/instance-removal#checks-before-removal). |
| `instances.check-publication` | Checks whether an origin branch or tag contains a commit. |
| `instances.refresh-environment-config` | Rebuilds an existing current release config cache as the production Instance user, verifies it through the application's Artisan bootstrap, and atomically replaces it before env-sync resets FPM opcache. |
| `instances.remove-source` | Checks and deletes an active checkout in one run. |
| `instances.prepare-source-removal` | Records the removal journal before finalization. |
| `instances.read-source-removal-state` | Reports whether a recorded source is present, quarantined, or removed. |
| `instances.read-removal-receipt` | Reports how complete a receipt-backed removal is. |
| `instances.clean-removal-receipt` | Finishes a receipt-backed removal. |
| `instances.finalize-source-removal` | Moves a recorded source into quarantine and deletes it. |
| `gateway.install-start-hooks` | Installs the Gateway's PHP-FPM [start hooks](/reference/extensions#plug-in-points) and removes each listed hook that no extension contributes. |
| `php.install-build` | Installs and checks the recipe's [PHP build](/reference/compute-drivers#install-the-php-build), with a rollback. |
| `database-servers.write-valkey-acl` | Writes the ACL file of a [Valkey Database server](/reference/database-servers#a-valkey-server) into its volume through a short-lived container. |

## Why it works this way

The decision is still in progress. [ADR 0211](/decisions/0211-ship-node-changes-as-remote-programs) holds its reasons and rejected alternatives until the remaining inline scripts move.
