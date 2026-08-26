---
paths:
  - 'bootstrap/**'
  - 'config/**'
  - 'orbit'
---

# Runtime Bootstrap

- Keep Laravel Zero boot and configuration small and CLI-specific.
- Keep Laravel Boost as a development-only integration. Its compatibility bindings must not change production command behavior.
- Do not add database, web-route, frontend, queue, scheduler, remote shell, or background Agent setup.
