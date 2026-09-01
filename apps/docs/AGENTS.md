# Orbit documentation tooling

This project owns the console tooling that verifies and routes the canonical
documentation corpus under the repository-root `docs/` directory.

## Boundaries

- Keep maintained documentation under root `docs/`; never add a second content
  tree below this application.
- Keep the application console-only. It has no public web route, database,
  queue, frontend build, or production deployment.
- Treat generated files as routing aids, never as product authority.
- Make generation an explicit write operation and keep linting, context lookup,
  tests, and CI read-only.
- Add semantic rules only for current Orbit invariants and cover valid and
  invalid cases with focused tests.

## Verification

- Run `composer docs:lint` after changing the root documentation corpus.
- Run `composer context:build` after a change affects the context index.
- Run `composer check` for changes to this application or its documentation
  contracts.
