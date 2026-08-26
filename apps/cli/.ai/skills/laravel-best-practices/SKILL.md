---
name: laravel-best-practices
description: Apply Laravel Zero and container conventions when changing Orbit CLI PHP code, configuration, services, or error handling.
---

# Laravel Best Practices

Use the APIs from the installed Laravel Zero and Illuminate versions. Inspect
the installed source when an API is version-sensitive.

## Structure

- Follow the existing command, service, repository, data, provider, and support boundaries.
- Use dependency injection and existing container bindings. Keep configuration in `config/` and read it through Laravel configuration APIs.
- Do not add web routes, controllers, models, database state, queues, schedulers, or frontend setup to this CLI.
- Keep remote business policy in the Gateway. The CLI performs bounded parsing and disclosure guards before it creates typed SDK requests.

## Errors And Security

- Use the shared command failure boundary for deterministic human and JSON errors.
- Preserve a validated gateway request ID. Never expose credentials, unsafe input, SDK diagnostics, or previous exceptions.
- Keep `gateway:trust` and other approved local actions explicit and visible. Normal commands remain SDK-backed HTTP operations.
