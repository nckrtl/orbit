# Feature plan

Plan format: 1
Issue: ORB-999
Flow: discovery
Review verdict: PENDING

## Outcome

Show the selected delivery flow.

## Code boundaries

In:
- bin/loop-flow and apps/docs/tests/Unit/FutureFlowTest.php

Out:
- Keep topology acquisition unchanged.

## Documentation

none: the reference already describes this output.

### Audit

No drift found.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Reports discovery | bin/loop-flow | `bin/loop-flow status | cat` |
| Accepts either flow | bin/loop-flow | ``rg 'discovery|proof'`` and `echo a\|b` |

## Incus observations

Incus: not required.

## Implementation order

1. Add the focused test, then implement the output.

## Must preserve

- Flow selection remains local to the worktree.

## Open questions

- none

## Deviations

none

## Review findings

