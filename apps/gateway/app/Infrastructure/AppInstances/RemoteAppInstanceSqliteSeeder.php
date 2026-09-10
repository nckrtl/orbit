<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Domain\AppInstances\Sqlite\AppInstanceSqliteSeeder;
use App\Domain\AppInstances\Sqlite\SqliteSeedPlacement;
use App\Domain\AppInstances\Sqlite\SqliteSeedResult;
use App\Domain\AppInstances\Sqlite\SqliteSnapshotTransfer;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use SensitiveParameter;
use Throwable;

final readonly class RemoteAppInstanceSqliteSeeder implements AppInstanceSqliteSeeder
{
    private const string StateRoot = '/var/lib/orbit/app-instance-sqlite-seeds';

    private const string SourceProgram = <<<'PYTHON'
        import contextlib, hashlib, json, os, pwd, sqlite3, stat, sys, tempfile, time, urllib.parse

        mode, base, source_path, source_user, transport_user, operation, state_dir, snapshot_path = sys.argv[1:]
        state_path = os.path.join(state_dir, "state.json")
        manager_uid = os.geteuid()

        class BoundaryError(Exception):
            pass

        def safe_absolute(path):
            return os.path.isabs(path) and os.path.normpath(path) == path and all(
                segment not in ("", ".", "..") for segment in path.split("/")[1:]
            )

        def safe_tree(path, final_regular=False):
            current = "/"
            segments = path.split("/")[1:]
            for index, segment in enumerate(segments):
                current = os.path.join(current, segment)
                metadata = os.lstat(current)
                if stat.S_ISLNK(metadata.st_mode):
                    raise BoundaryError
                if index < len(segments) - 1 and not stat.S_ISDIR(metadata.st_mode):
                    raise BoundaryError
            metadata = os.lstat(path)
            if final_regular and not stat.S_ISREG(metadata.st_mode):
                raise BoundaryError
            if not final_regular and not stat.S_ISDIR(metadata.st_mode):
                raise BoundaryError
            return metadata

        @contextlib.contextmanager
        def identity(account):
            current_uid = os.geteuid()
            current_gid = os.getegid()
            current_groups = os.getgroups()
            if current_uid not in (0, account.pw_uid):
                raise BoundaryError
            if current_uid == account.pw_uid:
                yield
                return
            try:
                os.initgroups(account.pw_name, account.pw_gid)
                os.setegid(account.pw_gid)
                os.seteuid(account.pw_uid)
                yield
            finally:
                os.seteuid(current_uid)
                os.setegid(current_gid)
                os.setgroups(current_groups)

        def ensure_state_directory(create=True):
            parent = os.path.dirname(state_dir)
            if not os.path.exists(state_dir) and not create:
                return False
            os.makedirs(parent, mode=0o700, exist_ok=True)
            parent_metadata = os.lstat(parent)
            if (
                not stat.S_ISDIR(parent_metadata.st_mode)
                or stat.S_ISLNK(parent_metadata.st_mode)
                or parent_metadata.st_uid != manager_uid
                or stat.S_IMODE(parent_metadata.st_mode) != 0o700
            ):
                raise BoundaryError
            if not os.path.exists(state_dir):
                os.mkdir(state_dir, 0o700)
            metadata = os.lstat(state_dir)
            if (
                not stat.S_ISDIR(metadata.st_mode)
                or stat.S_ISLNK(metadata.st_mode)
                or metadata.st_uid != manager_uid
                or stat.S_IMODE(metadata.st_mode) != 0o700
            ):
                raise BoundaryError
            return True

        def load_state():
            try:
                metadata = os.lstat(state_path)
            except FileNotFoundError:
                return None
            if (
                not stat.S_ISREG(metadata.st_mode)
                or stat.S_ISLNK(metadata.st_mode)
                or metadata.st_uid != manager_uid
                or stat.S_IMODE(metadata.st_mode) != 0o600
            ):
                raise BoundaryError
            with open(state_path, "r", encoding="utf-8") as handle:
                value = json.load(handle)
            if not isinstance(value, dict):
                raise BoundaryError
            return value

        def write_state(value):
            descriptor, temporary = tempfile.mkstemp(prefix=".state-", dir=state_dir)
            try:
                os.fchmod(descriptor, 0o600)
                with os.fdopen(descriptor, "w", encoding="utf-8", closefd=False) as handle:
                    json.dump(value, handle, sort_keys=True, separators=(",", ":"))
                    handle.flush()
                    os.fsync(handle.fileno())
                os.close(descriptor)
                descriptor = -1
                os.replace(temporary, state_path)
                directory = os.open(state_dir, os.O_RDONLY | os.O_DIRECTORY)
                try:
                    os.fsync(directory)
                finally:
                    os.close(directory)
            finally:
                if descriptor >= 0:
                    os.close(descriptor)
                try:
                    os.unlink(temporary)
                except FileNotFoundError:
                    pass

        def digest(path):
            value = hashlib.sha256()
            with open(path, "rb", buffering=0) as handle:
                while True:
                    chunk = handle.read(65536)
                    if not chunk:
                        break
                    value.update(chunk)
            return value.hexdigest()

        def expected_state(value):
            if (
                value.get("operation") != operation
                or value.get("base") != base
                or value.get("source_path") != source_path
                or value.get("snapshot_path") != snapshot_path
            ):
                raise BoundaryError

        def validate_snapshot(value, transport_uid):
            metadata = os.lstat(snapshot_path)
            if (
                not stat.S_ISREG(metadata.st_mode)
                or stat.S_ISLNK(metadata.st_mode)
                or metadata.st_uid != transport_uid
                or stat.S_IMODE(metadata.st_mode) != 0o600
                or metadata.st_ino != value.get("inode")
                or metadata.st_size != value.get("size")
                or digest(snapshot_path) != value.get("digest")
            ):
                raise BoundaryError
            return metadata

        def remove_owned_snapshot(value, source_uid, transport_uid):
            try:
                metadata = os.lstat(snapshot_path)
            except FileNotFoundError:
                return
            if (
                not stat.S_ISREG(metadata.st_mode)
                or stat.S_ISLNK(metadata.st_mode)
                or metadata.st_uid not in (source_uid, transport_uid)
            ):
                raise BoundaryError
            recorded_inode = value.get("inode")
            if recorded_inode is not None and metadata.st_ino != recorded_inode:
                raise BoundaryError
            os.unlink(snapshot_path)

        try:
            if not safe_absolute(base) or not safe_absolute(source_path) or not safe_absolute(snapshot_path):
                raise BoundaryError
            if os.path.commonpath((base, source_path)) != base or source_path == base:
                raise BoundaryError
            source_account = pwd.getpwnam(source_user)
            transport_account = pwd.getpwnam(transport_user)
            if mode == "cleanup":
                if not ensure_state_directory(create=False):
                    if os.path.lexists(snapshot_path):
                        raise BoundaryError
                    print("CLEANED")
                    raise SystemExit(0)
                state = load_state()
                if state is None:
                    if os.path.lexists(snapshot_path):
                        raise BoundaryError
                    os.rmdir(state_dir)
                    print("CLEANED")
                    raise SystemExit(0)
                expected_state(state)
                remove_owned_snapshot(state, source_account.pw_uid, transport_account.pw_uid)
                os.unlink(state_path)
                os.rmdir(state_dir)
                print("CLEANED")
                raise SystemExit(0)

            if mode != "prepare":
                raise BoundaryError

            if os.path.exists(state_dir):
                ensure_state_directory()
            state = load_state()

            if state is not None:
                expected_state(state)
                if state.get("status") == "ready":
                    try:
                        metadata = validate_snapshot(state, transport_account.pw_uid)
                    except FileNotFoundError:
                        os.unlink(state_path)
                        state = None
                    else:
                        print(f"READY\t{metadata.st_size}\t{state['digest']}\t{snapshot_path}")
                        raise SystemExit(0)
                if state is not None:
                    remove_owned_snapshot(state, source_account.pw_uid, transport_account.pw_uid)
                    os.unlink(state_path)
            elif os.path.lexists(snapshot_path):
                raise BoundaryError

            with identity(source_account):
                safe_tree(base)
                safe_tree(source_path, final_regular=True)
                descriptor = os.open(source_path, os.O_RDONLY | os.O_NONBLOCK | os.O_NOFOLLOW)
                try:
                    source_metadata = os.fstat(descriptor)
                    if not stat.S_ISREG(source_metadata.st_mode):
                        raise BoundaryError
                finally:
                    os.close(descriptor)
                try:
                    source_uri = f"file:{urllib.parse.quote(source_path, safe='/')}?mode=ro"
                    source = sqlite3.connect(source_uri, uri=True, timeout=5.0)
                    source.execute("PRAGMA query_only = ON")
                    quick_check = source.execute("PRAGMA quick_check").fetchall()
                    if quick_check != [("ok",)]:
                        raise BoundaryError
                    page_size = int(source.execute("PRAGMA page_size").fetchone()[0])
                    page_count = int(source.execute("PRAGMA page_count").fetchone()[0])
                    required = max(source_metadata.st_size, page_size * page_count)
                    filesystem = os.statvfs("/tmp")
                    available = filesystem.f_bavail * filesystem.f_frsize
                    reserve = 1048576
                    if required <= 0 or available < required + reserve:
                        raise BoundaryError
                except sqlite3.DatabaseError as exception:
                    raise BoundaryError from exception

            ensure_state_directory()
            write_state({
                "operation": operation,
                "base": base,
                "source_path": source_path,
                "snapshot_path": snapshot_path,
                "status": "preparing",
            })

            try:
                with identity(source_account):
                    previous_umask = os.umask(0o077)
                    try:
                        destination = sqlite3.connect(snapshot_path)
                    finally:
                        os.umask(previous_umask)
                    try:
                        maximum_pages = (available - reserve) // page_size
                        backup_deadline = time.monotonic() + 30.0

                        def progress(status, remaining, total):
                            if total > maximum_pages:
                                raise BoundaryError
                            if status != sqlite3.SQLITE_DONE and time.monotonic() >= backup_deadline:
                                raise BoundaryError

                        source.backup(destination, pages=-1, progress=progress, sleep=0.01)
                        integrity = destination.execute("PRAGMA integrity_check").fetchall()
                        if integrity != [("ok",)]:
                            raise BoundaryError
                    finally:
                        destination.close()
                        source.close()
                    snapshot_descriptor = os.open(snapshot_path, os.O_RDONLY | os.O_NOFOLLOW)
                    try:
                        os.fsync(snapshot_descriptor)
                    finally:
                        os.close(snapshot_descriptor)
            except sqlite3.DatabaseError as exception:
                raise BoundaryError from exception

            os.chown(snapshot_path, transport_account.pw_uid, transport_account.pw_gid)
            os.chmod(snapshot_path, 0o600)
            snapshot_metadata = os.lstat(snapshot_path)
            snapshot_digest = digest(snapshot_path)
            write_state({
                "operation": operation,
                "base": base,
                "source_path": source_path,
                "snapshot_path": snapshot_path,
                "status": "ready",
                "inode": snapshot_metadata.st_ino,
                "size": snapshot_metadata.st_size,
                "digest": snapshot_digest,
            })
            print(f"READY\t{snapshot_metadata.st_size}\t{snapshot_digest}\t{snapshot_path}")
        except BoundaryError:
            print("REFUSED")
            raise SystemExit(42)
        except SystemExit:
            raise
        except Exception:
            print("FAILED")
            raise SystemExit(43)
        PYTHON;

    private const string TargetProgram = <<<'PYTHON'
        import contextlib, hashlib, json, os, pwd, sqlite3, stat, sys, tempfile, urllib.parse

        mode, home, target_user, transport_user, operation, state_dir, expected_size, expected_digest = sys.argv[1:]
        expected_size = int(expected_size)
        state_path = os.path.join(state_dir, "state.json")
        destination_path = os.path.join(home, "database.sqlite")
        manager_uid = os.geteuid()
        replacement_installed = False
        unrecorded_candidate = None
        unrecorded_candidate_inode = None

        class BoundaryError(Exception):
            pass

        @contextlib.contextmanager
        def identity(account):
            current_uid = os.geteuid()
            current_gid = os.getegid()
            current_groups = os.getgroups()
            if current_uid not in (0, account.pw_uid):
                raise BoundaryError
            if current_uid == account.pw_uid:
                yield
                return
            try:
                os.initgroups(account.pw_name, account.pw_gid)
                os.setegid(account.pw_gid)
                os.seteuid(account.pw_uid)
                yield
            finally:
                os.seteuid(current_uid)
                os.setegid(current_gid)
                os.setgroups(current_groups)

        def safe_absolute(path):
            return os.path.isabs(path) and os.path.normpath(path) == path and all(
                segment not in ("", ".", "..") for segment in path.split("/")[1:]
            )

        def safe_directory(path, owner_uid):
            current = "/"
            for segment in path.split("/")[1:]:
                current = os.path.join(current, segment)
                metadata = os.lstat(current)
                if stat.S_ISLNK(metadata.st_mode) or not stat.S_ISDIR(metadata.st_mode):
                    raise BoundaryError
            metadata = os.lstat(path)
            if metadata.st_uid != owner_uid:
                raise BoundaryError
            return metadata

        def destination_metadata(target_uid, require=False):
            try:
                metadata = os.lstat(destination_path)
            except FileNotFoundError:
                if require:
                    raise BoundaryError
                return None
            if (
                stat.S_ISLNK(metadata.st_mode)
                or not stat.S_ISREG(metadata.st_mode)
                or metadata.st_uid != target_uid
                or stat.S_IMODE(metadata.st_mode) != 0o600
            ):
                raise BoundaryError
            return metadata

        def ensure_state_directory():
            parent = os.path.dirname(state_dir)
            os.makedirs(parent, mode=0o700, exist_ok=True)
            parent_metadata = os.lstat(parent)
            if (
                stat.S_ISLNK(parent_metadata.st_mode)
                or not stat.S_ISDIR(parent_metadata.st_mode)
                or parent_metadata.st_uid != manager_uid
                or stat.S_IMODE(parent_metadata.st_mode) != 0o700
            ):
                raise BoundaryError
            if not os.path.exists(state_dir):
                os.mkdir(state_dir, 0o700)
            metadata = os.lstat(state_dir)
            if (
                stat.S_ISLNK(metadata.st_mode)
                or not stat.S_ISDIR(metadata.st_mode)
                or metadata.st_uid != manager_uid
                or stat.S_IMODE(metadata.st_mode) != 0o700
            ):
                raise BoundaryError

        def load_state():
            try:
                metadata = os.lstat(state_path)
            except FileNotFoundError:
                return None
            if (
                stat.S_ISLNK(metadata.st_mode)
                or not stat.S_ISREG(metadata.st_mode)
                or metadata.st_uid != manager_uid
                or stat.S_IMODE(metadata.st_mode) != 0o600
            ):
                raise BoundaryError
            with open(state_path, "r", encoding="utf-8") as handle:
                value = json.load(handle)
            if not isinstance(value, dict):
                raise BoundaryError
            return value

        def write_state(value):
            descriptor, temporary = tempfile.mkstemp(prefix=".state-", dir=state_dir)
            try:
                os.fchmod(descriptor, 0o600)
                with os.fdopen(descriptor, "w", encoding="utf-8", closefd=False) as handle:
                    json.dump(value, handle, sort_keys=True, separators=(",", ":"))
                    handle.flush()
                    os.fsync(handle.fileno())
                os.close(descriptor)
                descriptor = -1
                os.replace(temporary, state_path)
                directory = os.open(state_dir, os.O_RDONLY | os.O_DIRECTORY)
                try:
                    os.fsync(directory)
                finally:
                    os.close(directory)
            finally:
                if descriptor >= 0:
                    os.close(descriptor)
                try:
                    os.unlink(temporary)
                except FileNotFoundError:
                    pass

        def digest(path):
            value = hashlib.sha256()
            with open(path, "rb", buffering=0) as handle:
                while True:
                    chunk = handle.read(65536)
                    if not chunk:
                        break
                    value.update(chunk)
            return value.hexdigest()

        def valid_payload(
            path,
            expected_uid=None,
            expected_inode=None,
            payload_size=expected_size,
            payload_digest=expected_digest,
        ):
            metadata = os.lstat(path)
            if stat.S_ISLNK(metadata.st_mode) or not stat.S_ISREG(metadata.st_mode):
                raise BoundaryError
            if expected_uid is not None and metadata.st_uid != expected_uid:
                raise BoundaryError
            if expected_inode is not None and metadata.st_ino != expected_inode:
                raise BoundaryError
            if metadata.st_size != payload_size or digest(path) != payload_digest:
                raise BoundaryError
            try:
                database_uri = f"file:{urllib.parse.quote(path, safe='/')}?mode=ro&immutable=1"
                database = sqlite3.connect(database_uri, uri=True)
                database.execute("PRAGMA query_only = ON")
                if database.execute("PRAGMA integrity_check").fetchall() != [("ok",)]:
                    raise BoundaryError
            except sqlite3.DatabaseError as exception:
                raise BoundaryError from exception
            finally:
                if "database" in locals():
                    database.close()
            return metadata

        def operation_state(value):
            if (
                value.get("operation") != operation
                or value.get("destination") != destination_path
            ):
                raise BoundaryError

        def state_payload(value):
            payload_size = value.get("expected_size")
            payload_digest = value.get("expected_digest")
            if (
                not isinstance(payload_size, int)
                or payload_size <= 0
                or not isinstance(payload_digest, str)
                or len(payload_digest) != 64
                or any(character not in "0123456789abcdef" for character in payload_digest)
            ):
                raise BoundaryError
            return payload_size, payload_digest

        def remove_owned(path, inode):
            if not isinstance(path, str) or not safe_absolute(path):
                raise BoundaryError
            try:
                metadata = os.lstat(path)
            except FileNotFoundError:
                return
            if stat.S_ISLNK(metadata.st_mode) or not stat.S_ISREG(metadata.st_mode) or metadata.st_ino != inode:
                raise BoundaryError
            os.unlink(path)

        def create_incoming(transport_account):
            descriptor, path = tempfile.mkstemp(prefix=f"orbit-sqlite-{operation[:16]}-", dir="/tmp")
            os.fchmod(descriptor, 0o600)
            os.fchown(descriptor, transport_account.pw_uid, transport_account.pw_gid)
            metadata = os.fstat(descriptor)
            os.close(descriptor)
            return path, metadata.st_ino

        def complete_state(state, target_uid):
            payload_size, payload_digest = state_payload(state)
            destination = valid_payload(
                destination_path,
                target_uid,
                payload_size=payload_size,
                payload_digest=payload_digest,
            )
            candidate = state.get("candidate")
            candidate_inode = state.get("candidate_inode")
            if isinstance(candidate, str) and isinstance(candidate_inode, int):
                remove_owned(candidate, candidate_inode)
            incoming = state.get("incoming")
            incoming_inode = state.get("incoming_inode")
            if isinstance(incoming, str) and isinstance(incoming_inode, int):
                remove_owned(incoming, incoming_inode)
            state["status"] = "complete"
            state["destination_inode"] = destination.st_ino
            state.pop("candidate", None)
            state.pop("candidate_inode", None)
            state.pop("incoming", None)
            state.pop("incoming_inode", None)
            write_state(state)

        def abandon_state(state):
            candidate = state.get("candidate")
            candidate_inode = state.get("candidate_inode")
            if isinstance(candidate, str) and isinstance(candidate_inode, int):
                remove_owned(candidate, candidate_inode)
            incoming = state.get("incoming")
            incoming_inode = state.get("incoming_inode")
            if isinstance(incoming, str) and isinstance(incoming_inode, int):
                remove_owned(incoming, incoming_inode)
            os.unlink(state_path)
            os.rmdir(state_dir)

        def cleanup_unrecorded_candidate():
            if not isinstance(unrecorded_candidate, str) or not isinstance(unrecorded_candidate_inode, int):
                return
            try:
                current_state = load_state()
                if (
                    isinstance(current_state, dict)
                    and current_state.get("candidate") == unrecorded_candidate
                    and current_state.get("candidate_inode") == unrecorded_candidate_inode
                ):
                    return
                remove_owned(unrecorded_candidate, unrecorded_candidate_inode)
            except Exception:
                pass

        try:
            if expected_size <= 0 or len(expected_digest) != 64 or any(character not in "0123456789abcdef" for character in expected_digest):
                raise BoundaryError
            if not safe_absolute(home) or not safe_absolute(state_dir):
                raise BoundaryError
            target_account = pwd.getpwnam(target_user)
            transport_account = pwd.getpwnam(transport_user)
            if home != target_account.pw_dir:
                raise BoundaryError
            with identity(target_account):
                safe_directory(home, target_account.pw_uid)
                if not os.access(home, os.W_OK | os.X_OK, effective_ids=True):
                    raise BoundaryError
            if os.path.exists(state_dir):
                ensure_state_directory()
            state = load_state()

            if state is not None:
                status = state.get("status")
                if status == "complete":
                    operation_state(state)
                    completed_size, completed_digest = state_payload(state)
                    destination_inode = state.get("destination_inode")
                    if not isinstance(destination_inode, int):
                        raise BoundaryError
                    valid_payload(
                        destination_path,
                        target_account.pw_uid,
                        destination_inode,
                        completed_size,
                        completed_digest,
                    )
                    print("COMPLETE")
                    raise SystemExit(0)
                operation_state(state)
                state_size, state_digest = state_payload(state)
                if status == "installing":
                    try:
                        destination = destination_metadata(target_account.pw_uid)
                        if destination is not None:
                            valid_payload(
                                destination_path,
                                target_account.pw_uid,
                                payload_size=state_size,
                                payload_digest=state_digest,
                            )
                            complete_state(state, target_account.pw_uid)
                            print("COMPLETE")
                            raise SystemExit(0)
                    except BoundaryError:
                        abandon_state(state)
                        raise
                elif status == "prepared":
                    try:
                        destination = destination_metadata(target_account.pw_uid)
                    except BoundaryError:
                        abandon_state(state)
                        raise
                    if destination is not None:
                        abandon_state(state)
                        raise BoundaryError
                else:
                    raise BoundaryError
                if state_size != expected_size or state_digest != expected_digest:
                    abandon_state(state)
                    state = None
            elif destination_metadata(target_account.pw_uid) is not None:
                raise BoundaryError

            filesystem = os.statvfs(home)
            if filesystem.f_flag & getattr(os, "ST_RDONLY", 1):
                raise BoundaryError
            if filesystem.f_bavail * filesystem.f_frsize < expected_size + 1048576:
                raise BoundaryError

            if mode == "prepare":
                if state is None:
                    ensure_state_directory()
                    incoming, incoming_inode = create_incoming(transport_account)
                    state = {
                        "operation": operation,
                        "expected_size": expected_size,
                        "expected_digest": expected_digest,
                        "destination": destination_path,
                        "status": "prepared",
                        "incoming": incoming,
                        "incoming_inode": incoming_inode,
                    }
                    write_state(state)
                    print(f"READY\t{incoming}")
                    raise SystemExit(0)

                if state.get("status") == "installing":
                    candidate = state.get("candidate")
                    candidate_inode = state.get("candidate_inode")
                    if isinstance(candidate, str) and isinstance(candidate_inode, int):
                        try:
                            valid_payload(candidate, target_account.pw_uid, candidate_inode)
                            print("RESUME")
                            raise SystemExit(0)
                        except BoundaryError:
                            abandon_state(state)
                            raise

                if state.get("status") != "prepared":
                    raise BoundaryError
                incoming = state.get("incoming")
                incoming_inode = state.get("incoming_inode")
                if not isinstance(incoming, str) or not isinstance(incoming_inode, int):
                    raise BoundaryError
                with identity(transport_account):
                    descriptor = os.open(incoming, os.O_WRONLY | os.O_TRUNC | os.O_NOFOLLOW)
                    try:
                        metadata = os.fstat(descriptor)
                        if (
                            not stat.S_ISREG(metadata.st_mode)
                            or metadata.st_ino != incoming_inode
                            or metadata.st_uid != transport_account.pw_uid
                        ):
                            raise BoundaryError
                        os.fchmod(descriptor, 0o600)
                    finally:
                        os.close(descriptor)
                print(f"READY\t{incoming}")
                raise SystemExit(0)

            if mode != "install" or state is None:
                raise BoundaryError

            status = state.get("status")
            if status == "prepared":
                incoming = state.get("incoming")
                incoming_inode = state.get("incoming_inode")
                if not isinstance(incoming, str) or not isinstance(incoming_inode, int):
                    raise BoundaryError
                valid_payload(incoming, transport_account.pw_uid, incoming_inode)
                candidate_descriptor, candidate = tempfile.mkstemp(prefix=".database.sqlite.orbit-", dir=home)
                unrecorded_candidate = candidate
                unrecorded_candidate_inode = os.fstat(candidate_descriptor).st_ino
                try:
                    os.fchmod(candidate_descriptor, 0o600)
                    os.fchown(candidate_descriptor, target_account.pw_uid, target_account.pw_gid)
                    with open(incoming, "rb", buffering=0) as source:
                        while True:
                            chunk = source.read(65536)
                            if not chunk:
                                break
                            offset = 0
                            while offset < len(chunk):
                                written = os.write(candidate_descriptor, chunk[offset:])
                                if written <= 0:
                                    raise OSError
                                offset += written
                    os.fsync(candidate_descriptor)
                    candidate_metadata = os.fstat(candidate_descriptor)
                    unrecorded_candidate_inode = candidate_metadata.st_ino
                finally:
                    os.close(candidate_descriptor)
                state["status"] = "installing"
                state["candidate"] = candidate
                state["candidate_inode"] = candidate_metadata.st_ino
                write_state(state)
                unrecorded_candidate = None
                unrecorded_candidate_inode = None
            elif status == "installing":
                candidate = state.get("candidate")
                candidate_inode = state.get("candidate_inode")
                if not isinstance(candidate, str) or not isinstance(candidate_inode, int):
                    raise BoundaryError
                valid_payload(candidate, target_account.pw_uid, candidate_inode)
            else:
                raise BoundaryError

            try:
                destination = destination_metadata(target_account.pw_uid)
            except BoundaryError:
                abandon_state(state)
                raise
            if destination is not None:
                abandon_state(state)
                raise BoundaryError
            try:
                os.link(candidate, destination_path, follow_symlinks=False)
            except FileExistsError:
                abandon_state(state)
                raise BoundaryError
            replacement_installed = True
            directory = os.open(home, os.O_RDONLY | os.O_DIRECTORY)
            try:
                os.fsync(directory)
            finally:
                os.close(directory)
            complete_state(state, target_account.pw_uid)
            print("CHANGED")
        except BoundaryError:
            cleanup_unrecorded_candidate()
            print("UNCONFIRMED" if replacement_installed else "REFUSED")
            raise SystemExit(44 if replacement_installed else 42)
        except SystemExit:
            raise
        except Exception:
            cleanup_unrecorded_candidate()
            print("UNCONFIRMED" if replacement_installed else "FAILED")
            raise SystemExit(44 if replacement_installed else 43)
        PYTHON;

    public function __construct(
        private SshExecutor $ssh,
        private SqliteSnapshotTransfer $transfer,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
    ) {}

    public function seed(
        SqliteSeedPlacement $source,
        SqliteSeedPlacement $target,
        #[SensitiveParameter]
        string $sourcePath,
    ): SqliteSeedResult {
        $this->assertRequest($source, $target, $sourcePath);
        $operation = hash('sha256', implode("\0", [
            'sqlite-seed-v1',
            (string) $source->appInstanceId,
            $source->environment,
            $source->basePath,
            $source->executionUser,
            (string) $target->appInstanceId,
            $target->basePath,
            $target->executionUser,
            $sourcePath,
        ]));
        $sourceState = self::StateRoot."/source-{$source->appInstanceId}-target-{$target->appInstanceId}";
        $targetState = self::StateRoot."/target-{$target->appInstanceId}";
        $snapshotPath = "/tmp/orbit-sqlite-{$operation}.sqlite";
        [$snapshotBytes, $snapshotDigest, $preparedSnapshotPath] = $this->prepareSource(
            $source,
            $sourcePath,
            $operation,
            $sourceState,
            $snapshotPath,
        );
        $targetPreparation = $this->prepareTarget(
            $target,
            $operation,
            $targetState,
            $snapshotBytes,
            $snapshotDigest,
        );

        if ($targetPreparation === 'REFUSED') {
            $this->cleanupSource($source, $sourcePath, $operation, $sourceState, $snapshotPath);
            $this->failPreflight();
        }

        if ($targetPreparation === 'COMPLETE') {
            return $this->cleanupResult($source, $sourcePath, $operation, $sourceState, $snapshotPath, false);
        }

        if (str_starts_with($targetPreparation, "READY\t")) {
            $incomingPath = substr($targetPreparation, 6);

            try {
                $this->transfer->transfer(
                    $source,
                    $preparedSnapshotPath,
                    $target,
                    $incomingPath,
                    $snapshotBytes,
                    $snapshotDigest,
                );
            } catch (Throwable) {
                $this->failTransfer();
            }
        } elseif ($targetPreparation !== 'RESUME') {
            $this->failPreflight();
        }

        try {
            $installed = $this->targetCommand(
                $target,
                'install',
                $operation,
                $targetState,
                $snapshotBytes,
                $snapshotDigest,
            );
        } catch (Throwable) {
            return SqliteSeedResult::unconfirmed();
        }

        if ($installed->succeeded() && $installed->stdout === "CHANGED\n" && $installed->stderr === '' && ! $installed->truncated) {
            return $this->cleanupResult($source, $sourcePath, $operation, $sourceState, $snapshotPath, true);
        }

        if ($installed->succeeded() && $installed->stdout === "COMPLETE\n" && $installed->stderr === '' && ! $installed->truncated) {
            return $this->cleanupResult($source, $sourcePath, $operation, $sourceState, $snapshotPath, false);
        }

        if ($installed->exitCode === 42 && $installed->stdout === "REFUSED\n" && $installed->stderr === '' && ! $installed->truncated) {
            $this->cleanupSource($source, $sourcePath, $operation, $sourceState, $snapshotPath);
            $this->failInstall();
        }

        if ($installed->exitCode === 43 && $installed->stdout === "FAILED\n" && $installed->stderr === '' && ! $installed->truncated) {
            $this->failInstall();
        }

        return SqliteSeedResult::unconfirmed();
    }

    /** @return array{int, string, string} */
    private function prepareSource(
        SqliteSeedPlacement $source,
        string $sourcePath,
        string $operation,
        string $stateDirectory,
        string $snapshotPath,
    ): array {
        try {
            $result = $this->sourceCommand(
                $source,
                'prepare',
                $sourcePath,
                $operation,
                $stateDirectory,
                $snapshotPath,
            );
        } catch (Throwable) {
            $this->failPreflight();
        }

        if (! $result->succeeded() || $result->truncated || $result->stderr !== '') {
            $this->failPreflight();
        }

        $parts = explode("\t", trim($result->stdout));

        if (
            count($parts) !== 4
            || $parts[0] !== 'READY'
            || filter_var($parts[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
            || preg_match('/\A[0-9a-f]{64}\z/D', $parts[2]) !== 1
            || $parts[3] !== $snapshotPath
        ) {
            $this->failPreflight();
        }

        return [(int) $parts[1], $parts[2], $parts[3]];
    }

    private function prepareTarget(
        SqliteSeedPlacement $target,
        string $operation,
        string $stateDirectory,
        int $snapshotBytes,
        string $snapshotDigest,
    ): string {
        try {
            $result = $this->targetCommand(
                $target,
                'prepare',
                $operation,
                $stateDirectory,
                $snapshotBytes,
                $snapshotDigest,
            );
        } catch (Throwable) {
            $this->failPreflight();
        }

        if (
            $result->exitCode === 42
            && $result->stdout === "REFUSED\n"
            && $result->stderr === ''
            && ! $result->truncated
        ) {
            return 'REFUSED';
        }

        if (! $result->succeeded() || $result->truncated || $result->stderr !== '') {
            $this->failPreflight();
        }

        $receipt = trim($result->stdout);

        if (in_array($receipt, ['COMPLETE', 'RESUME'], true)) {
            return $receipt;
        }

        if (preg_match('/\AREADY\t(\/tmp\/orbit-sqlite-[A-Za-z0-9._-]+)\z/D', $receipt) !== 1) {
            $this->failPreflight();
        }

        return $receipt;
    }

    private function cleanupResult(
        SqliteSeedPlacement $source,
        string $sourcePath,
        string $operation,
        string $stateDirectory,
        string $snapshotPath,
        bool $changed,
    ): SqliteSeedResult {
        if (! $this->cleanupSource($source, $sourcePath, $operation, $stateDirectory, $snapshotPath)) {
            return SqliteSeedResult::unconfirmed();
        }

        return $changed ? SqliteSeedResult::changed() : SqliteSeedResult::unchanged();
    }

    private function cleanupSource(
        SqliteSeedPlacement $source,
        string $sourcePath,
        string $operation,
        string $stateDirectory,
        string $snapshotPath,
    ): bool {
        try {
            $cleanup = $this->sourceCommand(
                $source,
                'cleanup',
                $sourcePath,
                $operation,
                $stateDirectory,
                $snapshotPath,
            );
        } catch (Throwable) {
            return false;
        }

        if (! $cleanup->succeeded() || $cleanup->truncated || $cleanup->stdout !== "CLEANED\n" || $cleanup->stderr !== '') {
            return false;
        }

        return true;
    }

    private function sourceCommand(
        SqliteSeedPlacement $source,
        string $mode,
        string $sourcePath,
        string $operation,
        string $stateDirectory,
        string $snapshotPath,
    ): CommandResult {
        return $this->ssh->execute(
            $this->connection($source),
            new RemoteCommand([
                'sudo',
                '-n',
                '--',
                'python3',
                '-c',
                self::SourceProgram,
                $mode,
                $source->basePath,
                $sourcePath,
                $source->executionUser,
                (string) $source->node->user,
                $operation,
                $stateDirectory,
                $snapshotPath,
            ], maxOutputBytes: 512),
        );
    }

    private function targetCommand(
        SqliteSeedPlacement $target,
        string $mode,
        string $operation,
        string $stateDirectory,
        int $snapshotBytes,
        string $snapshotDigest,
    ): CommandResult {
        return $this->ssh->execute(
            $this->connection($target),
            new RemoteCommand([
                'sudo',
                '-n',
                '--',
                'python3',
                '-c',
                self::TargetProgram,
                $mode,
                $target->basePath,
                $target->executionUser,
                (string) $target->node->user,
                $operation,
                $stateDirectory,
                (string) $snapshotBytes,
                $snapshotDigest,
            ], maxOutputBytes: 512),
        );
    }

    private function connection(SqliteSeedPlacement $placement): SshConnection
    {
        return new SshConnection(
            host: (string) $placement->node->wireguard_ip,
            user: (string) $placement->node->user,
            port: 22,
            identityFile: $this->keys->privateKeyPath(),
            knownHostsFile: $this->knownHosts->path(),
        );
    }

    private function assertRequest(
        SqliteSeedPlacement $source,
        SqliteSeedPlacement $target,
        string $sourcePath,
    ): void {
        if (
            $source->appInstanceId <= 0
            || $target->appInstanceId <= 0
            || $source->appInstanceId === $target->appInstanceId
            || ! in_array($source->environment, ['development', 'production'], true)
            || $target->environment !== 'production'
            || ! $this->safeAbsolutePath($source->basePath)
            || ! $this->safeAbsolutePath($target->basePath)
            || ! $this->safeAbsolutePath($sourcePath)
            || ! str_starts_with($sourcePath, $source->basePath.'/')
            || ! $this->safeUser($source->executionUser)
            || ! $this->safeUser($target->executionUser)
            || $target->basePath !== "/home/{$target->executionUser}"
            || ! $this->availableNode($source)
            || ! $this->availableNode($target)
        ) {
            $this->failPreflight();
        }
    }

    private function safeAbsolutePath(string $path): bool
    {
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0") || str_contains($path, "\n") || str_contains($path, "\r")) {
            return false;
        }

        $segments = array_slice(explode('/', $path), 1);

        return $segments !== [] && array_all(
            $segments,
            static fn (string $segment): bool => ! in_array($segment, ['', '.', '..'], true),
        );
    }

    private function safeUser(string $user): bool
    {
        return preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/D', $user) === 1;
    }

    private function availableNode(SqliteSeedPlacement $placement): bool
    {
        return
            is_string($placement->node->wireguard_ip)
            && filter_var($placement->node->wireguard_ip, FILTER_VALIDATE_IP) !== false
            && $this->safeUser($placement->node->user);
    }

    private function failPreflight(): never
    {
        throw new ResourceOperationException(
            errorCode: 'sqlite.seed_preflight_failed',
            message: 'The recorded SQLite seed source or target is unavailable.',
            status: 409,
        );
    }

    private function failTransfer(): never
    {
        throw new ResourceOperationException(
            errorCode: 'sqlite.seed_transfer_failed',
            message: 'The SQLite snapshot transfer failed safely.',
            status: 409,
        );
    }

    private function failInstall(): never
    {
        throw new ResourceOperationException(
            errorCode: 'sqlite.seed_failed',
            message: 'The target SQLite seed installation failed safely.',
            status: 409,
        );
    }
}
