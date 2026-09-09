# Feature plan

Issue: ORB-207
Review verdict: NO PLAN REVIEW (authorized no-plan implementation path)

## Outcome

An authorized active Gateway peer can import the recorded workload `.env` or update one stored AppInstance environment value. The Gateway encrypts all values, validates the complete stored result, and returns only bounded result metadata. Neither operation changes the workload file or runs the application.

## Code boundaries

In:
- Gateway migration, encrypted environment-value model, owner relation, and deletion behavior.
- Strict import and update API requests, AppInstance ID or exact Route-hostname selection, access enforcement, bounded results, and sanitized activity.
- Owner/context validation, reusable read-preflight selection, remote import read, dotenv parsing, Laravel APP_URL normalization, result validation, and serialized mutation.
- `docs/reference/environment-variables.md`, its documentation routes, and focused Gateway tests.

Out:
- Remote environment writes or synchronization, application execution, framework cache or service changes, provisioning changes, and new Route behavior.
- SDK, CLI, harness implementation, database-path placeholders, key deletion, plaintext display, application-key generation, and migration-time remote scans.

## Documentation

Audit scope: ORB-207; `docs/domains/applications.md`, `docs/reference/appinstance-removal.md`, `docs/reference/gateway-trust.md`, `docs/reference/routes.md`, and the required `docs/reference/environment-variables.md`, routed from `docs/README.md`.

Fixed:
- `docs/reference/environment-variables.md`: the required reference was absent -> documents selectors, request and result contracts, import replacement, parsing, limits, placeholders, Laravel normalization, encryption recovery, failures, and stored-only effects.
- `docs/domains/applications.md`: application guidance did not route operators to Gateway-owned environment configuration -> links the focused reference without duplicating its contract.
- `docs/README.md`: the required reference was not discoverable -> adds it to product references.

Reported:
- none.

Verification: `composer docs-build` and `composer docs-lint` after the reference and routes are written.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| 1 | Strict import/update Form Requests | `AppInstanceEnvironmentTest.php` |
| 2 | Environment target resolver | `AppInstanceEnvironmentTest.php` |
| 3 | Owner/context validator | `AppInstanceEnvironmentTest.php` |
| 4 | Peer and owning-Node middleware boundary | `AppInstanceEnvironmentTest.php` |
| 5 | Migration, encrypted model, unique owner/key pair | `AppInstanceEnvironmentTest.php` (Database) |
| 6 | Complete-result validator limits | `AppInstanceEnvironmentValidationTest.php` |
| 7 | Closed placeholder vocabulary and expansion bound | `AppInstanceEnvironmentValidationTest.php` |
| 8 | Recorded-context read preflight and importer | Incus `environment-import-placement` |
| 9 | Reusable operation preflight contract | `AppInstanceOperationPreflightTest.php` |
| 10 | Isolated dotenv parser and importer | `AppInstanceEnvironmentImportTest.php` |
| 11 | Laravel APP_URL normalization | `AppInstanceEnvironmentImportTest.php` |
| 12 | Import conflict/replacement semantics | `AppInstanceEnvironmentImportTest.php` |
| 13 | Offline update semantics | `AppInstanceEnvironmentTest.php` (API) |
| 14 | Locked owner/Route revalidation and atomic mutation | `AppInstanceEnvironmentConcurrencyTest.php` |
| 15 | Migration/removal/Doctor disposition | Database and Doctor architecture tests |
| 16 | Bounded responses/errors/activity | API and command activity tests |
| 17 | Framework-independent dev/prod behavior | Incus `environment-store-without-application-bootstrap` |
| 18 | Environment reference | `composer docs-lint` |
| 19 | Gateway and repository checks | `composer check`; `PHPRC=/dev/null ORBIT_TEST_PROCESSES=80 bin/test` |

## Implementation order

1. Write and verify the scoped environment reference.
2. Add encrypted persistence and owner/removal integration.
3. Add complete-result validation and isolated dotenv import parsing.
4. Add recorded-context validation, reusable read preflight, and remote import reading.
5. Add strict target resolution, requests, actions, controller routes, results, and activity redaction.
6. Add focused database, domain, infrastructure, API, concurrency, activity, and architecture regressions.
7. Run focused tests and the Gateway checks; commit a product/docs checkpoint.
8. Exercise both proof scenarios on discovery, write the mutating proof plan and fixtures, integrate current main, run full checks, and coordinate exact proof with root.

## Must preserve

- Existing instance endpoint selectors and all legacy Instance/provisioning/source contracts.
- Active-peer and binary owning-Node access before file contents, stored values, SSH, or mutation.
- Arbitrary submitted, imported, stored, decrypted, and remote values stay out of responses, logs, activity, exceptions, debug output, and command arguments.
- Update remains offline; import reads only the recorded `.env`; neither changes source, services, framework caches, or application state.
- Whole-result validation and owner/Route revalidation commit atomically under contention.
- Product work does not touch harness implementation.

## Open questions

- none.

## Deviations

- none.

## Review findings

- Resolved: dotenv expansion validation now uses UTF-8 character offsets and covers a multibyte prefix before a file-local expansion.
- Resolved: remote check and read observations now reject nonempty stderr and cover bounded diagnostic redaction.
