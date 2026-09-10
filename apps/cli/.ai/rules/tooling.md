---
paths:
  - '.editorconfig'
  - '.gitattributes'
  - '.gitignore'
  - 'pint.json'
  - 'phpstan.neon'
  - 'phpunit.xml.dist'
  - 'rector.php'
---

# Development Tooling

- Pest 5 runs the full suite in parallel without TIA through `composer test`.
- Pint owns formatting and syntax checks. Larastan owns static analysis. Rector owns automated PHP refactoring checks.
- Do not commit test caches, runtime state, credentials, or generated environment files.
