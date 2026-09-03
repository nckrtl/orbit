# ADR NNNN: <Imperative title naming the decision, not the topic>

In the context of <situation>, facing <requirement>, we decided for <option> and against <alternatives>, to achieve <quality>, accepting <drawback>.

## Status

Proposed. Extends [ADR NNNN](NNNN-name.md). Supersedes [ADR NNNN](NNNN-name.md) for <one clause>.

## Context

<Two to four sentences. The forcing problem, the constraint that rules out the current model, and the drivers the options are judged against. Do not re-explain the current system; link it.>

## Decision

- <Actor> must <observable obligation>.
- <Actor> must not <forbidden action>.
- <Actor> may <granted permission> when <condition>.
- <Owner> owns <thing>; <other> never <mutates it>.

## Rejected alternatives

- <Option>: rejected because <one concrete reason>.
- <Option>: rejected because <one concrete reason>.

## Consequences

- <Capability that becomes possible.>
- <Cost, limitation, or drawback the decision accepts.>
- <Change that must land before this ships.>

## Affects

- Components: <apps/cli, apps/docs, apps/e2e, apps/gateway, packages/php-sdk, or none>
- ADRs: extends [ADR NNNN](NNNN-name.md); supersedes [ADR NNNN](NNNN-name.md) for <clause>; or none
- Detail: <the docs/reference page that describes the mechanism once it ships, written as a path rather than a link while the page does not exist yet, and the implementing Linear issue until then; or none>
- Verify: <test suite, lint rule, Doctor check, or proof command that shows conformance>
