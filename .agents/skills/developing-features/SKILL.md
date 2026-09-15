---
name: developing-features
description: Use when implementing or fixing Orbit behavior and preparing a complete PR.
---

# Developing Features

Deliver a complete feature PR following the [contributor guide](../../../docs/contributor-guide.md).

## Prepare

Read the request, affected code, tests, and ADRs. Settle unclear behavior with the user. Build on decisions and documentation already prepared for the feature.

Before coding, draft significant ADR changes and update the user-facing documentation. Follow the [documentation guide](../writing-documentation/SKILL.md) and keep the proposal consistent.

## Implement and check

Implement the documented behavior using the affected project's conventions. Add tests for success and important failure cases. Check ownership, input validation, and secret handling where the feature changes them.

For command changes, follow the [CLI standard](../../../docs/reference/cli-ux.md). Use [verifying-cli-output](../verifying-cli-output/SKILL.md) when interaction or rendering needs a real terminal.

Run `composer test:affected` and `composer check` in each changed project. Confirm that the tests covering the feature ran. Keep the documentation and ADRs aligned with the result and run the documentation checks.

## Submit

Use the [PR template](../../../.github/pull_request_template.md) to describe the problem, resulting behavior, ADRs, documentation, checks, and limitations. Submit the complete PR when requested. Address review findings and repeat affected checks.
