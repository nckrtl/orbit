---
name: designing-cli-commands
description: Design, implement, or audit Orbit CLI input and output against the shared CLI design standard and current command contracts.
---

# Designing CLI commands

Read `docs/reference/cli-ux.md` from the repository root, then the current command reference, governing ADRs, CLI project rules, and relevant tests. The standard owns presentation, interaction, and explicit destructive consent. The command contract owns names, targets, defaults, existing consent/override option meanings, response schemas, transport, and available modes. The canonical renderings the standard names, under `apps/cli/tests/Expected`, are the examples to copy.

## Sketch first

For new interaction, such as prompts or a step sequence that no command has, write a design sketch under `apps/cli/design` before the real command. A sketch uses the CLI's own prompt, progress, and failure primitives with a scripted scenario and an `--outcome` option for each refusal; see `apps/cli/design/README.md`. Run it with `ORBIT_DESIGN=1`, drive it in a terminal one key at a time, and record the agreed flow with `verifying-cli-output`. The agreed scenario becomes the mock Gateway scenario of the real command, and the agreed recording becomes its expected output.

## Design or implement

1. Identify the command's current inputs, modes, side effects, and result contract. Preserve API/SDK compatibility and current product boundaries. Flag a material conflict rather than resolving it through a silent fallback or renamed field.
2. Choose the standard's prompt and rendering primitives. State exact prompts, columns, labels, selection results, empty states, progress transitions, and terminal outcomes where the change needs them.
3. Use shared CLI helpers where they preserve the observable contract. Keep domain policy and remote infrastructure execution outside the CLI. A synchronous call gets truthful indeterminate feedback, not fabricated remote stages.
4. Keep human and machine renderers backed by the same result. Check every return and error path for machine-output contamination, secret leakage, and misleading success.
5. Compare the output with the agreed renderings. Run `bin/cli-contract --changed` for a Gateway response change, or the family's contract test for a rendering change. Rewrite `apps/cli/tests/Expected` only with `ORBIT_EXPECTED=update` after the diff is reviewed, and add a contract case for a new command or outcome; see `docs/reference/gateway-response-fixtures.md`.
6. Update maintained documentation with the behavior. Use current repository checks and the verification skill for terminal behavior.

## Audit

Inventory the actual public command registry with optional supported extensions enabled. Record the source commit and configuration used to enumerate it. Keep hidden/internal commands separate and identify shared infrastructure they use.

Start with [the adoption-record template](templates/adoption-record.md). Keep
filled records with the task's review evidence outside the checkout.
Do not turn a baseline signature or an unverified implementation into a verdict.

For each command record its source, contract, supported modes, applicable rules, observed gaps, justified exceptions, automated checks, terminal cases, artifact paths, and verdict. A gap remains open until verified; a current implementation alone does not justify an exception.

Inspect the full human surface, the actual nested machine shape, missing/invalid input, cancellation, consent, empty results, warnings, partial outcomes, and failures where applicable. Preserve current command-specific constraints such as explicit-only targets or force options that discard source.

Use `verifying-cli-output` when correctness depends on prompts, terminal width, color, cursor movement, streaming, buffering, or liveness. A JSON test or final transcript cannot prove those behaviors. Return findings and evidence to the implementation or review task; do not claim broader command coverage than the inspected matrix supports.
