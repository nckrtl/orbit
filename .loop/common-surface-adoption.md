# ORB-352 common entry-point adoption record

Candidate: af717b848c956f1f9ac017c1a3def66474b290ae. Flow: proof.
Launcher: /opt/orbit/php/8.5/bin/php /home/orbit/orbit/apps/cli/orbit.
Runtime: disposable Ubuntu app-dev on Beast, invoked through the supported harness and Solo.
Verdict at publication: Unverified pending fresh isolated proof and independent review. This covers common entry points only; all 108 public command-family verdicts remain Unverified.

The candidate-bound proof action `contracts` records every row below in terminal, pipe and forced-ANSI-pipe variants. Each case retains `case.json`, separate stdout/stderr, descriptor facts, recorder invocation, raw/chunks/frames, expected status and assertions under `contracts/<case>-<variant>/`. Rules: preserve native framework shape, suppress prompts and escape bytes on machine/pipe channels, preserve numeric status, and show complete common information. The expected failures are asserted, not suppressed.

| Surface | Case prefix | Required observable outcome |
| --- | --- | --- |
| No arguments | default | Complete supported summary; exit 0 |
| --version | version | One native application/version line; exit 0 |
| help activity:list | help | Usage, options and current --json option; exit 0 |
| list | list | Complete supported summary; exit 0 |
| completion bash | completion | Native Bash completion source passes Bash syntax check; exit 0 |
| _complete | complete | Internal completion protocol returns exact command suggestions; exit 0 |
| Unknown command | unknown | Readable diagnostic on stderr, no product envelope; exit 1 |
| activity:list --unexpected --json | parser | Pre-binding JSON failure retains exact envelope, code and request_id; exit 1 |
| list --format=json | list-json | Native application/commands/namespaces schema; exit 0 |

Exceptions are contract-backed: framework list and completion retain their framework formats instead of gaining Orbit product JSON envelopes; internal _complete is accounted separately from public product commands. Parser refusal proves the common boundary, not activity:list family adoption. These common read-only surfaces have no destructive consent or post-selection mutation path.

Tests: CLI CommandSurfaceTest and GatewayErrorRenderingTest through TIA, plus the candidate Builder receipt and contracts action. Primitive layout, mode, prompt, liveness and lifecycle evidence is mapped in plan.md and development.md. Final evidence locations and independent verdict belong to the root delivery report and captured harness review record; do not rewrite this published artifact after proof.
