---
paths:
  - 'app/Commands/**'
---

# Commands

## Keep operator commands thin and HTTP-only
Normal commands validate explicit input, send typed orbit-php-sdk HTTP requests to the gateway, and render deterministic human and JSON output. Do not add SSH, remote sudo, infrastructure mutation, an Agent, hidden transport, or a generic executor. Privileged local changes are limited to explicit visible gateway:trust.
