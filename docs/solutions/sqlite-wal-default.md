---
title: "SQLite WAL default"
description: "Why Orbit-owned SQLite connections use WAL, and how to confirm that a Laravel app opens new databases in that mode."
covers:
  - apps/gateway/config/database.php
  - apps/gateway/tests/Unit/Configuration/SqliteJournalModeTest.php
---

# SQLite WAL default

## Problem

A Laravel app on SQLite fails with `database is locked` when one connection holds the write lock and another needs it. DELETE journal mode, the SQLite default, blocks those concurrent connections.

## Cause

Laravel sets `PRAGMA journal_mode` only when `config/database.php` has a non-null `journal_mode`. A config with `journal_mode => null` creates new files in DELETE mode. Each process applies only the connection array it ships, so an app does not inherit the Gateway's setting.

## Solution

Set WAL on the SQLite connection that the process opens:

```php
'busy_timeout' => 5000,
'journal_mode' => 'WAL',
'synchronous' => 'NORMAL',
'transaction_mode' => 'IMMEDIATE',
```

The Gateway uses this set for `gateway.sqlite`, and its connection is the reference for every Orbit-owned SQLite connection. New Laravel app templates on SQLite ship the same `journal_mode`, so the first migration never creates a DELETE-mode file. `busy_timeout` with `IMMEDIATE` transactions makes a writer wait for the lock instead of failing at once.

A change to the journal mode of an existing database is an operations step. It is costly to reverse, so an application pull request never runs it on a live Node. An existing file keeps its mode until an operator changes it or the next Laravel connection with a non-null `journal_mode` opens it.

## Limits

WAL writes `-wal` and `-shm` files next to the database. Copy, backup, and recovery must keep those files with the main file. The default applies to SQLite only. MySQL and PostgreSQL connections are unchanged.

## Verification

`config('database.connections.sqlite.journal_mode')` is `WAL`, and `PRAGMA journal_mode` on an open connection returns `wal`. For the Gateway, `apps/gateway/tests/Unit/Configuration/SqliteJournalModeTest.php` checks both.
