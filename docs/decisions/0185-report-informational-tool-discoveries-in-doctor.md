# ADR 0185: Report informational tool discoveries in Doctor

Doctor reports unregistered installed packages as informational findings. They do not make a Node unhealthy.

## Status

In progress.

Principle: [Deterministic first](/mission#principles). Code compares package inventory with explicit Tool ownership and computes health from the finding kind.

## Context

Doctor checks registered resources and has only drift and unverifiable findings. Scans for unmanaged packages are outside its documented boundary. With selected tool ownership, an unregistered package is useful information, not a broken contract.

The Node Tools page needs the same discoveries. Doctor must keep its [verify-only boundary](/cli/doctor#verify-only) and must not become an adoption or repair engine.

## Decision

Add the finding kind `informational`. The tool family uses read-only Homebrew and Vite+ inventory to report unregistered installed packages, including casks. The finding identifies the manager, package, normalized installed version when available, dependency status, and whether adoption is supported. Safe package names may identify findings without a persisted resource ID.

Informational findings appear in human and JSON output and have a separate summary count. They do not change family or Node health, drift or unverifiable counts, or exit status. A report containing only informational findings is healthy and exits 0. Drift or unverifiable findings still exit 1.

`checked` keeps counting registered resources. Unregistered discoveries do not inflate it. Missing registered packages and rejected stored constraints keep their existing drift behavior. A failed or incomplete supported inventory scan is unverifiable even when the Node has no Tool records. A conflicting scope is informational when no Tool uses that manager, and unverifiable only when a Tool does. An unreachable Node does not run the scan; with no Tool rows it reports no tool issue. A manager that is absent or unsupported has an explicit scan state and creates no false package finding.

Doctor creates no Tool records, adopts nothing, and stores no inventory or report history. The web app uses a separate read-only inventory request and an explicit adoption request; it does not persist a Doctor report as its package database.

## Rejected alternatives

- Mark every unmanaged package as drift: selected ownership intentionally leaves packages outside Orbit.
- Hide discoveries: the user cannot choose which existing tools to adopt.
- Adopt packages during Doctor: a read would change ownership and enable mutation.
- Treat scan failure as an empty inventory: that would report success without checking the host.

## Consequences

- Doctor shows useful package discoveries without false alarms.
- API types, CLI rendering, report counts, and exit-code calculations gain a third finding kind.
- Package discovery extends the previous exclusion of unmanaged host-state scans; other host inventory remains outside Doctor.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/web
- ADRs: none
- Detail: [doctor: Informational package discoveries](/cli/doctor#informational-package-discoveries)
- Verify: informational-only healthy reports and exit 0, mixed findings and exit 1, zero registered Tools, partial scan failures, and no package or ownership writes
