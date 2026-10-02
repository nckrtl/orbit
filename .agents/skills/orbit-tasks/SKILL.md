---
name: orbit-tasks
description: Use when preparing, implementing, or reviewing an Orbit task group.
---

# Orbit Tasks

The Gateway Tasks engine is generic; this file defines Orbit's repository policy. Orbit's Project task check enforces repository-specific requirements deterministically.

## Prepare the feature contract

When implementing a subtask in Orbit's allocated environment, you may create, modify, reset, and delete disposable fixtures, including Routes and publications, without asking for permission. Verify task ownership and the target environment before deletion, use the required CLI confirmation flags, and follow the environment's lease and cleanup rules. This permission does not extend to live or shared resources or another task's fixtures.

Start after [grill-with-docs](../grill-with-docs/SKILL.md), once the behavior is agreed and the ADRs and documentation are written on the group's branch. They are the contract that every subtask implements. Read the group's brief, ADRs, maintained documentation, and relevant code before splitting work. Look for prefactoring that makes the feature easier to build; put it first as its own subtask.

Every group starts with a docs subtask, before any implementation subtask. Run the deterministic impact check against the group's start commit and every planned path named in the brief, including paths that do not exist yet:

```bash
bin/docs-impact --base <start-commit> --paths <planned-path> --paths <another-planned-path>
```

