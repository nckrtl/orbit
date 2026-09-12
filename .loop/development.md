# ORB-200 development record

Flow: discovery
Candidate: `e207ad5423c4dd0bf0eab0572e5ae07a605e7560`
Planning base: `7b0e9402681e48db21da847812439d0da1f25344`
Discovery topology: `775df31ec5b56a464906cce11bf424a5`

## Local checks

- `cd apps/gateway && composer test:affected` first selected 3 changed test files. It ran 3,433 tests and found 11 errors in the new production fixture because an active Route was inserted before its target. The fixture now inserts a pending Route, attaches its target, and activates it.
- `cd apps/gateway && composer test:affected` then passed with 44 tests and 170 assertions for the corrected affected inspector file. The other two changed test files passed in the first run.
- Root `composer check` passed on the clean exact candidate. Its Builder receipt is `/home/nckrtl/orbit/.git/orbit-checks/de6b62af89ed5646599d3bbd5d918d5ff4006119/review-_k1maior/result.json`.
- `git diff --check 7b0e9402681e48db21da847812439d0da1f25344..de6b62af89ed5646599d3bbd5d918d5ff4006119` exited 0.

## Discovery setup

The prepared snapshot needed current Gateway migrations before the ORB-199 clone path could run. The setup ran `php artisan migrate --force --no-interaction` inside the Gateway guest. The snapshot's development candidate also needed its recorded `source_is_laravel` and `provisioning_step` fields brought to the current contract and its snapshot-created untracked `composer.lock` moved aside during candidate inspection. The file was restored after cloning.

The setup assigned the existing standalone app-prod Node the temporary TLD `prod.orbit`, cloned AppInstance 1 through `POST /api/v1/instances/1/clone`, stored one redacted environment value with `orbit env:update`, synchronized it with `orbit env:sync`, and ran `orbit instance:deploy 2 --json`. The first selected release was `20260912083623-c18a696acbe6e55c`. A baseline command exited 0:

`bin/e2e-topology exec ORB-200 app-dev --argv='["orbit","doctor","--node=3","--family=instance","--json"]'`

It returned one checked production AppInstance with no findings.

## production-doctor-layout

Every Doctor invocation used the baseline command above. An unhealthy Doctor report exits 1 by contract. Fixture commands used `bin/e2e-topology exec ORB-200 app-prod --argv='["bash","-lc",...]'` and restored the exact moved file, link, owner, or directory after the observation.

- Moving `current` aside represented a prepared target with no first selection. Doctor exited 0 with no findings. Restoring the retained selection also returned no findings.
- Replacing `current` with `releases/orb200-missing` produced `instance.release_selection_mismatch`, `instance.selected_release_root_mismatch`, and `instance.environment_projection_mismatch`. The original `current` link was restored.
- Replacing the selected release's `public` directory with a link to `/tmp/orb200-outside` produced only `instance.selected_release_root_mismatch`. The directory was restored and the temporary target removed.
- Changing `/home/orbit-app-1` ownership to `root:root` produced bounded production-home and selected-root drift. The original `orbit-app-1:orbit-app-1` owner was restored.
- Moving `/home/orbit-app-1/.env` aside produced `instance.environment_projection_mismatch` plus the still-active escaped-root fixture finding during that intermediate run. After both fixtures were restored, Doctor exited 0 with no findings.
- Temporarily recording `/home/orbit/apps/../outside` as development AppInstance 1's checkout produced bounded `instance.inspection_failed`; no path appeared in the report. The original development checkout was restored. The snapshot's existing source-identity finding remained separate from this path-boundary observation.

## production-doctor-runtime

- Replacing `pm.max_children = 20` with the valid local tuning value `pm.max_children = 37` left Doctor healthy. The original file and SHA-256 were restored.
- Appending the forbidden identity directive `user = root` to `local.conf` produced only `instance.php_fpm_projection_mismatch`. Restoring the exact file returned the baseline to healthy.
- Changing `/run/php/orbit-app-1.sock` ownership to `root:caddy:660` produced only `instance.php_fpm_projection_mismatch`. The before and after Doctor socket observations both remained `root:caddy:660`, so Doctor did not repair it. The fixture restored `orbit-app-1:caddy:660`.
- Stopping `orbit-orbit-app-1-php8.5-fpm.service` produced only `instance.php_fpm_projection_mismatch`. The service remained inactive after Doctor and was started only by fixture restoration.
- Clearing the recorded production socket produced `instance.php_fpm_association_missing`, `instance.php_fpm_projection_mismatch`, and the resulting aggregate `instance.caddy_projection_mismatch`. Restoring the recorded socket returned Doctor to healthy. Focused probe tests separately cover cross-AppInstance sharing for service, pool, or socket.
- Appending `# orb200 drift` to the live workload `app-dev.caddy` fragment produced only `instance.caddy_projection_mismatch`. Restoring the exact fragment returned Doctor to healthy. The expected fragment uses the existing aggregate `AppDevSiteRepository` projection, whose focused tests cover standalone workload and Cluster workload/Router placement.
- A second deployment selected `20260912084531-8965186b1c97b6ab`. `orbit instance:rollback 2 --release=20260912083623-c18a696acbe6e55c --json` selected the older retained release, and Doctor still exited 0 with no findings.
- Replacing the selected release's `public/index.php` with a response that returned HTTP 500 made `curl` report `http=500`; Doctor still exited 0 with no findings. The exact application file was restored. No Git fetch or HTTP request was part of Doctor.

## production-doctor-read-only

The healthy production comparison ran the same app-prod state command before and after Doctor. It records a deterministic home metadata/content hash after refreshing Git status, service PID and `NRestarts`, socket device/inode/owner/mode, live Caddy hash, and Git porcelain hash. Both observations were identical:

