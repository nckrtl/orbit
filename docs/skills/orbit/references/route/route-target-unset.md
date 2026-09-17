---
title: "route:target:unset"
description: "Clear the configured target."
---

# route:target:unset

Clear the configured target while keeping the Route, its domain, scope, and publication intent.

```bash
orbit route:target:unset <route> [--yes]
```

Clearing an already empty Route returns it unchanged. The Gateway returns `route.target_conflict` when clearing would leave an active App instance without a Route.
