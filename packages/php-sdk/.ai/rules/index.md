# Orbit PHP SDK rule index

Read `AGENTS.md`, then this index, before you change or review this package.
Read each rule that covers the files in scope.

## Bootstrap gate

The repository guidance is invalid when this index is missing or unreadable,
an indexed rule is missing or empty, or a material path is not covered. Stop
package work. Restore the committed guidance with
`git restore --source=HEAD -- AGENTS.md .ai/rules composer.json`, then run
`composer guidance:check`. Do not report a missing `.ai/rules/` tree as a
non-blocking observation.

This directory is a repository-owned instruction source. The SDK stays
framework-neutral. Do not add Laravel or Boost for symmetry with other Orbit
repositories.

## Rules

- [PHP and Spatie](./php-spatie.md) defines package-level PHP conventions and
  the framework boundary.
- [Saloon transport](./saloon-transport.md) defines request, DTO, envelope, and
  fake behavior.
- [Redaction and security](./redaction-security.md) defines credential,
  correlation, diagnostics, TLS, and root-CA boundaries.
- [Public contract](./public-contract.md) defines the approved small Gateway
  surface and cross-repository ownership.
- [Testing and quality](./testing-quality.md) defines Pest 5 parallel no-TIA, Mago,
  Rector, and handoff gates.

## Path coverage

| Material paths | Required rules |
| --- | --- |
| `src/Gateway*.php` | PHP and Spatie; Saloon transport; Redaction and security |
| `src/Requests/**/*.php` | PHP and Spatie; Saloon transport; Redaction and security; Public contract |
| `src/Responses/**/*.php` | PHP and Spatie; Saloon transport; Redaction and security; Public contract |
| `src/Support/**/*.php` | PHP and Spatie; Saloon transport; Redaction and security |
| `src/Testing/**/*.php` | PHP and Spatie; Saloon transport; Redaction and security; Testing and quality |
| `tests/**/*.php` | PHP and Spatie; Saloon transport; Redaction and security; Public contract; Testing and quality |
| `README.md`, `AGENTS.md`, `.agents/**/*.md`, `.ai/rules/**/*.md` | Public contract; Testing and quality |
| `composer.json`, `composer.lock`, `phpunit.xml.dist`, `mago.toml`, `rector.php`, `.gitignore` | PHP and Spatie; Testing and quality |
