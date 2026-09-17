---
title: "dns:resolve"
description: "Configure or remove a local resolver override for one TLD."
---

# dns:resolve

Route every domain under one development TLD to an IP address, or remove that override with `--reset`.

```bash
orbit dns:resolve <tld> [target] [--reset]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `tld` | yes | Development TLD as one lowercase DNS label without a leading dot, such as `test`. |
| `target` | for configuration | IPv4 or IPv6 address that wildcard domains under the TLD resolve to. Not accepted with `--reset`. |

| Option | Meaning |
| --- | --- |
| `--reset` | Remove the local resolver override for the TLD. |

```bash
orbit dns:resolve test 192.168.6.20
orbit dns:resolve test --reset
```

The CLI writes `address=/<tld>/<target>` to `~/.config/orbit/dnsmasq.d/<tld>.conf`, includes that directory from the Homebrew `dnsmasq.conf`, installs `/etc/resolver/<tld>` with `nameserver 127.0.0.1`, restarts the Homebrew `dnsmasq` service, verifies that a lookup under the TLD returns the target, and flushes the macOS resolver cache. Writing the resolver file and restarting the service use `sudo`, so the command can ask for local administrator privileges.

The result reports a `status` of `resolved`, `already_resolved`, `reset`, or `already_absent`, and whether anything `changed`.

> **Note:** Restart open browsers after a change. A browser reuses an existing connection and keeps sending traffic to the old address until it reconnects.

| Error code | Meaning |
| --- | --- |
| `dns.tld_invalid` | The TLD is not one lowercase DNS label. |
| `dns.target_invalid` | The target is not an IP address, or a target was given with `--reset`. |
| `dns.unsupported_platform` | The operator machine is not macOS. |
| `dns.dnsmasq_missing` | Homebrew `dnsmasq` is not installed. |
| `dns.write_failed` | The configuration or resolver file could not be written. |
| `dns.refresh_failed` | The files changed, but `dnsmasq` could not be restarted or does not answer with the target. |
