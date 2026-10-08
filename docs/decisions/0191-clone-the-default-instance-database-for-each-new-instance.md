---
title: "ADR 0191: Clone the default Instance's database for each new Instance"
sidebarTitle: "0191 Instance database clones"
description: "In progress. instance:create gives each new development Instance its own copy of the default Instance's database, of the same kind, plus a test database, and writes .env.testing. instance:destroy drops the copy."
---

# ADR 0191: Clone the default Instance's database for each new Instance

When the Project's `default` Instance has a database attached under prefix `DB`, `instance:create` gives each new development Instance its own copy of that database. The copy has the same kind: a MySQL database on the same server, or a SQLite file. Orbit also creates a test database of the same kind and writes `.env.testing`, so tests never touch the Instance's own data. `instance:destroy` drops the copy.

## Status

In progress.

Principle: this decision serves [agents operate, humans steer](/mission#principles) and [deterministic first](/mission#principles). One command gives an agent an isolated Instance with real data, and the rule depends only on what the default Instance has attached.

## Context

A new development Instance gets a checkout and a Route, but no database. On beast, Instances of one Project share one database or point at none. A test run with `RefreshDatabase` can then wipe data that another Instance uses.

The `default` Instance is the Project's main development source. It follows the Project's default branch, and its name fixes its path and domain. So it is the natural source of a Project's working data.

## Decision

`CreateInstanceAction` owns the clone. It runs after the source is ready and before the setup steps, so setup steps such as migrations run against the copy.

### When Orbit clones

Orbit clones when all of these are true:

- The new Instance is a development Instance and is not named `default`.
- The Project has an Instance named `default`.
- That Instance has a connection attached under prefix `DB`.

Otherwise `instance:create` behaves as before.

### MySQL

The source connection must point to a [Database server](/decisions/0190-run-mysql-servers-with-a-gateway-held-admin-password). Orbit creates a database named `<project>_<instance>` and the Instance's user on that server, with hyphens replaced by underscores and a hash suffix when a name is too long. It copies the data with `mysqldump --single-transaction` piped into `mysql` inside the server's container. It also creates an empty `<name>_test` database and grants the user every database whose name starts with `<name>_test`, so Laravel's parallel testing can create `<name>_test_test_1` and the rest. It records the connection with the Instance as owner and attaches it under prefix `DB`.

A source connection that has no server returns `instance.database_clone_unsupported` before anything changes.

### SQLite

The source file must be inside the `default` Instance's checkout. Orbit copies it to the same relative path in the new checkout. On the same Node, Orbit holds SQLite's write lock while it takes a reflink of the database file and its `-wal` file, so the copy shares data blocks with the source. Without block cloning, when a writer holds the lock for two seconds, or on another Node, Orbit takes a consistent snapshot with SQLite's backup API and copies it, between Nodes through the existing SQLite transfer. It registers the copy as a `sqlite` connection owned by the Instance and attaches it under prefix `DB`. The test database is `:memory:`. Any other case returns `instance.database_clone_unsupported` before anything changes.

### Test configuration

For an Instance with an owned database, every environment synchronization also sets the `DB_*` keys in `.env.testing` to the test database. It creates a missing file from the same values as `.env`, with `APP_ENV=testing`, and keeps the other lines of an existing untracked file. Orbit never writes a `.env.testing` that Git tracks in the checkout; it records the test database name in the activity instead. Laravel loads it when `APP_ENV` is `testing`, which `phpunit.xml` sets. A `phpunit.xml` that forces other `DB_*` values still wins, so such a repository must drop those lines to use the test database.

### Failure and removal

A clone that fails stops `instance:create` with `instance.database_clone_failed`. The Instance is removed as after a failed setup, without the teardown steps because no setup step ran, and the partial copy is dropped. `instance:destroy` drops every database the Instance owns, their test databases, and the Instance's user, and deletes the connection records. Orbit never drops a database that the Instance does not own.

## Rejected alternatives

- An opt-in flag on `instance:create`: an Instance created without it shares or lacks data, which is the problem this solves.
- A Project setting that names the source Instance: the `default` Instance already is the main source, and a second setting would allow two answers.
- Convert a SQLite source to MySQL on each clone: type differences between the engines make the copy unreliable. The clone keeps the source's kind.
- Let each repository read a `DB_TEST_*` key: every repository would need its own change before tests are safe.

## Consequences

- Each development Instance has isolated data and its own test database.
- Each clone holds a full copy of the default Instance's data, including personal data.
- A Project whose `default` Instance has no attached database gets no clone. Its main Instance must be named `default`.
- Creation takes longer by the time of the copy.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk
- ADRs: [ADR 0190](/decisions/0190-run-mysql-servers-with-a-gateway-held-admin-password)
- Detail: [Applications: Create a development Instance](/domains/applications), [Database connections](/reference/database-connections), [Instance removal](/reference/instance-removal), [Instance environment variables](/reference/environment-variables)
- Verify: Gateway tests for both kinds of clone, refusals, failure rollback, removal, and `.env.testing`; an Incus proof that clones a MySQL default database into a new Instance and runs a test against `<name>_test`
