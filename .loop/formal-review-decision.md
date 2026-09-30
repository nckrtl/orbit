# ORB-677: mandatory formal GitHub approval

Maintainer-approved correction, 2026-10-01. This supersedes the earlier comment-only/optional-approval plan. A formal GitHub APPROVED review from the designated final reviewer must cover the current PR head. The review body carries full SHA, whole-PR evidence/results/limitations, and verdict. Submit APPROVE using the reviews API with commit_id bound to the reviewed SHA. A comment alone, dismissed/stale approval, outstanding requested changes from the final reviewer, wrong identity, or unreadable review data blocks merging.

The DevOps reviewer still uses the maintainer GitHub CLI profile to submit the review and merge the reviewed SHA after green Required checks, complete required verification, resolved findings, and scoped authorization. A new head requires renewed review and formal approval. The admin bypass is unchanged, so this is a workflow gate. No ruleset, App merge endpoint, auto-merge, or external DevOps setup is added.

The active task workspace is not edited by the coordinator. Prepared corrections live on plan/task-677-formal-review; compare its documentation/skill delta from 117aa26196ede32950456ce6dd7d20cb7e9d1459, preserving intervening work when applying it.

1. ORB-678: existing in-flight docs task; historical brief is locked. Scope correction recorded in task comment 1555.
2. ORB-732: formal-approval documentation correction, builds on 678. Three owning pages, documentation checks, and full planned-path impact evidence. One session: a narrow workflow correction, no product code.
3. ORB-679: corrected skills and scenario proof, builds on 732. Two skills, walkthrough evidence, checks, and independent review. One session: bounded guidance work with no live merge demonstration.

The reviewer-feedback feature is tracked separately as ORB-733, in backlog. Its docs/planning seed must produce the full contract and implementation breakdown before dispatch. It consumes review feedback but does not perform the external final review or merge.
