---
title: "ADR 0127: Share one SSH connection per Node"
sidebarTitle: "0127 Share one SSH connection per Node"
description: "Proposed. The Gateway keeps one multiplexed OpenSSH connection per Node and runs each remote command as a channel on it."
---

# ADR 0127: Share one SSH connection per Node

The Gateway keeps one authenticated OpenSSH connection open per Node user and address, and runs each remote command as a channel on that connection. The connection closes after 60 idle seconds.

## Status

Proposed.

## Context

The Gateway manages Nodes over SSH, as [the architecture page](/architecture) describes. Every remote command starts a new `ssh` process that opens a TCP connection, exchanges keys, and authenticates before the command runs. Converges run long chains of commands: the PHP-FPM converge on beast took 17 seconds, and one Caddy access check runs hundreds of commands.

A spike on the Gateway against beast and `services` measured OpenSSH 10.2 connection multiplexing:

- A command on a new connection took 188 ms. On a shared connection it took 18 ms.
- Killing a command's process group, as the Gateway does on a timeout or cancel, left the shared connection running.
- A command's output pipes closed when the command ended, even when that command started the shared connection.
- Scripts sent on stdin ran unchanged.
- On a Node whose sshd allows 10 sessions per connection, 15 concurrent commands all succeeded. OpenSSH opened a direct connection for each command the shared connection refused.

## Decision

- `NativeSshExecutor` adds `ControlMaster=auto`, `ControlPath=<ssh directory>/mux/%C`, and `ControlPersist=60s` to every command. The SSH directory is the directory of the Gateway's identity file, so the sockets live in `ORBIT_HOME/ssh/mux`.
- The Gateway creates the `mux` directory with mode `0700`. OpenSSH creates each socket with mode `0600`. Every Gateway process runs as the `orbit` user, so web requests, the scheduler, and commands share the same connections.
- `%C` hashes the local host, the Node address, the port, and the user. A Node that moves to a new address or port gets a new connection.
- When the socket path would exceed the Unix socket limit, or the directory cannot be created, the Gateway runs the command on its own connection.
- A shared connection keeps the existing `ServerAliveInterval=5` and `ServerAliveCountMax=2`, so a dead Node ends it within about ten seconds.
- The two `scp` transfers keep their own connections. They run rarely and do not use `NativeSshExecutor`.

## Rejected alternatives

- Keep one connection per command: rejected because connection setup costs about ten times the command itself.
- A persistent SSH tunnel: rejected because WireGuard already provides the private network. A tunnel only helps when a service on the Node listens for it.
- An Orbit agent on every Node: deferred. It adds an application to build, ship, and update on every Node. SSH access for recovery would still be needed.
- Raise `MaxSessions` on every Node: rejected because OpenSSH already falls back to a direct connection when a Node refuses a channel.

## Consequences

- Converges, removals, task ticks, and other command chains spend about 18 ms per command instead of about 188 ms.
- A Node authenticates the Gateway once per connection instead of once per command.
- A key or host-key change on a Node takes effect for the Gateway when its connection closes, which happens after 60 idle seconds. Node retargeting uses a new address, so it gets a new connection immediately.
- When a Node refuses a channel, OpenSSH writes two warning lines to that command's stderr before it opens a direct connection.

## Affects

- Components: apps/gateway
- ADRs: [ADR 0033](/decisions/0033-trust-wireguard-members-for-private-node-traffic)
- Detail: [Architecture](/architecture)
- Verify: `NativeSshExecutorTest`, and after deployment `ssh -O check` against a Node's socket in `ORBIT_HOME/ssh/mux`.
