---
title: "node:add"
description: "Provision a new machine or converge an existing Node."
---

# node:add

Add a machine to the fleet. The same command provisions a new machine and converges an existing Node after you change its TLD, roles, or settings.

```bash
orbit node:add <name> [host] [options]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `name` | yes | Node name. It stays stable for the life of the record. |
| `host` | no | Public SSH host. Required for a new machine. |

| Option | Default | Meaning |
| --- | --- | --- |
| `--ssh-port=PORT` | `22` | Public SSH port. |
| `--user=USER` | `root` for a new Node, the managed user for an existing Node | Bootstrap SSH user. Name one when the host allows no root login or the managed user cannot log in. |
| `--orbit-user=USER` | `orbit` | Orbit-managed system user that every later Gateway command runs as. |
| `--platform=PLATFORM` | `linux` | Node platform. Only `linux` is accepted. |
| `--architecture=ARCH` | observed on the machine | Machine architecture such as `x86_64` or `aarch64`. A new Node records the observed value, and an explicit value must equal it. |
| `--tld=TLD` | none | Node TLD for generated development Route domains and production clone previews. Required for the `app-dev` role unless an active Cluster with a TLD owns the Node. |
| `--role=ROLE` | none | Initial role assignment. Repeat the option for more than one role. |
| `--host-key-fingerprint=SHA256` | none | Approved SSH host key fingerprint. Required for a new Linux machine. Read it on the machine with `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub`. |
| `--cluster=ID` | none | Numeric Cluster ID to join. |
| `--wireguard-ip=IP` | generated | Stable WireGuard IP address. |
| `--wireguard-address=IP` | none | Alias of `--wireguard-ip`. |
| `--lan-ip=IP` | none | Cluster-local LAN IPv4 address that a Router uses to reach this Node. |
| `--wireguard-endpoint=HOST:PORT` | generated | Per-node WireGuard endpoint override. See [WireGuard endpoints](https://orbit.nckrtl.com/docs/reference/wireguard-endpoints.md). |
| `--dns-server=IP` | Orbit VPN DNS | Per-node DNS server override. See [Private DNS](https://orbit.nckrtl.com/docs/reference/private-dns.md). |
| `--setting=PATH:VALUE` | none | Repeatable typed Node setting. The only setting path is `apps.path`. |

The Gateway opens one SSH session as the bootstrap user, installs the base packages, creates the managed user, and then verifies SSH as that user. Bootstrap opens the `orbit:public-ssh-recovery` firewall rule; the first role convergence closes public SSH again, so a Node provisioned with roles ends reachable only over WireGuard.

Provision an app-dev machine with its own TLD and apps root:

```bash
orbit node:add beast beast.example.com \
  --role=app-dev \
  --tld=test \
  --setting=apps.path:/srv/orbit/apps
```

Recorded output from commit e6e0f765 on 2026-09-17, exit status 0:

```text
$ orbit node:add demo 10.232.5.20 --role=app-dev --tld=demo --host-key-fingerprint=SHA256:p33m9PAmcSAIb++hnNYTgfHlQqucDN0vo2JEhOAq73A
┌  Add Node
│
├  ● Added Node
│
└  Added Node.

Node [demo] is active.
Request ID: 34c55c80-904f-4cfd-8e8d-7a18fdc8a984
```

Converge an existing Node after adding a role:

```bash
orbit node:add beast --role=app-dev --role=database
```

> **Note:** A production Node that receives clones needs `--tld`; the [instance:clone](../instance/instance-clone.md) command derives its preview domain from that value.

The roles you can assign are `app-dev`, `app-prod`, `router`, `ingress`, `database`, `metrics`, `gateway`, and `vpn`. The [Database role](https://orbit.nckrtl.com/docs/reference/database-role.md) and [Metrics](https://orbit.nckrtl.com/docs/reference/metrics.md) references list which roles may share one Node.

## After a refusal

The Gateway checks the request before it opens SSH, so these refusals leave the machine untouched.

| Error code | What to do |
| --- | --- |
| `node.tld_required` | The Node gets the `app-dev` role and no Cluster TLD covers it. Add `--tld`, or add the Node to a Cluster with a TLD first. |
| `node.ssh_host_fingerprint_required` | The machine is new to the Gateway. Read the fingerprint on the machine and pass `--host-key-fingerprint`. |
| `node.host_key_fingerprint_invalid` | The value is not `SHA256:` followed by 43 base64 characters. |
