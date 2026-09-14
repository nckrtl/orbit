# ADR 0074: Hibernate idle app-dev AppInstance Processes

In the context of development AppInstances that keep Vite and other lifecycle Processes running without HTTP traffic, facing wasted CPU and memory on app-dev Nodes, we decided for idle HTTP halt and first-request wake of AppInstance Processes on Nodes with the active app-dev role and against Workspace scopes, Schedule pauses, or treating restart policy as keep-alive, to keep development runtimes on demand, accepting that a cold first request waits for those Processes to start.

## Status

Accepted on 2026-09-14. Extends [ADR 0036](0036-support-only-appinstances.md) and [ADR 0069](0069-allow-node-process-targets.md).

## Context

Development AppInstances install lifecycle Processes such as Vite on the app-dev Node. Those Processes stay running after the operator leaves the site. PHP is served by Caddy to a per-site PHP-FPM pool on the shared per-version service in [ADR 0021](0021-pin-sury-php-fpm-with-opcache-profiles-per-role.md); those pools are not Processes. [ADR 0036](0036-support-only-appinstances.md) removed Workspace as an application model, and [ADR 0069](0069-allow-node-process-targets.md) allows Node-owned Processes that must keep their own lifecycle. Schedules are native systemd timers on [the Schedules page](../reference/schedules.md) and are not Processes.

## Decision

- The Gateway must halt AppInstance Processes owned by a development AppInstance on a Node with the active `app-dev` role after the configured idle HTTP window.
- The Gateway must start every desired-running AppInstance Process for that AppInstance when the next HTTP request arrives, and must leave desired-stopped Processes stopped.
- The Gateway must hold that wake until every desired-running Process is running, and until a Vite development-server Process accepts connections on `127.0.0.1:5173`.
- The Gateway must not halt Node Processes, production AppInstance Processes, or Schedules.
- The Gateway must not stop the shared PHP-FPM service or its pools.
- The Gateway must not change PHP-FPM pool configuration or Caddy FastCGI socket paths for hibernation.
- The Gateway must not treat a Process restart policy as an exemption from idle halt.
- The Gateway must install app-dev AppInstance Process units without host-boot start intent.
- Caddy on the AppInstance Node must intercept a request when the awake marker is absent and must call the Gateway activation API before it proxies the site.
- Hibernation must not stop, disable, or change a Schedule.
- The Gateway owns idle sweep, awake markers, and wake; Caddy on the workload Node owns the request intercept and the HTTP activity log.

## Rejected alternatives

- Workspace hibernation scopes: rejected because ADR 0036 removed Workspace as an application model.
- Pause Schedules during idle: rejected because Schedules are independent systemd timers and must keep firing.
- Restart policy as keep-alive: rejected because a restart policy only covers crash recovery while the unit is started; an exemption field is a separate Process contract.
- `systemctl enable` for app-dev AppInstance Processes: rejected because host boot would start the group without an HTTP request.
- Stop the shared PHP-FPM service or remove a per-site pool during idle: rejected because ADR 0021 shares one FPM master per PHP version across every site on the Node, and each pool already exits idle workers.

## Consequences

- The first HTTP request after idle or host reboot waits for desired-running Processes to start.
- Doctor reports desired running and observed stopped while a group is asleep.
- Operators who want a Process to survive idle HTTP silence need a keep-alive contract outside this record.

## Affects

- Components: apps/gateway
- ADRs: extends [ADR 0036](0036-support-only-appinstances.md) and [ADR 0069](0069-allow-node-process-targets.md)
- Detail: [App-dev runtime hibernation](../reference/app-dev-runtime-hibernation.md)
- Verify: `composer docs-lint`; Gateway hibernation, Caddy wake, and Process on-demand start tests
