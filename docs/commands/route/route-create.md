---
title: "route:create"
description: "Create an explicit Route."
---

Create an explicit App Route or a Node-owned custom proxy Route.

```bash
orbit route:create <app> <domain> [--publication=INTENT] [--target=ID | --node=ID | --cluster=ID]
orbit route:create <domain> --node=NODE --upstream=URL
orbit route:create <domain> --node=NODE --process=PROCESS
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `app` | App form | Numeric App ID that owns the App Route. |
| `domain` | yes | Route domain. The Gateway normalizes it and refuses one that another Route or a reserved platform name owns. |

| Option | Default | Meaning |
| --- | --- | --- |
| `--publication=INTENT` | `private` | `private` or `public` for an App Route. Custom proxy Routes are private only. |
| `--target=ID` | none | Numeric App instance target. The App Route scope derives from that App instance's Node or active Cluster. |
| `--node=NODE` | none | Numeric Node scope for a targetless App Route, or the serving Node ID or name for a custom proxy Route. |
| `--cluster=ID` | none | Numeric Cluster scope for a targetless App Route. |
| `--upstream=URL` | none | Loopback HTTP URL for a custom proxy Route, such as `http://127.0.0.1:4788`. |
| `--process=PROCESS` | none | Node-owned Process name or ID on the serving Node for a custom proxy Route. |

```bash
orbit route:create 4 shop.example.test --target=12
orbit route:create 4 preview.example.test --cluster=3
orbit route:create executor.orbit --node=beast --upstream=http://127.0.0.1:4788
orbit route:create executor.orbit --node=beast --process=executor
```

`--upstream` or `--process` selects the custom proxy form. That form requires `--node` and forbids `app`, `--target`, `--cluster`, and public publication. The first positional argument is the domain. The CLI resolves `--node` as an ID or registered Node name.

The App form refuses `--target` together with a scope option (`route.scope_conflict`) and a targetless Route without exactly one scope (`route.scope_required`) before it sends a request. Creating the same App Route again with identical App, domain, intent, scope, and target returns the existing Route; a retry that changes one of those values fails with `route.retry_conflict`. Creating the same custom proxy Route again with identical domain, Node, and upstream or Process returns the existing Route.

| Error code | Meaning |
| --- | --- |
| `route.domain_invalid` | The domain is not a valid normalized domain. |
| `route.domain_conflict` | Another Route owns the domain, or the name is reserved (`gateway.orbit`, `metrics.orbit`). |
| `route.target_app_conflict` | The target App instance belongs to another App. |
| `route.target_inactive` | The target App instance is not active. |
| `route.target_conflict` | The target App instance already belongs to another Route. |
| `route.router_required` | The Cluster scope has no active Router. |
| `route.upstream_invalid` | The upstream is not a loopback HTTP URL on the serving Node. |
| `route.upstream_unresolved` | The Process has no single Node-local listener the Gateway can use. |
| `route.process_conflict` | The Process is missing, not Node-owned, or not on the serving Node. |
