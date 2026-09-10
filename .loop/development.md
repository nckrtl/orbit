# Development observations

Issue: ORB-216
Flow: discovery
Attempt: `c9df908c5c8042ac0770e6ca0310006a`

## Production fixture

- App `2`, AppInstance `2`, Node `3`, hostname `orb216-release.test`.
- Recorded user and home: `orbit-app-2`, `/home/orbit-app-2`.
- Recorded checkout: `/home/orbit-app-2/releases/initial` at `0ef01549870b3234a3a9f602904a39c3ed73f44c`.
- Effective root: `/home/orbit-app-2/current/public`.

Provisioning created the user-owned `releases/initial` checkout and `/home/orbit-app-2/.env` with mode `0600`. `releases/initial/.env` resolved to the home file. Both `current` and `database.sqlite` were absent. Synchronizing the stored literal `DB_DATABASE=/srv/orb216/literal.sqlite` preserved it exactly.

## Refusals and selected-release fixtures

- `validateCurrent()` rejected an escaped `current` symlink, a directory at `current`, and an unsaved `root="../escape"` fixture. Each command exited `1`.
- A controlled `current -> releases/old` fixture resolved both the selected release and its `public` root inside the recorded home. HTTPS returned `old-release`.
- Replacing the controlled fixture with `current -> releases/new` made the next request fail before Caddy access was prepared. Calling `prepareCaddyAccess()` granted the required access; the following HTTPS request returned `new-release`. `readlink -f` and `realpath -e` then named the selected `new` release and its `public` directory.
- The active workload fragment rendered `root * /home/orbit-app-2/current/public` and `resolve_root_symlink` inside its production `php_fastcgi` block.

These links were controlled observation fixtures. ORB-216 did not add or invoke a deployment switch.

## Removal and retention

Before removal:

- Deterministic `releases/` archive SHA-256: `f22d904e99188849f36e644db6035dce207c4e8952d4a7f925765927ecc49ac7`.
- Home `.env` SHA-256: `830d47cb284bf7c1816efa3d16762e98d2d179b9358b900658a6372bb8f865cf`, mode `0600`.
- Local tuning SHA-256: `601cad33e13c85df264153247ac384a383829eac1142cdfb478b24e4b6ec48fd`.

`instance:remove 2 --json` exited `0` and reported the operation completed. After removal:

- `current` was absent.
- The deterministic `releases/` archive, home `.env`, and local tuning digests were unchanged. The environment file remained user-owned with mode `0600`.
- `/etc/orbit/php-fpm/orbit-app-2` retained only `local.conf`; the generated runtime directory, identity marker, systemd unit, service link, PID file, and socket were absent.
- The AppInstance and its final-target Route were absent from CLI lists.
- The certificate directory was absent. The active Caddy projections on `app-prod` and `app-dev` contained no `orb216-release` or `orbit-app-2` entry.
- The unrelated development AppInstance and Route remained active.

`bin/e2e-topology verify ORB-216` exited `0` for attempt `c9df908c5c8042ac0770e6ca0310006a`.

Discovery development only; isolated acceptance proof not run
