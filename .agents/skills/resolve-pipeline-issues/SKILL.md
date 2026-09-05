---
name: resolve-pipeline-issues
description: Resolve Orbit Blocked and Backlog Linear issues one at a time, or return a read-only proposal for one exact issue delegated at a process stop before blocking or after it moves to Blocked or Backlog.
---

# Resolve Pipeline Issues

Turn unready Orbit Linear work into complete, verifiable `Todo` contracts. In delegated advisory mode, diagnose one issue's process stop and propose a resolution. This skill does not plan or implement the issue.

## Choose the mode

- Use interactive pipeline mode only when the user explicitly asks to resolve the Orbit pipeline, such as “Let's resolve pipeline issues.”
- Use interactive single-issue mode only when the user explicitly names one `Blocked` or `Backlog` issue by ID or URL and asks to resolve it or make it ready.
- Use delegated advisory mode when an orchestrator assigns a dedicated resolution agent one exact Orbit issue by ID or URL and asks for a proposed resolution of a named process stop before blocking, or after that issue moves to `Blocked` or `Backlog`. The assignment supplies the stop evidence and current state; moving to `Blocked` is not a prerequisite. Dispatch, adoption, escalation, and comment publication remain outside this skill.
- Do not activate merely because an issue is mentioned, happens to have one of those states, or needs a status explanation.

Interactive pipeline mode continues across turns without requiring a new invocation after each issue. Keep only one issue active at a time so its decisions and approval remain clear. Interactive single-issue mode stops after that issue. Delegated advisory mode inspects and returns a proposal for only the assigned issue.

## Build the current queue

Start from live Linear and current `origin/main`. Read each issue's description, comments, status history, parent and children, labels, attachments, and relations. Read every relevant accepted ADR, maintained page, current implementation, nearby test, and available proof seam. Treat old plans, branches, comments, and prior audits as leads rather than current authority.

In interactive pipeline mode, address all `Blocked` issues before any `Backlog` issue. Within a state, prefer unresolved prerequisites that unlock other work, then preserve Linear's displayed order. Refresh the queue after every published issue because status, relations, and repository state may have changed.

In interactive single-issue mode, inspect only the named issue. If it is no longer `Blocked` or `Backlog`, report its current state and stop. In delegated advisory mode, inspect only the named issue and confirm that the assigned stop still exists in its current state. If it has been resolved or the issue is complete or canceled, report that evidence and stop; do not recreate the blocker or change its state to qualify for this skill.

Apply the parent and child rules from `creating-issues`. A parent is shaped as an outcome and ordered set of children, never as claimable implementation work; its leaf children carry acceptance.

## Explain the gap first

Before proposing edits, explain in plain language:

1. what the issue is meant to deliver;
2. the exact inherent blocker or underspecified contract;
3. which apparent blockers are only ordinary issue dependencies; and
4. the recommended Orbit-aligned resolution and its main trade-off.

Base the recommendation on accepted ADRs, current ownership and lifecycle boundaries, existing terminology and behavior, the smallest safe delivery split, and proof that the repository can actually run. Do not preserve an old proposal merely because it appears in a comment or retained plan.

An ordinary unfinished prerequisite belongs only in a Linear `blocked by` relation. It is not `Readiness` and does not justify `Blocked` or `Backlog` once the issue's own contract is complete.

## Shape one issue

This section applies only to the interactive modes.

Apply `grilling` with the `domain-modeling` discipline. Establish repository and Linear facts yourself. Ask every currently ready product question in one short numbered round, recommend the most Orbit-aligned answer with its trade-off, and let the user decide. Rebuild the decision frontier after each answer.

Cover the outcome, terminology, ownership, scope, delivery splits, dependencies, compatibility, migration, failure, retry, rollback, removal, security, acceptance, and proof where they can change the contract. Do not implement the issue, write its plan, or mutate Linear during shaping.

When the choice changes architecture, durable ownership, security, or a cross-component contract, route it through `recording-decisions`. Resume the issue only after the governing ADR is accepted on current `origin/main`.

When no material fact or decision remains, present the complete shaping handoff and obtain confirmation.

## Return a delegated proposal

In delegated advisory mode, independently inspect the issue, its comments and relations, accepted ADRs on current `origin/main`, maintained documentation, current code and tests, and available proof capability. Determine the exact inherent blocker or underspecification, separate it from ordinary dependencies, and recommend the most Orbit-aligned resolution with its main trade-off.

Do not interview the user, mutate Linear, post a comment, change status, create or accept an ADR, edit repository files, or publish an issue contract. Return a useful proposal even when a human decision is still required. Recommend an answer to each open decision; do not stop at “human decision needed.” Clearly label the result as an unapproved proposal.

Return this concise Markdown contract:

- **Issue and current state**
- **Intended outcome**
- **Exact blocker or underspecification**
- **Ordinary dependencies**
- **Recommended resolution**
- **Main trade-off**
- **Required ADR**: explicitly `None`, or each proposed new, amended, or superseding ADR and why it is needed
- **Human decisions still needed**: explicitly `None`, or each decision with a recommended answer
- **Proposed issue and status changes**
- **Verification and restart condition**: how to prove the resolution works and what work can then resume
- **Evidence checked**

The orchestrator owns every action after this return, including whether to post the proposal as a comment, keep the issue in its current state, request a human decision, adopt the proposal as the new direction, invoke interactive shaping and publication, or move the issue to `Todo`.

## Publish the ready contract

This section applies only to the interactive modes. Delegated advisory mode ends after returning its proposal.

After the handoff is confirmed, use `creating-issues`. Draft the exact description and every Linear field, lint the draft, and show the complete payload for explicit approval. Recheck Linear, `origin/main`, and governing ADR status immediately before publishing. Publish only the approved payload and read it back.

Apply these state rules:

- A complete issue moves to `Todo`, even when unfinished `blocked by` relations remain. Linear enforces those dependencies.
- A `Blocked` issue returns to `Todo` when its inherent blocker is removed.
- A `Backlog` issue moves to `Todo` when its outcome, scope, acceptance, components, ADRs, dependencies, and proof venues are complete.
- `Readiness` exists only while a product decision, boundary, acceptance criterion, governing ADR, or usable proof capability is unresolved. Remove it before `Todo`.

Keep real dependency relations. Do not delete or rewrite historical blocker comments. After publication and read-back, resolve a resolvable blocker thread only when every inherent blocker it names is addressed; ordinary dependencies remain represented by relations.

In interactive pipeline mode, report the completed issue briefly, refresh the queue, and immediately introduce the next issue. Do not require the user to ask whether to continue. Finish when no `Blocked` or `Backlog` issue remains, the user pauses the run, or an external prerequisite cannot be completed within the authorized scope; in the last case, report the exact stop and what would resume it.
