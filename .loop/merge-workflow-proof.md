# Merge workflow walkthrough

This file is a walkthrough of the written review and merge skills against the corrected final-review contract. It is not a live review, not a live merge, and not proof that a pull request was merged. No review was submitted and no pull request was merged for this evidence. The refused and allowed cases below are decisions produced by applying the skills and `docs/reference/implementation-loop.md`. The only live commands are the read-only GitHub and CLI observations that follow.

Sources checked while writing this walkthrough:

- `.agents/skills/reviewing-pull-requests/SKILL.md`, section "Final review of an Orbit task pull request"
- `.agents/skills/merging-pull-requests/SKILL.md`
- `docs/reference/implementation-loop.md`, sections "Final review of an Orbit task pull request" and "The maintainer approves every merge"
- `docs/contributor-guide.md`, section "Review and merge"
- `docs/reference/tasks.md`, settling section, for the statement that a Tasks engine subtask approval does not merge the pull request

The formal-approval contract supersedes the older comment-only and optional-approval wording. A plain comment does not satisfy the gate.

## Read-only observations

Observed at `2026-09-30T23:10:12Z` with GitHub CLI `2.98.0` (`2026-08-20`). The active account was `nckrtl` (`gh api user` id `18613261`). These calls did not create a review, change a ruleset, enable auto-merge, or merge a pull request.

Installed `gh pr merge` help exposes `--merge`, `--match-head-commit SHA`, `--admin`, `--auto`, and `--disable-auto`. The help also says that, when a merge queue is involved, failing required checks enable auto-merge, and that `--admin` bypasses a merge queue. The workflow does not use those paths.

Installed `gh pr review` help exposes `--approve`, `--request-changes`, `--comment`, and `--body`. It has no flag that sets `commit_id`. The review skill therefore posts `event` and `commit_id` through `gh api` to the reviews endpoint. `gh pr review --approve` is not the formal gate.

Repository `nckrtl/orbit` at observation time:

| Setting | Result |
| --- | --- |
| `allow_auto_merge` | `false` |
| `allow_merge_commit` | `true` |
| `allow_rebase_merge` | `true` |
| `allow_squash_merge` | `true` |
| `delete_branch_on_merge` | `false` |
| Default branch | `main` |
| Active account permission | `admin` (`permissions.admin` true) |
| Legacy branch protection | HTTP 404, "Branch not protected" |

Ruleset `22330893`, "Orbit verified merges", enforces `deletion` and `non_fast_forward` on `refs/heads/main`. `bypass_actors` is empty. `current_user_can_bypass` for `nckrtl` is `never`.

Ruleset `24188414`, "Pull requests pass CI", enforces `required_status_checks` on `refs/heads/main`. The required context is `Required checks` (integration id `15368`). `strict_required_status_checks_policy` is false, so the branch does not have to be up to date. The only bypass actor is repository role id `5` with `bypass_mode` `always`. `current_user_can_bypass` for the admin account `nckrtl` is `always`. No ruleset requires a pull request review.

That admin bypass is a workflow limitation. GitHub does not enforce `Required checks` for this maintainer profile, and it does not require the formal approval. The merge skill still checks both before `gh pr merge`. This group does not change the ruleset, does not add a bypass actor, and does not enable auto-merge.

Check-reading limitation, from pull request 846's merged head `b51ae8317b7c58a842dd9fa0cf87f390ec9e1e6b`, read only:

- Commit check runs reported `total_count` 14. The run named `Required checks` had `status` `completed`, `conclusion` `success`, and `head_sha` equal to that commit.
- `gh pr checks 846 --required --json name,state,bucket` returned one row: name `Required checks`, state `SUCCESS`, bucket `pass`. That command follows the pull request head. It is not the SHA-bound source of truth, and a pass here does not mean GitHub will enforce the check for the admin account.
- `GET /repos/nckrtl/orbit/commits/<sha>/status` returned `state` `pending` and zero statuses for that same commit. The legacy combined status is the wrong gate. The merge skill uses the check run named `Required checks`.

