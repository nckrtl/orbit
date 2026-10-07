# Green commit fixture source

Captured with read-only `gh api --method GET` calls against the public `nckrtl/orbit` repository on 2026-10-07:

- `commits.json`: `repos/nckrtl/orbit/commits?sha=refs/heads/main&per_page=25`. History is linear; every commit has one parent.
- `required-checks.json`: `repos/nckrtl/orbit/commits/{sha}/check-runs?check_name=Required%20checks&per_page=100` for commits of that history, keyed by SHA. `dabaa4e2d67f…` has `total_count: 0`: it was pushed together with `dc629e7fb299…`, so CI ran only for the newer head.
- `required-checks-states.json`: one real response for each state, keyed by state.
  - `success`: `c7f8ae627b0e…`.
  - `failure`: `8c2ce14ad2e2…`, a CI run that failed.
  - `failure_after_cancel`: `211e9c25fc4b…`, a CI run cancelled by a newer push. `Required checks` still ran (`if: always()`) and failed.
  - `success_in_cancelled_workflow`: `42a9b6dd5220…`. Every job, including `Required checks`, succeeded, but GitHub reports the workflow run as cancelled.
  - `none`: `dabaa4e2d67f…`, with no run.
  - `queued` and `in_progress`: `12c0d3df0d38…`, polled every 3 seconds while its CI ran. Until the jobs it needs finish, `Required checks` has no run at all; it was `queued` and then `in_progress` for about 6 seconds.
- `check-runs-pages.json`: `repos/nckrtl/orbit/commits/42a9b6dd5220…/check-runs?per_page=5&page={1,2,3}` with `--include`. Every page reports `total_count: 15`; page 4 returned no runs. Link headers carried `next`/`last` as expected and are not used.
- `compare.json`: `repos/nckrtl/orbit/compare/{base}...{head}?per_page=1` for `ahead` (`dc629e7fb299…...c7f8ae627b0e…`), `identical` (`c7f8…...c7f8…`), `behind` (`c7f8…...dc62…`), and `diverged` (`c7f8…` against the head of pull request 972).

Responses are projected to the fields the Gateway reads, plus a few for readability. Commits keep `sha`, `html_url`, the first message line, the committer date, and parent SHAs; author and committer identities are dropped. Check runs keep `id`, `name`, `head_sha`, `status`, `conclusion`, `started_at`, `completed_at`, `html_url`, `details_url`, `check_suite.id`, and `app.slug`. Comparisons keep `status`, `ahead_by`, `behind_by`, `total_commits`, `base_commit.sha`, and `merge_base_commit.sha`; `commits` and `files` are emptied. SHAs, ids, timestamps, and URLs are the captured values. Responses contain no token, and no response header is kept.

Tests move a state onto another commit (`GreenCommitFixtures::requiredChecksAs`), rewrite a comparison's base to the requested commit, and change single fields to build hostile variants. Those are deliberate mutations of the captured shapes, not more captured records. Raw captures stay uncommitted under `.orbit-artifacts/green-commit/`.
