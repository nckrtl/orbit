# Orbit CLI

Laravel Zero 13 client for Orbit.

- Use PHP 8.5 with strict types.
- Keep the CLI stateless except for `~/.orbit/config.json`.
- Send operational commands through `nckrtl/orbit-php-sdk`.
- Do not execute remote infrastructure commands in the CLI.
- Use Pest 5 with `describe()` and `it()`.
- Use Mago for formatting, linting, and analysis.

## Required Guidance Bootstrap

`.ai/rules/index.md` and every indexed project rule file are required repository state.
If either is absent, the checkout or Boost bootstrap is incomplete.

Run `composer guidance:check` before planning or editing any file. If it fails
because guidance is missing or incomplete:

1. Restore the committed guidance inputs and outputs with
   `git restore --source=HEAD -- AGENTS.md .ai .agents/skills .codex/config.toml boost.json config/boost.php composer.json composer.lock`.
2. Run `composer install` when `vendor/` is absent or incomplete.
3. Run `composer guidance:generate` to refresh Boost-managed output.
4. Run `composer guidance:check` again and stop if it still fails.

Do not reconstruct missing rules from memory. Do not silently continue without
the project rule set.

===

<laravel-boost-guidelines>
=== .ai/orbit rules ===

# Orbit CLI Guidelines

## Context

- This repository is a Laravel Zero 13 client, not a web application.
- Confirm installed package versions before using version-specific APIs.
- Follow existing file structure and sibling conventions. Do not add a dependency or a new top-level directory without approval.

## Skills

- Activate the relevant skill from `.agents/skills` before work in that domain.
- Every named skill must have a readable `SKILL.md`. Treat a missing skill as an incomplete Boost bootstrap and use the Required Guidance Bootstrap recovery steps.

## Verification

- Use focused Pest 5 tests during development and Test Impact Analysis through `composer test`.
- Run `composer check` and `composer test:full` before delivery. Mago and Rector are the configured PHP quality tools.

=== .ai/spatie rules ===

# Spatie Guidelines

- Activate `spatie-laravel-php` for Laravel and PHP code.
- Activate `spatie-security` for security-sensitive work.
- Activate `spatie-version-control` for Git and version-control work.

=== boost rules ===

# Laravel Boost

## Project Rules

- `.ai/rules/index.md` and every indexed rule file are required repository state. Open the index, read every rule whose globs match the files in scope, and search `.ai/rules` for relevant terms before planning or editing.
- If `.ai/rules` does not exist, the checkout or Boost bootstrap is incomplete. Restore or regenerate the guidance before planning or editing any file. Do not silently continue without the project rule set.
- Store durable project decisions in a concise repository-owned rule file and keep its frontmatter globs in the index. Run `composer guidance:check` after each rule change.

## Boost Commands

- Run `composer guidance:generate` to refresh Boost-managed guidance, skills, and scoped rules without interactive package discovery.
- Run `composer guidance:check` to verify the committed guidance and installed skill output.
- The Boost MCP server is configured in `.codex/config.toml`. Use a Boost MCP tool only when the active client exposes that tool. Use repository commands and files when it does not.

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

</laravel-boost-guidelines>