`files=27768eaee088b18f68d8d25ffef4121bd8b960bf32a6ac9a5860bb42d24978af pid=22230 restarts=0 socket=29:3706:orbit-app-1:caddy:660 caddy=c3cd5677ea5892109430c3c90d4cab52bf4fc1e98c6d7c906b92663c1fce6c96 git=e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855`

The Activity count was 1223 before a separate healthy Doctor call and 1224 after it. No other checked state changed.

- Recording `conversion-source-failed` as the production provisioning checkpoint produced only bounded `instance.inspection_failed`. Reading the row after Doctor still returned `conversion-source-failed`; fixture restoration changed it.
- Recording `deployment-activation-pending` produced only bounded `instance.inspection_failed`. Reading the row after Doctor still returned `deployment-activation-pending`; fixture restoration changed it.
- Recording `10.44.0.99` as the workload WireGuard address produced only `instance.node_unreachable`. Reading the Node after Doctor still returned `10.44.0.99`; fixture restoration returned it to `10.44.0.3`.
- The wrong-socket and stopped-service comparisons in `production-doctor-runtime` also confirmed Doctor did not chown the socket or start the service.

## Restoration and final topology checks

The active production fixture was removed through `orbit instance:remove 2 --json`. Its Route, home, dedicated service, pool, socket, and workload Caddy projection were removed by the product cleanup path. The temporary Node TLD and snapshot compatibility fields were restored to their original values. The development candidate's `composer.lock` is back in its original location.

- `bin/e2e-topology sync ORB-200` exited 0 and returned `ready 775df31ec5b56a464906cce11bf424a5`.
- `bin/e2e-topology verify ORB-200` exited 0 and returned `verified 775df31ec5b56a464906cce11bf424a5`.
- Discovery remains available for reviewer inspection.

Discovery development only; isolated acceptance proof not run

## Independent review corrections

The correction commit `e207ad5423c4dd0bf0eab0572e5ae07a605e7560` addresses both findings against reviewed candidate `de6b62af89ed5646599d3bbd5d918d5ff4006119` without changing the approved plan.

- The production inspection program no longer invokes `php-fpm -t` or any PHP-FPM executable. It compares the exact generated main, pool, master INI, unit, identity marker, and local tuning files, then reads the active service, effective executable and root UID, socket, and Caddy state. The protected-program test rejects PHP-FPM execution and known mutation commands.
- The local tuning guard now rejects the identity/path-changing `chroot` directive in addition to the existing generated identity directives.
- `cd apps/gateway && composer test:affected` passed 44 tests and 172 assertions for the affected inspector test file. `cd apps/gateway && composer check` passed its 12 guidance tests with 267 assertions, Rector, Pint, and PHPStan.
- Root `composer check` passed all 15 validate, project-check, and affected-test actions on the clean exact correction candidate. The retained Builder receipt is `/home/nckrtl/orbit/.git/orbit-checks/e207ad5423c4dd0bf0eab0572e5ae07a605e7560/review-u4tquwo1/result.json`.

### Corrected production runtime observation

The retained discovery topology was synchronized before setup. The temporary production fixture used AppInstance 3 because the prior removed fixture had consumed AppInstance 2. Production removal had correctly retained the prior fixture's application content and local tuning. Setup moved those exact retained paths to named temporary archives, resumed the failed clone after each preserved-state boundary, deployed release `20260912093441-8bcff34dc027f1da`, and returned the production instance family to healthy before the observations.

- The exact `local.conf` was backed up, then `chroot = /` was appended. Doctor exited 1 with only `instance.php_fpm_projection_mismatch`. Its SHA-256 remained `6f99312da91f03b1c283d0870b76bae2d3ea85c0c3edd95ad7ff516e27fdca77` after Doctor. Restoring the exact backup returned SHA-256 `96ff1889f06e052c95f6de42102ba50284102920a2bec7b3e8da998d1e37488e`, and Doctor exited 0 with no findings.
- A healthy before/after snapshot covered deterministic file metadata and content for `/home/orbit-app-1` and `/etc/orbit/php-fpm/orbit-app-1`; the exact unit and local tuning files; `/var/log/php-fpm.log`; service PID and `NRestarts`; socket device, inode, ownership, mode, and size; the resolved Caddy source and workload fragment; and Git porcelain state. Git status ran before each home snapshot so its own index refresh could not affect file-content evidence. Directory mtimes were excluded because Git's temporary index lock changes the `.git` directory mtime during the observation itself.
- Both complete scoped snapshots had SHA-256 `5a786c2b4201b7a6e7ccb6aac544933f8ca0cd27caefec26cce354807c1f5f93`. `/var/log/php-fpm.log` remained the same `root:root` mode-0600 inode, size 4,395, mtime, and SHA-256 `4407ee0d04e9566559525985b62212903cc7ddc81027a2525f7e9f9718c10090`. The service stayed at PID 29532 with zero restarts, and the socket, Caddy, runtime files, application files, and Git state were identical. Only the normal Activity count increased from 1263 to 1264.

AppInstance 3 was removed through the product path. The temporary correction content and source-state paths were removed, the exact retained pre-correction application content and local tuning were restored, Node 3's TLD and AppInstance 1's snapshot compatibility fields returned to `null`, and the candidate `composer.lock` returned to its original path. Final `bin/e2e-topology sync ORB-200` and `bin/e2e-topology verify ORB-200` both exited 0 for discovery `775df31ec5b56a464906cce11bf424a5`. Discovery remains available for reviewer inspection.

Discovery development only; isolated acceptance proof not run
