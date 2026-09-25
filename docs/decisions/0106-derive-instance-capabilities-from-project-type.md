---
title: "ADR 0106: Derive instance capabilities from Project type"
sidebarTitle: "0106 Derive instance capabilities from Project type"
description: "Proposed. Project.type is a closed enum that decides whether an Instance gets a Route and whether an app-prod PHP Instance runs a dedicated FPM master."
---

# ADR 0106: Derive instance capabilities from Project type

`Project.type` is a closed enum. It decides routing, PHP-FPM capability, and the meaning of the Project root. Operators do not pick those behaviors per Instance. The values are `monorepo`, `laravel-app`, `laravel-package`, and `node-package`. A Node package is a non-web Project and uses its package manager lockfiles for dependency refresh.

## Status

Proposed. Amends [ADR 0028](/decisions/0028-require-one-route-per-active-appinstance) so the one-Route rule applies to web-serving Project types. Amends [ADR 0045](/decisions/0045-isolate-production-php-fpm-by-unix-user) so a dedicated FPM master exists only when the Instance serves PHP. Extends [ADR 0105](/decisions/0105-name-applications-as-project-and-instance).

## Context

Every active Instance needs exactly one Route ([ADR 0028](/decisions/0028-require-one-route-per-active-appinstance)). That matches a Laravel application that serves HTTP. It does not match the Orbit monorepo or a Laravel package, which must not publish a hostname or keep an idle FPM master.

Type belongs on the Project. Instances of one repository share the same routing and serving contract. A desktop or Vite type is added only when that type needs different behavior.

Existing Projects have no type. The upgrade must assign a value to every row. Existing Routes, environment values, releases, analytics, and TaskGroup morphs stay.

## Decision

- `Project.type` is required. The closed enum is `monorepo`, `laravel-app`, `laravel-package`, and `node-package`.
- New Project creation requires `type`. Compatibility `app:create` callers that omit it receive `laravel-app`.
- `project:update` may change `type`. A change to `laravel-app` is refused while an active Instance has no Route. A change away from `laravel-app` keeps existing Routes.
- Routing is derived from type, not from an Instance flag.
- `laravel-app` is the only web-serving type in this decision. An active `laravel-app` Instance must have exactly one Route. Orbit creates that Route during provisioning and cloning onto app-prod, using the current preview-hostname rule for clones.
- `monorepo`, `laravel-package`, and `node-package` Instances get no Route by default. An operator may attach a Route only with an explicit domain and a supported serving target (a web root Orbit can publish). Those types may stay active without a Route.
- A Project root is a normalized path relative to its repository. Package types may use `.` to mean the repository root; web-serving Projects must use a valid relative web root. A package Instance rooted at `.` is not a supported Route target; it needs an explicit relative web root first.
- Every PHP Instance on an app-prod Node still receives a dedicated Unix user ([ADR 0045](/decisions/0045-isolate-production-php-fpm-by-unix-user) and [ADR 0107](/decisions/0107-key-isolation-and-releases-to-node-role)).
- Orbit starts a dedicated FPM master only when that Instance serves PHP. `laravel-app` serves PHP. `laravel-package` and `node-package` do not start idle FPM. A `monorepo` Instance starts FPM only after an explicit supported serving target is attached.
- The upgrade classifies existing Projects with no nulls:
  - Repository identity `github.com/nckrtl/orbit`, or slug `orbit` with that identity, becomes `monorepo`.
  - A Project whose stored root is `public` or ends with `/public`, or that already has production PHP-FPM identity, becomes `laravel-app`.
  - Every other Project becomes `laravel-package`.
- Operators may correct a classification with `project:update --type` after the upgrade. The classifier does not delete Routes, environment values, releases, or morph rows.

## Rejected alternatives

- Keep one Route for every active Instance: rejected because packages and the Orbit monorepo would keep publishing hostnames they do not serve.
- Store routing and FPM flags on each Instance: rejected because those capabilities belong to the repository kind, not a placement.
- Add a generic desktop type: rejected because no distinct behavior is defined. `node-package` is included because its repository-root defaults and Node lockfile refresh behavior differ from web-serving Projects.
- Infer type only from current Routes: rejected because [ADR 0028](/decisions/0028-require-one-route-per-active-appinstance) already forced Routes onto non-serving placements.

## Consequences

- Active package and monorepo Instances can exist without a Route. SQLite triggers and API guards must allow that.
- Existing package Routes remain until an operator removes them.
- A misclassified Project can keep serving until Ops sets the intended type.
- Tasks that place an Orbit checkout that is not visitable already skip Routes ([ADR 0103](/decisions/0103-absorb-commander-tasks-as-a-gateway-extension)). This record does not enable the `tasks` extension.

## Affects

- Components: apps/cli, apps/docs, apps/e2e, apps/gateway, packages/php-sdk
- ADRs: amends [ADR 0028](/decisions/0028-require-one-route-per-active-appinstance) and [ADR 0045](/decisions/0045-isolate-production-php-fpm-by-unix-user); extends [ADR 0105](/decisions/0105-name-applications-as-project-and-instance)
- Detail: [Projects](/reference/apps), [Routes](/reference/routes), [PHP runtimes](/reference/php-runtime)
- Verify: `composer docs-lint`; Gateway type, route-constraint, and clone FPM tests
