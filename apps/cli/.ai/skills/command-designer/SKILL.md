---
name: command-designer
description: Design or change Orbit CLI commands and their user-facing contract. Use for command surface, command signatures, options, output, JSON mode, exit codes, or CLI UX changes.
---

# Command Designer

Keep the command surface as small as the requested operation permits.

## Boundaries

- Keep the CLI stateless except for `~/.orbit/config.json`.
- Send every remote action through `nckrtl/orbit-php-sdk` as an HTTP call.
- Never execute infrastructure commands or SSH from the CLI.
- Do not add commands, arguments, options, output formats, or abstractions for possible future use.

## Command Contract

Define only the contract elements that the command needs:

- stable argument and option names;
- concise human-readable success and error output;
- `--json` output when machine-readable use is required;
- explicit exit behavior when callers must distinguish outcomes.

Keep human output and JSON output deterministic. Do not expose SDK or transport details unless they help the user resolve an error.

- `--json` selects machine output and never grants destructive consent.
- Destructive commands require interactive confirmation or explicit `--force` before the one remote request.
- JSON success uses the exact typed SDK response array.
- JSON errors use one top-level `error` key with exactly `code`, `message`, and `request_id`.
- Orbit-handled failures exit with `1`; successful commands exit with `0`.

## Verification

Test observable command behavior instead of internal method calls. Cover each required input, output, side effect, and exit outcome. Run the relevant existing Composer scripts, then run `composer check` before completion.
