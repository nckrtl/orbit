---
title: "GitHub App"
description: "How a Gateway registers its own GitHub App and installs it on a GitHub account. How Orbit reads private repositories through the App or the Gateway's GitHub CLI, publishes task pull requests, reviews and merges pull requests, and finds the newest green commit of a branch."
covers:
  - apps/gateway/app/Domain/GitHub/**
  - apps/gateway/app/Infrastructure/GitHub/**
  - apps/gateway/app/Http/Controllers/Api/GitHubAppController.php
  - apps/gateway/app/Actions/GitHub/**
---

# GitHub App

A Gateway reads private `github.com` repositories through its own GitHub App. The [Tasks](/reference/tasks) extension also publishes its pull requests through it, and, for a [review-and-merge](/reference/tasks#review-and-merge) Project, reviews and merges pull requests through it. Orbit reads public repositories without the App. A Project whose owner does not install the App can [read through the Gateway's GitHub CLI](#read-through-the-github-cli) instead.

## What the App is

Each Gateway owns at most one GitHub App. The App is a registration on GitHub with a private key that only this Gateway holds. It has six permissions: `Actions: read`, `Checks: read`, `Contents: write`, `Metadata: read`, `Pull requests: write`, and `Workflows: write`. It receives no webhooks and cannot change repository settings. Each operation asks GitHub for a token with only the permissions it needs, so a read never carries write access.

A task push token carries `Workflows: write`, so a task can change `.github/workflows/`. An installation that has not accepted that permission refuses such a push, and the task reason names `Workflows` as the permission to grant. [Tasks](/reference/tasks#pull-request-and-settle-metrics) describes that failure.

The App is public on GitHub, so any GitHub account can install it. An installation gives your Gateway access to that account's repositories. It gives the installing account nothing.

The Gateway stores the App ID, slug, name, owner, and URL as one plain Gateway setting, and the private key as an encrypted one. No API response, Activity, or Doctor result contains the key or a token.

## Read CI artifacts

The opt-in [TIA setup step](/reference/instance-setup#restore-a-ci-tia-baseline) and the [web build of a Gateway release](/reference/gateway-recovery#web-build) read CI artifacts. Each uses a separate token for one repository with only `Actions: read`. The token and signed download URL stay on the Gateway. Artifact storage receives no GitHub Authorization header. Existing Apps must add the Actions read permission in GitHub App settings, and each installation must accept the update before artifact downloads work. Changing Orbit’s manifest affects new registrations only.

## Register and install

Run [`orbit github:app:install`](/cli/github#orbit-githubappinstall) from a machine whose browser can reach the Gateway's private HTTPS address.

| Run | The command |
| --- | --- |
| First run on a Gateway | Registers the App on GitHub, then opens the install page. |
| Each next run | Opens the install page for another account or organization. |

Registration takes one confirmation in the browser. The Gateway serves a page that posts the App's manifest to GitHub. GitHub shows the App name, and you confirm. GitHub App names are unique across GitHub, so GitHub asks for another name when the name is taken. GitHub then redirects the browser to the Gateway with a one-time code and the `state` value that ties it to this registration. The Gateway exchanges the code for the App ID, slug, and private key. A registration expires after one hour.

On the install page, choose the account or organization and all or selected repositories. The Gateway lists the App's installations on GitHub each time it needs them. So a new installation, a changed repository selection, and an uninstall take effect on the next read.

## How Orbit reads a repository

The Gateway reads a Project repository with `git ls-remote` to resolve its default branch. A Node clones or fetches source for production provisioning, a deployment, a development checkout, a repository or default-branch change, an Instance clone, the published-commit check before a development checkout is removed, and a task workspace before each agent turn.

Each Project has a `source_access` setting. This section describes `github_app`, the default. [Read through the GitHub CLI](#read-through-the-github-cli) describes `gh_cli`.

For each read of a `github.com` repository that an installation covers, the Gateway asks GitHub for a token with `contents: read` for that one repository. The token expires after one hour. The Gateway passes it to `git` through the environment of that one command, as `GIT_CONFIG_*` variables. The token never appears in the origin URL, the command arguments, `.git/config`, or a file on the Node.

Token-bearing Git runs as the Node's managed user with a private git directory at mode `0700`, without a worker ACL. The Gateway copies objects and refs as files, not by running Git against the checkout. It does not copy config or hooks. The private config contains only the remote URL the Gateway writes; an unexpected executable config key stops the operation and asks for assistance. [The checkout cannot inherit the token](#the-checkout-cannot-inherit-the-token) explains why the checkout is not trusted.

Every repository read disables hooks with `core.hooksPath=/dev/null` and clears `core.fsmonitor`, `core.alternateRefsCommand`, `core.sshCommand`, `core.askPass`, `core.gitProxy`, `credential.helper`, and `uploadpack.packObjectsHook`. Direct token-bearing Git uses the same overrides. Clone uses `--no-checkout`; clone, fetch, and push do not check out file contents.

| Repository | Orbit reads it |
| --- | --- |
| On `github.com`, covered by an installation | Over HTTPS with a token. An origin such as `git@github.com:owner/name` is read through its HTTPS form. The stored origin stays as it is. |
| On `github.com`, not covered | Without a credential. A public repository works, and a private repository fails. |
| On another host | Without a credential. A private repository needs an SSH key that you place on the Node. |

When the Gateway cannot resolve the default branch of a `github.com` repository, `project.default_branch_unavailable` names a missing installation and the `gh_cli` setting as possible fixes.

The Gateway needs outbound HTTPS to `api.github.com` for every read of a covered repository. When GitHub does not answer, Orbit reads without a credential, so a public repository still works.

## Read through the GitHub CLI

A Project with `source_access: gh_cli` is read with the GitHub CLI login of the Gateway's `orbit` user. Orbit never asks the App for that Project. Use it for a private `github.com` repository whose owner does not install the App.

```bash
orbit project:update 14 --source-access=gh_cli --default-branch=main
```

### Prepare the Gateway

Install `gh` on the Gateway host and log in as the `orbit` user:

```bash
sudo -u orbit -H gh auth login --hostname github.com
sudo -u orbit -H gh auth status
```

Nodes need no GitHub CLI and no login. So a `gh_cli` Instance moves to any Node, as any other Instance does.

Orbit accepts any login and does not check its scopes. A usual `gh auth login` gives a token that reaches every repository of the account, with write access, until you revoke it. A [fine-grained token](https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/managing-your-personal-access-tokens) with only `Contents: read` limits that reach. Pass it with `gh auth login --with-token`.

### How a read works

For each read, the Gateway runs `gh auth token --hostname github.com` as `orbit`. It hands the token to `git` in the same way as an App token, for the same reads: through `GIT_CONFIG_*` variables of one command, on the Gateway or on a Node through the script on standard input. An SSH-form origin is read through its HTTPS form, and the stored origin stays as it is.

A command that carries a token does not use the checkout as its git directory. The Gateway writes a private git directory the agent cannot write, copies `objects` and `refs` as files, and does not copy `config` or `hooks`. The same command sets `core.hooksPath` to `/dev/null` and sets `core.fsmonitor`, `core.alternateRefsCommand`, `core.sshCommand`, `core.askPass`, `core.gitProxy`, `credential.helper`, and `uploadpack.packObjectsHook` empty. Git 2.55 runs `core.alternateRefsCommand` from the shell during fetch, and that child would inherit `GIT_CONFIG_VALUE_0`, the `Authorization` header. An empty value replaces a program named in a checkout config. When the private config contains any other key whose value Git executes, the Gateway does not run `git`.

The Gateway stores no token. No API response, Activity, Doctor result, origin URL, command argument, `.git/config`, or file on a Node contains it.

### Limits and failures

`gh_cli` needs a `github.com` repository URL. For another host, validation fails on `source_access`. The same check runs when an update changes the URL.

A read never falls back to the App or to a read without a credential. When `gh` is missing, the request fails with `github.cli_unauthenticated`. It fails the same way when `orbit` has no login for `github.com`.

A read that the login cannot complete keeps its own code, such as `project.default_branch_unavailable`. The message names the GitHub CLI login.

A change of `source_access` alone touches no checkout. The Gateway first resolves the remote default branch with the new setting. When that read fails, nothing changes.

Tasks publish only through the App. Creating a task for a `gh_cli` Project fails with `tasks.github_app_required`. A task that exists when its Project changes to `gh_cli` fails to publish and asks for assistance.

## How Orbit publishes a task pull request

After the Gateway commits an approved subtask, it asks GitHub for a token with `contents: write` and `pull_requests: write` for the Project repository. The token reaches the Node in the same way as a read token. `git` pushes the stored commit as `<commit_sha>:refs/heads/task-{id}` and never uses `HEAD`. After the last approval, the Gateway opens the pull request with the same kind of token. When `ORBIT_TASKS_REVIEW_REQUEST_LOGINS` is set, it then requests those GitHub logins as reviewers with that write token, skipping the pull request author. A failed reviewer request does not block publication. [Tasks](/reference/tasks#pull-request-and-settle-metrics) describes the request.

Publishing has no path without the App. Without an installation that covers the repository, the task counts a communication failure and then asks for assistance. [Tasks](/reference/tasks#pull-request-and-settle-metrics) describes the retry.

GitHub refuses a token that asks for a permission the installation has not accepted, with 422 and the message "The permissions requested are not granted to this installation." The assistance reason quotes that message. The account owner accepts the permissions in the installation settings on GitHub.

## How Orbit watches a task pull request

Each scheduler tick reads a settling group's pull request. While it is open, the Gateway also asks for a token with only `checks: read` and lists the check runs of the head commit, at most once a minute. With a non-empty repository entry in `orbit.tasks.github_reviewers`, it reads submitted GitHub reviews through a separate token with only `pull_requests: read`. This permission is already covered by the App's `Pull requests: write` grant; the registration, installation permissions, and webhook policy do not change. The watcher never uses the maintainer's CLI identity. [Fix a settling pull request](/reference/tasks#fix-a-settling-pull-request) describes conflicts, failed checks, and trusted requested changes.

The checks token is separate, because GitHub refuses a whole token request when one permission is not accepted. When GitHub refuses the checks token, the Gateway skips the check runs and still reports conflicts. The check run list follows every page, up to 1,000 runs. A longer list, or one that changes between pages, leaves the health unread for that tick.

### Read review records

Review reads use the App on the Gateway only. The review list is `GET /repos/{owner}/{repo}/pulls/{number}/reviews`. Findings come from `GET /repos/{owner}/{repo}/pulls/{number}/reviews/{review_id}` and `/reviews/{review_id}/comments`. Review lists follow at most 10 pages of 100 records; inline comments from the selected review use at most 5 pages of 100 records. Pagination is accepted only for the expected repository and endpoint on `api.github.com`. A next-page link after the last allowed page is overflow. Invalid records, incomplete pagination, permission failures, and transport failures are unreadable, never a partial successful selection. Review/comment URLs are provenance, not URLs that Orbit fetches.

A cached snapshot lives at most 60 seconds and is scoped to repository, PR, head, and trust configuration. Before consuming a request, the watcher performs uncached reads of the PR, the complete review list, and selected findings. The [Tasks contract](/reference/tasks#retrieve-the-findings) bounds the complete packet at 64 KiB and defines edit/head races, durable deduplication, retries, and assistance. Review failure does not turn CI green, change consumption, or disable existing CI/conflict repair. A token is never cached with review data, put in a brief, or passed to an agent.

Review consumption reads review decisions; it never submits, edits, dismisses, or requests one. Reading `APPROVED` supplies [durable approval evidence](/reference/tasks#inspect-approval-observations), not merge enforcement. Only complete uncached scans confirm that evidence; cached, failed, or incomplete reads do not confirm current approval. The local inspection report reads stored provenance and freshness, without a GitHub call or token. Outside a review-and-merge Project, Orbit does not merge. [How Orbit reviews and merges a pull request](#how-orbit-reviews-and-merges-a-pull-request) covers that Project. The [final-review workflow](/reference/implementation-loop#final-review-of-an-orbit-task-pull-request) keeps the maintainer identity's admin bypass and requires its own exact-head checks.

### Watch open subtasks' branch pull requests

While a task has a subtask in `todo`, `running`, or `reviewing`, the Gateway also lists pull requests for head `{owner}:task-{id}`, or `{owner}:{pr_branch}` for an [incoming pull request](/reference/tasks#incoming-pull-requests), at most once a minute per task. The list is `GET /repos/{owner}/{repo}/pulls` with query `head={owner}:task-{id}` and `state=all`. The token asks only for `pull_requests: read`. The Gateway accepts GitHub's canonical owner and repository casing in a listed pull request URL, because that identity is case-insensitive. It still requires the exact `https://github.com/` host and scheme, the `/pull/{number}` path, and a number matching the row. A URL for another repository leaves the list unreadable.

The Gateway resolves the repository's installation id, caches it, and reuses that id for later lists of the same repository. The cached id is not a column on the task. When GitHub refuses the token for that id, the Gateway drops the cached id, resolves the installation again, and retries the list once. A second failure leaves the list unreadable. [Watch the branch while subtasks are open](/reference/tasks#watch-the-branch-while-subtasks-are-open) describes which pull request is stored and what a merged or closed result does.

## How Orbit reviews and merges a pull request

A Project with [review and merge](/reference/tasks#review-and-merge) on uses the App for four more calls. Each call asks GitHub for its own token, as the table shows.

| Call | Endpoint | Token |
| --- | --- | --- |
| List open pull requests | `GET /repos/{owner}/{repo}/pulls?state=open`, oldest first, at most three pages of 100 | The cached `pull_requests: read` token of the [branch watch](#watch-open-subtasks-branch-pull-requests) |
| Submit a review | `POST /repos/{owner}/{repo}/pulls/{number}/reviews` with `event` `APPROVE` or `REQUEST_CHANGES` and `commit_id` | The publishing token |
| Read the merge check | `GET /repos/{owner}/{repo}/commits/{sha}/check-runs?check_name={name}`, every page | `checks: read` |
| Merge | `PUT /repos/{owner}/{repo}/pulls/{number}/merge` with `merge_method` `merge` and `sha` | The publishing token |

The publishing token asks for `contents: write`, `pull_requests: write`, and `workflows: write`. GitHub documents the review and merge endpoints under `Pull requests: write`, and the merge endpoint under `Contents: write` too. A merge of a change under `.github/workflows/` also needs `workflows`. So the flow needs no new App permission, no maintainer credential, and no webhook.

A malformed pull request in the list fails the whole list for that minute. A refused review fails the step, and Orbit retries it on the publication backoff. A refused merge is recorded as the task's merge result with GitHub's status and message. A server error or an unreachable GitHub leaves the merge result `waiting`.

GitHub forbids an account to review its own pull request, and that includes the App. So Orbit submits reviews only on pull requests that a person opened. It never reviews a pull request it opened. The merge needs no review: the `sha` parameter makes GitHub refuse the merge when the head moved.

An App merge is a push by the App to the default branch, so GitHub starts the `push` workflows for the merge commit. A merge with the workflow `GITHUB_TOKEN` would start none. The Gateway's [automatic release](/reference/gateway-recovery#automatic-releases) needs that `Required checks` run on the merge commit.

## Find the newest green commit

The Gateway can find the newest commit of a branch that may ship. This is the first step of an automatic release. The [automatic release runner](/reference/gateway-recovery#automatic-releases) asks once a minute while automatic releases are enabled. While it is up to date, it also reads the branch head at most every 15 minutes, with the installation, one `contents: read` token, and one commits page. The question names a repository, a branch, and a required check. For the Gateway itself these are `nckrtl/orbit`, `main`, and `Required checks`. The release history adds two inputs: the commit that is deployed now, and the commits that already failed a release.

A commit qualifies when all of these hold. They are the same rules that [`bin/pr-head-check`](https://github.com/nckrtl/orbit/blob/main/bin/pr-head-check) applies to a pull request head.

- At least one check run has exactly the required name.
- Every such run has `head_sha` equal to the commit.
- Every such run has completed with conclusion `success`. Any other state or conclusion fails this rule, including a queued or running run.
- Every such run was created by GitHub Actions (`app.slug` is `github-actions`). Another App with `checks: write` could create a run with the same name. Its run never makes a commit green, and it disqualifies the commit.
- The commit strictly descends from the deployed commit. The compare API must report `ahead`, with the deployed commit as the merge base.
- The commit has not failed a release.

A `behind`, `identical`, or `diverged` comparison never qualifies. So the Gateway never downgrades, and it never releases history that left the branch.

The Gateway walks the branch from its head along first parents, newest first. It stops at the deployed commit. A commit of a merged side branch is never a candidate, even when its pull request checks passed. The first qualifying commit wins, so a newer commit whose checks still run does not hold back an older green one.

A deployed commit is required. Without one, descent cannot be proven, so there is no answer. The first release of a target is deployed by hand.

The deployed commit does not need to be among the listed commits. When it is older than the commits page or the 20 candidates, the walk still examines the newest candidates, and the compare proves descent for the first green one. When no candidate descends from the deployed commit, for example after a force push removed it from the branch, there is no answer. That lasts until an operator releases a commit of the branch, or the branch merges the deployed commit.

GitHub runs CI only for the head of a push. A commit that was pushed together with a newer one has no `Required checks` run, so it never qualifies; the newer commit covers it. A `Required checks` run can also succeed while GitHub reports its workflow run as cancelled, when the cancellation came after the job finished. The check run decides.

One answer costs these reads:

| Read | Token | Bound |
| --- | --- | --- |
| `GET /repos/{owner}/{repo}/installation` | App JWT | Once |
| `GET /repos/{owner}/{repo}/commits?sha=refs/heads/{branch}` | `contents: read` | One page of 100 commits |
| `GET /repos/{owner}/{repo}/commits/{sha}/check-runs?check_name={name}` | `checks: read`, minted only when a candidate needs it | Every page, at most 1,000 runs, for at most 20 candidates |
| `GET /repos/{owner}/{repo}/compare/{deployed}...{sha}` | `contents: read` | Once, for the first green candidate |

Each answer also mints one `contents: read` token, and one `checks: read` token when a candidate needs it. No new App permission is needed. When the deployed commit is the branch head, the answer needs only the installation, one token, and the commit list.

The Gateway reads the branch through its full ref, so a tag with the same name is never read instead. When none of the 20 newest candidates qualifies, there is no answer until a newer commit turns green. Every failure fails closed: an App that is not registered or not installed, a refused token, an unknown commit, a check run list that changes or ends before its `total_count`, or a malformed record means no answer for that attempt.

## What the App does not cover

Git commands that you run by hand in a development checkout use your own credentials. Orbit installs no credential helper on a Node and does not sign the GitHub CLI in on a Node.

A task agent does not receive a GitHub token and never fetches or pushes. The agent runs as `orbit-worker`. The token exists only in the environment of one `git` command, and that command uses the private git directory above, running as the Node's managed user. A program that the agent starts cannot see the token.

The candidate gate is the exception: it runs workspace programs as the managed user, and such a program could observe a token-bearing `git` process of that user that runs at the same time. [The candidate gate runs as the managed user](/reference/pi-server#the-candidate-gate-runs-as-the-managed-user) records that cost. The agent shares the Pi server's user, so it can read that server's token and provider sign-in. [Limits](/reference/pi-server#limits) records both bounds. [One user for every task agent](/reference/pi-server#one-user-for-every-task-agent) explains the account boundary.

`git checkout` and the approval commit do not carry the token. The approval commit and Git in a task workspace run as `orbit-worker`, so a filter they start runs as `orbit-worker`. Orbit's own checkout of a development Instance, at create and on a branch switch, runs as the managed user, so the managed user owns the files it writes. Fetch, `git clone --no-checkout`, and push do not check out file contents. Task teardown runs as `orbit-worker` and executes the root-owned helper, not a file from the checkout. Privileged removal deletes the tree as the managed user and runs no checkout program.

Before each agent turn, the Gateway itself fetches the task workspace. That fetch uses the read token and `--no-tags`, not a token handed to the agent. [Tasks](/reference/tasks#fetch-before-a-turn) names the refs. When the fetch fails, the turn still starts, and its message says the fetch failed and warns that `origin/*` may be stale.

## Errors

The `github` commands return these codes. Reads of a `gh_cli` Project return `github.cli_unauthenticated`.

| Error code | Meaning |
| --- | --- |
| `github.app_missing` | The Gateway has no GitHub App. Run `github:app:install`. |
| `github.registration_invalid` | The redirect does not match a registration that this Gateway started, or the registration expired. Run `github:app:install` again. |
| `github.registration_failed` | GitHub refused to exchange the one-time code. |
| `github.unavailable` | The Gateway could not reach `api.github.com`, or GitHub refused the App credential. |
| `github.installation_pending` | The command stopped waiting before the Gateway saw a new installation. Finish the installation in the browser and check with `github:app:show`. |
| `github.cli_unauthenticated` | A read of a `gh_cli` Project found no `gh` on the Gateway, or no `github.com` login for the `orbit` user. Run `gh auth login` as `orbit`. |

A read that fails for a repository reason keeps its own error code, such as `project.default_branch_unavailable`.

## Recover and remove

A Gateway restored from its backup keeps the App, because the backup holds the database and the encryption key. [Gateway recovery](/reference/gateway-recovery) describes that backup. A new Gateway without the backup has no private key, and GitHub never shows a key again. Run `github:app:install` on the new Gateway to register a new App, install it on each account again, and delete the old App on GitHub.

[`orbit github:app:destroy`](/cli/github#orbit-githubappdestroy) deletes the stored App ID, slug, and private key. Orbit then reads every repository without a credential and cannot publish task pull requests. GitHub has no API that deletes an App registration, so the command prints the GitHub page where you delete it.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### One App per Gateway

A shared Orbit App would need a central token service that holds its private key. Every clone would then depend on that service, the service could read every user's private repositories, and each Gateway would have to prove which installations are its own. One registration per Gateway costs one browser confirmation, once.

### A token for each operation

The Gateway starts every repository read and push itself, so it can create a token at the moment of use. A token stored on a Node, or refreshed there on a schedule, would rest on every Node and need recovery when it goes stale. A leaked token reads one repository for at most one hour.

### The checkout cannot inherit the token

The token is in the environment of one `git` process, running as the managed user. A program named by the checkout, including a hook, `fsmonitor`, or `core.alternateRefsCommand`, would be a child of that process and would see the token. Git 2.55 runs `core.alternateRefsCommand` from a shell during fetch, and that child inherits `GIT_CONFIG_VALUE_0`, including the Authorization header. Clearing only hooks and `fsmonitor` was therefore rejected. Token-bearing Git uses a private git directory that the worker cannot write and clears executable config keys as a second guard. It never reads the checkout's `.git/config` for that operation.

The task agent runs as `orbit-worker` and is not that process. Filters started by the approval commit or by Git in a task workspace run as the worker without the token. Orbit's own create and branch-switch checkouts run as the managed user. A filter can only come from `.git/config`, which the worker cannot write, and later steps such as `.env` and SQLite setup require files the managed user owns. The baseline and handoff checks are the one exception, for host-dependent tests; [the candidate gate runs as the managed user](/reference/pi-server#the-candidate-gate-runs-as-the-managed-user).

Create-time source resolution may run as the managed user before an agent has written the checkout. The node agent reads Git with libgit2, which starts no checkout program. [One user for every task agent](/reference/pi-server#one-user-for-every-task-agent) explains the account and ACL choices.

A credential helper on the Node was rejected because it remains callable by the agent after the command ends. The private git directory is temporary; the token stays in one command's environment. These controls separate the GitHub credential from agent programs. They do not stop a member of `incus-admin` who deliberately uses its root-equivalent power.

### No personal access token and no deploy keys

A personal access token acts as the operator, lasts for months, and would rest on every Node. The [GitHub CLI setting](#the-gateway-github-cli-for-projects-without-the-app) accepts the first two costs for the Projects that choose it, and keeps the token on the Gateway. A deploy key needs repository administration rights to install, one key per repository and Node, and gives no single place to grant or revoke access.

### The Gateway GitHub CLI for Projects without the App

An organization can refuse to install a third-party App. Its operators already have a GitHub CLI login. One login on the Gateway serves every Node, because the Gateway already sends a token with each read. The token rests only on the Gateway, which already holds the App's private key and SSH access to every Node. A Node sees the token only while a read runs there, so whoever controls that Node can copy it. That person already controls the Node, so this adds no new boundary.

A login on each Node was rejected. Every Node needs its own login, and moving an Instance needs one on the target. A credential helper that runs `gh` in each command was rejected, because production clones run as the Instance user, which has no login. Storing the token in Orbit was rejected, because the GitHub CLI already stores and refreshes it. A fallback to the App or to no credential was rejected, because the error then points at the wrong cause. Publishing task pull requests through the GitHub CLI is not built yet.

### Review reads do not need review authority

The existing App grant can mint a token narrowed to `pull_requests: read`. Feedback needs no new App permission, maintainer credential, or public webhook. Numeric account trust and once-only repair belong to the [Tasks contract](/reference/tasks#trusted-reviews-are-input-not-merge-authority); the App merely supplies complete bounded source records. A write-scoped publishing token is not needed for review retrieval.

### One App reviews and merges

The App already publishes Orbit's task pull requests, so it reviews and merges with the grants it holds. The maintainer's GitHub CLI profile was rejected for this: it is a personal identity with admin bypass, so a merge through it skips the ruleset. The App is bound to the ruleset like any other actor. A second App with only review rights was rejected: it would add a registration, a private key, and an installation for each account, and it still could not approve Orbit's own pull requests.

### No webhooks

The Gateway is private, so GitHub cannot reach it. It lists installations and pull request state when it needs them.

### Cache the installation for the branch list

The Gateway is private, so GitHub cannot push a merge event to it. `tasks:tick` runs every 10 seconds. A list on every tick would call GitHub six times a minute for each active task. The [list-by-head read](#how-orbit-watches-a-task-pull-request) runs at most once a minute. The installation id stays in the Gateway cache so those lists do not resolve the installation again. A refused token drops the id and resolves it once more.
