<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\DeploymentLayout\DeploymentLayoutInventory;
use App\Domain\AppInstances\DeploymentLayout\ProductionLayoutConverter;
use App\Domain\AppInstances\ProductionPhpRuntimeIdentity;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppProd\AppProdSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppInstance;
use App\Models\Route;
use JsonException;

final readonly class RemoteProductionLayoutConverter implements ProductionLayoutConverter
{
    private const string SourceAccessFunctions = <<<'PYTHON'
        def inspect_source_access(home, document_root, expected_uid, expected_gid, home_device, refuse):
            if not document_root or os.path.isabs(document_root):
                refuse("document root is invalid")
            document_root_path = os.path.abspath(os.path.normpath(os.path.join(home, document_root)))
            if document_root_path != home and not document_root_path.startswith(home + os.sep):
                refuse("document root is outside the production home")
            if os.path.realpath(document_root_path) != document_root_path:
                refuse("document root has an unsafe canonical path")
            try:
                ancestor = document_root_path
                while True:
                    ancestor_metadata = os.lstat(ancestor)
                    if not stat.S_ISDIR(ancestor_metadata.st_mode) or stat.S_ISLNK(ancestor_metadata.st_mode):
                        refuse("document root has an unsafe ancestor")
                    if ancestor == home:
                        break
                    ancestor = os.path.dirname(ancestor)
                for current, directories, files in os.walk(home, topdown=True, followlinks=False):
                    retained_directories = []
                    for name in directories + files:
                        entry = os.path.join(current, name)
                        entry_metadata = os.lstat(entry)
                        if entry_metadata.st_uid != expected_uid or entry_metadata.st_gid != expected_gid:
                            refuse("production source has unexpected ownership")
                        if name in directories and not stat.S_ISLNK(entry_metadata.st_mode) and entry_metadata.st_dev == home_device:
                            retained_directories.append(name)
                    directories[:] = retained_directories
                for current, directories, files in os.walk(document_root_path, topdown=True, followlinks=False):
                    for name in directories + files:
                        if stat.S_ISLNK(os.lstat(os.path.join(current, name)).st_mode):
                            refuse("document root contains a symbolic link")
            except OSError:
                refuse("production source metadata cannot be inspected")
        PYTHON;

    private const string PreflightProgram = self::SourceAccessFunctions.<<<'PYTHON'

        import base64, hashlib, json, os, pwd, re, stat, subprocess, sys

        home, user, instance, version, expected_env_hash, requested_sqlite, route_id, route_node_id, hostname, route_status, route_targets_hash, document_root = sys.argv[1:]
        instance_id = int(instance)
        release = f"{home}/releases/initial"
        staging = f"/home/.orbit-layout-{instance_id}"
        previous_socket = f"/run/php/orbit-app-instance-{instance_id}.sock"
        database = f"{home}/database.sqlite"
        source_marker = f"/var/lib/orbit/app-instance-sources/{instance_id}/release-layout"
        dedicated_runtime = f"/etc/orbit/php-fpm/{user}"
        dedicated_service = f"orbit-{user}-php{version}-fpm.service"
        dedicated_unit = f"/etc/systemd/system/{dedicated_service}"
        dedicated_socket = f"/run/php/{user}.sock"

        def refuse(message):
            print(message, file=sys.stderr)
            raise SystemExit(42)

        def digest(path):
            value = hashlib.sha256()
            with open(path, "rb", buffering=0) as source:
                while chunk := source.read(65536):
                    value.update(chunk)
            return value.hexdigest()

        def sqlite_sidecar_exists(path):
            return any(os.path.lexists(path + suffix) for suffix in ("-wal", "-shm", "-journal"))

        try:
            account = pwd.getpwnam(user)
        except KeyError:
            refuse("production user is missing")
        if account.pw_dir != home or home != f"/home/{user}":
            refuse("production identity is invalid")
        if os.path.realpath(home) != home:
            refuse("production home has an unsafe canonical path")
        try:
            metadata = os.lstat(home)
        except OSError:
            refuse("production home is unavailable")
        if not stat.S_ISDIR(metadata.st_mode) or stat.S_ISLNK(metadata.st_mode):
            refuse("production home has an unsafe type")
        if metadata.st_uid != account.pw_uid or metadata.st_gid != account.pw_gid:
            refuse("production home has unexpected ownership")
        inspect_source_access(home, document_root, account.pw_uid, account.pw_gid, metadata.st_dev, refuse)
        requested_candidate = None
        if requested_sqlite:
            requested_candidate = requested_sqlite if os.path.isabs(requested_sqlite) else os.path.join(home, requested_sqlite)
            requested_candidate = os.path.abspath(os.path.normpath(requested_candidate))
        conflicts = [
            staging,
            f"{home}/releases",
            f"{home}/current",
            source_marker,
            dedicated_runtime,
            dedicated_unit,
            dedicated_socket,
            f"{database}-wal",
            f"{database}-shm",
            f"{database}-journal",
        ]
        if requested_candidate != database:
            conflicts.append(database)
        if any(os.path.lexists(path) for path in conflicts):
            refuse("a conversion destination is occupied")
        environment = f"{home}/.env"
        try:
            environment_metadata = os.lstat(environment)
        except OSError:
            refuse("environment file is missing")
        if not stat.S_ISREG(environment_metadata.st_mode) or stat.S_ISLNK(environment_metadata.st_mode):
            refuse("environment file has an unsafe type")
        if environment_metadata.st_uid != account.pw_uid or environment_metadata.st_gid != account.pw_gid:
            refuse("environment file has unexpected ownership")
        environment_hash = digest(environment)
        if environment_hash != expected_env_hash:
            refuse("environment file does not match stored values")
        try:
            repository_root = subprocess.check_output(
                ["sudo", "-n", "-u", user, "--", "git", "-C", home, "rev-parse", "--show-toplevel"],
                text=True,
                stderr=subprocess.DEVNULL,
            ).strip()
            git_head = subprocess.check_output(
                ["sudo", "-n", "-u", user, "--", "git", "-C", home, "rev-parse", "--verify", "HEAD"],
                text=True,
                stderr=subprocess.DEVNULL,
            ).strip()
            git_state = subprocess.check_output(
                ["sudo", "-n", "-u", user, "--", "git", "-C", home, "status", "--porcelain=v2", "-z"],
                stderr=subprocess.DEVNULL,
            )
        except (OSError, subprocess.CalledProcessError):
            refuse("source is not a readable Git checkout")
        if repository_root != home or re.fullmatch(r"[0-9a-f]{40}", git_head) is None:
            refuse("source Git identity is invalid")

        sqlite_path = None
        sqlite_hash = None
        if requested_sqlite:
            candidate = requested_candidate
            if candidate == home or not candidate.startswith(home + os.sep):
                refuse("SQLite source is outside the production home")
            if os.path.realpath(candidate) != candidate:
                refuse("SQLite source has an unsafe canonical path")
            relative = os.path.relpath(candidate, home)
            if relative == ".env" or relative.startswith(".git/") or relative == ".git":
                refuse("SQLite source conflicts with managed source")
            try:
                sqlite_metadata = os.lstat(candidate)
            except OSError:
                refuse("SQLite source is missing")
            if not stat.S_ISREG(sqlite_metadata.st_mode) or stat.S_ISLNK(sqlite_metadata.st_mode):
                refuse("SQLite source has an unsafe type")
            if sqlite_metadata.st_uid != account.pw_uid or sqlite_metadata.st_gid != account.pw_gid:
                refuse("SQLite source has unexpected ownership")
            if sqlite_sidecar_exists(candidate):
                refuse("SQLite source has an uncheckpointed sidecar")
            with open(candidate, "rb", buffering=0) as sqlite_file:
                if sqlite_file.read(16) != b"SQLite format 3\x00":
                    refuse("SQLite source has an invalid header")
            selected_identity = (sqlite_metadata.st_dev, sqlite_metadata.st_ino)
            inspected = False
            try:
                process_entries = os.listdir("/proc")
                inspected = True
                for process in process_entries:
                    if not process.isdigit():
                        continue
                    descriptor_directory = f"/proc/{process}/fd"
                    try:
                        descriptors = os.listdir(descriptor_directory)
                    except (FileNotFoundError, PermissionError):
                        continue
                    for descriptor in descriptors:
                        try:
                            opened = os.stat(f"{descriptor_directory}/{descriptor}")
                        except OSError:
                            continue
                        if (opened.st_dev, opened.st_ino) == selected_identity:
                            refuse("SQLite source has an open file handle")
            except OSError:
                refuse("open-file state cannot be inspected")
            if not inspected:
                refuse("open-file state cannot be inspected")
            sqlite_path = candidate
            sqlite_hash = digest(candidate)
            sqlite_identity = f"{sqlite_metadata.st_dev}:{sqlite_metadata.st_ino}"
        else:
            sqlite_identity = None

        pool_path = f"/etc/php/{version}/fpm/pool.d/orbit-scopes.conf"
        pool_name = f"orbit-app-instance-{instance_id}"
        supported = {"pm", "pm.max_children", "pm.process_idle_timeout", "pm.max_requests", "catch_workers_output"}
        managed = {"user", "group", "listen", "listen.owner", "listen.group", "listen.mode", "chdir", "clear_env"}
        generated = {
            "env[home]": home,
            "env[user]": user,
            "env[path]": "/usr/local/bin:/opt/orbit/composer/vendor/bin:/usr/bin:/bin",
            "php_admin_value[opcache.validate_timestamps]": "0",
        }
        tuning_lines = [f"[orbit-{user}]"]
        in_pool = False
        found_pool = False
        try:
            with open(pool_path, encoding="utf-8") as pools:
                for original in pools:
                    line = original.strip()
                    section = re.fullmatch(r"\[([^]]+)\]", line)
                    if section:
                        in_pool = section.group(1) == pool_name
                        found_pool = found_pool or in_pool
                        continue
                    if not in_pool or not line or line.startswith((";", "#")):
                        continue
                    if "=" not in line:
                        refuse("shared PHP tuning is malformed")
                    key, value = (part.strip() for part in line.split("=", 1))
                    key = key.lower()
                    if key in supported:
                        tuning_lines.append(line)
                    elif key in generated and value == generated[key]:
                        continue
                    elif key in ("env[home]", "env[user]"):
                        refuse("shared PHP tuning changes the production identity")
                    elif key.startswith("env[") or key.startswith("php_admin_value["):
                        tuning_lines.append(line)
                    elif key in managed:
                        continue
                    else:
                        refuse("shared PHP tuning contains an unsupported directive")
        except OSError:
            refuse("shared PHP pool configuration is unavailable")
        if not found_pool:
            refuse("shared PHP pool is missing")
        tuning = "\n".join(tuning_lines) + "\n"
        try:
            source_caddyfile = os.path.realpath("/etc/caddy/Caddyfile")
            fragment = os.path.join(os.path.dirname(source_caddyfile), "fragments", "app-dev.caddy")
            with open(fragment, encoding="utf-8") as caddy_file:
                caddy = caddy_file.read()
        except OSError:
            refuse("serving projection is unavailable")
        expected_root = f"root * {home}/{document_root}"
        if f"https://{hostname} {{" not in caddy or expected_root not in caddy or f"php_fastcgi unix/{previous_socket}" not in caddy:
            refuse("serving projection does not match the flat placement")
        try:
            socket_metadata = os.stat(previous_socket)
        except OSError:
            refuse("shared PHP socket is unavailable")
        if not stat.S_ISSOCK(socket_metadata.st_mode):
            refuse("shared PHP socket has an unsafe type")
        print(json.dumps({
            "source_path": home,
            "release_path": release,
            "source_identity": f"{metadata.st_dev}:{metadata.st_ino}",
            "git_head": git_head,
            "git_state_hash": hashlib.sha256(git_state).hexdigest(),
            "environment_hash": environment_hash,
            "sqlite_source_path": sqlite_path,
            "sqlite_hash": sqlite_hash,
            "sqlite_identity": sqlite_identity,
            "local_tuning_hash": hashlib.sha256(tuning.encode()).hexdigest(),
            "local_tuning": base64.b64encode(tuning.encode()).decode(),
            "route_id": int(route_id),
            "route_node_id": int(route_node_id),
            "route_hostname": hostname,
            "route_status": route_status,
            "route_targets_hash": route_targets_hash,
            "document_root": document_root,
            "previous_socket": previous_socket,
        }, separators=(",", ":")))
        PYTHON;

    private const string SqliteQuiescenceProgram = <<<'PYTHON'
        import hashlib, os, pwd, stat, sys

        home, release, relative, expected_identity, expected_hash, user = sys.argv[1:]
        database = f"{home}/database.sqlite"

        def refuse():
            raise SystemExit(42)

        def sqlite_sidecar_exists(path):
            return any(os.path.lexists(path + suffix) for suffix in ("-wal", "-shm", "-journal"))

        def parent_descriptor(root, relative_path):
            parts = relative_path.split("/")
            if not parts or any(part in ("", ".", "..") for part in parts):
                refuse()
            descriptor = os.open(root, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
            try:
                for part in parts[:-1]:
                    child = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=descriptor)
                    os.close(descriptor)
                    descriptor = child
                return descriptor, parts[-1]
            except BaseException:
                os.close(descriptor)
                raise

        def regular_descriptor(root, relative_path):
            parent, leaf = parent_descriptor(root, relative_path)
            try:
                descriptor = os.open(leaf, os.O_RDONLY | os.O_NOFOLLOW, dir_fd=parent)
            finally:
                os.close(parent)
            metadata = os.fstat(descriptor)
            if not stat.S_ISREG(metadata.st_mode):
                os.close(descriptor)
                refuse()
            return descriptor, metadata

        if os.path.realpath(home) != home or os.path.realpath(release) != release:
            refuse()

        account = pwd.getpwnam(user)
        selected = f"{release}/{relative}"
        selected_exists = os.path.lexists(selected)
        database_exists = os.path.lexists(database)

        if sqlite_sidecar_exists(selected) or sqlite_sidecar_exists(database):
            refuse()

        if selected_exists and os.path.islink(selected):
            if not database_exists or os.path.realpath(selected) != database:
                refuse()
            descriptor, metadata = regular_descriptor(home, "database.sqlite")
        elif selected_exists and not database_exists:
            descriptor, metadata = regular_descriptor(release, relative)
        elif not selected_exists and database_exists:
            descriptor, metadata = regular_descriptor(home, "database.sqlite")
        else:
            refuse()

        try:
            if metadata.st_uid != account.pw_uid or metadata.st_gid != account.pw_gid:
                refuse()
            if f"{metadata.st_dev}:{metadata.st_ino}" != expected_identity:
                refuse()
            value = hashlib.sha256()
            while chunk := os.read(descriptor, 65536):
                value.update(chunk)
            if value.hexdigest() != expected_hash:
                refuse()

            inspected = False
            try:
                processes = os.listdir("/proc")
                inspected = True
                for process in processes:
                    if not process.isdigit():
                        continue
                    if int(process) == os.getpid():
                        continue
                    descriptors = f"/proc/{process}/fd"
                    try:
                        entries = os.listdir(descriptors)
                    except (FileNotFoundError, PermissionError):
                        continue
                    for entry in entries:
                        try:
                            opened = os.stat(f"{descriptors}/{entry}")
                        except OSError:
                            continue
                        if (opened.st_dev, opened.st_ino) == (metadata.st_dev, metadata.st_ino):
                            refuse()
            except OSError:
                refuse()
            if not inspected:
                refuse()
            if sqlite_sidecar_exists(selected) or sqlite_sidecar_exists(database):
                refuse()
        finally:
            os.close(descriptor)
        PYTHON;

    private const string PersistentStateProgram = <<<'PYTHON'
        import hashlib, os, pwd, stat, sys

        home, release, environment_hash, sqlite_relative, sqlite_hash, sqlite_identity, user = sys.argv[1:]

        def refuse():
            raise SystemExit(42)

        def digest_descriptor(descriptor):
            os.lseek(descriptor, 0, os.SEEK_SET)
            value = hashlib.sha256()
            while chunk := os.read(descriptor, 65536):
                value.update(chunk)
            return value.hexdigest()

        def parent_descriptor(root_descriptor, relative_path):
            parts = relative_path.split("/")
            if not parts or any(part in ("", ".", "..") for part in parts):
                refuse()
            descriptor = os.dup(root_descriptor)
            try:
                for part in parts[:-1]:
                    child = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=descriptor)
                    os.close(descriptor)
                    descriptor = child
                return descriptor, parts[-1]
            except BaseException:
                os.close(descriptor)
                raise

        def regular_descriptor(parent, leaf):
            descriptor = os.open(leaf, os.O_RDONLY | os.O_NOFOLLOW, dir_fd=parent)
            metadata = os.fstat(descriptor)
            if not stat.S_ISREG(metadata.st_mode):
                os.close(descriptor)
                refuse()
            return descriptor, metadata

        def owned_symlink(target, leaf, parent):
            os.symlink(target, leaf, dir_fd=parent)
            os.chown(leaf, account.pw_uid, account.pw_gid, dir_fd=parent, follow_symlinks=False)

        def sqlite_sidecar_exists(path):
            return any(os.path.lexists(path + suffix) for suffix in ("-wal", "-shm", "-journal"))

        if os.path.realpath(home) != home or os.path.realpath(release) != release:
            refuse()
        account = pwd.getpwnam(user)
        home_descriptor = os.open(home, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        release_descriptor = os.open(release, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)

        try:
            if sqlite_relative:
                selected_path = f"{release}/{sqlite_relative}"
                database_path = f"{home}/database.sqlite"
                if sqlite_sidecar_exists(selected_path) or sqlite_sidecar_exists(database_path):
                    refuse()

            environment_source = os.lstat(".env", dir_fd=release_descriptor) if os.path.lexists(f"{release}/.env") else None
            environment_destination = os.lstat(".env", dir_fd=home_descriptor) if os.path.lexists(f"{home}/.env") else None
            if environment_source is not None and stat.S_ISLNK(environment_source.st_mode):
                if os.readlink(".env", dir_fd=release_descriptor) != "../../.env" or environment_destination is None:
                    refuse()
            elif environment_source is not None and environment_destination is None:
                descriptor, metadata = regular_descriptor(release_descriptor, ".env")
                try:
                    if digest_descriptor(descriptor) != environment_hash:
                        refuse()
                    os.rename(".env", ".env", src_dir_fd=release_descriptor, dst_dir_fd=home_descriptor)
                    os.fchown(descriptor, account.pw_uid, account.pw_gid)
                    os.fchmod(descriptor, 0o600)
                finally:
                    os.close(descriptor)
                owned_symlink("../../.env", ".env", release_descriptor)
                environment_destination = os.lstat(".env", dir_fd=home_descriptor)
            elif environment_source is None and environment_destination is not None:
                descriptor, metadata = regular_descriptor(home_descriptor, ".env")
                try:
                    if digest_descriptor(descriptor) != environment_hash:
                        refuse()
                finally:
                    os.close(descriptor)
                owned_symlink("../../.env", ".env", release_descriptor)
            else:
                refuse()

            descriptor, metadata = regular_descriptor(home_descriptor, ".env")
            try:
                if metadata.st_uid != account.pw_uid or metadata.st_gid != account.pw_gid:
                    refuse()
                if digest_descriptor(descriptor) != environment_hash:
                    refuse()
            finally:
                os.close(descriptor)
            environment_link = os.lstat(".env", dir_fd=release_descriptor)
            if environment_link.st_uid != account.pw_uid or environment_link.st_gid != account.pw_gid:
                refuse()

            if sqlite_relative:
                source_parent, source_leaf = parent_descriptor(release_descriptor, sqlite_relative)
                try:
                    source_exists = os.path.lexists(f"{release}/{sqlite_relative}")
                    database_exists = os.path.lexists(f"{home}/database.sqlite")
                    if source_exists and stat.S_ISLNK(os.lstat(source_leaf, dir_fd=source_parent).st_mode):
                        expected_link = os.path.relpath(f"{home}/database.sqlite", os.path.dirname(f"{release}/{sqlite_relative}"))
                        if os.readlink(source_leaf, dir_fd=source_parent) != expected_link or not database_exists:
                            refuse()
                    elif source_exists and not database_exists:
                        descriptor, metadata = regular_descriptor(source_parent, source_leaf)
                        try:
                            if f"{metadata.st_dev}:{metadata.st_ino}" != sqlite_identity:
                                refuse()
                            if digest_descriptor(descriptor) != sqlite_hash:
                                refuse()
                            if sqlite_sidecar_exists(selected_path) or sqlite_sidecar_exists(database_path):
                                refuse()
                            os.rename(source_leaf, "database.sqlite", src_dir_fd=source_parent, dst_dir_fd=home_descriptor)
                            os.fchown(descriptor, account.pw_uid, account.pw_gid)
                            os.fchmod(descriptor, 0o600)
                        finally:
                            os.close(descriptor)
                        link_target = os.path.relpath(f"{home}/database.sqlite", os.path.dirname(f"{release}/{sqlite_relative}"))
                        owned_symlink(link_target, source_leaf, source_parent)
                    elif not source_exists and database_exists:
                        descriptor, metadata = regular_descriptor(home_descriptor, "database.sqlite")
                        try:
                            if f"{metadata.st_dev}:{metadata.st_ino}" != sqlite_identity:
                                refuse()
                            if digest_descriptor(descriptor) != sqlite_hash:
                                refuse()
                        finally:
                            os.close(descriptor)
                        link_target = os.path.relpath(f"{home}/database.sqlite", os.path.dirname(f"{release}/{sqlite_relative}"))
                        owned_symlink(link_target, source_leaf, source_parent)
                    else:
                        refuse()
                    selected_link = os.lstat(source_leaf, dir_fd=source_parent)
                    if selected_link.st_uid != account.pw_uid or selected_link.st_gid != account.pw_gid:
                        refuse()
                finally:
                    os.close(source_parent)

                descriptor, metadata = regular_descriptor(home_descriptor, "database.sqlite")
                try:
                    if f"{metadata.st_dev}:{metadata.st_ino}" != sqlite_identity:
                        refuse()
                finally:
                    os.close(descriptor)

            if os.path.lexists(f"{home}/current"):
                if not os.path.islink(f"{home}/current") or os.readlink("current", dir_fd=home_descriptor) != "releases/initial":
                    refuse()
            else:
                owned_symlink("releases/initial", "current", home_descriptor)
            if os.path.realpath(f"{home}/current") != release:
                refuse()
            current_metadata = os.lstat("current", dir_fd=home_descriptor)
            if current_metadata.st_uid != account.pw_uid or current_metadata.st_gid != account.pw_gid:
                refuse()
        finally:
            os.close(release_descriptor)
            os.close(home_descriptor)
        PYTHON;

    public function __construct(private AppProdSshExecutor $ssh) {}

    public function preflight(
        AppInstance $appInstance,
        Route $route,
        #[\SensitiveParameter]
        string $expectedEnvironment,
        ?string $sqliteSourcePath,
    ): DeploymentLayoutInventory {
        $appInstance->loadMissing(['app', 'node']);
        $route->loadMissing('targets');
        $home = $this->home($appInstance);
        $user = $this->user($appInstance);
        $version = $appInstance->selected_php_version;
        $root = $appInstance->root ?? $appInstance->app->root;

        if (! is_string($version) || ! is_string($root) || ! is_int($route->node_id)) {
            $this->refused('The production PHP version is unavailable.');
        }

        try {
            $result = $this->ssh->execute(
                $appInstance->node,
                new RemoteCommand(
                    arguments: [
                        'sudo',
                        '-n',
                        'python3',
                        '-c',
                        self::PreflightProgram,
                        $home,
                        $user,
                        (string) $appInstance->id,
                        $version,
                        hash('sha256', $expectedEnvironment),
                        $sqliteSourcePath ?? '',
                        (string) $route->id,
                        (string) $route->node_id,
                        $route->hostname,
                        $route->status->value,
                        $this->routeTargetsHash($route),
                        $root,
                    ],
                    maxOutputBytes: 16_384,
                ),
                step: 'deployment-layout-preflight',
                errorCode: 'deployment_layout.preflight_refused',
            );
        } catch (RuntimeConvergenceException $exception) {
            $this->rethrowExpectedConflict(
                $exception,
                'deployment_layout.preflight_refused',
                'The deployment-layout preflight refused conversion.',
            );
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->refused('The deployment-layout inventory is invalid.');
        }

        if (! is_array($decoded)) {
            $this->refused('The deployment-layout inventory is invalid.');
        }

        $tuning = base64_decode($decoded['local_tuning'] ?? null, true);
        unset($decoded['local_tuning']);

        if (! is_string($tuning) || ! hash_equals((string) ($decoded['local_tuning_hash'] ?? ''), hash('sha256', $tuning))) {
            $this->refused('The deployment-layout runtime inventory is invalid.');
        }

        return DeploymentLayoutInventory::fromArray($decoded);
    }

    public function moveSource(AppInstance $appInstance, DeploymentLayoutInventory $inventory): void
    {
        $appInstance->loadMissing(['app', 'node']);
        try {
            $this->ssh->execute(
                $appInstance->node,
                new RemoteCommand(
                    arguments: [
                        'sudo', 'bash', '-seuo', 'pipefail', '--',
                        $this->user($appInstance),
                        $inventory->sourcePath,
                        $inventory->releasePath,
                        (string) $appInstance->id,
                        $inventory->sourceIdentity,
                        $appInstance->app->repository_url,
                        $inventory->gitHead,
                        $inventory->gitStateHash,
                    ],
                    input: <<<'BASH'
                    user=$1
                    home=$2
                    release=$3
                    instance=$4
                    expected_identity=$5
                    repository=$6
                    expected_head=$7
                    expected_state=$8
                    staging="/home/.orbit-layout-$instance"
                    releases="$home/releases"
                    state_directory="/var/lib/orbit/app-instance-sources/$instance"
                    marker="$state_directory/release-layout"
                    identity() { stat -c '%d:%i' -- "$1"; }
                    test "$home" = "/home/$user"
                    test "$release" = "$home/releases/initial"

                    inspection=
                    for candidate in "$home" "$staging" "$release"; do
                        if [ -d "$candidate" ] && [ ! -L "$candidate" ] && [ "$(identity "$candidate")" = "$expected_identity" ]; then
                            test -z "$inspection" || exit 42
                            inspection=$candidate
                        fi
                    done
                    test -n "$inspection" || exit 42
                    repository_root=$(sudo -n -u "$user" -- git -C "$inspection" rev-parse --show-toplevel) || exit 42
                    test "$repository_root" = "$inspection" || exit 42
                    actual_head=$(sudo -n -u "$user" -- git -C "$inspection" rev-parse --verify HEAD) || exit 42
                    test "$actual_head" = "$expected_head" || exit 42
                    actual_state=$(sudo -n -u "$user" -- git -C "$inspection" status --porcelain=v2 -z | sha256sum | awk '{print $1}') || exit 42
                    test "$actual_state" = "$expected_state" || exit 42

                    if [ -d "$release" ] && [ ! -L "$release" ]; then
                        test "$(identity "$release")" = "$expected_identity"
                    else
                        if [ -d "$home" ] && [ ! -L "$home" ] && [ "$(identity "$home")" = "$expected_identity" ]; then
                            test ! -e "$staging" && test ! -L "$staging"
                            mv -- "$home" "$staging"
                        fi
                        test -d "$staging" && test ! -L "$staging"
                        test "$(identity "$staging")" = "$expected_identity"
                        if [ ! -e "$home" ] && [ ! -L "$home" ]; then
                            install -d -o "$user" -g "$user" -m 0700 -- "$home"
                        fi
                        test -d "$home" && test ! -L "$home"
                        test "$(stat -c '%U:%G' -- "$home")" = "$user:$user"
                        install -d -o "$user" -g "$user" -m 0700 -- "$releases"
                        mv -- "$staging" "$release"
                    fi

                    install -d -o root -g root -m 0700 -- /var/lib/orbit/app-instance-sources "$state_directory"
                    expected=$(printf '%s\0%s\0%s\0%s\0' "$repository" "$user" "$home" initial | base64 --wrap=0)
                    if [ -e "$marker" ] || [ -L "$marker" ]; then
                        test -f "$marker" && test ! -L "$marker"
                        test "$(base64 --wrap=0 -- "$marker")" = "$expected"
                    else
                        candidate="$state_directory/.release-layout.$$.candidate"
                        printf '%s\0%s\0%s\0%s\0' "$repository" "$user" "$home" initial > "$candidate"
                        chown root:root -- "$candidate"
                        chmod 0600 -- "$candidate"
                        mv -fT -- "$candidate" "$marker"
                    fi
                    BASH,
                ),
                step: 'deployment-layout-source-move',
                errorCode: 'deployment_layout.source_move_failed',
            );
        } catch (RuntimeConvergenceException $exception) {
            $this->rethrowExpectedConflict(
                $exception,
                'deployment_layout.source_changed',
                'The recorded production source changed before conversion.',
            );
        }
    }

    public function assertSqliteQuiescent(AppInstance $appInstance, DeploymentLayoutInventory $inventory): void
    {
        if (
            $inventory->sqliteSourcePath === null
            || $inventory->sqliteHash === null
            || $inventory->sqliteIdentity === null
        ) {
            return;
        }

        $appInstance->loadMissing('node');
        $sqliteRelative = $this->sqliteRelativePath($inventory);

        try {
            $this->ssh->execute(
                $appInstance->node,
                new RemoteCommand(
                    arguments: [
                        'sudo', '-n', 'python3', '-c', self::SqliteQuiescenceProgram,
                        $inventory->sourcePath,
                        $inventory->releasePath,
                        $sqliteRelative,
                        $inventory->sqliteIdentity,
                        $inventory->sqliteHash,
                        $this->user($appInstance),
                    ],
                    maxOutputBytes: 4096,
                ),
                step: 'deployment-layout-sqlite-quiescence',
                errorCode: 'deployment_layout.sqlite_not_quiescent',
            );
        } catch (RuntimeConvergenceException $exception) {
            $this->rethrowExpectedConflict(
                $exception,
                'deployment_layout.sqlite_not_quiescent',
                'The selected SQLite database is not safely quiescent.',
            );
        }
    }

    public function placePersistentState(AppInstance $appInstance, DeploymentLayoutInventory $inventory): void
    {
        $appInstance->loadMissing('node');
        $sqliteRelative = $inventory->sqliteSourcePath === null ? '' : $this->sqliteRelativePath($inventory);

        try {
            $this->ssh->execute(
                $appInstance->node,
                new RemoteCommand(
                    arguments: [
                        'sudo', '-n', 'python3', '-c', self::PersistentStateProgram,
                        $inventory->sourcePath,
                        $inventory->releasePath,
                        $inventory->environmentHash,
                        $sqliteRelative,
                        $inventory->sqliteHash ?? '',
                        $inventory->sqliteIdentity ?? '',
                        $this->user($appInstance),
                    ],
                    maxOutputBytes: 4096,
                ),
                step: 'deployment-layout-persistent-state',
                errorCode: 'deployment_layout.persistent_state_failed',
            );
        } catch (RuntimeConvergenceException $exception) {
            $this->rethrowExpectedConflict(
                $exception,
                'deployment_layout.persistent_state_conflict',
                'The recorded persistent-state placement changed during conversion.',
            );
        }
    }

    public function runtimeTuning(AppInstance $appInstance, DeploymentLayoutInventory $inventory): string
    {
        $appInstance->loadMissing('node');
        $version = $appInstance->selected_php_version;

        if (! is_string($version)) {
            $this->refused('The production PHP version is unavailable.');
        }

        try {
            $result = $this->ssh->execute(
                $appInstance->node,
                new RemoteCommand(
                    arguments: [
                        'sudo', 'python3', '-c',
                        <<<'PYTHON'
                        import hashlib, re, sys
                        path, old_pool, new_pool, expected, home, user = sys.argv[1:]
                        supported = {"pm", "pm.max_children", "pm.process_idle_timeout", "pm.max_requests", "catch_workers_output"}
                        managed = {"user", "group", "listen", "listen.owner", "listen.group", "listen.mode", "chdir", "clear_env"}
                        generated = {
                            "env[home]": home,
                            "env[user]": user,
                            "env[path]": "/usr/local/bin:/opt/orbit/composer/vendor/bin:/usr/bin:/bin",
                            "php_admin_value[opcache.validate_timestamps]": "0",
                        }
                        lines = [f"[{new_pool}]"]
                        active = False
                        found = False
                        with open(path, encoding="utf-8") as source:
                            for original in source:
                                line = original.strip()
                                section = re.fullmatch(r"\[([^]]+)\]", line)
                                if section:
                                    active = section.group(1) == old_pool
                                    found = found or active
                                    continue
                                if active and line and not line.startswith((";", "#")) and "=" in line:
                                    key, value = (part.strip() for part in line.split("=", 1))
                                    key = key.lower()
                                    if key in supported:
                                        lines.append(line)
                                    elif key in generated and value == generated[key]:
                                        continue
                                    elif key in ("env[home]", "env[user]"):
                                        raise SystemExit(42)
                                    elif key.startswith("env[") or key.startswith("php_admin_value["):
                                        lines.append(line)
                                    elif key in managed:
                                        continue
                                    else:
                                        raise SystemExit(42)
                        if not found:
                            raise SystemExit(42)
                        tuning = "\n".join(lines) + "\n"
                        if hashlib.sha256(tuning.encode()).hexdigest() != expected:
                            raise SystemExit(42)
                        sys.stdout.write(tuning)
                        PYTHON,
                        "/etc/php/{$version}/fpm/pool.d/orbit-scopes.conf",
                        "orbit-app-instance-{$appInstance->id}",
                        "orbit-{$this->user($appInstance)}",
                        $inventory->localTuningHash,
                        $this->home($appInstance),
                        $this->user($appInstance),
                    ],
                    maxOutputBytes: 16_384,
                ),
                step: 'deployment-layout-runtime-tuning',
                errorCode: 'deployment_layout.runtime_tuning_changed',
            );
        } catch (RuntimeConvergenceException $exception) {
            $this->rethrowExpectedConflict(
                $exception,
                'deployment_layout.runtime_tuning_changed',
                'The recorded production PHP tuning changed during conversion.',
            );
        }

        return $result->stdout;
    }

    public function validateServingAssociation(AppInstance $appInstance, DeploymentLayoutInventory $inventory): void
    {
        $appInstance->loadMissing('node');

        try {
            $this->ssh->execute(
                $appInstance->node,
                new RemoteCommand(
                    arguments: [
                        'sudo', 'bash', '-seu', '--',
                        $inventory->sourcePath,
                        $inventory->routeHostname,
                        $inventory->documentRoot,
                        $inventory->previousSocket,
                    ],
                    input: <<<'BASH'
                        trap 'exit 42' ERR
                        home=$1
                        hostname=$2
                        document_root=$3
                        socket=$4
                        test -S "$socket"
                        source_caddyfile=$(readlink -f /etc/caddy/Caddyfile)
                        fragment="$(dirname "$source_caddyfile")/fragments/app-dev.caddy"
                        test -f "$fragment" && test ! -L "$fragment"
                        grep -Fqx -- "https://$hostname {" "$fragment"
                        grep -Fq -- "root * $home/$document_root" "$fragment"
                        grep -Fqx -- "php_fastcgi unix/$socket {" "$fragment"
                        BASH,
                    maxOutputBytes: 4096,
                ),
                step: 'deployment-layout-serving-validation',
                errorCode: 'deployment_layout.serving_conflict',
            );
        } catch (RuntimeConvergenceException $exception) {
            $this->rethrowExpectedConflict(
                $exception,
                'deployment_layout.serving_conflict',
                'The recorded AppInstance serving association changed.',
            );
        }
    }

    public function validatePlacedLayout(AppInstance $appInstance, DeploymentLayoutInventory $inventory): void
    {
        $appInstance->loadMissing(['app', 'node', 'routes']);
        $route = $appInstance->routes->firstWhere('id', $inventory->routeId);
        $root = $appInstance->root ?? $appInstance->app->root;
        $identity = ProductionPhpRuntimeIdentity::from($appInstance);
        $sqliteRelative = $inventory->sqliteSourcePath === null ? '' : $this->sqliteRelativePath($inventory);

        if (
            ! $route instanceof Route
            || ! is_string($root)
            || $route->hostname !== $inventory->routeHostname
            || $root !== $inventory->documentRoot
        ) {
            $this->refused('The recorded serving association is unavailable.');
        }

        try {
            $this->ssh->execute(
                $appInstance->node,
                new RemoteCommand(
                    arguments: [
                        'sudo', 'bash', '-seu', '--',
                        $this->user($appInstance),
                        $inventory->sourcePath,
                        $inventory->releasePath,
                        $identity->socket,
                        $route->hostname,
                        $root,
                        $sqliteRelative,
                        $identity->runtimeDirectory,
                        $identity->generatedDirectory,
                        $identity->localTuningPath,
                        $identity->unitPath,
                        $identity->markerPath,
                        $identity->service,
                        $identity->version,
                        base64_encode($identity->marker()),
                        $inventory->sourceIdentity,
                        "/var/lib/orbit/app-instance-sources/{$appInstance->id}/release-layout",
                        base64_encode(implode("\0", [
                            $appInstance->app->repository_url,
                            $identity->user,
                            $identity->home,
                            'initial',
                            '',
                        ])),
                    ],
                    input: <<<'BASH'
                    trap 'exit 42' ERR
                    user=$1
                    home=$2
                    release=$3
                    socket=$4
                    hostname=$5
                    document_root=$6
                    sqlite_relative=$7
                    runtime_directory=$8
                    generated_directory=$9
                    local_tuning=${10}
                    unit_path=${11}
                    marker_path=${12}
                    service=${13}
                    version=${14}
                    expected_marker=${15}
                    expected_source_identity=${16}
                    source_marker=${17}
                    expected_source_marker=${18}
                    test "$home" = "/home/$user"
                    test -d "$release" && test ! -L "$release"
                    test "$(stat -c '%d:%i' -- "$release")" = "$expected_source_identity"
                    test -f "$source_marker" && test ! -L "$source_marker"
                    test "$(base64 --wrap=0 -- "$source_marker")" = "$expected_source_marker"
                    test -f "$home/.env" && test ! -L "$home/.env"
                    test "$(stat -c '%U:%G:%a' -- "$home/.env")" = "$user:$user:600"
                    test -L "$release/.env"
                    test "$(realpath -e -- "$release/.env")" = "$home/.env"
                    test -L "$home/current"
                    test "$(realpath -e -- "$home/current")" = "$release"
                    if [ -n "$sqlite_relative" ]; then
                        selected="$release/$sqlite_relative"
                        test -f "$home/database.sqlite" && test ! -L "$home/database.sqlite"
                        test "$(stat -c '%U:%G:%a' -- "$home/database.sqlite")" = "$user:$user:600"
                        test -L "$selected"
                        test "$(realpath -e -- "$selected")" = "$home/database.sqlite"
                    fi
                    test -d "$runtime_directory" && test ! -L "$runtime_directory"
                    test -d "$generated_directory" && test ! -L "$generated_directory"
                    test -f "$local_tuning" && test ! -L "$local_tuning"
                    test -f "$unit_path" && test ! -L "$unit_path"
                    test -f "$marker_path" && test ! -L "$marker_path"
                    test "$(base64 --wrap=0 -- "$marker_path")" = "$expected_marker"
                    for generated_file in php-fpm.conf pool.conf master.ini local.sha256; do
                        test -f "$generated_directory/$generated_file"
                        test ! -L "$generated_directory/$generated_file"
                    done
                    pool_configuration="$generated_directory/pool.conf"
                    grep -Fqx -- "[orbit-$user]" "$pool_configuration"
                    grep -Fqx -- "user = $user" "$pool_configuration"
                    grep -Fqx -- "group = $user" "$pool_configuration"
                    grep -Fqx -- "listen = $socket" "$pool_configuration"
                    grep -Fqx -- "listen.owner = $user" "$pool_configuration"
                    grep -Fqx -- "listen.group = caddy" "$pool_configuration"
                    grep -Fqx -- "listen.mode = 0660" "$pool_configuration"
                    grep -Fqx -- "chdir = $home" "$pool_configuration"
                    grep -Fqx -- "ExecStart=/usr/sbin/php-fpm$version --nodaemonize --fpm-config $generated_directory/php-fpm.conf" "$unit_path"
                    PHP_INI_SCAN_DIR="/etc/php/$version/fpm/conf.d:$generated_directory" \
                        /usr/sbin/php-fpm"$version" -y "$generated_directory/php-fpm.conf" -t
                    systemctl is-active --quiet "$service"
                    main_pid=$(systemctl show --property MainPID --value "$service")
                    test "$main_pid" -gt 1
                    test "$(readlink -f -- "/proc/$main_pid/exe")" = "/usr/sbin/php-fpm$version"
                    test -S "$socket"
                    test "$(stat -c '%U:%G:%a' -- "$socket")" = "$user:caddy:660"
                    source_caddyfile=$(readlink -f /etc/caddy/Caddyfile)
                    fragment="$(dirname "$source_caddyfile")/fragments/app-dev.caddy"
                    test -f "$fragment" && test ! -L "$fragment"
                    grep -Fqx -- "https://$hostname {" "$fragment"
                    grep -Fq -- "root * $home/current/$document_root" "$fragment"
                    grep -Fqx -- "php_fastcgi unix/$socket {" "$fragment"
                    BASH,
                    maxOutputBytes: 4096,
                ),
                step: 'deployment-layout-validation',
                errorCode: 'deployment_layout.validation_failed',
            );
        } catch (RuntimeConvergenceException $exception) {
            $this->rethrowExpectedConflict(
                $exception,
                'deployment_layout.validation_failed',
                'The completed deployment layout no longer agrees with its recorded state.',
            );
        }
    }

    private function routeTargetsHash(Route $route): string
    {
        $targets = $route->targets
            ->map(static fn ($target): array => [
                'app_instance_id' => $target->app_instance_id,
                'position' => $target->position,
            ])
            ->values()
            ->all();

        return hash('sha256', json_encode($targets, JSON_THROW_ON_ERROR));
    }

    private function sqliteRelativePath(DeploymentLayoutInventory $inventory): string
    {
        $prefix = "{$inventory->sourcePath}/";

        if (
            $inventory->sqliteSourcePath === null
            || ! str_starts_with($inventory->sqliteSourcePath, $prefix)
        ) {
            $this->refused('The recorded SQLite source path is invalid.');
        }

        return substr($inventory->sqliteSourcePath, strlen($prefix));
    }

    private function rethrowExpectedConflict(
        RuntimeConvergenceException $exception,
        string $errorCode,
        string $message,
    ): never {
        if ($exception->result?->exitCode === 42) {
            throw new ResourceOperationException($errorCode, $message, 409, $exception);
        }

        throw $exception;
    }

    private function user(AppInstance $appInstance): string
    {
        if (! is_string($appInstance->production_user)) {
            $this->refused('The production user is unavailable.');
        }

        return $appInstance->production_user;
    }

    private function home(AppInstance $appInstance): string
    {
        if (! is_string($appInstance->production_home)) {
            $this->refused('The production home is unavailable.');
        }

        return $appInstance->production_home;
    }

    private function refused(string $message): never
    {
        throw new ResourceOperationException('deployment_layout.preflight_refused', $message, 409);
    }
}
