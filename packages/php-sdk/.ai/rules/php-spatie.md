# PHP and Spatie

These rules apply to PHP source, tests, and package tooling.

- Use PHP 8.5. Start each PHP file with `declare(strict_types=1)`.
- Follow the Spatie PHP coding guidelines and PSR-12. Prefer typed parameters,
  properties, and return values. Use constructor property promotion when it
  improves clarity. Import symbols instead of using long inline names.
- Keep docblocks only when they add types or contract details that PHP cannot
  express. Use precise array shapes and generic types.
- Keep classes small. Prefer `final` and `readonly` when extension or mutation
  is not part of the contract. Use early returns and clear names.
- Keep the package framework-neutral. Laravel and Boost are not dependencies.
  Do not use containers, facades, framework helpers, application config, or
  environment reads.
- Keep business logic, validation policy, presentation, remote execution, and
  local trust-store changes outside the SDK.
