---
title: "ADR 0155: Authenticate the Node agent with a per-Node secret"
sidebarTitle: "0155 Authenticate the Node agent with a per-Node secret"
description: "Proposed. The Gateway gives each Node agent a random secret in a root-only file and keeps only its SHA-256 hash. Every agent endpoint requires that secret as well as the Node's WireGuard address, so a local user on a Node cannot act as its agent."
---

# ADR 0155: Authenticate the Node agent with a per-Node secret

The Gateway writes a random secret for each Node agent to a file that only root can read, and it stores only the secret's SHA-256 hash. The agent sends the secret on every Gateway request. Every agent endpoint requires the secret in addition to the Node's WireGuard address. A local user on a Node can then no longer sign the agent's channel membership, publish as the agent, or read the agent's watch list.

## Status

Proposed.

This amends [ADR 0128](/decisions/0128-run-a-visibility-only-agent-on-managed-nodes) and [ADR 0129](/decisions/0129-publish-node-presence-and-process-state-on-per-node-presence-channels). ADR 0129 signs the `agent.{id}` membership for any request from Node `{id}`'s WireGuard address. This ADR adds a second factor that only the agent holds.

## Context

The Gateway identifies every API caller by its WireGuard source address, as [ADR 0033](/decisions/0033-trust-wireguard-members-for-private-node-traffic) and the node access model define. Any process on a Node reaches the Gateway from that address, whatever its Unix user.

Three agent endpoints need no access edge. They check only that the caller's address belongs to an active, managed Node:

| Endpoint | What a caller gets |
| --- | --- |
| `GET /api/v1/agent/realtime` | The Reverb URL, serving address, app key, the Node's channel, and the agent's member ID |
| `POST /api/v1/agent/broadcasting/auth` | A Pusher signature for member `agent.{id}` on `presence-node.{id}` |
| `GET /api/v1/agent/workspaces` | The path, base branch, and starting commit of each task checkout on the Node |

Production Nodes run customer application code as unprivileged users, such as `orbit-app-{id}` for each Project on an `app-prod` Node ([ADR 0045](/decisions/0045-isolate-production-php-fpm-by-unix-user)). An independent review reproduced on Incus that such a user can call all three endpoints as the Node's agent. With the signature it joins `presence-node.{id}` as `agent.{id}` and can:

- publish false Process state and task workspace state that the web app shows and the [Gateway view](/decisions/0148-keep-a-gateway-view-of-node-agent-state) accepts. A false report can end a wake early, make the hibernator skip a stop, skip the ownership check before a Process log read, and answer the task tick's commit check and a group's line diff.
- reset the Gateway view with a lower `sequence`, so the Gateway falls back to SSH for that Node.
- receive every event on the channel, including the real agent's reports and the members' identities.

The damage stays on the attacker's own Node. No endpoint signs another Node's channel, and the agent runs no command. It still breaks the promise that a Node's reports come from its agent, and the proposed live log tails ([PR #690](https://github.com/nckrtl/orbit/pull/690)) add an agent endpoint and channel for log lines, where the same gap would expose logs.

The agent runs as `root` without capabilities. A file owned by `root` with mode `0600`, in a directory with mode `0700`, is readable by the agent as its owner and by no other local user.

## Decision

The Gateway owns the secret, its hash, and the check. The agent owns reading the secret and sending it.

### The secret

- Every agent converge makes sure the Node has an agent secret in `/etc/orbit/agent/secret`, owned by `root:root` with mode `0600`. The converge also sets `/etc/orbit/agent` to `root:root` mode `0700`, because Ubuntu's `install` writes a candidate file with mode `0644` and applies `0600` only after the contents are written. The secret is 32 random bytes from the Gateway's CSPRNG, written as 64 lowercase hexadecimal characters.
- The Gateway writes the file over SSH with the secret on standard input, never in a command's arguments. It stores only the SHA-256 hash of the secret in the Node record, and never logs or returns the secret or the hash.
- A converge keeps the secret while the file's SHA-256 hash equals the stored hash. It writes a new secret when the file is missing or differs, or when the Gateway holds no hash. A new secret restarts the agent. There is no scheduled rotation. Removing a Node deletes the file with `/etc/orbit/agent`, and a new Node record starts without a hash.
- The converge writes the secret file first, then installs the binary and every other file, and restarts the agent last. An agent older than 0.3.0 ignores the file, and the running agent reads its secret only at start, so a new file changes nothing before the restart. A 0.3.0 binary on disk always finds a secret, even when the converge stops right after the swap.
- The converge stores a new hash only after the restart succeeded, both when it ends an exemption and when it replaces an older hash. Every earlier failure leaves the running agent with a secret the Gateway accepts, and leaves the file different from the stored hash. Doctor then reports `mismatch`, and the next converge writes a new secret and restarts the agent. Storing a replacement hash before the restart would refuse the running agent when the restart command fails, with a file that matches the record, so Doctor would report nothing and no converge would repair it.
- A converge that installs an agent older than 0.3.0 sets the exemption right before it swaps the binary, and again before the restart. The exemption accepts both the agent that stops and the agent that starts, also when the older binary starts after a converge that failed past the swap.
- One agent converge runs per Node at a time, under a lock in a file cache store under `ORBIT_HOME` that the Gateway pins whatever `CACHE_STORE` says. A second converge waits up to 2 minutes and then fails with `agent.converge_busy`. The lock expires after 4 minutes, so a converge that dies without releasing it, such as a PHP-FPM worker killed at its 600-second request limit, blocks the Node's agent converges for at most 4 minutes. A running converge renews the lock before each step that changes the Node or the record, and stops with `agent.converge_lock_lost` when another converge took it over after it expired. The binary download is bounded to 20 seconds to connect and 120 seconds in total, so one step fits inside the lock's term. Without the lock, two converges of one Node each write a secret, and one of them can store the other one's hash.
- The agent reads the file at start and exits with an error when it is missing or malformed. It sends `Authorization: Bearer {secret}` on every Gateway request, over the TLS connection that already verifies `gateway.orbit` against the Orbit CA.

