---
title: "Source alpha"
description: "A bounded trial, known limitations, release criteria, and feedback."
---

# Source alpha

Early users can use this guide to choose a bounded Orbit trial and report useful feedback. The trial starts with source installation and ends with one development App instance serving a private HTTPS page. Use disposable machines and data you can replace.

## Trial path

Follow the guides in this order and keep the source commit used on each machine.

| Step | Guide | Required observation |
| --- | --- | --- |
| Install the CLI and Gateway | [Installation](/reference/installation) | Composer platform checks pass and the Gateway services start. |
| Connect the CLI | [Installation](/reference/installation#connect-the-cli) | A trusted HTTPS request to `gateway:status` succeeds. |
| Add one development Node | [First app](/reference/first-app) | The Node and its `app-dev` role become active over WireGuard. |
| Serve an application | [First app](/reference/first-app#verify-the-page) | Private DNS resolves the returned hostname and HTTPS serves the expected page. |
| Recover an update | [Gateway recovery](/reference/gateway-recovery) | A restored disposable Gateway reads its saved state and serves the same application through the same fleet. |

The guides describe the current source contract. A documentation build or an automated test does not prove that this complete path works on a fresh machine.

## Limits

The first trial has a deliberately small environment and workload. Orbit Ops can also download toolbox binaries from the [CLI binaries](/reference/cli-binaries) contract. That download is outside this trial.

| Area | Trial boundary |
| --- | --- |
| Distribution | Full monorepo source at one recorded commit or published release tag; this trial does not require a binary. Standalone Composer package publication needs a separate distribution workflow. |
| Machines | Ubuntu 26.04 Gateway and managed Nodes; see [requirements](/reference/installation#requirements). |
| Application | One standalone development Node and one public Git repository with a static page. Application databases and dependency installation need separate application setup. |
| Network | Private HTTPS through WireGuard and Orbit DNS; no public production launch is implied. |
| Production | Production deployment commands exist, but this trial establishes no production readiness, availability guarantee, or application-data recovery guarantee. |
| Updates | Review each release's instructions. Database migrations can prevent a code-only downgrade. |

Private Route changes can require coordinated runtime and DNS work. Consult the refusal boundaries in [Routes](/reference/routes) before changing hostnames, membership, or routing. An active App instance means Orbit completed provisioning; it does not establish application health.

## Release criteria

The maintainer uses observed results to decide whether to publish a source alpha tag. The release notes identify the exact commit, supported environment, known limitations, and the results of these checks.

| Check | Evidence needed before claiming it passed |
| --- | --- |
| Fresh installation | Start with fresh machines and no retained Orbit state; run the published guides and record commands, exit codes, and machine versions. |
| First application | Verify the expected page through private DNS and HTTPS without bypassing certificate verification. |
| Recovery | Restore a pre-update backup in a disposable environment and verify state, access, and the application URL. |
| Documentation | Check the rendered navigation and guides, and run the documentation and Mintlify checks. |
| Candidate | Retain the local quality-gate result and independent review for the exact source commit. |
| Open issues | Classify issues against this path; unresolved failures of the path block release, unrelated features do not. |

These criteria are a preparation checklist, not a release approval. Do not infer a published release from a version string in a development checkout.

## Feedback

Use [GitHub issues](https://github.com/nckrtl/orbit/issues) for bugs and questions. Include the source commit from `git rev-parse HEAD`, the CLI output from `orbit --version`, the Gateway version from `orbit gateway:status`, and the operating systems involved. Describe the command, expected result, observed result, and reproduction steps.

Include stable error codes and request IDs when available. Review output before sharing it and remove tokens, private keys, passwords, environment values, credential-bearing repository URLs, and personal data. Never attach the Gateway database or a state backup. The public [contributor guide](https://github.com/nckrtl/orbit/blob/main/CONTRIBUTING.md) explains local checks and maintainer-owned machine verification.
