---
title: "SQLite WAL default"
description: "Why Orbit ships WAL for SQLite and how to confirm a Laravel app will open new databases in that mode."
---

# SQLite WAL default

## Problem

A Laravel app on SQLite fails with `database is locked` when one connection holds a write lock and another needs the writer. DELETE journal mode, the SQLite default when `journal_mode` is null, blocks those concurrent connections.

## Cause

Laravel only sets `PRAGMA journal_mode` when `config/database.php` has a non-null `journal_mode`. A template that ships `journal_mode => null` leaves new files in DELETE. Orbit Ops can WAL-enable a live file, but the next app that copies the null template creates another DELETE-mode database.

## Solution

Ship WAL on the SQLite connection that the process actually opens:

```php
'busy_timeout' => 5000,
'journal_mode' => 'WAL',
'synchronous' => 'NORMAL',
'transaction_mode' => 'IMMEDIATE',
```

The Gateway already uses this pairing for `gateway.sqlite`. [ADR 0083](/decisions/0083-default-sqlite-journal-mode-to-wal) owns that default. New Laravel templates should copy it so migrate does not create a DELETE-mode file.

Do not repair live node files from an application pull request. Changing journal mode on an existing database is an operations step.

## Limits

WAL writes `-wal` and `-shm` sidecars next to the database. Copy, backup, and recovery tools must keep those files with the main file. A sidecar-free WAL file can still create sidecars when opened.

This default applies to SQLite only. MySQL and PostgreSQL connections are unchanged.

## Verification

Confirm `journal_mode` is `WAL` in the application's `config/database.php`. `config('database.connections.sqlite.journal_mode')` must be `WAL`. Opening that connection and running `PRAGMA journal_mode` returns `wal`. For the Gateway, `apps/gateway/tests/Unit/Configuration/SqliteJournalModeTest.php` covers both checks.
