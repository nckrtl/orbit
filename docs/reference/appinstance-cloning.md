# AppInstance cloning

This page tells an operating agent how Orbit can seed a production AppInstance from one SQLite database while the candidate keeps running. [ADR 0047](../decisions/0047-create-production-appinstances-from-candidates.md) owns the candidate-cloning boundary.

## Select an optional SQLite database

Database seeding is optional. When a clone includes a seed, the operating agent supplies one explicit absolute path on the candidate AppInstance.

The Gateway validates the source and target before it can replace the target database.

| Boundary | Requirement |
| --- | --- |
| Source placement | The resolved path stays within the candidate's recorded development checkout or production home. |
| Source identity | The Gateway checks and reads the path as the candidate's recorded runtime user. |
| Source file | The path identifies one readable regular SQLite database with valid structure. |
| Capacity | The candidate has enough temporary space for the bounded snapshot, and the target has enough space for its installation. |
| Target path | The destination is the production home's `database.sqlite`, owned by the target runtime user with protected permissions. |

An unsafe path, unreadable or non-regular file, invalid database, unavailable execution identity, or insufficient source or target capacity stops the seed before target replacement. Omitting the source path creates no database.

## Keep the candidate live

Orbit creates a transactionally consistent snapshot while the candidate can continue writing in SQLite write-ahead logging mode. It checks the snapshot's integrity before transfer.

The seed operation does not stop source processes or schedules, pause queue processing, clear a queue, force a checkpoint, or modify the source database. The installed target contains the committed state represented by the snapshot, including pending jobs stored there.

## Install and retry the target seed

Orbit transfers the validated snapshot without putting database bytes in Activity or generic logs. It installs the complete snapshot through an atomic replacement at the target path.

An interrupted attempt resumes only temporary work that belongs to the same seed. It removes its own temporary files, preserves unrelated files, and refuses a destination database that the operation does not own. After a completed installation, an identical retry keeps the existing target database without replacing it.

## Clean only target application state

Orbit does not clean application data in either database. Before target workers start, the operating agent removes copied target queue entries or performs other target-only application cleanup when the application requires it.

Never clear the candidate queue for cloning. External storage and databases other than SQLite need their own preparation outside this seed operation.

The owning implementation and tests live in `apps/gateway`.
