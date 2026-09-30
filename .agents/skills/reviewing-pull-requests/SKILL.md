---
name: reviewing-pull-requests
description: Use when independently reviewing an Orbit feature proposal before coding or a completed PR before merge.
---

# Reviewing Pull Requests

Independently assess whether the feature's architecture, documentation, and behavior agree. Review the supplied proposal or PR and record the revision.

For a requested review before coding, check the intended behavior, feasibility, proposed ADRs, documentation, and important failure cases. Return findings for the author to address.

## Review a completed PR

Check correctness, regressions, test coverage, architectural decisions, and documentation. Inspect the required CI results. Check affected security boundaries, including ownership, untrusted input, credentials, TLS, and SSH identities.

For command behavior, use [designing-cli-commands](../designing-cli-commands/SKILL.md) and the [CLI standard](../../../docs/reference/cli-ux.md). Use [verifying-cli-output](../verifying-cli-output/SKILL.md) for real terminal checks. For a web UI change, open the phone and desktop screenshots from [verifying-web-ui](../verifying-web-ui/SKILL.md) and judge the layout on a phone. Do not stop at the diff.

Reproduce the feature's user-visible behavior and important failure cases on Incus. Verify the running source commit and use machines allocated to the review. The [Incus topology reference](../../../docs/reference/incus-topologies.md) describes harness commands. If access is unavailable, return the code findings and leave Incus review pending.

## Report

Give actionable findings with file references, their effect, and suggested corrections. Include the reviewed commit, check results, Incus observations, and limitations. Link sanitized evidence stored outside the repository.

After fixes, review the changes and repeat affected checks. Confirm that the final assessment covers the current PR commit. Publish the review when authorized. The maintainer approves the completed feature before merge.

For delegated final DevOps review of an Orbit task PR, follow the [final review workflow](../../../docs/reference/implementation-loop.md#final-review-of-an-orbit-task-pull-request). Submit a formal GitHub review through the maintainer's GitHub CLI profile, with the full reviewed head SHA, checks and results, limitations, evidence links, and verdict in its body. Use the reviews API with `commit_id` set to the reviewed SHA and `event: APPROVE` only when all required verification is complete and blocking findings are resolved. Use `REQUEST_CHANGES` for blocking findings; a plain comment is not approval. New head commits require another review and formal approval. Delegated final review and merge authorizes the named work; the [merge skill](../merging-pull-requests/SKILL.md) verifies GitHub's review records and merges that commit.
