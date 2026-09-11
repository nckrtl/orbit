# ORB-215 development record

Flow: discovery.

Incus: required. Standard discovery attempt: `795dd520dcaa9b441ade56d3454003aa`.

Discovery development only; isolated acceptance proof was not run.

## Implementation

- Added `ProductionPhpRuntimeManager::refreshCache(AppInstance): void`.
- The remote implementation resolves the recorded `ProductionPhpRuntimeIdentity` before transport and passes only its fixed user, home, PHP version, service, pool, socket, marker, lock directory, marker bytes, and 30-second reset deadline.
- The remote script uses the existing per-user runtime lock. It authenticates the marker, service, master process, exact socket, FPM SAPI, PHP version, worker UID, and worker-to-master association.
- A root-owned mode-0444 PHP probe in a random mode-0711 directory under the root-owned sticky `/tmp` is reached only through the recorded Unix socket. A bounded stdlib FastCGI client observes the baseline, requests `opcache_reset()`, and polls later requests until counters advance and pending/in-progress state clears.
- Success is emitted only after the same active service, master PID and start time, executable, socket, and socket ownership are rechecked. The exact local receipt is `COMPLETE\n` with empty stderr and no truncation.
- Exit 41 maps to `app-prod.php_cache_socket_unavailable`, 42 to `app-prod.php_cache_association_invalid`, 43 to `app-prod.php_cache_reset_rejected`, and 44 to `app-prod.php_cache_reset_pending`. Other remote or protocol failures retain `app-prod.php_cache_refresh_failed`.
- Cleanup removes the exact probe, client, marker comparison file, and random probe directory on every exit. No CLI reset, service reload/restart, socket scan, alternate socket, or fallback is present.

## Focused local checks during development

```text
cd apps/gateway
vendor/bin/pint --dirty --format agent
vendor/bin/pest --compact tests/Feature/Infrastructure/AppInstances/ProductionPhpCacheTest.php tests/Feature/Infrastructure/AppInstances/NativeAppInstanceRemovalProjectorTest.php

Pint: passed
Pest: 21 passed, 124 assertions
```

## Discovery setup

The topology was acquired before implementation with:

```text
bin/e2e-topology acquire ORB-215 /fast/worktrees/orbit/orb-215
ready 795dd520dcaa9b441ade56d3454003aa
```

Two controlled Apps and production AppInstances were created on the existing `app-prod` Node through Gateway Tinker. Each instance used a different production Unix user and the canonical persisted runtime identity. `ProductionAppInstanceSourceLifecycle::prepareUser()` created the users, then `ProductionPhpRuntimeManager::converge()` created both dedicated PHP 8.5 runtimes.

```text
selected: app_id=2 instance_id=2 user=orbit-app-2
  service=orbit-orbit-app-2-php8.5-fpm.service
  socket=/run/php/orbit-app-2.sock
control: app_id=3 instance_id=3 user=orbit-app-3
  service=orbit-orbit-app-3-php8.5-fpm.service
  socket=/run/php/orbit-app-3.sock
```

`libfcgi-bin` was installed as disposable discovery tooling on `app-prod`. It was used only to warm and read controlled plain-PHP fixture files; product provisioning and repository dependencies were not changed.

The request shape used for controlled reads was:

```bash
sudo -u "$user" env \
  HOME="/home/$user" \
  SCRIPT_FILENAME="/home/$user/public/$script" \
  SCRIPT_NAME="/$script" \
  REQUEST_METHOD=GET \
  REQUEST_URI="/$script" \
  DOCUMENT_ROOT="/home/$user/public" \
  SERVER_PROTOCOL=HTTP/1.1 \
  REDIRECT_STATUS=200 \
  cgi-fcgi -bind -connect "/run/php/$user.sock"
```

The internal operation was invoked from the mounted Gateway with the selected AppInstance loaded with `app` and `node.roles`:

```php
app(\App\Domain\AppInstances\ProductionPhpRuntimeManager::class)
    ->refreshCache($instance);
```

## production-opcache-isolation

Both `index.php` files were requested once with `opcache.validate_timestamps=0`, then changed from `selected-old` to `selected-new` and from `control-old` to `control-new`. Requests before refresh still returned the two old values.

```text
selected PID before: 17076
control PID before: 17078
selected socket: orbit-app-2:caddy:660 /run/php/orbit-app-2.sock
control socket: orbit-app-3:caddy:660 /run/php/orbit-app-3.sock
both services: active

refresh selected result: complete
selected response after: selected-new
control response after: control-old
selected PID after: 17076
control PID after: 17078
temporary refresh paths after: none
```

Only the selected runtime observed changed code. The control runtime kept its warmed entry and both masters remained unchanged.

## production-opcache-completion

A controlled request in the selected runtime remained in flight while a refresh started. A systemd transient timer created its release file five seconds after setup. The hold script called `clearstatcache()` while waiting so release observation was deterministic.

