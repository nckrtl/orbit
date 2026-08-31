---
name: developing-features
description: Use when implementing a preflight-approved Orbit Linear issue from its worktree.
---

# Developing Features

You are the single implementer for one Linear issue. Implement it in its
worktree and open a pull request. Issue state, preflight orchestration, review,
merge, and cleanup belong to other agents.

## Inputs

Require all of:

- the unchanged Ready Linear issue;
- a bootstrapped worktree under `.worktrees/<issue>-<slug>` on a branch from
  `main`;
- `<worktree>/.orbit/plan.md` with `Verdict: PASS`.

Stop and say so if any input is missing, the plan has unresolved reviewer
findings, or the plan contradicts the issue or a governing ADR. Follow the
approved implementation order without widening scope.

## Steps

1. **Read the contract.** Read the issue, approved preflight, acceptance map,
   components, and ADR. If it changes harness code, follow **Harness issues** below.
   If it has no `Proof: incus` line, do steps 5 and 7 only.
2. **Fresh topology.** `bin/e2e-topology acquire <ISSUE> <worktree>`. Three VMs
   from the standby snapshot; the worktree is mounted at `/home/orbit/orbit` on
   `gateway` and `app-dev`. `app-prod` runs no Orbit code.
3. **Get it right.** `bin/e2e-topology shell <ISSUE> <role>` opens a shell on a
   node as `orbit`. Edit the worktree (host or node, same files), run Orbit,
   repeat until the topology shows the behaviour the issue asks for. Use
   `bin/e2e-topology exec <ISSUE> <role> --argv='[...]'` for scripted checks.
   Keep a note of every manual step.
4. **Report harness gaps.** If the harness itself (anything under `apps/e2e`
   or `bin/e2e-*`) blocks you, stop and report it. Do not change the harness
   in a feature branch.
5. **Codify.** Work through the approved implementation order. Every manual
   step becomes product code with tests, or is dropped as not needed.
   Test-driven for behaviour. Run the focused proof mapped to each completed
   increment, then each changed project's `composer check` and root `bin/test`.
   Commit the coherent feature; separate commits per increment are optional.
6. **Prove fresh.** Write `proofs/<ISSUE>.json`:

   ```json
   {
     "setup": [{"id": "text", "node": "gateway", "argv": ["..."], "timeout_seconds": 60}],
     "acceptance": [{"id": "text", "node": "app-dev", "argv": ["orbit", "..."], "timeout_seconds": 60}]
   }
   ```

   One acceptance action per acceptance criterion; every action must exit 0.
   Optional fixture files go in `proofs/<ISSUE>/` and are staged to
   `/var/lib/orbit-e2e/proof/` on every node. Then:
   `git merge main`, `bin/e2e-topology release <ISSUE>`,
   `bin/e2e-topology prove <ISSUE>`. On `diagnosis`, fix, commit, prove again.
   Leave the proved topology alive.
7. **Pull request.** Push. Use the template: what changed, why, and
   "Proved with `proofs/<ISSUE>.json` at `<sha>`",
   "Harness: `bin/e2e-live <sha>` passed.", or "Automated tests only".
   Short and for humans. Do not wait for CI.

## Delegation

You remain responsible for the whole feature, integration, topology, proof,
and pull request. Use bounded subagents only for genuinely independent targeted
code reads, tests, or components. Do not assign one agent per plan increment or
replace yourself with another feature orchestrator. Do the work directly when
the change touches at most five files.

## Corrections

You remain the implementer for review corrections. Accept only concrete defects
against the issue, governing ADRs, existing invariants, tests, or repository
rules. A genuinely new requirement becomes separate Linear work. Fix all valid
findings, commit, `git merge main`, release and prove again, push, then reply
"Addressed in `<sha>`".

## Harness issues

Harness code is everything under `apps/e2e` and `bin/e2e-*`, except
`apps/e2e/tests/Feature/**` and `apps/e2e/tests/Unit/**`. An issue that
changes it skips steps 2, 3, 4, and 6:

- Implement with unit tests (step 5).
- Prove with `bin/e2e-live <full sha>`. It builds a standby from your commit
  and runs the feature flow once end to end (about four minutes).
- The PR's Proof line is "Harness: `bin/e2e-live <sha>` passed."

Tips for `apps/e2e`:

- Run `vendor/bin/mago format` before `composer check`.
- Keep `it` bodies straight-line and fixtures in helper functions; mago's
  complexity rule counts the enclosing `describe`.
- Probe evidence has a fixed key set. Record extra detail in `expected` and
  `observed`.

## Rules

- Feature branches never touch `apps/e2e` or `bin/e2e-*`.
- Proof actions are read-only checks unless the plan says `"mutates": true`.
- A plan that removes a node declares what it ends with, for example
  `"ends_with": {"nodes": ["gateway", "app-dev"]}`; the probes of the removed
  node are then skipped and named in the result, and a node declared absent
  that is still registered fails.
- One issue per worktree; do not reuse a topology across issues.
