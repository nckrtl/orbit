# ADR 0043: Manage Homebrew Core formulae

In the context of machine-level Tools unavailable through Orbit's existing managers, facing a requirement to install Homebrew packages without accepting caller-controlled software sources or builds, we decided for a Homebrew scope that Orbit owns and limits to bottled Homebrew Core formulae and against taps, casks, source builds, and generic Brew input, to add broad Linux Tool coverage, accepting a smaller package set than Homebrew itself supports.

## Status

Accepted on 2026-09-08. Extends [ADR 0001](0001-tool-management.md) and [ADR 0042](0042-provision-tool-managers-on-demand.md).

## Context

[ADR 0001](0001-tool-management.md) keeps the manager registry closed and requires another decision before Brew support. Homebrew can provide machine-level Linux tools such as Herdr, but its complete interface accepts extra repositories, local definitions, applications, source compilation, and options that exceed Orbit's bounded Tool input. Orbit needs a Homebrew boundary that retains manager-native package behavior without making the Tool API a remote software-source or build interface.

## Decision

- The Gateway must provide Homebrew through one shared Orbit-owned scope on each supported SSH-managed Linux Node.
- The Gateway owns the Homebrew bootstrap source, version, integrity check, prefix, environment, and upgrade policy.
- The Gateway may recognize an existing Homebrew manager at that scope when its ownership, upstream origin, version, and integrity satisfy Orbit's policy.
- The Gateway must accept only canonical unqualified formula names from Homebrew Core as Homebrew Tool packages.
- The Gateway must install only a compatible upstream bottle whose integrity Homebrew verifies.
- The Gateway must not build a formula from source.
- The Gateway must not accept taps, casks, URLs, local formula definitions, Git references, service operations, or caller-supplied Brew options.
- The Gateway must not adopt a formula already present in the Orbit-owned Homebrew scope without matching Tool intent.
- The Gateway must remove only the exact formula recorded by Orbit and must not run Homebrew dependency autoremove as part of Tool removal.
- The Gateway must keep Homebrew process lifecycle outside Tool operations.

## Rejected alternatives

- Add Herdr as a package-specific Tool definition: rejected because Orbit already models one manager-native package without per-Tool definitions.
- Use Mise for machine-level package installation: rejected because project-aware version activation and configuration are unnecessary for this outcome.
- Require Orbit to replace every existing Homebrew manager: rejected because a compliant installation at the same protected scope has the same manager identity and replacement would disrupt packages outside Orbit's Tool intent.
- Expose unrestricted Brew arguments and taps: rejected because that interface lets callers select executable package definitions and repositories outside the code-owned registry boundary.
- Allow source builds when no bottle exists: rejected because build-time dependencies and formula code would widen and destabilize the remote mutation boundary.

## Consequences

- Operators can manage bottled Homebrew Core formulae, including Herdr, through the existing Tool model.
- Homebrew is the fourth code-owned Tool Manager and follows the on-demand lifecycle from ADR 0042.
- Orbit can manage the Homebrew prerequisite without claiming formulae that lack matching Tool intent.
- Formulae without a compatible bottle and packages outside Homebrew Core remain unavailable through Orbit.
- Orbit must maintain and verify the Homebrew bootstrap inputs as part of Gateway releases.
- Homebrew occupies a retained machine-level prefix even after its final Tool is removed.

## Affects

- Components: apps/cli, apps/gateway, packages/php-sdk
- ADRs: extends [ADR 0001](0001-tool-management.md) and [ADR 0042](0042-provision-tool-managers-on-demand.md)
- Detail: [Tools](../reference/tools.md)
- Verify: issue-local Incus proof installs, inspects, updates, and removes a Homebrew Core bottle