The start commit is the merge base with `origin/main`. Repeat `--paths` for every planned path. For `docs_required`, update the impacted pages or run the named generator. For `no_docs_change`, provide the complete JSON report as the subtask deliverable; the reviewer must confirm that the report and planned paths are complete before implementation begins. Follow the [impact-check contract](../../../docs/contributor-guide.md#2-write-the-documentation) in the contributor guide for the report and handoff rules.

Use the docs subtask to write or update the relevant maintained documentation when the report requires it. Record a significant decision in an ADR with the status `In progress.`, following `docs/decisions/README.md`. The ADRs and documentation changed against the group's base commit are the feature contract for later subtasks.

Plan the absorption into the last subtask that completes a decision, with a `file` deliverable for the absorbing page. That subtask writes the behavior and lasting reasons into the owning page, deletes the ADR, and adds its redirect and "Retired decisions" row. An implementer absorbs an ADR only when its deliverables name that page. A decision that spans several groups keeps its ADR until the group that completes it.

Keep the branch's documentation, ADRs, implementation, tests, and task briefs consistent. Focus the group on one feature and order subtasks by dependency.

## Split the work

Give each subtask one concise goal that an implementer can finish and a reviewer can verify in one turn. Keep each subtask independently verifiable and limit it to at most five deliverables. Split work that needs more than five.

Name contract documents and paths in a brief when the contract requires them. Leave implementation paths to the implementer otherwise. A subtask's brief should state its goal, contract, dependencies, deliverables, and acceptance checks.

Prefer a narrow vertical slice that a reviewer can verify from start to finish over a horizontal slice of one layer. When behavior spans several projects, split by project only when each project's tests prove its side of the contract. For a wide refactor that mechanically breaks many call sites, sequence separate expand, migrate, and contract subtasks: add the new form beside the old, move callers in batches, then remove the old form after no callers remain.

Subtasks never carry proofs, proof scripts, or proof deliverables. Behavior tests and the review accept a subtask. The implementer may use the group's Incus topology for discovery, and the reviewer reproduces the feature on it; see [using-incus-topologies](../using-incus-topologies/SKILL.md). A check on shared machines, such as beast or the live Gateway, needs merged, deployed code, so list it in the PR as an operator step after deploy.

Keep CI, release, and deployment work separate from product code. Subtasks run in dependency order on one shared branch; a later subtask may build on an earlier one but never finishes its work.

A subtask that changes the web UI must include a screenshot `review` deliverable for the phone and desktop PNGs produced by `bin/web-verify`. The reviewer judges the phone layout as described in [Verifying web UI](../verifying-web-ui/SKILL.md): content starts near the top, controls are reachable, filters are not an awkward stack, and long lists and filters use native patterns such as infinite scroll and sheets. Reading the diff is not a substitute for this review. Count the screenshot deliverable toward the five-deliverable limit.

## Bugs

The first code-changing subtask in a bug group reproduces the failure before the fix. Add a `command` deliverable with `fails_on_base` set to the JSON boolean `true` and `paths` listing the working-tree files needed for the base run. The command must exit nonzero on the subtask's start commit and zero on the working tree. The command itself applies Project-specific matching, such as selecting a test by name; the engine checks only the exit status. A docs-only subtask does not count as the first code-changing subtask. The [tasks reference](../../../docs/reference/tasks.md#prove-a-command-fails-on-the-start-commit) defines the two runs.

Use a deliverable like this in that subtask's `deliverables` array:

```json
[
  {"id": "layout-repro", "type": "command", "description": "The home-screen regression fails before the fix", "command": "vendor/bin/pest tests/Feature/HomeScreenTest.php --filter='home screen layout'", "directory": "apps/gateway", "fails_on_base": true, "paths": ["apps/gateway/tests/Feature/HomeScreenTest.php"]}
]
```

When the bug cannot be reproduced automatically, for example an iOS behavior that shows up only on a device, say so in the brief. Add a `review` deliverable for the manual check, and do not set `fails_on_base`.

## Review the breakdown

Before moving the group to Todo, present the breakdown to the operator as a numbered list. For each subtask show its title, what it builds on, its goal from the user's point of view, and its deliverables. Ask whether the granularity is right, whether each dependency is real, and whether any subtask should be merged or split. Iterate until the operator approves.

## Write the briefs

Use this template for every subtask brief:

```markdown
**Goal:** the behavior this subtask makes work.

**Contract:** the ADR sections and documentation pages it implements, with anchors.

**Builds on:** the earlier subtasks it depends on, or "None".

**Deliverables:**
- One observable result per line, such as a behavior, an endpoint, a test that proves it, or a check that passes.
- For a web UI change, a screenshot `review` deliverable. The reviewer opens the phone and desktop PNGs and judges the phone.

**Acceptance:** the checks to run and what they must show.
```

Every subtask that changes the web UI gets the screenshot `review` deliverable, even when UI work is only part of the subtask. Its description names the phone and desktop PNGs from `bin/web-verify` and the phone judgment described above.

Name file paths only where the contract fixes them, such as an install path or documentation page. Leave other paths and code to the implementer, because they go stale.

## Deliverables and handoff

Use typed deliverables to describe observable results. The current engine supports `file`, `command`, and `review`; follow the [Tasks reference](../../../docs/reference/tasks.md#deliverables) for fields and verification. Prefer one deliverable for each explicit result promised in the brief. Measurement and investigation claims must be `command` deliverables, so Orbit's handoff check records the command output. Evidence at handoff says where or how each deliverable is met.

Do not assume that every Project uses Composer, Pest, PHP, or any particular test runner. Use the task check configured for the Orbit Project and the deliverable type supported by the current engine.

## Implement a subtask

- Keep handoff evidence, such as impact reports, logs, and check output, under `.orbit-artifacts/`. Git ignores it. Do not commit evidence.
- Build test fixtures from real output. Capture it from the real tool or its source, and do not invent it.
- Make every new test able to fail. Confirm that it fails without the change.
- When the handoff check fails, read the failed step's log and fix the cause before you hand off again.

## Review a subtask

Block approval on defects within the subtask's brief and contract. Also block on data loss, a security gap, or a break in an earlier subtask. Refuse a handoff that commits evidence files.

Report other gaps as follow-ups, each with a one-line reason. The subtask that finishes the pull request decides every open follow-up. It fixes it, files it as a task, or records it as a limitation in the pull request.

## Create the work in Orbit

Find the Orbit Project and its id, then call `tasks-create` with that Project id to create the group. Create ordered subtasks with `tasks-subtask-create`, including their deliverables. Use the task APIs or MCP tools to keep the branch's task group consistent with the agreed contract. Move the group to Todo only after the operator approves the breakdown.
