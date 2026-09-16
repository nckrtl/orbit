---
title: "ADR 0083: Default SQLite journal_mode to WAL"
sidebarTitle: "0083 Default SQLite journal_mode to WAL"
description: "Proposed. Orbit-owned SQLite and new Laravel app templates use WAL so concurrent readers do not block writers."
---

# ADR 0083: Default SQLite journal_mode to WAL

Orbit-owned SQLite files and new Laravel application templates default `journal_mode` to WAL. Laravel applies that pragma on connect. Operators do not run live-node `PRAGMA` repairs from this change.

## Status

Proposed.

## Context

SQLite's default journal mode is DELETE. A writer holds a reserved lock that blocks other connections, including readers that then need to write. Commander hit `database is locked` under concurrent task work while its Laravel config still shipped `journal_mode => null`. Orbit Ops already switched existing `/fast/apps` SQLite files to WAL on beast. New databases and templates that keep `null` still open in DELETE.

The Gateway already sets WAL, `synchronous` NORMAL, `busy_timeout` 5000, and `transaction_mode` IMMEDIATE on `gateway.sqlite`. Laravel application configs that omit `journal_mode` do not inherit that Gateway setting. Each process applies only the connection array it ships.

Changing journal mode on a live file is an operations step. It is costly to reverse and must not run from application pull requests.

## Decision

- Orbit-owned SQLite connections must set `journal_mode` to `WAL` and `synchronous` to `NORMAL`.
- New Laravel application templates that default to SQLite must ship the same `journal_mode` so a first migrate does not create a DELETE-mode file.
- `busy_timeout` and `transaction_mode` IMMEDIATE stay the pairing that waits for a writer instead of failing immediately.
- This record does not authorize mutating live node SQLite files. Existing databases keep the journal mode already stored on disk until an operator or the next Laravel connect with a non-null `journal_mode` changes them.

## Rejected alternatives

- Leave `journal_mode` null in templates: rejected because new SQLite files stay in DELETE and reproduce the writer lock.
- Run `PRAGMA journal_mode=WAL` on live beast `/fast/apps` from this change: rejected because Ops already did that work and application PRs do not mutate fleet files.
- Force WAL only in Orbit's SQLite seed or inspector path: rejected because application PHP processes open their own PDO connections through Laravel config.

## Consequences

- Gateway `config/database.php` remains the reference Orbit SQLite connection.
- New apps that copy the Launch starter kit inherit WAL on first connect.
- Existing application files that still ship `journal_mode => null` keep whatever mode is already on disk until their config is updated.
- WAL creates `-wal` and `-shm` sidecars next to the database file. Backup and recovery paths must treat those files as part of the database.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: none
- Detail: [SQLite WAL default](/solutions/sqlite-wal-default)
- Verify: Gateway unit test that the shipped sqlite connection is WAL and that Laravel applies `PRAGMA journal_mode=wal` on connect
