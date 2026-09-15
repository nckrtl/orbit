# ORB-193 development record

Flow: discovery
Candidate: 3c429873100e7ee955c4750aec4bc19e79470aa8
Base: 6eeb4d6b2e5d4150ffe5d8abe93e4208e548b77d

## Named Pest

Related Route files: 79 passed, 495 assertions.

Acceptance filters: 9 passed, 81 assertions.

- `validates Cluster activation and deactivation before they become authoritative`
- `prepares Cluster Router paths before activation and Node scope before deactivation`
- `activates a TLD-less Cluster with owned Routes on one colocated Router`
- `restores Cluster and Route state when activation fails before publication`
- `does not return reconciliation_required after Cluster activation or deactivation`
- `converges a same-domain Cluster scope change on one Route and restores after a pre-cutover failure`
- `applies proposed Cluster placement on a generated domain replacement`
- `reconciles Cluster activation for an active AppInstance Route without moving placement`
- `accepts pending active activating retiring and failed Route statuses`

## Checks

- `composer docs-lint`: passed
- `cd apps/gateway && composer check`: passed (guidance 22, Rector, Pint, PHPStan)
- Builder gate: passed (`.git/orbit-checks/3c429873100e7ee955c4750aec4bc19e79470aa8/review-hf1ko58e/result.json`)

Gateway `test:affected` in the Builder receipt: 3920 passed, 23557 assertions.
