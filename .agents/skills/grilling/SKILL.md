---
name: grilling
description: Use when an Orbit idea or request needs a decision-tree interview before it can become an implementation contract.
---

# Grilling

Interview the user until the proposed change has no material decision hidden inside it. The handoff should let issue creation, preflight, implementation, and review proceed without reopening product design. This is conversation-only shaping: inspect read-only evidence, recommend choices, and produce a confirmed shaping handoff. Do not create or change issues, ADRs, documentation, code, or other project state.

## Work the decision tree

Build a private tree of decisions. A question is ready only when every decision it depends on is settled. Ask all ready questions in one short numbered round, give a recommended answer and its main trade-off for each, then wait for the user's decisions. Rebuild the tree after every answer.

Ask only questions whose answers can change the outcome, scope, canonical terms, ownership, contracts, dependencies, migration, compatibility, failure and retry behavior, rollback or removal behavior, security, acceptance, proof feasibility, or ADR classification. Do not ask for facts you can find in the repository, issue tracker, or other available read-only sources. Establish those facts yourself and surface contradictions.

Decisions belong to the user. Recommendations should make the best choice easy to evaluate, but must not silently settle a branch. A question that depends on an unanswered question belongs in a later round.

Use concrete scenarios to test each important relationship and lifecycle transition. Cover the normal path, boundary cases, mutation or movement, partial failure, recovery, compatibility, and removal where they can change the contract.

## Finish

The interview is complete when the decision frontier is empty and every material fact needed by the contract has been established. A material fact blocks completion just like an undecided product choice. Present one concise shaping handoff containing:

- the user-visible outcome and explicit non-goals;
- chosen behavior for the scenarios that fixed the design;
- canonical terms and ownership boundaries;
- scope, delivery splits, and real dependencies;
- migration, compatibility, failure, retry, rollback, and removal decisions that apply;
- governing accepted ADRs and any decision that needs a new ADR;
- observable acceptance behavior and feasible proof seams; and
- any remaining non-contract fact that can be verified later without changing the outcome, scope, acceptance, dependencies, proof, or ADR boundary.

Ask the user to confirm or correct that handoff. Do not act on it before confirmation.