Identity limitation, from the same read-only pull request: REST `user.login` was `orbit-nckrtl[bot]`, while `gh pr view --json author` reported `app/orbit-nckrtl`. The merge skill compares the review login with the REST pull request login so an App author is not missed or mismatched.

No merge queue rule was present. The help text about enabling auto-merge for a merge queue does not describe current `main`. The workflow still must not pass `--auto`.

## Allowed case

Merge is allowed only when every condition below is true for one named Orbit task pull request. This walkthrough does not claim that any open pull request met them.

1. The maintainer authorized final review and merge of that named work. The active `gh` account is the maintainer profile.
2. The feature is complete, blocking findings are resolved, and required verification is complete, including independent code review and required Incus evidence. A limitation does not leave required behavior unverified.
3. `bin/tia-cache status --json --remote` is readable and `correctness_failures` is empty, or this pull request is the reviewed fix for the open failure.
4. The reviewer already submitted `event` `APPROVE` through the reviews API, with `commit_id` set to the full head SHA they assessed. The returned `user.login`, `state` `APPROVED`, and `commit_id` match that submission. The reviewer is not the pull request author.
5. A fresh read of the review list still shows that approval: maintainer login, not the author, state `APPROVED`, `commit_id` equal to the current full head SHA, and a body that records that SHA, the whole-pull-request assessment, the evidence and results, the limitations, and the verdict. No later `CHANGES_REQUESTED` review from that account has the same `commit_id`.
6. A complete check-run read for that SHA shows `Required checks` completed with conclusion `success` and `head_sha` equal to that SHA.
7. An immediate re-read of `.head.sha` still equals that SHA.
8. The merge command is `gh pr merge <pr-url> --merge --match-head-commit <reviewed-sha>` from the maintainer profile. It does not use `--admin` or `--auto`.

The review skill performs steps 1, 2, and 4. The merge skill performs steps 1 through 3 and 5 through 8, including reading the review records again. The feature-delivery page requires the same formal approval, the same `commit_id`, successful `Required checks` on that head, and the same merge command.

## Refused cases

Each row is a decision from the skills. None of these situations was staged on GitHub for this file.

