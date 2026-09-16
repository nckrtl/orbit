---
paths:
  - '.editorconfig'
  - '.gitattributes'
  - '.gitignore'
  - 'box.json'
  - 'phpacker/**'
  - 'pint.json'
  - 'phpstan.neon'
  - 'phpunit.guidance.xml'
  - 'phpunit.xml.dist'
  - 'rector.php'
---

# Development Tooling

- Pest 5 runs in parallel with TIA through `composer test`.
- Pint owns formatting and syntax checks. Larastan owns static analysis. Rector owns automated PHP refactoring checks.
- Do not commit test caches, runtime state, credentials, or generated environment files.
- `box.json` is the Laravel Zero PHAR layout for `bin/orbit-build-cli-binary`. Keep PHPacker in `phpacker/` so its Symfony 7 constraint does not join the CLI lockfile.
