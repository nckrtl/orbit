---
name: pest-testing
description: Use for every Pest 5 test change in the Orbit CLI, including TDD, command tests, security regressions, and Test Impact Analysis.
---

# Pest Testing

Use Pest 5 with `describe()` and `it()`. Follow existing test helpers and
sibling command tests.

## TDD

1. Add the smallest failing behavior test.
2. Run the focused test and confirm the intended failure.
3. Implement the minimum change.
4. Re-run the focused test, then the affected family.

Test public behavior: input, typed SDK request, request count, output, exit code,
and local side effects. Use fake SDK transport and temporary `$ORBIT_HOME`
state. Never contact a live gateway or node.

## Gates

- `composer test` runs Pest with Test Impact Analysis.
- `composer test:full` runs the complete suite with `--no-tia`.
- Report exact test and assertion counts.
