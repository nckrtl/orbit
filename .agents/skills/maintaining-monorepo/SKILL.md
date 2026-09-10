---
name: maintaining-monorepo
description: Use when diagnosing or repairing failed main checks or cache maintenance across the Orbit monorepo. Routine cache warming runs through repository scripts.
---

# Maintaining the Monorepo

Own one assigned maintenance incident across CLI, Gateway, Docs, E2E, and PHP
SDK. Diagnose the failure, recover within the assigned scope, and return verified
evidence to the external orchestrator. One maintenance owner covers the whole
monorepo; keep each project's dependencies, checks, and writable caches separate.

The orchestrator owns assignment, worker lifecycle, Linear and GitHub publication,
merge pauses, review dispatch, and merge. This role does not approve its own
repairs, change the orchestrator's configuration, or start competing maintenance
agents. Normal cache refreshes use deterministic background scripts and do not
require an agent session.

## Establish the incident

Read [Implementation loop](../../../docs/reference/implementation-loop.md),
the current repository commands, and the nearest `AGENTS.md` for affected code.
Use current behavior when choosing commands; a planned cache capability is not
evidence that a command supports it. Discovery is the default repair flow.

The assignment identifies the failure, exact main commit, failed commands and
logs, affected projects, previous recovery attempts, and available checkouts.
Inspect missing evidence before retrying. Compare the failing commit with current
main: a later merge may already have fixed the incident. Preserve the original
failure and verify the current result before calling it resolved.

Confirm that the assignment is the monorepo's active maintenance owner. Reuse
the existing incident and handoff when the same failure is reported again.
Coordinate with the orchestrator before writing to a checkout or cache that an
existing refresh process still owns.

## Classify and recover

- **Cache or environment failure:** failed downloads, cache copying, publication,
  coverage setup, or maintenance process execution can prevent warming without
  proving a source regression. Retain the last successful compatible publications
  and give the orchestrator evidence that delivery can continue with those caches
  or cold checks. Diagnose the cause and use a bounded repository-command retry
  after correcting it. Repeating the same failed action without new evidence is
  not recovery.
- **Failure in main:** a failing test, formatting check, or static analysis on
  merged main requires immediate notice to the orchestrator to hold unrelated
  feature merges while allowing an independently reviewed repair or revert.
  Reproduce the failure at the recorded commit and assess current main.
  Unrelated feature development can continue. Repair or propose a revert through
  an independently reviewed change.
- **Unresolved cause:** report what is known, what remains untested, and the next
  diagnostic action. Never relabel a failed correctness check as a cache problem
  merely to clear a merge hold. Product or architecture decisions go back to the
  orchestrator with a recommended resolution.

Run checks sequentially across affected projects with bounded test workers.
Start with the failed command and focused reproduction. Expand verification for
cross-project impact or unresolved failures; routine full suites are not a cache
repair technique. Preserve logs and useful failure state before changing caches.

## Checkouts and source repairs

Keep the primary checkout clean and available for feature creation. Maintenance
checks run in the owned maintenance checkout or an isolated diagnostic worktree.
Do not edit source in primary main or in the cache publisher's checkout, reset
another worker's edits, or remove its resources to obtain a clean run.

Source changes use one repair issue and a separate whole-monorepo worktree based
on current main. If no repair issue exists, return the diagnosed scope and
verifiable acceptance criteria to the orchestrator; diagnosis need not wait for
issue creation. Implement the assigned repair through
[developing-features](../developing-features/SKILL.md), including its focused
tests, project checks, candidate artifacts, and independent-review handoff.
The Builder runs root `composer check` on the exact repair candidate before independent review.

A merged PR cannot receive a later repair: use a new PR even when reusing its
failure evidence. Keep repairs limited to the incident. Harness changes still
require the repository's dedicated issue and selected-flow verification contract.
Maintenance does not grant production restart or deployment authority.

## Cache and recovery evidence

Use repository-owned cache commands and their compatibility checks. Never share
writable project caches, publish a feature TIA graph as a main baseline, or
delete successful publications to force a refresh. A cache miss or zero selected
tests is not evidence that the reported behavior works.

Report TIA, Pint, and PHPStan/Larastan results separately for each affected
project. Verify the actual published commit, compatibility, and successful command
outcomes where publication is supported. Copied quality caches and successful
background queueing do not prove that a new main publication exists.

After a repair merges, verify the original failure on main containing that
repair, rerun the relevant checks, and inspect the resulting cache state. A
passing repair branch or a queued refresh alone cannot clear a failure in main.
Return that evidence to the orchestrator, which owns lifting the merge hold.
Do not leave an unowned background process when returning.

## Handoff

Return the incident and affected projects; failing and checked main SHAs;
classification and delivery impact; commands, exit codes, and retained logs;
actions taken; repair issue, worktree, candidate and review handoff when needed;
cache publications or cold fallbacks; and any remaining limitation.

End with one concrete outcome: recovered with verification, repair ready for
independent review, or unresolved with the next owner, action, and restart
condition. Preserve evidence and reusable lessons; suggest a focused change to
this role when the incident demonstrates missing guidance. Do not rewrite the
role during an unrelated repair.
