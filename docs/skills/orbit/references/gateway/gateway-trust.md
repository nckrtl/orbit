---
title: "gateway:trust"
description: "Trust or re-verify the active Gateway root CA."
---

# gateway:trust

Trust the root CA of the active Gateway in the local operating-system trust store, or confirm that it is already trusted.

```bash
orbit gateway:trust [--accept-ca-change]
```

| Option | Meaning |
| --- | --- |
| `--accept-ca-change` | Accept a fetched certificate that differs from the profile's current pin. Use it only after you verify the new fingerprint. |

The CLI fetches the certificate, verifies it with a pinned request, installs it when it is missing, and saves the private certificate path on the profile only when the profile still has the name, URL, and pin that the command started with. Without `--accept-ca-change`, a pinned Gateway that presents another certificate exits with `gateway.ca_changed` before any installation.

```bash
orbit gateway:trust
orbit gateway:trust --accept-ca-change
```

> **Note:** Operating-system trust and the local profile are separate stores. When the guarded profile save fails with `gateway.ca_profile_update_failed`, the trust store change is already complete; inspect the active profile and run `gateway:trust` again.
