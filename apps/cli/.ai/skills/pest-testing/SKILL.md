---
name: pest-testing
description: Use for every Pest 5 test change in the Orbit CLI, including TDD, command tests, security regressions, and parallel TIA verification.
---

# Pest Testing

Use Pest 5 with `describe()` and `it()`. Follow existing test helpers and
sibling command tests.

## TDD

1. Add the smallest failing behavior test.
2. Run `composer test:affected` and confirm the intended failure.
3. Implement the minimum change.
4. Re-run `composer test:affected`.

Test public behavior: input, typed SDK request, request count, output, exit code,
and local side effects. Use fake SDK transport and temporary `$ORBIT_HOME`
state. Never contact a live gateway or node.

## Gates

- `composer test` runs Pest in parallel with `--tia`.
- Report exact test and assertion counts.
