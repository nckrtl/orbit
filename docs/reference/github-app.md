---
title: "GitHub App"
description: "How a Gateway registers its own GitHub App, how an operator installs it on a GitHub account, and how Orbit uses it to read private repositories and publish task pull requests."
---

# GitHub App

This reference is for operators whose Projects live in private `github.com` repositories, and for operators who use the [tasks extension](/reference/tasks). A Gateway reads private repositories through its own GitHub App, and the tasks extension publishes its pull requests through it. Orbit reads public repositories without it. [ADR 0098](/decisions/0098-read-github-repositories-through-a-gateway-owned-github-app) owns the decision.

## What the App is

Each Gateway owns at most one GitHub App. The App is a registration on GitHub with a private key that only this Gateway holds. It has four permissions, `Checks: read`, `Contents: write`, `Metadata: read`, and `Pull requests: write`, and it receives no webhooks. It cannot change repository settings. Each operation asks GitHub for a token with only the permissions it needs, so a read never carries write access.

The App is public on GitHub. Public means that any GitHub account can install it, which lets you add organizations that do not own the registration. An installation gives your Gateway access to that account's repositories. It gives the installing account nothing.

The Gateway stores the App ID, slug, and private key as encrypted settings. No API response, activity record, or [Doctor](/concepts#doctor) result contains the key or a token.

## Register and install

Run [`orbit github:app:install`](/cli/github#orbit-githubappinstall) from a machine whose browser can reach the Gateway's private HTTPS address.

| Run | The command |
| --- | --- |
| First run on a Gateway | Registers the App on GitHub, then opens the install page. |
| Each next run | Opens the install page for another account or organization. |

Registration takes one confirmation in the browser. The Gateway serves a page that sends the App's definition to GitHub. GitHub shows the definition with the App name, and you confirm. GitHub App names are unique across GitHub, so GitHub asks for another name when the name is taken. GitHub then redirects the browser to the Gateway with a one-time code, and the Gateway exchanges the code for the App ID and private key. The code is valid for one hour.

On the install page, choose the account or organization and either all repositories or selected ones. The Gateway receives no webhook. It lists the App's installations on GitHub each time it needs them, so a new installation, a changed repository selection, and an uninstall on GitHub take effect on the next read.

## How Orbit reads a repository

Orbit reads a Project repository when the Gateway resolves a default branch with `git ls-remote`, and when a Node clones or fetches source. Node reads happen for production provisioning, a production deployment, a development checkout, a repository or default-branch change, a clone of an Instance, and the published-commit check before a development checkout is removed.

For each of these reads of a `github.com` repository that an installation covers, the Gateway asks GitHub for a token. The token reads that one repository and expires after one hour. The Gateway passes it to `git` in the environment of that one command. The token is never part of the origin URL, the command arguments, `.git/config`, or a file on the Node.

| Repository | Orbit reads it |
| --- | --- |
| On `github.com`, covered by an installation | Over HTTPS with a token for that repository. An origin of the form `git@github.com:owner/name` is read through its HTTPS form. The stored origin stays as it is. |
| On `github.com`, not covered | Without a credential. A public repository works, and a private repository fails. |
| On another host | Without a credential. A private repository needs an SSH key that you place on the Node. |

When the Gateway cannot resolve the default branch of a `github.com` repository, `app.default_branch_unavailable` names the missing installation as a possible cause.

The Gateway needs outbound HTTPS access to `api.github.com` for every read of a covered repository. When GitHub does not answer, Orbit reads the repository without a credential, so a public repository still works.

## How Orbit publishes a task pull request

After the Gateway commits an approved subtask, it asks GitHub for a token with `Contents: write` and `Pull requests: write` for the Project repository. The token reaches the Node the same way as a read token, and `git` pushes that stored commit to `origin/task-{group id}` as `<commit_sha>:refs/heads/task-{group id}`. The push never uses `HEAD`. After the last approval, the Gateway opens the pull request with the same kind of token and then reads its state.

Unlike a read, publishing has no path without the App: without an App or an installation that covers the repository, the task counts a communication failure and then asks for assistance. [ADR 0121](/decisions/0121-end-agent-turns-with-a-run-receipt) owns the pull request, and [ADR 0160](/decisions/0160-push-each-approved-subtask-and-remove-the-finished-workspace-clone) owns the push after every approval. The [tasks reference](/reference/tasks#pull-request-and-settle-metrics) describes the retry.

An installation made before the App gained `Contents: write` and `Pull requests: write` keeps its old permissions until the account owner accepts the new ones in the installation settings on GitHub. Until then, GitHub refuses the publish token with 422, and the assistance reason quotes GitHub's message: "The permissions requested are not granted to this installation."

## How Orbit watches a task pull request

Each scheduler tick reads a settling group's pull request with the pull request token. While the pull request is open, the Gateway also asks GitHub for a second token with only `Checks: read`, and lists the check runs of the pull request's head commit. A conflict or a failed check appends one fixup subtask. Each problem gets at most two fixups. When every current problem is at that cap, the group asks for assistance. The [tasks reference](/reference/tasks#fix-a-settling-pull-request) describes both. [ADR 0140](/decisions/0140-watch-settling-pull-requests-for-conflicts-and-failed-checks) owns detection. [ADR 0164](/decisions/0164-heal-a-settling-pull-request-with-a-fixup-subtask) owns the fixup.

The checks token is separate because GitHub refuses a whole token request that names a permission the installation has not accepted. When GitHub refuses the checks token, the Gateway skips the check runs and still reports conflicts. Publishing and merge watching keep working.

An App registered before Orbit requested `Checks: read` does not have it. Open the App settings on GitHub, set Permissions → Checks to Read-only, and save. Then the account owner accepts the new permission in the settings of each installation. Failed checks are reported after that.

## What the App does not cover

Git commands that you or an agent run by hand in a development checkout use your own credentials. Orbit installs no credential helper on a Node and does not sign the GitHub CLI in. Agents never receive a token.

## Errors

The `github` commands return these codes.

| Error code | Meaning |
| --- | --- |
| `github.app_missing` | The Gateway has no GitHub App. Run `github:app:install`. |
| `github.registration_invalid` | The redirect from GitHub does not match a registration that this Gateway started, or the one-time code has expired. Run `github:app:install` again. |
| `github.registration_failed` | GitHub refused to exchange the one-time code. |
| `github.unavailable` | The Gateway could not reach `api.github.com`, or GitHub refused the App credential. |
| `github.installation_pending` | The command stopped waiting before the Gateway saw a new installation. Finish the installation in the browser and check with `github:app:show`. |

A read that fails for a repository reason keeps its existing error code, such as `app.default_branch_unavailable`.

## Recover and remove

A Gateway restored from its backup keeps the App, because the backup holds the database and the encryption key. [Gateway recovery](/reference/gateway-recovery) describes that backup. A new Gateway without that backup has no private key, and GitHub does not show an existing key again. Run `github:app:install` on the new Gateway to register a new App, install it on each account again, and delete the old App on GitHub.

[`orbit github:app:destroy`](/cli/github#orbit-githubappdestroy) deletes the stored App ID, slug, and private key. Orbit then reads every repository without a credential. GitHub offers no API that deletes an App registration, so the command prints the GitHub page where you delete it.
