---
title: "Tech stack"
description: "The languages, frameworks, and platform each part of Orbit uses."
---

# Tech stack

Orbit is one repository with separate projects. Each project has its own dependencies, tests, and lock file.

## Projects

Each project in the repository uses the stack in this table.

| Project | Stack |
| --- | --- |
| `apps/gateway` | PHP 8.5, Laravel 13, SQLite, Laravel MCP, Laravel AI |
| `apps/cli` | PHP 8.5, Laravel Zero 13 |
| `packages/php-sdk` | PHP 8.5, Saloon |
| `apps/web` | TypeScript, React, TanStack Router and Query, Tailwind CSS, Vite+ |
| `apps/desktop` | Tauri 2 |
| `apps/agent` | Rust |
| `apps/pi-server` | TypeScript on Bun, the Pi coding agent |
| `apps/docs` | PHP 8.5, Laravel 13, Librarian |
| `apps/e2e` | PHP 8.5, Laravel 13, Incus |

## Platform

Service Nodes run Ubuntu 26.04. macOS Nodes support selected Homebrew formulae, casks, and Vite+ global tools through SSH, without service roles. macOS system updates and firewall management are outside this support. On Ubuntu, Orbit runs services natively under systemd: Caddy serves HTTP and HTTPS, PHP-FPM runs PHP from a pinned Sury apt source, and WireGuard carries the private network. Docker runs container Processes, such as shared databases and Plausible, and the `metrics` role's Prometheus and Grafana. Reverb on the `websocket` Node carries realtime events. Mintlify publishes this documentation.

## Checks

Pest, Pint, Larastan, PHPStan, and Rector check the PHP projects. Vitest and Playwright check the web app. Incus provides disposable Linux machines for end-to-end tests. GitHub Actions runs the checks for every pull request. See the [contributor guide](/contributor-guide) for the local workflow.
