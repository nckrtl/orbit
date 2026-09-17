---
title: "metrics:credentials"
description: "Show or reset the verified Grafana administrator credential."
---

Show the verified Grafana administrator credential, or reset the password.

```bash
orbit metrics:credentials [--reset] [--json]
```

| Option | Meaning |
| --- | --- |
| `--reset` | Set a new password through Grafana's own API and return it after authenticated verification. |

The username is `admin`. Human output prints the URL, username, password, and request ID; the response carries `Cache-Control: no-store`. The Gateway gives each Metrics Node one credential owner, so a concurrent read or reset waits within the command deadline and then either reads the owner's result or gets `metrics.credentials_busy` without changing credential state.

<Note>
Open Grafana at `https://metrics.orbit` from the active Gateway Node or from an active WireGuard peer that holds a directed access grant to the Gateway Node. The Gateway's Caddy identifies the caller from the connection address, refuses an alternate host or direct Gateway address, and proxies admitted traffic to Grafana over WireGuard. Grafana then asks for its own login with this credential.
</Note>

A reset that fails before or after apply keeps the encrypted pending password; the next reset authenticates that pending password first and promotes it without applying it again when Grafana already accepts it.
