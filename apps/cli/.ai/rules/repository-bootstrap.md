---
paths:
  - '.ai/**'
  - '.agents/**'
  - '.codex/**'
  - 'AGENTS.md'
  - 'app/Providers/LaravelBoostCompatibilityServiceProvider.php'
  - 'app/Support/LaravelZero*.php'
  - 'boost.json'
  - 'composer.json'
  - 'composer.lock'
  - 'config/boost.php'
  - 'tests/Feature/BoostGuidanceTest.php'
---

# Repository Bootstrap

## Keep Guidance Reproducible

The committed `.ai` sources, generated `AGENTS.md` block, installed
`.agents/skills`, Boost configuration, and Composer scripts form one bootstrap
contract. Run `composer guidance:generate` after a source or Boost configuration
change, then run `composer guidance:check`. Never treat missing required
guidance as permission to continue.

## Name Only Available Capabilities

Every skill named by repository guidance must have a readable installed
`SKILL.md`. Describe an MCP tool only when the active client exposes it. Keep
repository-command fallbacks accurate and executable.