```text
hold and release timer armed: 1789108391.476880252
refresh started:             1789108391.900964828
refresh completed:           1789108398.445165308
elapsed refresh:             6.544 seconds
result: complete
selected master: 17076 before and after
control master: 17078 before and after
```

The operation remained pending past the scheduled five-second release. It returned only after the released worker reached the reset safe point and a later FPM observation reported advanced restart counters with `restart_pending=false` and `restart_in_progress=false`. The selected service was not reloaded or restarted.

Discovery also exposed and corrected an initial false failure: while a reset is legitimately pending, PHP reports `opcache_enabled=false`. The final client accepts that state only after reset acceptance and only while pending or in-progress is true. It still requires `opcache_enabled=true` for completion.

## production-opcache-failure

Each controlled failure targeted only the selected runtime. After every case, the changed setup was restored before the next case. The control response stayed `control-old`, its service stayed active, its socket stayed `orbit-app-3:caddy:660`, and its master remained PID 17078.

### Exact socket unavailable

The selected socket pathname was renamed temporarily, the refresh was invoked, and the same socket was restored.

```text
started: 1789108417.302600613
finished: 1789108418.822100540
exit: 41
error: app-prod.php_cache_socket_unavailable
selected PID: 17076 before and after
control PID: 17078 before and after
```

### Recorded association invalid

The selected marker's service line was changed temporarily, the refresh was invoked, and the exact marker backup was restored.

```text
exit: 42
error: app-prod.php_cache_association_invalid
selected PID: 17076 before and after
control PID: 17078 before and after
```

The operation rejected the marker before opening the recorded socket.

### Reset rejected

For controlled setup only, `opcache.enable=Off` was appended to the selected runtime's generated master INI and only that selected service was restarted. The PID created by setup stayed stable during the refresh. The exact INI backup was then restored and only the selected service was restarted again.

```text
selected test PID: 18976 before and after the refresh
exit: 43
error: app-prod.php_cache_reset_rejected
control PID: 17078 before and after
selected restored PID after setup cleanup: 19074
```

The setup/restoration restarts are not product behavior. The tested refresh did not reload or restart either service.

### Reset pending at deadline

A controlled selected-runtime request remained in flight for the whole refresh. No release was scheduled until after the bounded result returned.

```text
started:  1789108516.156142281
finished: 1789108547.613135787
elapsed:  31.457 seconds including Gateway/SSH overhead
exit: 44
error: app-prod.php_cache_reset_pending
selected PID: 19074 before and after
control PID: 17078 before and after
selected and control sockets: present with their original owner/group/mode
temporary refresh paths after: none
```

The hold was released only after the result. The operation did not claim success, switch sockets, or reload a service.

## Restoration and topology state

Both dedicated runtime fixtures were removed through `ProductionPhpRuntimeManager::remove()`. The controlled users, homes, lock files, transient units, AppInstances, and Apps were then removed. No product-owned discovery fixture remained.

```text
bin/e2e-topology verify ORB-215
verified 795dd520dcaa9b441ade56d3454003aa
```

The verified discovery topology remains acquired for independent reviewer inspection. The disposable `libfcgi-bin` observation tool remains only inside that disposable topology.

## Candidate checks

The focused runtime group also passed with the nearby existing runtime test:

```text
vendor/bin/pest --compact \
  tests/Feature/Infrastructure/AppInstances/ProductionPhpCacheTest.php \
  tests/Feature/Infrastructure/AppInstances/ProductionPhpRuntimeTest.php \
  tests/Feature/Infrastructure/AppInstances/NativeAppInstanceRemovalProjectorTest.php
27 passed, 189 assertions
```

`cd apps/gateway && composer check`, `composer docs-build`, `composer docs-lint`, and `git diff --check` passed. The docs build left `docs/generated/context.json` unchanged.

The clean candidate `43be1d622bd6d69c4d9ae492ced035920c5e165c` then passed root `composer check` across all five projects with TIA.

Builder gate: `/home/nckrtl/orbit/.git/orbit-checks/43be1d622bd6d69c4d9ae492ced035920c5e165c/review-k3vwzct0/result.json`.

## Implementation notes and limitations

- The approved plan did not choose a numeric reset deadline. Live `pm=ondemand` behavior showed that 10 seconds can coincide with the default 10-second worker idle timeout and falsely expire. The final fixed reset deadline is 30 seconds, still within the script's validated 1–30 second bound. This preserves the acceptance meaning and needs no issue-contract change.
- Acceptance item 5 contains stale full no-TIA CI wording. Per the approved plan and ADR 0059, focused acceptance tests and the Gateway project check run locally, and the retained Builder runs root `composer check` with all five project checks and TIA on the exact clean candidate.
- Discovery observations are development evidence, not isolated proof. No proof topology, proof fixture, or harness change was used.

## Helper coordination

- A bounded helper added the focused cache contract and failure-mapping tests plus the existing test fake update.
- A read-only helper reviewed the FastCGI protocol, timeout classification, cleanup, and post-completion association checks.
- A read-only helper prepared the two-runtime discovery procedure.
- Helpers supplied no formal approval and were complete before handoff.
