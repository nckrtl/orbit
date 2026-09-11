---
name: pest-testing
description: Use when writing, changing, debugging, or reviewing Pest tests in the Orbit Gateway.
---

# Pest testing

This repository uses Pest 5 and PHPUnit 13. Use `describe()` and `it()` with clear behavior names.

## Test design

- Start with a focused failing test and confirm the expected failure before implementation.
- Prefer real boundaries and Laravel fakes over tests that only restate mock expectations.
- Use factories or named states when they exist. Do not add unused factory or seeder ceremony.
- Use datasets for meaningful input matrices. Test API authentication, validation, redaction, ownership, rollback, and stable error contracts at their executing boundary.
- Do not delete tests without explicit approval.
- This Gateway has no UI or browser-test surface. Do not invent browser, Livewire, or Inertia tests.

## Commands

Run Pest through the project's TIA commands:

```bash
composer test
composer test:affected
```

Do not pass a test path, filter, group, or suite. Pest disables TIA for partial runs even when `--tia` is present. `composer test` runs Pest in parallel with Test Impact Analysis. Run the repository Rector and Pint and Larastan gates before handoff.
