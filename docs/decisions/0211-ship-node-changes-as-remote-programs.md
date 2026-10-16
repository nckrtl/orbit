---
title: "ADR 0211: Ship Node changes as remote programs"
sidebarTitle: "0211 Remote programs"
description: "In progress. Each script that the Gateway runs on a Node becomes a named program file with a JSON contract and named outcomes. One runner interface sends it, CI lints it, and tests run it directly."
---

# ADR 0211: Ship Node changes as remote programs

Every script that the Gateway runs on a Node moves out of PHP strings into a program file with a documented contract. One runner interface sends a program and returns its named outcome. CI lints every program, and tests run programs on their own. A ratchet keeps inline scripts from growing back.

## Status

In progress.

Principle: [Deterministic first](/mission#principles). Lint and direct tests decide what code can decide about Node-side code, and named outcomes replace exit codes that a reader must decode. It also serves [One way, one name](/mission#principles): one runner, one contract, and one stable name for each program. No principle exception is proposed.

## Context

The Gateway changes Nodes over SSH. About 210 scripts live in PHP nowdocs in 97 files under `apps/gateway/app`: 180 in Bash and 23 in Python. `RemoteCommand` sends each one on standard input to `bash -seu --` with escaped positional arguments. That delivery is safe, because nothing interpolates into the script. The rest has costs:

- CI cannot lint the scripts. No job runs shellcheck or ruff, and only a few tests run `bash -n`.
- Scripts are assembled from fragments at run time, such as `WorkspaceGit::bashPreamble()` and the removal's shared shell functions. No file shows the program that runs.
- Contracts are implicit: `$1` to `$11`, `shift 8`, and exit codes such as 10 to 15, 20, and 21 in the development source removal. About 47 PHP checks compare exit codes.
- A script cannot be tested on its own. Tests reach it only through the PHP class that builds it.

A better pattern already exists. `resources/tasks/check` and `resources/instances/lifecycle.py` are files with a documented interface that read JSON and print a structured result.

The Docker provider migration follows this decision as `docker.migrate` under `resources/remote/docker/`. It declares a timeout and the `planned`, `prepared`, `reported` and `failed` outcomes. `SshProgramRunner` sends its input on stdin. Preparation runs in a transient unit so an SSH disconnect cannot interrupt dpkg. Recipe convergence and migration share the apt and source-retirement code in `resources/remote/lib/recipe.py`; the migration does not assemble its own scripts in PHP.

The [Node agent](/reference/node-agent#the-agent-only-observes) only observes. SSH stays the only way to change a Node. A planned agent runner must fit the same contract.

## Decision

The Gateway owns every remote program, its runner, and its checks. The parts below form one contract.

### One file per program

Each program is one file under `apps/gateway/resources/remote/<area>/<name>.{sh,py}`. Its stable name is `<area>.<name>`, such as `instances.inspect-source`. `resources/` already holds the existing programs, and `remote/` separates programs that run on a Node from configuration templates and assets.

A header comment opens each file. It names the program, the libraries it uses, its outcomes, its input, its result, and its timeout. Shared code lives in `resources/remote/lib/`. One loader reads the header, prepends the libraries in the listed order, and computes the digest of the assembled source. Tests and the lint use the same assembly.

### Language by size

A short pass/fail script, roughly under 50 lines with a few arguments, stays Bash with positional arguments. Its outcome is `succeeded` or `failed`.

A program with branching or a structured result is Python 3.10 or newer, which every Ubuntu Node has. The bootstrap refuses an older Python with a message that names the minimum. It reads one JSON object on standard input and prints one JSON result on standard output:

```json
{"outcome": "path_mismatch", "data": {}}
```

Log lines go to standard error. A named outcome replaces each numeric exit code. A refusal is an outcome, not a crash, so the program exits 0 whenever it prints a result.

### A contract that does not depend on SSH

A program is a stable name, a content digest, JSON input, a JSON result with a named outcome from the declared list, log lines, a timeout, and cancellation. Nothing in the contract depends on SSH.

Input that carries a secret is marked as such. A Git read credential travels in the input under `git_read` and is marked as a Git credential, so the [task VM guard](/reference/compute-drivers#git-on-a-task-vm) still refuses it.

### One runner interface

`RemoteProgramRunner::run(Node, RemoteProgram, input)` returns a `ProgramResult` with the outcome and its data. An outcome that the program does not declare, a missing or malformed result, a timeout, and a cancellation raise `RemoteProgramFailed`. Callers never see the transport. They match the outcome and map it to their own error codes.

`SshProgramRunner` is the only implementation now. It uses the existing SSH executor and the Node's managed connection.

### Send each program with each call

The runner sends the assembled program on standard input with every call. A Python program starts as `python3 -I -c <bootstrap> <name>`. The bootstrap reads the program and the JSON input from standard input, separated by one NUL byte. A Bash program runs as `bash -seu -- <arguments>`. Nothing is installed on a Node, so a Gateway and a Node cannot disagree about a program's version. Programs work on a fresh Node and on a task VM.

### CI lint and direct tests

CI runs shellcheck on Bash and ruff on Python for every program in `apps/gateway/resources`, the existing ones included. Each finding is fixed or suppressed in place with a reason. Programs that use libraries are linted as assembled. Tests run programs directly against temporary directories, with the Python standard library's `unittest`. `bin/remote-programs-check` runs the lint and these tests. A CI job runs it, and `bin/review-check` runs it when a candidate changes a program.

### A ratchet on inline scripts

An architecture test lists the files under `apps/gateway/app` and `apps/gateway/extensions` that may still contain a nowdoc or heredoc labelled `BASH`, `PY`, `PYTHON`, `SH`, `SHELL`, or `AWK`. A file outside the list fails, and a listed file without one fails too. So a migration must shrink the list, and a new inline script needs a reviewed exception. Caddy, configuration, and text templates are configuration and stay out of scope.

### The first migration

The development source removal moves first, because it deletes source checkouts and its safety checks were hardest to read. Its nine inline scripts become eight Python programs with named outcomes and one shared library. Every check stays: path, ownership, layout, origin, branch, worktrees, dirty and unpublished content, and a source that changed between inspection and deletion. Direct tests and the existing lifecycle tests prove them.

### A planned agent runner

A second runner, `AgentProgramRunner`, is planned. It is not built now. It would let `orbit-agent` run a program, and it needs its own ADR because it reverses the agent's rule that it never changes a Node. The design now keeps these constraints open for it:

- The agent pulls jobs from the Gateway over WireGuard with its per-Node secret. It stays outbound-only and listens on no port.
- The agent runs only a program whose digest is in a manifest from the current Gateway release. It never runs arbitrary shell.
- Results return through the agent's existing reporting endpoints.
- A frequent program may get a native Rust implementation in the agent, with the same name and contract.

## Rejected alternatives

- Install programs on Nodes and run them by name: a Gateway release and a Node would disagree after an update, and a fresh Node or task VM would need an install step first.
- Keep inline scripts and only lint them: PHP would still assemble fragments and decode exit codes, and no test would run a script alone.
- Pass Python programs with `python3 -c <program>`: the program would travel in the command line, which has a size limit and shows in the process list. Standard input carries both the program and its input.
- Rewrite every script in Python: short pass/fail scripts read better in Bash, and a rewrite adds risk without benefit.
- Build the agent runner now: it changes the agent's security boundary and needs its own decision.

## Consequences

- A reviewer reads each program as one file with its contract in the header.
- CI catches shell and Python errors in Node programs before they run.
- A Python program costs one interpreter start, about 30 milliseconds, per call.
- The removal programs need `php` on the Node for the origin identity rule, as the Bash script did.
- Each remaining inline script moves in its own pull request. `RemotePhpPackageManager` is next.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: none
- Detail: [Remote programs](/reference/remote-programs) and [Instance removal](/reference/instance-removal)
- Verify: `bin/remote-programs-check`, `tests/Unit/Architecture/InlineRemoteScriptTest.php`, `tests/Feature/Infrastructure/Instances/RemoteDevelopmentInstanceSourceLifecycleTest.php`, and the `RemotePrograms` tests
