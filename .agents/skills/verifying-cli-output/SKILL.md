---
name: verifying-cli-output
description: Use when Orbit CLI interaction, rendering, animation, or terminal-versus-pipe behavior needs to be observed in a real terminal.
---

# Verifying CLI Output

Run and inspect Orbit commands in real terminals. Compare their behavior with the [CLI standard](../../../docs/reference/cli-ux.md) and the command's reference page.

## Record the behavior

Run the feature's source or binary in its intended environment. Record the commit, command, terminal size, and settings that affect output. Use the supported operating system for platform-specific behavior.

Capture interactive cases in a visible terminal. Test piped and machine output separately. Use disposable inputs and keep secrets out of recordings.

To drive a flow from an agent, use a Solo terminal: spawn a terminal process, send the command, then send each key as its own input with a short wait, and read the rendered screen back after every step. Laravel Prompts reads one key per read and ignores keys that arrive together, so send arrow keys, space, and enter separately. A text prompt shows its default as editable text, so typing appends to it. Record on Linux; the recorder on macOS runs Orbit without repainting, which hides animation.

The bundled recorder captures terminal output, screen frames, timing, and exit status. See [recorder usage](references/recorder.md) for commands and verification options.

## Inspect the result

Check prompts, keyboard selection, validation, cancellation, and consent. Inspect full screen frames for columns, wrapping, colors, and readable progress labels.

For animation and responsiveness, inspect changes over time against the CLI standard's cadence. Check that progress ends in the correct success, failure, or cancellation state.

Check that piped and machine output follows the command's format and exit-code contract. Test expected failures against their output, exit status, and side effects.

## Report

Return the cases tested, observed behavior, findings, and limitations to the implementation or review task. Preserve recordings outside the repository and link sanitized evidence from the PR review or audit record.

Export and verify guest recordings before releasing the environment. Record affected cases again after fixes.
