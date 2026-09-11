<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\DeploymentLayout\ProductionPhpRuntimeAdopter;
use App\Domain\AppInstances\ProductionPhpRuntimeIdentity;
use App\Domain\AppInstances\ProductionPhpRuntimeManager;
use App\Infrastructure\AppProd\AppProdSshExecutor;
use App\Infrastructure\Nodes\RemotePhpPackageManager;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppInstance;
use Illuminate\Support\Collection;

final readonly class RemoteProductionPhpRuntimeManager implements ProductionPhpRuntimeAdopter, ProductionPhpRuntimeManager
{
    public function __construct(
        private ProductionPhpRuntimeConfigRenderer $renderer,
        private AppProdSshExecutor $ssh,
        private string $lockDirectory = '/run/lock/orbit',
        private RemotePhpPackageManager $packages = new RemotePhpPackageManager,
        private int $cacheDeadlineSeconds = 30,
    ) {}

    public function converge(AppInstance $appInstance): void
    {
        $this->convergeWithTuning($appInstance, null);
    }

    public function adopt(AppInstance $appInstance, string $initialLocalTuning): void
    {
        $this->convergeWithTuning($appInstance, $initialLocalTuning);
    }

    private function convergeWithTuning(AppInstance $appInstance, ?string $initialLocalTuning): void
    {
        $identity = ProductionPhpRuntimeIdentity::from($appInstance);
        $configuration = $this->renderer->render($identity);
        /** @var Collection<int, string> $versions */
        $versions = collect([$identity->version]);
        $this->packages->installPackagesOnlyForAppProd(
            $appInstance->node->loadMissing('roles'),
            $versions,
            $this->ssh,
        );

        $this->ssh->execute(
            $appInstance->node,
            new RemoteCommand(
                arguments: [
                    'sudo',
                    'bash',
                    '-seu',
                    '--',
                    'converge',
                    $identity->user,
                    $identity->home,
                    $identity->version,
                    $identity->service,
                    $identity->pool,
                    $identity->socket,
                    $identity->runtimeDirectory,
                    $identity->generatedDirectory,
                    $identity->localTuningPath,
                    $identity->unitPath,
                    $identity->markerPath,
                    $this->lockDirectory,
                    base64_encode($configuration->main),
                    base64_encode($configuration->pool),
                    base64_encode($configuration->localDefaults),
                    base64_encode($configuration->masterIni),
                    base64_encode($configuration->unit),
                    base64_encode($identity->marker()),
                    base64_encode($initialLocalTuning ?? ''),
                    $initialLocalTuning === null ? '0' : '1',
                ],
                input: $this->convergeScript(),
            ),
            step: 'app-prod-php-runtime-converge',
            errorCode: 'app-prod.php_runtime_convergence_failed',
        );
    }

    public function remove(AppInstance $appInstance): void
    {
        $identity = ProductionPhpRuntimeIdentity::from($appInstance);

        $this->ssh->execute(
            $appInstance->node,
            new RemoteCommand(
                arguments: [
                    'sudo',
                    'bash',
                    '-seu',
                    '--',
                    'remove',
                    $identity->user,
                    $identity->service,
                    $identity->pool,
                    $identity->socket,
                    $identity->runtimeDirectory,
                    $identity->generatedDirectory,
                    $identity->localTuningPath,
                    $identity->unitPath,
                    $identity->markerPath,
                    $this->lockDirectory,
                    base64_encode($identity->marker()),
                ],
                input: $this->removeScript(),
            ),
            step: 'app-prod-php-runtime-remove',
            errorCode: 'app-prod.php_runtime_removal_failed',
        );
    }

    public function refreshCache(AppInstance $appInstance): void
    {
        $identity = ProductionPhpRuntimeIdentity::from($appInstance);

        try {
            $result = $this->ssh->execute(
                $appInstance->node,
                new RemoteCommand(
                    arguments: [
                        'sudo',
                        'bash',
                        '-seu',
                        '--',
                        'refresh-cache',
                        $identity->user,
                        $identity->home,
                        $identity->version,
                        $identity->service,
                        $identity->pool,
                        $identity->socket,
                        $identity->markerPath,
                        $this->lockDirectory,
                        base64_encode($identity->marker()),
                        (string) $this->cacheDeadlineSeconds,
                    ],
                    input: $this->refreshCacheScript(),
                    maxOutputBytes: 1024,
                ),
                step: 'app-prod-php-cache-refresh',
                errorCode: 'app-prod.php_cache_refresh_failed',
            );
        } catch (RuntimeConvergenceException $exception) {
            $this->rethrowCacheFailure($exception);
        }

        if ($result->truncated || $result->stdout !== "COMPLETE\n" || $result->stderr !== '') {
            throw new RuntimeConvergenceException(
                step: 'app-prod-php-cache-refresh',
                errorCode: 'app-prod.php_cache_reset_rejected',
                message: 'The production PHP cache returned an invalid completion receipt.',
                result: $result,
            );
        }
    }

    private function rethrowCacheFailure(RuntimeConvergenceException $exception): never
    {
        [$errorCode, $message] = match ($exception->result?->exitCode) {
            41 => [
                'app-prod.php_cache_socket_unavailable',
                'The production PHP cache socket is unavailable.',
            ],
            42 => [
                'app-prod.php_cache_association_invalid',
                'The production PHP cache service association is invalid.',
            ],
            43 => [
                'app-prod.php_cache_reset_rejected',
                'The production PHP cache reset was rejected.',
            ],
            44 => [
                'app-prod.php_cache_reset_pending',
                'The production PHP cache reset did not complete before the deadline.',
            ],
            default => throw $exception,
        };

        throw new RuntimeConvergenceException(
            step: 'app-prod-php-cache-refresh',
            errorCode: $errorCode,
            message: $message,
            previous: $exception,
            result: $exception->result,
        );
    }

    private function refreshCacheScript(): string
    {
        return <<<'BASH'
            operation=$1
            user=$2
            home=$3
            version=$4
            service=$5
            pool=$6
            socket=$7
            marker_path=$8
            lock_directory=$9
            marker_configuration=${10}
            deadline_seconds=${11}
            test "$operation" = refresh-cache

            test "$home" = "/home/$user"
            test "$service" = "orbit-$user-php${version}-fpm.service"
            test "$pool" = "orbit-$user"
            test "$socket" = "/run/php/$user.sock"
            test "$marker_path" = "/etc/orbit/php-fpm/$user/orbit.identity"
            case "$deadline_seconds" in ''|*[!0-9]*) exit 1 ;; esac
            test "$deadline_seconds" -ge 1
            test "$deadline_seconds" -le 30
            id "$user" >/dev/null
            test -d "$home"
            test ! -L "$home"
            test "$(stat -c '%U:%G' -- "$home")" = "$user:$user"

            umask 0077
            if ! mkdir -- "$lock_directory" 2>/dev/null; then
                test -d "$lock_directory"
                test ! -L "$lock_directory"
            fi
            if [ "$lock_directory" = /run/lock/orbit ]; then
                chmod 0700 -- "$lock_directory"
                test "$(stat -c '%U:%G:%a' -- "$lock_directory")" = root:root:700
            fi
            lock="$lock_directory/production-php-$user.lock"
            if [ -e "$lock" ] || [ -L "$lock" ]; then
                test -f "$lock"
                test ! -L "$lock"
                test "$(stat -c '%U:%G' -- "$lock")" = root:root
            fi
            exec 9>>"$lock"
            chmod 0600 -- "$lock"
            flock -w 30 9

            expected_marker=$(mktemp)
            probe_source=$(mktemp)
            client=$(mktemp)
            probe_root=/tmp
            test -d "$probe_root"
            test ! -L "$probe_root"
            test "$(stat -c '%U:%G:%a' -- "$probe_root")" = root:root:1777
            probe_directory=$(mktemp -d --tmpdir="$probe_root" ".orbit-opcache-refresh-$user.XXXXXXXX")
            probe="$probe_directory/probe.php"
            cleanup() {
                status=$?
                rm -f -- "$expected_marker" "$probe_source" "$client" "$probe"
                rmdir -- "$probe_directory" 2>/dev/null || true
                exit "$status"
            }
            trap cleanup EXIT
            chmod 0711 -- "$probe_directory"

            printf '%s' "$marker_configuration" | base64 --decode > "$expected_marker"
            if [ ! -f "$marker_path" ] || [ -L "$marker_path" ] \
                || [ "$(stat -c '%U:%G:%a' -- "$marker_path" 2>/dev/null)" != root:root:644 ] \
                || ! cmp -s -- "$expected_marker" "$marker_path"
            then
                exit 42
            fi

            if ! test -S "$socket"; then
                exit 41
            fi
            if [ "$(stat -c '%U:%G:%a' -- "$socket" 2>/dev/null)" != "$user:caddy:660" ]; then
                exit 42
            fi
            if ! systemctl is-active --quiet "$service"; then
                exit 42
            fi
            master_before=$(systemctl show --property=MainPID --value "$service" 2>/dev/null || true)
            case "$master_before" in ''|*[!0-9]*) exit 42 ;; esac
            if [ "$master_before" -le 1 ] || [ ! -e "/proc/$master_before/exe" ] \
                || [ "$(readlink -f -- "/proc/$master_before/exe" 2>/dev/null)" != "/usr/sbin/php-fpm$version" ]
            then
                exit 42
            fi
            master_start_before=$(sed 's/^.*) //' "/proc/$master_before/stat" 2>/dev/null | awk '{print $20}')
            case "$master_start_before" in ''|*[!0-9]*) exit 42 ;; esac
            if ! kill -0 "$master_before" 2>/dev/null; then
                exit 42
            fi

            cat > "$probe_source" <<'PHP'
            <?php

            declare(strict_types=1);

            header('Content-Type: application/json');

            $status = opcache_get_status(false);
            $statistics = is_array($status) ? ($status['opcache_statistics'] ?? null) : null;
            $processStatus = file_get_contents('/proc/self/status');

            if (! is_array($status) || ! is_array($statistics)) {
                echo json_encode(['error' => 'opcache-unavailable'], JSON_THROW_ON_ERROR);
                return;
            }

            if (! is_string($processStatus)) {
                echo json_encode(['error' => 'identity-unavailable'], JSON_THROW_ON_ERROR);
                return;
            }

            if (preg_match('/^Uid:\s+([0-9]+)/m', $processStatus, $uidMatch) !== 1) {
                echo json_encode(['error' => 'identity-unavailable'], JSON_THROW_ON_ERROR);
                return;
            }

            $identity = [
                'sapi' => PHP_SAPI,
                'version' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
                'uid' => (int) $uidMatch[1],
                'worker_pid' => getmypid(),
                'parent_pid' => posix_getppid(),
            ];
            $observation = [
                'opcache_enabled' => $status['opcache_enabled'] ?? null,
                'restart_pending' => $status['restart_pending'] ?? null,
                'restart_in_progress' => $status['restart_in_progress'] ?? null,
                'manual_restarts' => $statistics['manual_restarts'] ?? null,
                'last_restart_time' => $statistics['last_restart_time'] ?? null,
            ];

            if (($_SERVER['ORBIT_OPCACHE_ACTION'] ?? '') === 'reset') {
                echo json_encode([
                    'identity' => $identity,
                    'observation' => $observation,
                    'accepted' => opcache_reset(),
                ], JSON_THROW_ON_ERROR);
                return;
            }

            echo json_encode([
                'identity' => $identity,
                'observation' => $observation,
            ], JSON_THROW_ON_ERROR);
            PHP
            install -o root -g root -m 0444 -- "$probe_source" "$probe"

            cat > "$client" <<'PYTHON'
            import errno
            import json
            import socket
            import struct
            import sys
            import time

            FCGI_BEGIN_REQUEST = 1
            FCGI_END_REQUEST = 3
            FCGI_PARAMS = 4
            FCGI_STDIN = 5
            FCGI_STDOUT = 6
            FCGI_STDERR = 7
            FCGI_RESPONDER = 1
            REQUEST_ID = 1

            class CacheFailure(Exception):
                def __init__(self, exit_code):
                    self.exit_code = exit_code

            def encoded_length(length):
                if length < 128:
                    return bytes([length])
                return struct.pack('!I', length | 0x80000000)

            def record(record_type, content=b''):
                padding_length = (-len(content)) % 8
                header = struct.pack(
                    '!BBHHBB',
                    1,
                    record_type,
                    REQUEST_ID,
                    len(content),
                    padding_length,
                    0,
                )
                return header + content + (b'\0' * padding_length)

            def parameters(values):
                encoded = bytearray()
                for name, value in values.items():
                    name_bytes = name.encode()
                    value_bytes = value.encode()
                    encoded.extend(encoded_length(len(name_bytes)))
                    encoded.extend(encoded_length(len(value_bytes)))
                    encoded.extend(name_bytes)
                    encoded.extend(value_bytes)
                return bytes(encoded)

            def receive_exact(connection, length):
                received = bytearray()
                while len(received) < length:
                    chunk = connection.recv(length - len(received))
                    if not chunk:
                        raise CacheFailure(70)
                    received.extend(chunk)
                return bytes(received)

            def request(action, deadline, reset_accepted=False):
                remaining = deadline - time.monotonic()
                if remaining <= 0:
                    raise CacheFailure(44 if reset_accepted else 70)

                values = {
                    'GATEWAY_INTERFACE': 'CGI/1.1',
                    'REQUEST_METHOD': 'GET',
                    'SCRIPT_FILENAME': probe_path,
                    'SCRIPT_NAME': '/.orbit-opcache-refresh.php',
                    'QUERY_STRING': '',
                    'REQUEST_URI': '/.orbit-opcache-refresh.php',
                    'DOCUMENT_ROOT': home,
                    'SERVER_PROTOCOL': 'HTTP/1.1',
                    'SERVER_NAME': 'localhost',
                    'SERVER_PORT': '80',
                    'REMOTE_ADDR': '127.0.0.1',
                    'REMOTE_PORT': '1',
                    'SERVER_SOFTWARE': 'Orbit',
                    'REDIRECT_STATUS': '200',
                    'ORBIT_OPCACHE_ACTION': action,
                }
                begin = struct.pack('!HB5x', FCGI_RESPONDER, 0)
                payload = (
                    record(FCGI_BEGIN_REQUEST, begin)
                    + record(FCGI_PARAMS, parameters(values))
                    + record(FCGI_PARAMS)
                    + record(FCGI_STDIN)
                )
                output = bytearray()
                errors = bytearray()
                ended = False

                try:
                    with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as connection:
                        connection.settimeout(max(0.05, remaining))
                        connection.connect(socket_path)
                        connection.sendall(payload)

                        while not ended:
                            remaining = deadline - time.monotonic()
                            if remaining <= 0:
                                raise CacheFailure(44 if reset_accepted else 70)
                            connection.settimeout(max(0.05, remaining))
                            header = receive_exact(connection, 8)
                            version, record_type, request_id, content_length, padding_length, _ = struct.unpack(
                                '!BBHHBB',
                                header,
                            )
                            if version != 1 or request_id != REQUEST_ID:
                                raise CacheFailure(70)
                            content = receive_exact(connection, content_length)
                            if padding_length:
                                receive_exact(connection, padding_length)
                            if record_type == FCGI_STDOUT:
                                output.extend(content)
                            elif record_type == FCGI_STDERR:
                                errors.extend(content)
                            elif record_type == FCGI_END_REQUEST:
                                if content_length != 8:
                                    raise CacheFailure(70)
                                app_status, protocol_status = struct.unpack('!IB3x', content)
                                if app_status != 0 or protocol_status != 0:
                                    raise CacheFailure(70)
                                ended = True
                            if len(output) + len(errors) > 65536:
                                raise CacheFailure(70)
                except (FileNotFoundError, ConnectionRefusedError):
                    raise CacheFailure(41)
                except socket.timeout:
                    raise CacheFailure(44 if reset_accepted else 70)
                except OSError as error:
                    unavailable = {
                        errno.EACCES,
                        errno.ECONNREFUSED,
                        errno.ENOENT,
                        errno.ENOTSOCK,
                    }
                    raise CacheFailure(41 if error.errno in unavailable else 70)

                if errors or not ended:
                    raise CacheFailure(70)
                header_bytes, separator, body = bytes(output).partition(b'\r\n\r\n')
                if not separator:
                    raise CacheFailure(70)
                try:
                    header_lines = header_bytes.decode('ascii').split('\r\n')
                except UnicodeDecodeError:
                    raise CacheFailure(70)
                normalized_headers = {line.lower() for line in header_lines}
                if 'content-type: application/json' not in normalized_headers:
                    raise CacheFailure(70)
                status_headers = [line for line in header_lines if line.lower().startswith('status:')]
                if status_headers and status_headers != ['Status: 200 OK']:
                    raise CacheFailure(70)

                try:
                    result = json.loads(body)
                except (UnicodeDecodeError, json.JSONDecodeError):
                    raise CacheFailure(70)
                if not isinstance(result, dict):
                    raise CacheFailure(70)
                return result

            def observation(result, reset_accepted=False):
                if result == {'error': 'opcache-unavailable'}:
                    raise CacheFailure(43)
                if result == {'error': 'identity-unavailable'}:
                    raise CacheFailure(42)
                identity = result.get('identity')
                observed = result.get('observation')
                if not isinstance(identity, dict) or not isinstance(observed, dict):
                    raise CacheFailure(70)
                if identity != {
                    'sapi': 'fpm-fcgi',
                    'version': php_version,
                    'uid': expected_uid,
                    'worker_pid': identity.get('worker_pid'),
                    'parent_pid': master_pid,
                }:
                    raise CacheFailure(42)
                if type(identity['worker_pid']) is not int or identity['worker_pid'] <= 1:
                    raise CacheFailure(42)
                expected_keys = {
                    'opcache_enabled',
                    'restart_pending',
                    'restart_in_progress',
                    'manual_restarts',
                    'last_restart_time',
                }
                if set(observed) != expected_keys:
                    raise CacheFailure(70)
                if not isinstance(observed['opcache_enabled'], bool):
                    raise CacheFailure(70)
                if not isinstance(observed['restart_pending'], bool):
                    raise CacheFailure(70)
                if not isinstance(observed['restart_in_progress'], bool):
                    raise CacheFailure(70)
                if type(observed['manual_restarts']) is not int:
                    raise CacheFailure(70)
                if type(observed['last_restart_time']) is not int:
                    raise CacheFailure(70)
                if observed['opcache_enabled'] is not True:
                    waiting = observed['restart_pending'] or observed['restart_in_progress']
                    if not reset_accepted or not waiting:
                        raise CacheFailure(43)
                return observed

            socket_path, probe_path, home, php_version, expected_uid_value, master_pid_value, deadline_value = sys.argv[1:]
            expected_uid = int(expected_uid_value)
            master_pid = int(master_pid_value)
            deadline = time.monotonic() + int(deadline_value)

            try:
                before = observation(request('status', deadline))
                reset = request('reset', deadline)
                reset_before = observation(reset)
                if reset_before != before:
                    raise CacheFailure(70)
                if reset.get('accepted') is not True:
                    raise CacheFailure(43)

                while True:
                    after = observation(
                        request('status', deadline, reset_accepted=True),
                        reset_accepted=True,
                    )
                    advanced = (
                        after['manual_restarts'] > before['manual_restarts']
                        or after['last_restart_time'] > before['last_restart_time']
                    )
                    if advanced and not after['restart_pending'] and not after['restart_in_progress']:
                        break
                    if time.monotonic() >= deadline:
                        raise CacheFailure(44)
                    time.sleep(min(0.05, max(0.0, deadline - time.monotonic())))
            except CacheFailure as failure:
                sys.exit(failure.exit_code)
            except Exception:
                sys.exit(70)
            PYTHON
            chmod 0700 -- "$client"

            expected_uid=$(id -u "$user")
            python3 "$client" \
                "$socket" \
                "$probe" \
                "$home" \
                "$version" \
                "$expected_uid" \
                "$master_before" \
                "$deadline_seconds"

            if ! systemctl is-active --quiet "$service"; then
                exit 42
            fi
            master_after=$(systemctl show --property=MainPID --value "$service" 2>/dev/null || true)
            test "$master_after" = "$master_before" || exit 42
            if [ "$(sed 's/^.*) //' "/proc/$master_after/stat" 2>/dev/null | awk '{print $20}')" != "$master_start_before" ] \
                || [ "$(readlink -f -- "/proc/$master_after/exe" 2>/dev/null)" != "/usr/sbin/php-fpm$version" ] \
                || ! test -S "$socket" \
                || [ "$(stat -c '%U:%G:%a' -- "$socket" 2>/dev/null)" != "$user:caddy:660" ]
            then
                exit 42
            fi

            printf 'COMPLETE\n'
            BASH;
    }

    private function convergeScript(): string
    {
        return <<<'BASH'
            operation=$1
            user=$2
            home=$3
            version=$4
            service=$5
            pool=$6
            socket=$7
            runtime_directory=$8
            generated_directory=$9
            local_tuning=${10}
            unit_path=${11}
            marker_path=${12}
            lock_directory=${13}
            main_configuration=${14}
            pool_configuration=${15}
            local_defaults=${16}
            master_ini=${17}
            unit_configuration=${18}
            marker_configuration=${19}
            initial_local_tuning=${20}
            has_initial_local_tuning=${21}
            test "$operation" = converge

            test "$home" = "/home/$user"
            expected_service="orbit-$user-php${version}-fpm.service"
            test "$service" = "$expected_service"
            test "$pool" = "orbit-$user"
            test "$socket" = "/run/php/$user.sock"
            test "$runtime_directory" = "/etc/orbit/php-fpm/$user"
            test "$generated_directory" = "$runtime_directory/generated"
            test "$local_tuning" = "$runtime_directory/local.conf"
            test "$unit_path" = "/etc/systemd/system/$service"
            test "$marker_path" = "$runtime_directory/orbit.identity"
            id "$user" >/dev/null
            test -d "$home"
            test ! -L "$home"
            test "$(stat -c '%U:%G' -- "$home")" = "$user:$user"

            umask 0077
            if ! mkdir -- "$lock_directory" 2>/dev/null; then
                test -d "$lock_directory"
                test ! -L "$lock_directory"
            fi
            if [ "$lock_directory" = /run/lock/orbit ]; then
                chmod 0700 -- "$lock_directory"
                test "$(stat -c '%U:%G:%a' -- "$lock_directory")" = root:root:700
            fi
            lock="$lock_directory/production-php-$user.lock"
            if [ -e "$lock" ] || [ -L "$lock" ]; then
                test -f "$lock"
                test ! -L "$lock"
                test "$(stat -c '%U:%G' -- "$lock")" = root:root
            fi
            exec 9>>"$lock"
            chmod 0600 -- "$lock"
            flock -w 30 9

            expected_marker=$(mktemp)
            work_directory=$(mktemp -d)
            printf '%s' "$marker_configuration" | base64 --decode > "$expected_marker"
            trap 'rm -f -- "$expected_marker"; rm -rf -- "$work_directory"' EXIT

            runtime_created=0
            if [ ! -e "$runtime_directory" ] && [ ! -L "$runtime_directory" ]; then
                install -d -o root -g root -m 0755 -- "$runtime_directory"
                runtime_created=1
            fi
            test -d "$runtime_directory"
            test ! -L "$runtime_directory"
            test "$(stat -c '%U:%G:%a' -- "$runtime_directory")" = root:root:755

            if [ -e "$marker_path" ] || [ -L "$marker_path" ]; then
                test -f "$marker_path"
                test ! -L "$marker_path"
                test "$(stat -c '%U:%G:%a' -- "$marker_path")" = root:root:644
                cmp -s -- "$expected_marker" "$marker_path"
            else
                if [ "$runtime_created" = 0 ]; then
                    test ! -e "$generated_directory"
                    test ! -e "$local_tuning"
                fi
                test ! -e "$unit_path"
                test ! -e "$socket"
                marker_candidate="$runtime_directory/.orbit.identity.$$.candidate"
                install -o root -g root -m 0644 -- "$expected_marker" "$marker_candidate"
                mv -fT -- "$marker_candidate" "$marker_path"
            fi

            if [ ! -e "$local_tuning" ]; then
                test ! -L "$local_tuning"
                if [ "$has_initial_local_tuning" = 1 ]; then
                    printf '%s' "$initial_local_tuning" | base64 --decode > "$work_directory/local.defaults"
                else
                    printf '%s' "$local_defaults" | base64 --decode > "$work_directory/local.defaults"
                fi
                local_candidate="$runtime_directory/.local.conf.$$.candidate"
                install -o root -g root -m 0644 -- "$work_directory/local.defaults" "$local_candidate"
                mv -fT -- "$local_candidate" "$local_tuning"
            else
                test -f "$local_tuning"
                test ! -L "$local_tuning"
                test "$(stat -c '%U:%G:%a' -- "$local_tuning")" = root:root:644
                if [ "$has_initial_local_tuning" = 1 ]; then
                    printf '%s' "$initial_local_tuning" | base64 --decode > "$work_directory/local.expected"
                    cmp -s -- "$work_directory/local.expected" "$local_tuning"
                fi
            fi
            local_before=$(sha256sum -- "$local_tuning" | awk '{print $1}')

            awk -v expected_pool="[$pool]" '
                /^[[:space:]]*($|;|#)/ { next }
                /^[[:space:]]*\[/ {
                    line=$0
                    gsub(/^[[:space:]]+|[[:space:]]+$/, "", line)
                    if (line != expected_pool) exit 1
                    next
                }
                {
                    line=tolower($0)
                    sub(/^[[:space:]]+/, "", line)
                    if (line ~ /^include[[:space:]]*=/) exit 1
                    if (line ~ /^(pid|user|group|listen|listen[.]owner|listen[.]group|listen[.]mode|chdir|env\[home\]|env\[user\])[[:space:]]*=/) exit 1
                }
            ' "$local_tuning"

            printf '%s' "$main_configuration" | base64 --decode > "$work_directory/php-fpm.conf"
            printf '%s' "$pool_configuration" | base64 --decode > "$work_directory/pool.conf"
            printf '%s' "$master_ini" | base64 --decode > "$work_directory/master.ini"
            printf '%s' "$unit_configuration" | base64 --decode > "$work_directory/unit"
            cp -- "$local_tuning" "$work_directory/local.conf"
            sha256sum -- "$work_directory/local.conf" | awk '{print $1}' > "$work_directory/local.sha256"
            cp -- "$work_directory/php-fpm.conf" "$work_directory/php-fpm.validate.conf"
            sed -i \
                -e "s#^include = .*/generated/pool[.]conf\$#include = $work_directory/pool.conf#" \
                -e "s#^include = .*/local[.]conf\$#include = $work_directory/local.conf#" \
                "$work_directory/php-fpm.validate.conf"
            PHP_INI_SCAN_DIR="/etc/php/$version/fpm/conf.d:$work_directory" \
                /usr/sbin/php-fpm"$version" -y "$work_directory/php-fpm.validate.conf" -t
            fpm_ini=$(PHP_INI_SCAN_DIR="/etc/php/$version/fpm/conf.d:$work_directory" \
                /usr/sbin/php-fpm"$version" -i)
            printf '%s\n' "$fpm_ini" | grep -qF -- "$work_directory/master.ini"
            while IFS= read -r runtime_line || [ -n "$runtime_line" ]; do
                case "$runtime_line" in ''|';'*) continue ;; esac
                runtime_key=${runtime_line%%=*}
                runtime_key=${runtime_key%"${runtime_key##*[! ]}"}
                runtime_expected=${runtime_line#*=}
                runtime_expected=${runtime_expected#"${runtime_expected%%[! ]*}"}
                if [ "$runtime_key" = opcache.validate_timestamps ] && [ "$runtime_expected" = 0 ]; then
                    runtime_expected=Off
                fi
                if ! printf '%s\n' "$fpm_ini" \
                    | grep -qxF -- "$runtime_key => $runtime_expected => $runtime_expected"
                then
                    printf 'PHP %s dedicated fpm does not apply %s = %s from %s.\n' \
                        "$version" "$runtime_key" "$runtime_expected" "$work_directory/master.ini" >&2
                    exit 1
                fi
            done < "$work_directory/master.ini"

            had_generated=0
            had_unit=0
            was_active=0
            was_enabled=0
            if [ -e "$generated_directory" ] || [ -L "$generated_directory" ]; then
                test -d "$generated_directory"
                test ! -L "$generated_directory"
                test "$(stat -c '%U:%G:%a' -- "$generated_directory")" = root:root:755
                unexpected_generated=$(find -P "$generated_directory" -mindepth 1 -maxdepth 1 \
                    ! -name php-fpm.conf ! -name pool.conf ! -name master.ini ! -name local.sha256 -print -quit)
                test -z "$unexpected_generated"
                for generated_file in php-fpm.conf pool.conf local.sha256; do
                    generated_path="$generated_directory/$generated_file"
                    test -f "$generated_path"
                    test ! -L "$generated_path"
                    test "$(stat -c '%U:%G:%a' -- "$generated_path")" = root:root:644
                done
                if [ -e "$generated_directory/master.ini" ] || [ -L "$generated_directory/master.ini" ]; then
                    test -f "$generated_directory/master.ini"
                    test ! -L "$generated_directory/master.ini"
                    test "$(stat -c '%U:%G:%a' -- "$generated_directory/master.ini")" = root:root:644
                fi
                cp -a -- "$generated_directory" "$work_directory/generated.backup"
                had_generated=1
            fi
            if [ -e "$unit_path" ] || [ -L "$unit_path" ]; then
                test -f "$unit_path"
                test ! -L "$unit_path"
                test "$(stat -c '%U:%G:%a' -- "$unit_path")" = root:root:644
                cp -a -- "$unit_path" "$work_directory/unit.backup"
                had_unit=1
            fi
            systemctl is-active --quiet "$service" && was_active=1 || true
            systemctl is-enabled --quiet "$service" && was_enabled=1 || true
            if [ -e "$socket" ] || [ -L "$socket" ]; then
                test -S "$socket"
                test "$(stat -c '%U:%G:%a' -- "$socket")" = "$user:caddy:660"
            fi
            runtime_changed=0
            for comparison in php-fpm.conf pool.conf master.ini local.sha256; do
                if [ ! -f "$generated_directory/$comparison" ] \
                    || ! cmp -s -- "$work_directory/$comparison" "$generated_directory/$comparison"
                then
                    runtime_changed=1
                fi
            done
            if [ ! -f "$unit_path" ] || ! cmp -s -- "$work_directory/unit" "$unit_path"; then
                runtime_changed=1
            fi

            published=0
            restore_runtime() {
                status=$?
                trap - EXIT
                if [ "$status" -ne 0 ] && [ "$published" = 1 ]; then
                    systemctl disable --now "$service" >/dev/null 2>&1 || true
                    rm -rf -- "$generated_directory"
                    if [ "$had_generated" = 1 ]; then
                        cp -a -- "$work_directory/generated.backup" "$generated_directory"
                    fi
                    rm -f -- "$unit_path"
                    if [ "$had_unit" = 1 ]; then
                        cp -a -- "$work_directory/unit.backup" "$unit_path"
                    fi
                    systemctl daemon-reload || true
                    if [ "$was_enabled" = 1 ]; then systemctl enable "$service" >/dev/null 2>&1 || true; fi
                    if [ "$was_active" = 1 ]; then systemctl start "$service" >/dev/null 2>&1 || true; fi
                fi
                rm -f -- "$expected_marker"
                rm -rf -- "$work_directory"
                exit "$status"
            }
            if [ "$was_active" = 1 ] && [ "$runtime_changed" = 0 ]; then
                systemctl enable "$service"
            else
                trap restore_runtime EXIT

                generated_candidate="$runtime_directory/.generated.$$.candidate"
                install -d -o root -g root -m 0755 -- "$generated_candidate"
                printf '%s' "$main_configuration" | base64 --decode > "$generated_candidate/php-fpm.conf"
                printf '%s' "$pool_configuration" | base64 --decode > "$generated_candidate/pool.conf"
                printf '%s' "$master_ini" | base64 --decode > "$generated_candidate/master.ini"
                cp -- "$work_directory/local.sha256" "$generated_candidate/local.sha256"
                chown root:root -- "$generated_candidate/php-fpm.conf" "$generated_candidate/pool.conf" "$generated_candidate/master.ini" "$generated_candidate/local.sha256"
                chmod 0644 -- "$generated_candidate/php-fpm.conf" "$generated_candidate/pool.conf" "$generated_candidate/master.ini" "$generated_candidate/local.sha256"
                unit_candidate="/etc/systemd/system/.$service.$$.candidate"
                printf '%s' "$unit_configuration" | base64 --decode > "$unit_candidate"
                chown root:root -- "$unit_candidate"
                chmod 0644 -- "$unit_candidate"
                published=1
                if [ ! -e "$generated_directory" ]; then
                    install -d -o root -g root -m 0755 -- "$generated_directory"
                fi
                mv -fT -- "$generated_candidate/php-fpm.conf" "$generated_directory/php-fpm.conf"
                mv -fT -- "$generated_candidate/pool.conf" "$generated_directory/pool.conf"
                mv -fT -- "$generated_candidate/master.ini" "$generated_directory/master.ini"
                mv -fT -- "$generated_candidate/local.sha256" "$generated_directory/local.sha256"
                rmdir -- "$generated_candidate"
                mv -fT -- "$unit_candidate" "$unit_path"
                systemctl daemon-reload
                if [ "$was_active" = 1 ]; then
                    systemctl enable "$service"
                    systemctl restart "$service"
                else
                    systemctl enable --now "$service"
                fi
            fi
            systemctl is-active --quiet "$service"
            main_pid=$(systemctl show --property MainPID --value "$service")
            test "$main_pid" -gt 1
            test -e "/proc/$main_pid/exe"
            test "$(readlink -f -- "/proc/$main_pid/exe")" = "/usr/sbin/php-fpm$version"
            test -S "$socket"
            test "$(stat -c '%U:%G:%a' -- "$socket")" = "$user:caddy:660"
            local_after=$(sha256sum -- "$local_tuning" | awk '{print $1}')
            test "$local_before" = "$local_after"

            published=0
            trap - EXIT
            rm -f -- "$expected_marker"
            rm -rf -- "$work_directory"
            BASH;
    }

    private function removeScript(): string
    {
        return <<<'BASH'
            operation=$1
            user=$2
            service=$3
            pool=$4
            socket=$5
            runtime_directory=$6
            generated_directory=$7
            local_tuning=$8
            unit_path=$9
            marker_path=${10}
            lock_directory=${11}
            marker_configuration=${12}
            test "$operation" = remove

            case "$service" in
                php*.service) exit 1 ;;
                orbit-"$user"-php*-fpm.service) ;;
                *) exit 1 ;;
            esac
            test "$pool" = "orbit-$user"
            test "$socket" = "/run/php/$user.sock"
            test "$runtime_directory" = "/etc/orbit/php-fpm/$user"
            test "$generated_directory" = "$runtime_directory/generated"
            test "$local_tuning" = "$runtime_directory/local.conf"
            test "$unit_path" = "/etc/systemd/system/$service"
            test "$marker_path" = "$runtime_directory/orbit.identity"

            umask 0077
            if ! mkdir -- "$lock_directory" 2>/dev/null; then
                test -d "$lock_directory"
                test ! -L "$lock_directory"
            fi
            lock="$lock_directory/production-php-$user.lock"
            exec 9>>"$lock"
            chmod 0600 -- "$lock"
            flock -w 30 9

            expected_marker=$(mktemp)
            printf '%s' "$marker_configuration" | base64 --decode > "$expected_marker"
            trap 'rm -f -- "$expected_marker"' EXIT
            if [ -e "$marker_path" ] || [ -L "$marker_path" ]; then
                test -f "$marker_path"
                test ! -L "$marker_path"
                test "$(stat -c '%U:%G:%a' -- "$marker_path")" = root:root:644
                cmp -s -- "$expected_marker" "$marker_path"
            else
                test ! -e "$generated_directory"
                test ! -e "$unit_path"
                test ! -e "$socket"
                exit 0
            fi

            if ! systemctl disable --now "$service"; then
                test ! -e "$unit_path"
                systemctl stop "$service" >/dev/null 2>&1 || true
            fi
            if systemctl is-active --quiet "$service"; then
                exit 1
            fi
            main_pid=$(systemctl show --property MainPID --value "$service" 2>/dev/null || true)
            if [ -z "$main_pid" ]; then main_pid=0; fi
            case "$main_pid" in *[!0-9]*) exit 1 ;; esac
            test "$main_pid" -eq 0
            rm -rf -- "$generated_directory"
            rm -f -- "$unit_path"
            systemctl daemon-reload
            if [ -e "$socket" ] || [ -L "$socket" ]; then
                test -S "$socket"
                test "$(stat -c '%U:%G' -- "$socket")" = "$user:caddy"
                rm -f -- "$socket"
            fi
            if [ -e "$local_tuning" ] || [ -L "$local_tuning" ]; then
                test -f "$local_tuning"
                test ! -L "$local_tuning"
            fi
            test -f "$local_tuning" || true
            rm -f -- "$marker_path"
            test ! -e "$generated_directory"
            test ! -e "$unit_path"
            test ! -e "$socket"
            test -d "$runtime_directory"
            BASH;
    }
}
