# ORB-200 development record

Flow: discovery
Candidate: `7a71e0871a4f362097d3d867a945a16f07ceca47`
Planning base: `689f7785f38a9f4e531a5befb8cb202d2a5615a5`
Discovery topology: `775df31ec5b56a464906cce11bf424a5`

## Local checks

- `cd apps/gateway && composer test:affected` first selected 3 changed test files. It ran 3,433 tests and found 11 errors in the new production fixture because an active Route was inserted before its target. The fixture now inserts a pending Route, attaches its target, and activates it.
- `cd apps/gateway && composer test:affected` then passed with 44 tests and 170 assertions for the corrected affected inspector file. The other two changed test files passed in the first run.
- Root `composer check` passed on the clean exact candidate. Its Builder receipt is `/home/nckrtl/orbit/.git/orbit-checks/7a71e0871a4f362097d3d867a945a16f07ceca47/review-sbhbgueh/result.json`.
- `git diff --check 689f7785f38a9f4e531a5befb8cb202d2a5615a5..7a71e0871a4f362097d3d867a945a16f07ceca47` exited 0.

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
