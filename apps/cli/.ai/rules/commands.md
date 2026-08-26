---
paths:
  - 'app/Commands/**'
---

# Commands

## Keep operator commands thin and HTTP-only
Normal commands validate explicit input, send typed orbit-php-sdk HTTP requests to the gateway, and render deterministic human and JSON output. Do not add SSH, remote sudo, infrastructure mutation, an Agent, hidden transport, or a generic executor. Privileged local changes are limited to explicit visible gateway:trust.

## Use the managed Vite+ process entry point

Document project JavaScript systemd processes with `/usr/local/bin/vp` as the
absolute executable. Pass `run`, the script name, and each script argument as
one argv item through repeated `--command` options. Do not replace PHP or
Composer commands. Do not document direct package-manager project install/run
commands; project state lets Vite+ select the manager.
