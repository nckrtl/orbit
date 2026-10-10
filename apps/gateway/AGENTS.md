# Orbit Gateway

Laravel 13 control plane for Orbit.

- Use PHP 8.5 with strict types.
- Keep controllers thin. Put behavior in final readonly actions.
- Use Form Requests and typed data objects for input.
- Keep infrastructure execution synchronous and idempotent.
- Use Pest 5 with `describe()` and `it()`.
- Use Pint for formatting and Larastan for static analysis.
- Do not add queues other than the `task-vms` database queue of [task VM jobs](../../docs/reference/compute-drivers.md#jobs). Do not add a UI, Docker orchestration, or Node agents that run commands. `orbit-agent` only observes and publishes ([Node agent](../../docs/reference/node-agent.md#the-agent-only-observes)).
- Enforce binary directed node access at the HTTP boundary. One access edge permits all commands for its serving node. The active Gateway peer is implicit authority, and access to the Gateway is fleet-wide. Do not add granular permissions, presets, wildcards, or permission compatibility code.

## Track tool intent, not host inventory

Tool rows store managed intent, not observed host inventory.
The tool identity is node, manager, and package. Keep managers as protected
node prerequisites. Migrations must not scan the host or adopt packages, and
must not create rows for private bootstrap prerequisites. Explicit adoption is
a Tool operation, not a migration, and it stores one selected package rather
than an inventory.

## Keep tool input narrow

Tool install and adopt input is limited to node_id, manager, package, and version_constraint.
Tool scan input is the node_id query only.
Do not expose manager argv, scripts, repositories, environment variables, options,
or a generic script API. Do not add agent execution.
Keep manager policy in the Gateway; the SDK and CLI only transport and render
the typed contract.

## Use the closed tool manager registry

The closed tool manager registry contains apt, composer, vp, brew, and brew-cask.
apt and composer stay Linux-only. brew accepts verified Homebrew Core bottles on
Linux and macOS. brew-cask accepts official Homebrew casks on macOS. vp uses the
enrolled account's Vite+ global scope. Do not add a generic script API or agent
execution.
Use Vite+ global packages instead of exposing npm as a manager. A nullable
SemVer constraint gates the manager's normal candidate before mutation; it
never selects or downgrades a version.

Never persist or return raw manager stdout or stderr. Reject silent adoption,
protected removal, and unsafe shared-scope removal. Explicit adoption may
register one supported installed package and must not install, update, remove,
or repin it. Reject protected packages. For apt, protect every name returned by
NodeBootstrapPackageCatalog forNode and forRole, plus openssh-server and
wireguard-tools. That includes docker.io and dnsmasq. For brew, protect
wireguard-tools and wireguard-go. For vp, protect the root pnpm. Scan reports
those with adoption block protected. Do not adopt, install, or uninstall them.
APT removal must remove only the exact recorded package. Composer commands
target the exact root package in Orbit's shared global scope. vp targets the
exact root package in the enrolled account's global scope.
Homebrew formula commands accept only unqualified Homebrew Core names with a
stable, SHA-256-described bottle for the Node's platform and architecture.
brew-cask uses fixed official-cask commands and never accepts taps, URLs, local
files, caller options, or zap. Formula commands force bottle use, never start
formula services, remove only the exact recorded package, and never autoremove
dependencies.
Never run a Homebrew developer command, including brew ruby. Derive the macOS
bottle tag from sw_vers -productVersion and the stored CPU through the code-owned
table. Do not ask Homebrew to print the tag.

Treat managers as protected, role-independent Node capabilities. Allow Tool
mutations on active Linux and macOS Nodes whose WireGuard address and pinned
SSH host identity are managed by the Gateway. Linux roles, exporters, Processes,
Schedules, and the Node agent stay Linux-only.
Materialize a missing Linux manager on first use. On macOS, verify the existing
Homebrew prefix and the enrolled account's Vite+ scope in place and do not
replace them. Retain failed materialization for retry, and retain active manager
state after its final Tool is removed. Roles may require managers during
convergence but do not own them or their Tools. Never remove packages, Tool
intent, or manager state implicitly during role removal.

## Required Guidance Bootstrap

`AGENTS.md`, `.ai/rules`, `.agents/skills`,
`.codex/config.toml`, `boost.json`, and `config/boost.php` are required repository state.
Run `composer guidance:check` before planning or editing. Missing, unreadable,
empty, malformed, incomplete, or out-of-sync guidance is an incomplete checkout
or bootstrap. Make no product-code edits while this check fails.

Restore only the affected tracked guidance path from the current branch. If the
current branch does not contain it, replace the incomplete checkout. Then run
`composer install` and `composer guidance:check`. After the check passes, run
`composer guidance:update` to regenerate Boost-managed guidance and skills.
Boost cannot recreate deleted project-owned rules. Never silently continue when
the required project guidance is incomplete.

## Skills Activation

This project has domain-specific skills in `.agents/skills`. Activate each relevant skill before work in its domain.

## Test Enforcement

- Test every code change by adding or updating a test.
- Run the affected tests and important failure modes.
- Read the `testing-best-practices` skill before writing tests.

## JavaScript toolchain

Use `vp` for generic project package and script work. Vite+ defaults projects without a manager signal to pnpm.
Follow Vite+'s documented selection order. Bun is used only when project state
selects it. Vite+ manages Node through `vp env`; Orbit installs pnpm by default.
Orbit installs Bun separately. It is a host runtime. Keep PHP and Composer
workflows unchanged.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.5. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` as required repository state, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. The complete project guidance inventory is required repository state. Run `composer guidance:check` before planning or editing. If it fails, make no product-code edits. Restore the exact affected tracked guidance path, validate again, and then run `composer guidance:update`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

</laravel-boost-guidelines>
