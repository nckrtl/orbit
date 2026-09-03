---
name: recording-decisions
description: Use when drafting, revising, or accepting an Orbit architecture decision record.
---

# Recording Decisions

Write one architecture decision record under `docs/decisions` from `template.md` beside this skill. The record states one decision, the reasons it won, the alternatives it beat, and what it binds. It is read first by people and then by agents that derive Linear issues from it.

## Inputs

- The approved direction, discussion, or request that triggered the record.
- Accepted records under `docs/decisions` that the new record extends, amends, or supersedes.
- Current code, proof, and reference pages the decision constrains.

Stop when the request contains more than one independent decision, when the decision cannot be stated as bounded obligations, or when the user has not chosen between live options. Split the first case into one record per decision. The other two are issues or plans, not records.

## Write the record

1. Copy `template.md` to `docs/decisions/NNNN-short-decision-name.md` using the number one higher than the highest existing record; gaps in the sequence stay empty.
2. Fill the Y-statement under the H1 first. If it cannot be written in one sentence, the decision is not yet one decision.
3. Keep every sentence that would let a future implementer choose differently if removed. Move every sentence that describes how the choice is carried out to the `Detail` target and link it. CLI syntax, verification checklists, error text, field lists, and Doctor behavior are mechanism. Mechanism becomes acceptance criteria in the implementing issue and, once it ships, the `docs/reference` page that describes the command, field, failure code, or limit; the `Detail` line names that page even before it exists.
4. Inherit by reference. Name what the record extends or supersedes in one clause with a link. Never restate rules from an earlier record.
5. Write Decision bullets as `<actor> must`, `must not`, `may ... when`, or `owns`. One obligation per bullet. No adjective or adverb without evidence in Context.
6. List at least one rejected alternative with a concrete reason, and at least one consequence that is a cost or limitation.
7. Fill `Affects` with repository component names, linked ADR filenames, the mechanism target, and one verification the reader can run.
8. Let the decision set the length. A record grows by adding obligations, alternatives, and consequences, never by adding mechanism or narrative.

## Verify

Run `composer docs-lint` from the repository root. The rules `orbit.adr_structure` and `orbit.adr_language` reject a record with missing or reordered sections, a malformed Status line, an unknown component, or a blocked phrase. A new record makes the context index stale, so run `composer docs-build` from the repository root and commit `docs/generated/context.json` with the record. Fix every finding before presenting the draft. The blocked phrase list lives in `apps/docs/config/orbit-docs.php`.

## Accept and commit

- Present the draft as `Proposed`. Revise until the user approves the exact final text, then set `Accepted on YYYY-MM-DD` in the same sentence position.
- Accepted records are immutable. A later change is a new record that names the one it changes.
- Commit and pull request rules are in `docs/decisions/README.md` and ADR 0010.
- After the record reaches `origin/main`, inspect every open Linear issue whose outcome or `Scope` intersects the decision and hand each one to `creating-issues` for revalidation, as ADR 0010 requires: a conflicting contract returns to `Backlog` with a `Readiness` section, and obsolete work is cancelled only with the repository owner's explicit authority.
