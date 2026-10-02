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

Review a proof script against its brief: it fails closed, its post-cleanup audit shows no leftovers, and its scenario reproduces. Do not block on the script's own crash or recovery paths. Record such a concern as a non-blocking limitation. Product-code findings stay blocking.

## Report

Give actionable findings with file references, their effect, and suggested corrections. Include the reviewed commit, check results, Incus observations, and limitations. Link sanitized evidence stored outside the repository.

After fixes, review the changes and repeat affected checks. Confirm that the final assessment covers the current PR commit. Publish the review when authorized. The maintainer approves the completed feature before merge.

## Final review of an Orbit task pull request

For delegated final DevOps review, follow the [final review workflow](../../../docs/reference/implementation-loop.md#final-review-of-an-orbit-task-pull-request). Submit a formal GitHub review only when the maintainer has authorized final review of that named pull request. Use the maintainer's GitHub CLI profile. Confirm that `gh api user --jq .login` is that profile before you submit. Do not submit this review as the pull request author.

Assess the whole pull request, including the independent code review and the Incus evidence. The review body records the full reviewed head SHA, that whole-pull-request assessment, the evidence and results, the limitations, and the verdict. A limitation that leaves required behavior unverified prevents approval. Do not approve while blocking findings or required verification remain open. A Tasks engine subtask approval is not this review.

Re-read the pull request head immediately before you submit. If it is not the SHA you reviewed, stop. Review the new head, repeat the affected checks, and submit a new formal review for that SHA.

Submit the review through the GitHub reviews API so `commit_id` is the full reviewed SHA. `gh pr review` cannot set `commit_id`, so do not use it for this gate. `gh` fills `{owner}` and `{repo}` from the current repository. Replace `<number>` with the pull request number. Keep the body in a file outside the repository so the shell does not alter it.

```bash
gh api --method POST repos/{owner}/{repo}/pulls/<number>/reviews -f commit_id="<full-reviewed-sha>" -f event="APPROVE" -f body="$(cat <review-body-file>)"
```

Use `event` `APPROVE` only when the assessment is complete. Use `event` `REQUEST_CHANGES`, with the same `commit_id` and a body, when blocking findings remain. GitHub records that event as state `CHANGES_REQUESTED`. A plain comment, including a ready-to-merge verdict, is not approval.

Read the API response before you treat the review as submitted. The returned `user.login` must be the maintainer profile, the returned `commit_id` must be the reviewed SHA, and the returned `state` must be `APPROVED` or `CHANGES_REQUESTED` for the event you sent. A failed call submits nothing. The [merge skill](../merging-pull-requests/SKILL.md) reads GitHub's review records again and merges that commit.
