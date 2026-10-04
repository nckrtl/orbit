# ADR 0197: Keep delivery-line proofs as repository commands

Orbit proves reproduction, task-group shape, pull-request head review, and post-merge live state with four read-only repository commands. The commands reuse existing task fields and `fails_on_base`. They add no proof field on a subtask.

## Status

In progress.

Principle: [Deterministic first](/mission#principles).

## Context

BugBot, the merge reviewer, and OpsBot each need a yes-or-no result they can paste. Orbit already has the task engine's `fails_on_base` run, `CreateTaskGroupRequest` rules, the merge skill's review and leftover rules, and the live `/up` plus gateway-status checks recorded in ops verified-merges. A new deliverable type, a new Gateway endpoint, or a proof field on a subtask would create a second contract beside those rules. The [Orbit Tasks policy](https://github.com/nckrtl/orbit/blob/main/.agents/skills/orbit-tasks/SKILL.md) forbids proof fields on subtasks.

`bin/review-check`, the Project task check, and [`doctor`](/cli/doctor) already print one structured result, refuse to mutate what they inspect, and say what to do next on failure.

## Decision

Ship four repository commands that print one JSON object, exit nonzero on failure, and keep side effects behind `--dry-run`:

| Command | Proof |
| --- | --- |
| `bin/bug-repro` | The given command exits nonzero on current main, with the task engine's overlay `paths` |
| `bin/task-group-check` | One ordered group matches `CreateTaskGroupRequest` plus Orbit's goal, acceptance, gate, and bug-repro rules |
| `bin/pr-head-check` | One review and Required checks match the current head, and the diff has no leftover the merge skill already names |
| `bin/deploy-verify` | Live `APP_VERSION` matches the merged SHA, `/up` is up, and gateway status is `ok` |

The commands do not file a task, merge, deploy, or roll back. Instance rollback stays the production-code selector. It is not OpsBot's production rollback after a bad deploy.

`bin/bug-repro` names current main only when the local `origin/main` or `main` SHA matches `git ls-remote origin main`. It does not fetch. A cached ref that differs is `main_stale`. `bin/deploy-verify` tells the operator to set `SSL_CERT_FILE` to Orbit's root CA when HTTPS fails certificate verification. An unreachable result from one machine is not a reason to change the live checks.

`bin/pr-head-check` flattens both a bare check-runs object and the one-element array `gh api --paginate --slurp` wraps around it. It omits `--slurp` on older `gh` that do not have the flag. A `COMMENTED` review on the current head is enough; pass does not require `APPROVED`. The leftover scan skips the detector, the leftover-refusal test, and recorded delivery-line fixtures. A leftover added in product code still fails.

## Rejected alternatives

- A `proof` field on a subtask: the Orbit Tasks policy forbids it, and `fails_on_base` already names the failing command.
- A new CLI or Gateway framework for proofs: `bin/review-check` and the task check already own this style.
- Wiring `instance:rollback` into `bin/deploy-verify`: a rollback selects one retained release and does not undo deploy steps, environment writes, or data.

## Consequences

- BugBot can reproduce on current main without creating a task.
- Operators can validate a group before `tasks-create`.
- A merge reviewer can refuse a stale head without merging.
- OpsBot can verify a deploy from the same three checks recorded in ops verified-merges, or from a recorded fixture when production is unreachable.

## Affects

- Components: apps/e2e
- ADRs: none
- Detail: [Delivery-line proofs](/reference/delivery-line)
- Verify: `bin/bug-repro --help`; the four command test files; a real JSON result from each command
