---
title: "Contributing to Orbit"
sidebarTitle: "Contributor guide"
description: "Prepare architecture and documentation, build a feature, and submit a complete pull request."
---

# Contributing to Orbit

Submit a complete feature with its implementation, tests, documentation, and any architectural decisions. Orbit reproduces features on Incus during independent review, and the maintainer approves each merge.

From the repository root, install the project dependencies:

```bash
bin/bootstrap
```

## 1. Check the architecture

Read the affected [architecture decisions](/decisions/overview), code, tests, and documentation. Establish what the feature should do and which parts of Orbit it affects. You can find related documentation with `composer docs-context -- --component=apps/cli`, using the component your feature changes.

When the feature changes a significant architectural decision, draft an ADR in the same branch. Explain the alternatives, consequences, and any existing ADR it extends or supersedes.

Reviewers assess the proposed ADRs alongside the implementation and documentation. The ADRs become accepted when the maintainer approves the PR and it is merged. See the [ADR guide](https://github.com/nckrtl/orbit/blob/main/docs/decisions/README.md) for status and history conventions.

## 2. Write the documentation

Update the pages under `docs/` before coding. Describe what users can do, the limits, and what happens when an operation fails. Write in the present tense and check that the pages and proposed ADRs agree. The documentation ships with the implementation.

Run from the repository root:

```bash
composer docs-build
composer docs-lint
```

Commit `docs/generated/context.json` when generation changes it. For Mintlify page or navigation changes, also run `npx mint validate` and `npx mint broken-links` from `docs/`.

## 3. Implement and verify

Build the feature and tests against the documented behavior. Keep proposed ADRs and documentation aligned with what the implementation delivers. Explain material changes in direction in the PR.

`composer test:affected` selects tests with Pest test-impact analysis (TIA), which needs PCOV or Xdebug. Without a coverage driver, TIA is skipped and every test runs. On macOS, install PCOV with `brew install shivammathur/extensions/pcov@8.5`.

Run these commands in each changed project, such as `apps/cli`:

```bash
composer test:affected
composer check
```

Gateway tests run the shell programs that Orbit installs on Ubuntu Nodes. On macOS, install the Linux tools they need with `brew install bash coreutils gnu-sed findutils caddy`. The test bootstrap puts these tools first on `PATH`, supplies `setsid` and `flock`, and stops with the missing package names when a tool is absent. Tests of Node programs that use Linux kernel interfaces, such as `/proc/net/tcp` or `os.O_PATH`, run in a Debian PHP container through a local Docker runtime. Start one first, for example with `brew install colima docker` and `colima start`.

Add regression coverage for behavior changes and their important failure modes. Confirm that the tests exercising the new behavior ran.

GitHub CI runs quality checks and affected tests for all five projects, including documentation lint. Root `composer check` can also run the complete local check on a clean commit.

## 4. Submit a complete pull request

Explain the problem, resulting behavior, architectural decisions, documentation changes, verification results, and remaining limitations. Link an issue when one exists.

Request maintainer review when the feature is complete. If you use a draft PR while working, mark it ready when implementation is complete.

## Review and merge

Orbit's independent reviewer checks the code, documentation, and ADRs and reproduces the feature on Incus. The reviewer records the commit, environment, actions, results, and limitations.

Address review findings. Reviewers check the fixes and repeat affected verification on the updated PR. Passing CI, successful Orbit code and Incus review, resolved findings, and the maintainer's approval are required to merge.

## Use an agent

The skills in the repository guide an agent through the work.

| Task | Skill |
| --- | --- |
| Shape the feature and prepare its ADRs and documentation | [grill-with-docs](https://github.com/nckrtl/orbit/blob/main/.agents/skills/grill-with-docs/SKILL.md) |
| Implement, verify, and submit the feature | [developing-features](https://github.com/nckrtl/orbit/blob/main/.agents/skills/developing-features/SKILL.md) |
| Independently review a proposal or completed PR | [reviewing-pull-requests](https://github.com/nckrtl/orbit/blob/main/.agents/skills/reviewing-pull-requests/SKILL.md) |
| Merge an approved PR and clean up | [merging-pull-requests](https://github.com/nckrtl/orbit/blob/main/.agents/skills/merging-pull-requests/SKILL.md) |

For focused work, use [writing-documentation](https://github.com/nckrtl/orbit/blob/main/.agents/skills/writing-documentation/SKILL.md) or [verifying-cli-output](https://github.com/nckrtl/orbit/blob/main/.agents/skills/verifying-cli-output/SKILL.md). CLI development and review follow the [CLI standard](/reference/cli-ux).

An independent reviewer can review the proposed ADRs and documentation before coding when requested. The [feature delivery reference](/reference/implementation-loop) describes review evidence and merge responsibilities.

## Report a problem

Use [GitHub issues](https://github.com/nckrtl/orbit/issues) for bugs and questions. Include the source commit, Orbit version, operating system, exact command, expected result, and observed result. Remove credentials, environment values, private keys, tokens, and personal data from shared logs. Include a request ID and stable error code when available.