### The check

Every route under `/api/v1/agent/` requires the secret, in addition to an active WireGuard peer and the managed-node boundary. A new agent endpoint joins the same route group, so it requires the secret too.

| Request | Result |
| --- | --- |
| No bearer token | `401 agent.secret_required` |
| A token whose SHA-256 hash differs from the Node's stored hash, including another Node's secret | `403 agent.secret_invalid` |
| No stored hash for the Node, and the Node is not exempt | `403 agent.secret_invalid` |
| A matching token | The endpoint runs |

The Gateway compares the hashes in constant time.

### Rollout

Agent 0.3.0 is the first release that sends the secret. The Gateway pins 0.3.0. Each converge writes the per-Node secret before it restarts the Node into 0.3.0, then stores the hash and clears the exemption. A Node still on 0.2.0 stays exempt until its converge, so the rollout does not refuse that agent.

- The Node record has `agent_secret_exempt`. The migration sets it for every existing Node, because none of them runs an agent that sends a secret.
- An exempt Node without a stored hash is accepted without a secret. Every other Node must send its secret.
- A new Node is never exempt, because the Gateway converges its agent with the pinned release.

The exemption ends for the whole fleet when every Node has converged once with 0.3.0 or a newer release. After that, a cleanup change removes the column and its branch.

The Gateway keeps the 0.2.0 release checksums, so the pin can move back. A converge that installs an agent older than 0.3.0 sets the exemption before it swaps the binary and clears the stored hash, so the older agent is never refused.

### Doctor

Doctor reports `node.agent_secret_mismatch` in the `node` family for an eligible Node that has an agent binary. The pin is 0.3.0, so Doctor reports a Node that is still exempt as `exempt`, a missing secret file as `missing`, and a hash that differs from the stored one as `mismatch`. It reads only the file's SHA-256 hash with `sudo sha256sum`, within Doctor's existing 30-second Node inspection. The secret and both hashes stay out of the report.

## Rejected alternatives

- Keep the WireGuard address as the only identity: rejected because every Unix user on the Node shares that address, and production Nodes run customer code.
- Restrict the endpoints to root with a firewall owner match (`iptables -m owner --uid-owner 0`): rejected because it depends on per-Node firewall state that Orbit would have to converge and Doctor would have to verify, and it breaks silently when the rule is missing. A secret fails closed.
- Mutual TLS with a per-Node client certificate: rejected because it needs a certificate issuer, renewal, and Caddy client-auth configuration for one route group. The bearer secret gives the same guarantee against local users at a fraction of the cost.
- Store the secret itself on the Gateway: rejected because a hash is enough to verify a high-entropy secret, and a leaked Gateway database then reveals no agent secret. A slow password hash adds nothing for 256 random bits.
- Refuse every Node without a secret as soon as the pin reaches 0.3.0: rejected because the fleet converges one Node at a time after a Gateway deploy, and every Node still running 0.2.0 would lose its agent until then.
- Let the agent report its version and exempt callers that claim an old one: rejected because the caller controls that claim.

## Consequences

- A local user who is not root cannot act as the agent: the endpoints refuse it without the secret, and it cannot read the file. Root on a Node can still read the secret and report false state about its own Node, as ADR 0148 already accepts.
- A Node still on 0.2.0 stays exempt until its converge, and a local user on that Node can still act as its agent. Doctor names that Node with `node.agent_outdated` and `node.agent_secret_mismatch` (`exempt`).
- A failed converge never locks out the running agent. A converge that stops between the restart and the record can leave a Node exempt while its agent sends the secret, or refuse the new agent until the next converge. Doctor reports both, as `exempt` or `mismatch`, and the next converge repairs them.
- A converge that writes a new secret, such as after a manual file deletion, restarts the agent.
- A Gateway database restore that predates a Node's secret makes that Node's agent fail with `agent.secret_invalid` until the next converge writes a new secret. Doctor reports `node.agent_secret_mismatch` meanwhile.
- The other API routes keep identifying a caller by its WireGuard address. A local user on a Node that holds a Gateway access edge can still call those routes as that Node, as the node access model allows. This ADR covers the agent endpoints only.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: amends [ADR 0128](/decisions/0128-run-a-visibility-only-agent-on-managed-nodes) and [ADR 0129](/decisions/0129-publish-node-presence-and-process-state-on-per-node-presence-channels)
- Detail: [Node agent](/reference/node-agent#agent-secret), [`doctor`](/cli/doctor)
- Verify: Gateway tests for the secret check on every agent endpoint, the converge that writes and keeps the secret through standard input, the exemption, and the Doctor check; agent tests for loading and sending the secret; an Incus proof where a production Instance user can no longer read the secret or call the agent endpoints