| Case | Decision | Where the skills and docs require it |
| --- | --- | --- |
| Approved current head plus green CI | Allow, but only together with the other allowed-case conditions | Merge skill steps 3, 5, and 6. Feature delivery requires `APPROVED`, a matching `commit_id`, successful `Required checks`, and `gh pr merge --merge --match-head-commit`. |
| Plain ready-to-merge comment only | Refuse. A comment is not approval, even when the prose says ready to merge. | Review skill: a plain comment is not approval. Merge skill step 4. Feature delivery: a plain comment, including a ready-to-merge verdict, does not satisfy the gate. |
| Approval for another head, including a stale `APPROVED` review whose `commit_id` is not the current head | Refuse. Do not merge the current head on that approval. | Merge skill steps 3 and 4 keep only a review whose `commit_id` equals the current head. Feature delivery rejects an approval for another head and a stale approval. |
| Dismissed approval | Refuse. State `DISMISSED` is dropped even when `commit_id` matches. | Merge skill steps 3 and 4. Feature delivery rejects a dismissed approval. |
| Requested changes | Refuse while `CHANGES_REQUESTED` on the current head is the final reviewer's latest review on that head, or is later than that reviewer's `APPROVED` review on that same head. Submit `REQUEST_CHANGES` instead of `APPROVE` when blocking findings remain. | Review skill: `event` `REQUEST_CHANGES` for blocking findings. Merge skill steps 3 and 4. Feature delivery rejects outstanding requested changes from the final reviewer. An older `CHANGES_REQUESTED` review does not block a later `APPROVED` review on the new head. |
| Missing reviews | Refuse when a complete read returns no review that qualifies. An empty successful list is missing, not unreadable. | Merge skill step 4. |
| Unreadable reviews | Refuse when the review request fails, a page is missing, or the payload cannot be parsed. Do not treat that as an empty list and do not merge. | Merge skill step 4. Feature delivery rejects unreadable review data. |
| Wrong reviewer | Refuse when `user.login` is not the maintainer profile confirmed with `gh api user`. | Merge skill steps 3 and 4. Feature delivery requires the designated final reviewer's identity. |
| Pull request author | Refuse, including when the author is the maintainer account. Compare REST logins. | Review skill: do not submit as the author. Merge skill step 3 drops the author's login. Feature delivery: the pull request author cannot approve their own pull request. |
| Pending checks | Refuse when the `Required checks` run for that SHA is not `completed`. | Merge skill step 5. Feature delivery rejects a pending required check. |
| Failed checks | Refuse when that run is `completed` and `conclusion` is not `success`, including failure, cancellation, timeout, skip, or action required. | Merge skill step 5. Feature delivery rejects a failed required check. |
| Missing checks | Refuse when a complete check-run read has no run named `Required checks` on that SHA. | Merge skill step 5. Feature delivery rejects a missing required check. |
| Unreadable checks | Refuse when the check-run read fails, a page is missing, or the payload cannot be parsed. The legacy status endpoint is not a substitute. `gh pr checks` alone is not a substitute. | Merge skill step 5. Feature delivery rejects an unreadable required check. |
| Incomplete required review | Refuse approval and refuse merge when required code or Incus verification is still open, when a limitation leaves required behavior unverified, or when the approving body omits the full SHA, the whole-pull-request assessment, the evidence and results, the limitations, or the verdict. A Tasks subtask approval does not fill this gap. | Review skill assessment paragraph. Merge skill steps 1 and 3. Feature delivery: a limitation that leaves required behavior unverified prevents approval. |
| Missing authorization | Refuse both the formal review and the merge when the maintainer has not authorized final review and merge of that named pull request. Authorization for other work does not transfer. | Review skill: submit only when authorized. Merge skill step 2. Feature delivery: the delegation is consent for that work only. |
| Unresolved findings | Do not submit `APPROVE`. Submit `REQUEST_CHANGES` bound to the reviewed SHA when findings block the pull request. Do not merge. | Review skill. Merge skill step 1. |
| Known correctness failure on main | Refuse an unrelated merge when `bin/tia-cache status --json --remote` reports a non-empty `correctness_failures` object, or when that status cannot be read. A maintenance failure is not this hold. The reviewed fix for that failure is not an unrelated merge. | Merge skill steps 2 and 7. Feature delivery: a known correctness failure on main holds unrelated merges. |
| Head changed before submit or merge | Stop. Do not post `APPROVE` for a SHA that is no longer the head, and do not post `APPROVE` for the new SHA without reviewing it. Do not merge with a stale `--match-head-commit`, and do not remove that flag to merge the new head. Conflict resolution that adds a commit is a new head. | Review skill: re-read the head before submit. Merge skill step 6. Feature delivery: if the head changes, review the new commit and submit a new formal approval. |

## What this walkthrough does not show

- It does not show a live `APPROVE`, `REQUEST_CHANGES`, or `gh pr merge` call.
- It does not show GitHub rejecting an admin merge that skips `Required checks`. The ruleset observation shows the opposite: the maintainer profile can bypass that rule. The gate is procedural.
- It does not change auto-merge. `allow_auto_merge` was false, and the skills leave it false.
- It does not change rulesets, App permissions, the Gateway, the SDK, MCP, or an API contract.
- It does not prove the separate main-workspace `orbit-tasks` edit. This walkthrough does not use that file.

The external DevOps review configuration remains outside this group. The Tasks scheduler still only watches pull request state, conflicts, and CI, and it completes a task after the merge.
