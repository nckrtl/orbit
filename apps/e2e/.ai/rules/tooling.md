---
paths:
  - 'composer.json'
  - 'pint.json'
  - 'phpstan.neon'
  - 'boost.json'
  - 'phpunit.guidance.xml'
  - 'phpunit.scenario-cold.xml'
  - 'phpunit.scenario-snapshot.xml'
---

# Tooling rules

Use Composer scripts, Pint and Larastan, Rector, Pest, and Boost guidance. Scope analysis
and linting to app, bootstrap, config, and tests. Keep tooling console-only
and database-free, with no routes or web application paths.
