---
paths:
  - '.editorconfig'
  - '.gitattributes'
  - '.gitignore'
  - 'mago.toml'
  - 'phpunit.xml.dist'
  - 'rector.php'
---

# Development Tooling

- Pest 5 runs the full suite in parallel without TIA through `composer test`.
- Mago owns formatting, linting, and analysis. Rector owns automated PHP refactoring checks.
- Do not commit test caches, runtime state, credentials, or generated environment files.
