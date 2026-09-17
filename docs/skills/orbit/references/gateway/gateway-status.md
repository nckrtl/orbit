---
title: "gateway:status"
description: "Show the status and version of the active Gateway."
---

# gateway:status

Show the status and version reported by the active Gateway, with the profile name and URL.

```bash
orbit gateway:status [--json]
```

```bash
orbit gateway:status
```

Human output shows waiting feedback during the request, then a detail tree with the profile URL, Gateway name, status, Orbit version, PHP version, Laravel version, and request ID. Missing display values use an em dash. JSON keeps these values in the existing flat response without progress output.

The command returns `gateway.profile_missing` when no profile is active and `gateway.unreachable` when the Gateway does not answer.
