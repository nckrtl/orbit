# Orbit Gateway

The Laravel control plane for Orbit. It stores fleet state in SQLite and runs
synchronous infrastructure actions on registered nodes through SSH.

The gateway runs directly on Linux with Caddy and PHP-FPM. It does not use a
container, a background agent, or a queue worker.

## Requirements

- PHP 8.5
- Composer 2
- SQLite

## Managed application hosts

Orbit provisions Vite+, Vite+-managed LTS Node.js, npm, npx, default pnpm, and
a separate Bun runtime on `app-dev` and `app-prod` nodes. Use `vp install` and
`vp run <script>` in project setup and build instructions. Vite+ follows its
native package-manager selection order and defaults a project without a manager
signal to pnpm. PHP dependencies continue to use Composer.

## Tool management

The Gateway exposes exactly six versioned HTTP routes:

- `GET /api/v1/tool-managers?node_id=<id>` lists manager state for a node.
- `GET /api/v1/tools?node_id=<id>` lists managed tools for a node.
- `GET /api/v1/tools/<tool-id>` shows one managed tool.
- `POST /api/v1/tools` installs a tool or retries a failed install.
- `POST /api/v1/tools/<tool-id>/update` updates a tool or retries a failed update.
- `DELETE /api/v1/tools/<tool-id>` removes a tool or retries a failed removal.

Tool install input is limited to `node_id`, `manager`, `package`, and the
nullable `version_constraint`. The supported managers are `apt`, `vp`, and
`composer`. A non-null constraint checks the manager's normal candidate before
mutation. It does not select another release or downgrade automatically.
Failed operations retain one managed row and can be retried through the matching
operation.

APT manages exact Linux package names. VP manages global packages in Orbit's
shared node scope. Examples include `@openai/codex` and
`@anthropic-ai/claude-code`. Composer manages exact root packages in Orbit's
shared node scope. Removal targets only the exact recorded package. APT never
runs `autoremove`.

APT is available on managed Linux nodes. VP and Composer are available only
while an `app-dev` or `app-prod` role is provisioning or active. Removing the
last active app role is blocked while non-protected VP or Composer tools remain.
Remove those tools explicitly first. Successful last-app-role removal retains
protected manager rows and marks VP and Composer unavailable until later
app-role convergence. Role removal never removes packages or Tool intent
implicitly.

Manager output is not persisted or returned. Failures expose only stable error
codes and bounded, redacted diagnostics.

Orbit does not expose npm, Bun, Brew, scripts, manual installs, or observed
packages as tool managers. A tool package has no Orbit definition. The manager
validates and resolves the package in its own shared node scope.

## Development

```bash
composer setup
php artisan orbit:bootstrap 85.9.218.89 \
    --wireguard-endpoint=85.9.218.89:51820 \
    --private-interface=eth3
```

Run migrations explicitly after each pull. The bootstrap command creates the
gateway SSH key, WireGuard key, root CA, gateway node, roles, and VPN settings
under `ORBIT_HOME`.

Provision the first role-less operator directly on the gateway. Later peers use
the public CLI and the same gateway action.

Get the SSH host fingerprint from the node's provider console or another
trusted out-of-band channel. For an Ed25519 host key, run this on the node:

```bash
sudo ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub -E sha256
```

If you collect a candidate fingerprint over the public network, compare it with
the trusted value before you approve it. A network scan alone does not prove the
node's identity.

```bash
php artisan orbit:node-provision operator '<PUBLIC_SSH_HOST>' \
    --architecture='<x86_64-or-aarch64>' \
    --host-key-fingerprint='SHA256:<APPROVED_HOST_KEY_FINGERPRINT>'
```

Orbit allocates the peer WireGuard address and uses the gateway WireGuard and
DNS settings by default. For a peer that must use a private underlay, append the
optional `--wireguard-endpoint='<PRIVATE_GATEWAY_IP>:51820'` and
`--dns-server='<PRIVATE_DNS_IP>'` overrides.

The versioned status endpoint is `GET /api/v1/gateway/status`.

Use `ORBIT_HOME` to override the application data directory. The default is
`$HOME/.orbit`.

## Quality

```bash
composer test       # full Pest 5 suite in parallel without TIA
composer format     # Mago formatter
composer check      # full tests and all Mago checks
```
