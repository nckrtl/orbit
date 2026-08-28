---
paths:
  - 'app/Commands/**'
---

# Commands

## Keep operator commands thin and HTTP-only
Normal commands validate explicit input, send typed orbit-php-sdk HTTP requests to the gateway, and render deterministic human and JSON output. Do not add SSH, remote sudo, infrastructure mutation, an Agent, hidden transport, or a generic executor. Privileged local changes are limited to explicit visible gateway:trust.

Node access commands use numeric consumer and serving node IDs. Add is idempotent. Remove requires interactive confirmation or --force. The CLI sends typed SDK requests and never decides access, Gateway identity, or role authority locally. Do not add granular permission options or output.

## Use the managed Vite+ process entry point

Document project JavaScript systemd processes with `/usr/local/bin/vp` as the
absolute executable. Pass `run`, the script name, and each script argument as
one argv item through repeated `--command` options. Do not replace PHP or
Composer commands. Do not document direct package-manager project install/run
commands; project state lets Vite+ select the manager.

## Keep tool commands on the Gateway boundary

Tool commands use the PHP SDK only. Do not execute a package manager, process,
SSH, sudo, or SemVer policy in the CLI. Interactive install manager choices
come from the target node's active manager rows. Noninteractive and JSON calls
must supply node, manager, and package without prompting.
