---
paths:
  - 'bootstrap/**'
  - 'config/**'
---

# Bootstrap rules

Bootstrap only the Laravel console runtime and required bindings. Keep startup
deterministic and database-free. Do not register routes, HTTP middleware,
sessions, queues, or gateway application state.
