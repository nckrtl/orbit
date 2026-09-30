# ADR 0183: Manage macOS tools without service roles

Orbit manages tools on a macOS Node through its existing SSH account and WireGuard identity. The Node needs no service role.

## Status

In progress.

Principle: [Lean](/mission#principles). Platform support enables the requested tool operations without installing an application runtime or adding a role.

## Context

The Mini already joins the fleet with no roles. Its Node record identifies it as Linux on x86_64, but the machine runs macOS on Apple silicon. It has Homebrew and Vite+ installations owned by its existing user. The Linux bootstrap, systemd agent, and service roles cannot converge this machine.

[Tool Managers](/reference/tools#tool-managers) already serve Nodes independently of roles. [Nodes without roles](/reference/node-provisioning#nodes-without-roles) establish that service placement and management are separate facts.

## Decision

SSH management and service-role support are separate contracts.

### Enrollment

Support `macos` as a managed platform for tools. Use the existing account, working WireGuard connection, and Gateway-authorized SSH access. Pin the approved host identity and inspect the actual platform and architecture before enrollment succeeds. macOS enrollment does not create an account, install Ubuntu packages, or change host DNS and firewall settings.

Permit explicit enrollment of an existing roleless peer with incomplete SSH management. Correct its platform and architecture from verified observations while preserving its Node ID, access grants, and WireGuard identity. A requested identity that disagrees with the machine fails. Do not silently rewrite the identity of an already managed Node.

### Operation support

Separate SSH management eligibility from operation support. Tool management and Doctor support the enrolled Mac; Linux roles, exporters, Processes, Schedules, and agent endpoints retain explicit Linux checks. macOS Nodes reject service-role assignments before remote mutation. The Mac needs no Node agent in this slice; `orbit-agent` stays Linux-only and observation-only.

Use the existing Homebrew prefix and the enrolled user's Vite+ global scope. Inspecting or adopting a package never replaces or repins either manager. Missing managers or conflicting installation ownership are reported rather than repaired during discovery or adoption.

macOS OS updates, firewall management, application hosting, and a macOS Node agent are separate features.

## Rejected alternatives

- Give the Mini an application role: tools do not need application services, and the user requires empty roles.
- Run package operations through the Node agent: SSH already owns mutation and recovery; adding execution changes the observation-only boundary.
- Run the Ubuntu bootstrap on macOS: its packages, account commands, resolver, firewall, and systemd contract do not apply.
- Install parallel package-manager scopes: existing tools would remain outside the scope the user wants to manage.

## Consequences

- A Mac can become a managed Node while its roles stay empty.
- Each operation checks platform support instead of assuming SSH management implies Linux services.
- The first enrollment requires an existing account, working WireGuard, and SSH access from the Gateway.
- Mac-specific verification needs a real Mac; Incus verifies the Linux behavior only.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/web
- ADRs: none
- Detail: [Node provisioning: macOS Nodes](/reference/node-provisioning#macos-nodes)
- Verify: enrollment, platform mismatch, role rejection, Linux eligibility regressions, and a task-owned real-Mac fixture
