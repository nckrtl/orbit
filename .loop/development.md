# Development record

Issue: ORB-226
Flow: discovery
Discovery attempt: `240d7d4848e6c0adf0c12e44b936b59d`
Reviewed correction candidate: `44f2b2f7eb312f30fa2662ce29710a4726c4f3fc`
Current correction candidate: `e8ce4065fb2df0c849011befa396e554ad5d1ed8`

## Discovery observations

### sqlite-seed-preflight

- Relative, symlink, unreadable, and non-SQLite source paths each returned `sqlite.seed_preflight_failed` before transfer. The target remained absent and no source snapshot or attempt state remained.
- A 97 MB source database on a temporary 16 MB source `/tmp` returned `sqlite.seed_preflight_failed`. The original 912.9 MB `/tmp` mount was restored immediately; the target remained absent and no source attempt state remained.
- The same source against a temporary 16 MB target-home filesystem returned `sqlite.seed_preflight_failed`. The target filesystem was unmounted immediately; the target remained absent and no target or source attempt state remained.

### sqlite-live-snapshot

- Final-code live run completed in 21.9 seconds while a WAL writer, queue sentinel, and schedule sentinel ran under the recorded source identity.
- Source rows advanced from 90,029 before the seed to 97,925 after it. Both sentinels advanced. The source inode stayed `41644`, journal mode stayed `wal`, and `PRAGMA integrity_check` returned `ok`.
- The installed snapshot represented an intermediate committed point with 95,063 rows and `PRAGMA integrity_check=ok`.
- A recording SSH decorator observed only `source:prepare`, `target:prepare`, `target:install`, and `source:cleanup`, each as fixed `sudo -n -- python3 -c` argv. It found no process, queue, schedule, or checkpoint command.

### sqlite-seed-install

- The final target was `/home/orb226live/database.sqlite`, owned by `orb226live`, mode `0600`, with `PRAGMA integrity_check=ok` and the database-byte sentinel present once.
- No target-home candidate, SQLite sidecar, target `/tmp` incoming file, or source snapshot remained after confirmation.
- Gateway Activity contained zero matches and Gateway log files contained zero matches for `orb226-database-byte-sentinel` and `orb226-secret-payload-sentinel`.

### sqlite-seed-retry

- An injected protected-transfer interruption returned `sqlite.seed_transfer_failed`. The identical retry resumed the owned source snapshot and target incoming file, then returned `confirmed=true, changed=true`.
- The retry target was owned by `orb226retry`, mode `0600`, with `PRAGMA integrity_check=ok`, one database-byte sentinel row, and no candidate, incoming, WAL, or SHM files.
- After a new source write, an identical completed retry returned `confirmed=true, changed=false`; target inode `40767` and SHA-256 `3bccad05119c8bb58f03a5646c55bd7f121d7252eab16b5e22e3086d4033c243` stayed unchanged while source rows advanced from 87,387 to 87,388.
- A foreign target remained at inode `40785` and SHA-256 `cb6e4f179847b60747d23bc7685f11363a7bf00634284734232a49e16a0f0a17`; its `keep-me` row and unrelated sentinel file were unchanged, and the seed returned `sqlite.seed_preflight_failed` with no owned temporary state left.

## Development findings

- Live validation first exposed WAL/SHM sidecars beside a transferred WAL-mode file. Target validation now uses immutable read-only SQLite URIs, and the focused test requires every incoming sidecar to be absent.
- The hardened discovery `/tmp` refused a root truncate of the transport-owned retry file. Retry truncation now runs as the recorded transport identity with `O_NOFOLLOW` and inode revalidation.
- An unconfirmed target-preparation receipt originally removed the source snapshot. The state machine now retains both owned sides after an unconfirmed interruption, while a confirmed refusal cleans the source and creates no empty target state directory.

## Review correction

- Acceptance 2: replaced the restart-prone 256-page backup loop with one complete SQLite backup step. Lock retries have a 30-second monotonic deadline, and the existing capacity callback still refuses snapshots that exceed validated temporary space.
- Acceptance 4: a missing retained source snapshot now invalidates only its matching source state and is regenerated. Incomplete target state validates its own recorded payload, preserves an already-installed matching target, and otherwise removes only its recorded incoming or candidate files before binding to a newly generated payload.
- The PR-body omission requires no product or artifact-content change by itself. Tom owns that body-only correction.

### Corrected sqlite-live-snapshot

- A 67,657,728-byte snapshot completed in 2.2 seconds while an aggressive WAL writer with no delay, a queue sentinel, and a schedule sentinel ran under the source identity.
- Source rows advanced from 15,510 to 67,802. The snapshot captured 37,156 rows. Both sentinel values advanced, the source inode stayed `41522`, journal mode stayed `wal`, and source and target `PRAGMA integrity_check` returned `ok`.
- The target was owned by `orb226bounded`, mode `0600`, with no target-home candidate or target `/tmp` incoming file. All correction observer services are inactive.

### Corrected sqlite-seed-retry

- An injected protected-transfer interruption retained a ready 68,157,440-byte source snapshot and a prepared target incoming file, both bound to digest `157190aff763a67365ba45326083f8bae8854186c44fde9341e8192a6ae6bafd`.
- The retained source snapshot was then removed and the live source changed from 75,526 to 75,527 rows. The identical retry regenerated the snapshot, replaced the stale incomplete target binding, and returned `confirmed=true, changed=true` in 2.881 seconds.
- The installed target contained 75,527 rows, had a new digest, was owned by `orb226repair`, used mode `0600`, and passed integrity validation. The old incoming path, source state, source snapshot, target candidate, and other operation-owned temporary files were absent. The target retained only its completed retry receipt.

