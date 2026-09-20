---
title: "ADR 0098: Read GitHub repositories through a Gateway-owned GitHub App"
sidebarTitle: "0098 Read GitHub repositories through a Gateway-owned GitHub App"
description: "Proposed. Each Gateway owns one read-only GitHub App. The Gateway creates a short-lived token for one repository for each clone, fetch, and ls-remote. No GitHub credential is stored on a Node."
---

# ADR 0098: Read GitHub repositories through a Gateway-owned GitHub App

Each Gateway owns one GitHub App with read-only repository access. The Gateway creates a short-lived installation token for one repository each time Orbit reads that repository, and passes it to `git` for that one command. Private repositories work on every Node without a stored credential, and the operator's personal GitHub account stays out of Orbit.

## Status

Proposed.

This extends [ADR 0026](/decisions/0026-identify-each-app-by-one-repository) and [ADR 0031](/decisions/0031-clone-initial-production-source-during-provisioning). It follows the domain-owned encrypted settings of [ADR 0003](/decisions/0003-singleton-metrics-role) and the output limits of [ADR 0004](/decisions/0004-verify-only-doctor-boundary).

## Context

Orbit reads App repositories with plain `git`. The Gateway runs `git ls-remote` to resolve a default branch. Nodes run `git clone` and `git fetch` as the managed unix user for production provisioning, production deploys, development checkouts, App instance clones, and removal checks. None of these commands receives a credential, and `GitRepositoryOrigin` refuses an HTTPS origin that carries a user or password. A private repository works only after an operator places an SSH key on the Node by hand, and every new Node repeats that work.

A personal access token would fix this, but it acts as the operator, lasts up to a year, and must be stored on each Node or rotated by hand. A GitHub App has its own identity, per-repository access, and tokens that expire after one hour. The Gateway already starts every repository read over SSH, so it can supply a credential at the moment of use.

Orbit is self-hosted. A GitHub App's private key creates every token, so the key must stay on the operator's own Gateway. GitHub's App manifest flow lets a program create an App with one browser confirmation and receive the App ID and private key.

Writes to GitHub, such as pull requests from agents, belong to the tools that make them. Orbit works without those tools.

## Decision

- Each Gateway owns at most one GitHub App. `github:app:install` registers it on GitHub for a Gateway that has none and then continues with the installation. Registration is optional, because Orbit reads public repositories without it. The Gateway serves a page that posts an App manifest to GitHub, the operator confirms in the browser, and GitHub redirects to the Gateway with a code. The Gateway exchanges the code for the App ID, slug, and private key. A `state` value ties the redirect to the request that started it.
- The manifest requests `Contents: read` and `Metadata: read`, turns webhooks off, and marks the App public so that accounts other than its owner can install it. It omits `default_events` rather than sending an empty list, because GitHub's convert endpoint rejects `[]`. The operator chooses the App name, because GitHub App names are unique across GitHub.
- The Gateway stores the App ID, slug, and private key as GitHub-owned Secret settings with Gateway scope. API responses, activity, and Doctor output never contain the key or a token.
- The operator installs the App on each GitHub account or organization whose repositories Orbit reads. `github:app:install` opens the install page. It uses the `install` verb of the [CLI command vocabulary](/reference/cli-command-vocabulary), because GitHub calls it that. `github:app:destroy` uses `destroy`, because the Gateway owns what it deletes. The Gateway learns installations by listing them with the App credential; it receives no webhooks.
- For each `git ls-remote`, `git clone`, and `git fetch` that Orbit runs against a `github.com` repository covered by an installation, the Gateway creates an installation token limited to that repository and to `contents: read`. The token reaches `git` through the process environment of that one command. It never appears in the origin URL, command arguments, `.git/config`, or a file on the Node.
- A covered repository with a `git@github.com:` origin is read through its HTTPS form. [ADR 0026](/decisions/0026-identify-each-app-by-one-repository) already treats both forms as one repository. The stored origin does not change.
- A repository without a covering installation, and a repository on another host, is read without a credential as before. When the Gateway cannot resolve the default branch of a `github.com` repository, the error names the missing installation as a possible cause. A read also runs without a credential when GitHub does not answer the token request.
- Git commands that a person or agent runs by hand in a development checkout use that person's or tool's own credentials. Orbit installs no credential helper and does not authenticate the GitHub CLI.

## Rejected alternatives

- Store a personal access token and push it to Nodes: rejected. It acts as the operator, lives for months, and rests on every Node.
- Refresh an installation token on each Node on a schedule: rejected. Orbit starts every read itself, so a token for each operation needs no schedule, no stale-token recovery, and no credential on a Node.
- Register one shared Orbit App and run a central token service, as hosted platforms do: rejected. The App's private key can create tokens for every installation, so it cannot be given to Gateways. A central service holds it instead. Every clone then depends on that service, the service is able to read every user's private repositories, and each Gateway has to prove which installations belong to it. One registration for each Gateway costs one extra browser confirmation, once.
- Request write permissions so agents can open pull requests through the same App: rejected. It forces one bot identity on every tool and raises the cost of a leaked token. A tool that writes to GitHub brings its own App or credentials.
- Create a deploy key for each App repository: rejected. It needs repository administration rights to install, one key for each repository and Node, and gives no single place to grant or revoke access.

## Consequences

- A new Node can clone private repositories as soon as it is provisioned.
- A leaked token reads one repository for at most one hour.
- The operator performs one browser confirmation to create the App and one for each GitHub account that installs it.
- The Gateway needs outbound HTTPS access to `api.github.com` for every read of a covered repository.
- A Gateway restored from its backup keeps the App. A new Gateway without that backup has no private key. The operator runs `github:app:install` again, which registers a new App, installs it on each account again, and deletes the old App on GitHub.
- Private repositories on other Git hosts still need a manual SSH key.
- Hand-run `git pull` and `git push` in a development checkout of a private repository need credentials that Orbit does not supply.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/docs, apps/e2e
- ADRs: extends [ADR 0026](/decisions/0026-identify-each-app-by-one-repository) and [ADR 0031](/decisions/0031-clone-initial-production-source-during-provisioning); follows [ADR 0003](/decisions/0003-singleton-metrics-role) and [ADR 0004](/decisions/0004-verify-only-doctor-boundary)
- Detail: [GitHub App](/reference/github-app), [`github`](/cli/github), [Apps](/reference/apps)
- Verify: Gateway tests for manifest exchange, installation lookup, and token scope; tests that the clone, fetch, and ls-remote commands carry the token only in the environment; an Incus proof that clones a private repository on a fresh Node
