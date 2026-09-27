---
name: orbit-tasks
description: Orbit Project policy for planning task groups and defining verifiable subtask deliverables.
---

# Orbit Tasks

Use this skill when preparing or implementing an Orbit task group. The Gateway Tasks engine is generic; this file defines Orbit's repository policy. Orbit's Project task check enforces repository-specific requirements deterministically.

## Prepare the feature contract

Before implementation, add a documentation subtask that writes or updates the relevant maintained documentation. Record any architectural decision in an ADR, using the next available number and preserving the ADR process in `docs/decisions/README.md`. The ADRs and documentation changed against the group's base commit are the feature contract for later subtasks.

Keep the branch's documentation, ADRs, implementation, tests, and task briefs consistent. Focus the group on one feature and order subtasks by dependency.

## Split the work

Give each subtask one concise goal that an implementer can finish and a reviewer can verify in one turn. Keep each subtask independently verifiable and limit it to at most five deliverables. Split work that needs more than five.

Name contract documents and paths in a brief when the contract requires them. Leave implementation paths to the implementer otherwise. A subtask's brief should state its goal, contract, dependencies, deliverables, and acceptance checks.

## Bugs

For a bug, make the first code-changing subtask reproduce the failure before the fix. Provide a failing test or command deliverable when the Project task check supports it. If the bug cannot be reproduced automatically, state that in the brief and provide a reviewer-judged `review` deliverable for the manual reproduction. A documentation-only subtask does not count as the first code-changing subtask.

## Deliverables

Use typed deliverables to describe observable results. Orbit's current engine supports `file`, `test`, `command`, and `review`; follow the Gateway task reference for the fields and verification behavior. Prefer one deliverable for each explicit result promised in the brief. Evidence at handoff says where or how each deliverable is met.

Do not assume that every Project uses Composer, Pest, PHP, or any particular test runner. Use the task check configured for the Orbit Project and the deliverable type supported by the current engine. Future generic command deliverables, including `fails_on_base` and `paths`, are defined by ADR 0178 and are implemented in a later group.