## Missing target-file correction

- Acceptance 4: target preparation now treats a missing recorded incoming file in `prepared` state and a missing recorded candidate file in `installing` state as lost owned work. It uses the existing inode-bound abandon path to remove any remaining recorded file and state, then creates fresh target preparation state for the same retained source snapshot.
- Existing files with an unexpected type, inode, owner, size, or digest still fail closed. A matching already-installed target is still completed before candidate recovery, so retry does not replace valid installed data.
- Focused dataset coverage removes the recorded incoming file after an injected transfer failure and removes the recorded candidate after an injected install failure. Both identical retries transfer through fresh target state, complete successfully, retain unrelated target data, remove all incomplete source and target work, and retain only the completed target receipt.

### Missing target-file discovery observations

- A protected-transfer interruption left target `226902` in `prepared` state with owned incoming `/tmp/orbit-sqlite-14b493ebecf99824-l4zzw6mw` at inode `68`. After that file was removed, the identical retry returned `confirmed=true, changed=true`.
- The recovered target `/home/orb226incoming/database.sqlite` contained 45 rows, passed `PRAGMA integrity_check`, was owned by `orb226incoming`, and used mode `0600`. The unrelated file remained. The old incoming, replacement candidate, and source attempt state were absent; only the completed target receipt remained.
- An injected install failure left target `226904` in `installing` state with candidate `/home/orb226candidate/.database.sqlite.orbit-dqkx45ar` and a retained incoming file. After the candidate was removed, the identical retry returned `confirmed=true, changed=true`.
- The recovered target `/home/orb226candidate/database.sqlite` contained 45 rows, passed `PRAGMA integrity_check`, was owned by `orb226candidate`, and used mode `0600`. The unrelated file remained. The old and replacement candidate, both operation incoming files, and source attempt state were absent; only the completed target receipt remained.

## Resource state

- `bin/e2e-topology sync ORB-226`: `ready 240d7d4848e6c0adf0c12e44b936b59d`
- `bin/e2e-topology verify ORB-226`: `verified 240d7d4848e6c0adf0c12e44b936b59d`
- Temporary writer and sentinel services are inactive.
- Discovery remains acquired for reviewer inspection. It has not been released.

## Checks on initial candidate `319c3e2952504eafaa3ad94b47e739589d021045`

- `cd apps/gateway && vendor/bin/pest --no-tia --compact tests/Feature/Infrastructure/AppInstances/SqliteSeedTest.php`: 21 passed, 177 assertions.
- Focused SQLite test plus adjacent AppInstance operation preflight, environment writer, production release layout, and removal-retention tests: 55 passed, 312 assertions.
- `cd apps/gateway && composer check`: passed; guidance 12 passed with 267 assertions, Rector, Pint, and PHPStan passed.
- `composer docs-build`: passed.
- `composer docs-lint`: passed with 0 issues, errors, or warnings.
- `cd apps/cli && composer test`: 645 passed, 3,813 assertions.
- `cd apps/gateway && composer test`: 2,996 passed, 17,220 assertions.
- `cd apps/docs && composer test`: 32 passed, 75 assertions.
- `cd apps/e2e && composer test`: 1,231 passed, 6,456 assertions.
- `cd packages/php-sdk && composer test`: 422 passed, 1,674 assertions.
- Final `bin/e2e-topology sync ORB-226`: `ready 240d7d4848e6c0adf0c12e44b936b59d`.
- Final `bin/e2e-topology verify ORB-226`: `verified 240d7d4848e6c0adf0c12e44b936b59d`.

## Correction checks

- Corrected regression cases only: 3 passed, 26 assertions.
- `cd apps/gateway && vendor/bin/pest --no-tia --compact tests/Feature/Infrastructure/AppInstances/SqliteSeedTest.php`: 24 passed, 209 assertions.
- Focused SQLite test plus AppInstance operation preflight, environment writer, production release layout, and removal-retention tests: 65 passed, 393 assertions.
- `cd apps/gateway && composer check`: passed; guidance 12 passed with 267 assertions, Rector, Pint, and PHPStan passed.
- `cd apps/gateway && composer test`: 2,999 passed, 17,252 assertions.
- `git diff --check`: passed before commit.
- Final `bin/e2e-topology sync ORB-226`: `ready 240d7d4848e6c0adf0c12e44b936b59d`.
- Final `bin/e2e-topology verify ORB-226`: `verified 240d7d4848e6c0adf0c12e44b936b59d`.

## Missing target-file correction checks

- Focused missing-target regressions: 2 passed, 30 assertions.
- `cd apps/gateway && vendor/bin/pest --no-tia --compact tests/Feature/Infrastructure/AppInstances/SqliteSeedTest.php`: 26 passed, 239 assertions.
- `cd apps/gateway && vendor/bin/pint --dirty --format agent`: passed.
- `cd apps/gateway && composer check`: passed; guidance 12 passed with 267 assertions, Rector, Pint, and PHPStan passed.
- `cd apps/gateway && composer test`: 3,001 passed, 17,282 assertions.
- `git diff --check`: passed before commit.

## Limitations

- `git commit -S` could not sign because the worktree identity `Orbit Developer <developer@example.com>` has no GPG secret key in this environment. The correction commit was created without a signature; no Git identity or signing configuration was changed.

## Deviations

- none
