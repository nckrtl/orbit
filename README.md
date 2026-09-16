# Orbit

Orbit connects application development, hosting, and machine maintenance. Use its command-line interface yourself or through an AI agent. A self-hosted Gateway coordinates your Linux machines over SSH and WireGuard.

Register a Git repository, create a development App instance, and get a private HTTPS URL:

```bash
orbit app:create hello https://github.com/YOUR-ACCOUNT/hello.git --root=public
orbit instance:create APP_ID NODE_ID default
```

Replace the repository and IDs with your own values. The [Quickstart](https://orbit.nckrtl.com/docs/quickstart) sets up the Gateway and CLI, adds a machine, and serves a page. It is written for you and your coding agent together.

## Alpha status

Orbit is being prepared for a source-based alpha. Use disposable machines and test data. The [alpha guide](docs/reference/alpha.md) defines the trial path, limits, and release criteria. It does not claim that the fresh-machine trial or production readiness has passed.

The CLI requires PHP 8.5 and Composer 2. Managed Nodes, including the Gateway, require Ubuntu 26.04; Ubuntu 24.04 is unsupported. [Your part](https://orbit.nckrtl.com/docs/quickstart#your-part) of the Quickstart lists the machines and network access you need before you start.

## Documentation and feedback

Start with these guides:

- [Quickstart](https://orbit.nckrtl.com/docs/quickstart)
- [Update, back up, and recover](docs/reference/gateway-recovery.md)
- [Report a bug](https://github.com/nckrtl/orbit/issues/new?template=bug_report.yml)
- [Contribute](CONTRIBUTING.md)

Maintained documentation lives in `docs/`, with Mintlify navigation in `docs/docs.json`.

## Repository

Each project owns its Composer dependencies and checks:

| Path | Purpose |
| --- | --- |
| `apps/cli` | Laravel Zero command-line client |
| `apps/gateway` | Laravel control plane |
| `packages/php-sdk` | Framework-neutral HTTP client |
| `apps/docs` | Documentation checks and context generator |
| `apps/e2e` | Incus verification harness |

The [MIT license](LICENSE) covers the project. Third-party dependencies retain their own licenses.
