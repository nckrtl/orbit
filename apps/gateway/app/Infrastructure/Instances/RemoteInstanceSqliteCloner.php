<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\Instances\DatabaseClone\InstanceSqliteCloner;
use App\Domain\Instances\InstanceSandboxGuard;
use App\Domain\Instances\Sqlite\SqliteSeedPlacement;
use App\Domain\Instances\Sqlite\SqliteSnapshotTransfer;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use Throwable;

/**
 * Runs a small Python program as the Node's managed user, who owns development checkouts. The
 * program refuses a path outside the checkout or a path through a symlink. On one Node it takes a
 * reflink of the database and its WAL while it holds SQLite's write lock. It falls back to a backup
 * snapshot when the filesystem cannot clone, or when a writer keeps the lock for two seconds. Between Nodes, the Gateway moves the snapshot
 * with the protected SQLite transfer and checks its size and digest.
 */
final readonly class RemoteInstanceSqliteCloner implements InstanceSqliteCloner
{
    public const string Program = <<<'PYTHON'
        import fcntl, hashlib, json, os, sqlite3, stat, sys, tempfile, urllib.parse

        FICLONE = 0x40049409

        class BoundaryError(Exception):
            pass

        class UseSnapshot(Exception):
            pass

        def normalized(path):
            if not os.path.isabs(path) or os.path.normpath(path) != path:
                raise BoundaryError
            return path

        def walk(path, create_below=None):
            current = "/"
            for segment in normalized(path).split("/")[1:]:
                current = os.path.join(current, segment)
                try:
                    metadata = os.lstat(current)
                except FileNotFoundError:
                    if create_below is None or not current.startswith(create_below + "/"):
                        raise
                    os.mkdir(current, 0o755)
                    metadata = os.lstat(current)
                if stat.S_ISLNK(metadata.st_mode) or not stat.S_ISDIR(metadata.st_mode):
                    raise BoundaryError

        def inside(checkout, path):
            normalized(checkout)
            normalized(path)
            if not path.startswith(checkout + "/"):
                raise BoundaryError
            return path

        def source_file(checkout, path):
            inside(checkout, path)
            walk(os.path.dirname(path))
            metadata = os.lstat(path)
            if not stat.S_ISREG(metadata.st_mode) or metadata.st_uid != os.geteuid():
                raise BoundaryError
            return metadata

        def digest(path):
            hashed = hashlib.sha256()
            with open(path, "rb") as handle:
                for chunk in iter(lambda: handle.read(1048576), b""):
                    hashed.update(chunk)
            return hashed.hexdigest()

        def snapshot(source, directory):
            descriptor, temporary = tempfile.mkstemp(prefix=".orbit-sqlite-", dir=directory)
            os.close(descriptor)
            try:
                origin = sqlite3.connect("file:" + urllib.parse.quote(source) + "?mode=rw", uri=True)
                try:
                    copy = sqlite3.connect(temporary)
                    try:
                        origin.backup(copy)
                    finally:
                        copy.close()
                finally:
                    origin.close()
                with open(temporary, "rb") as handle:
                    os.fsync(handle.fileno())
                return temporary
            except BaseException:
                try:
                    os.unlink(temporary)
                except FileNotFoundError:
                    pass
                raise

        def clone(source, directory):
            descriptor, temporary = tempfile.mkstemp(prefix=".orbit-sqlite-", dir=directory)
            try:
                with open(source, "rb") as origin:
                    fcntl.ioctl(descriptor, FICLONE, origin.fileno())
            except OSError:
                os.close(descriptor)
                os.unlink(temporary)
                raise UseSnapshot
            os.close(descriptor)
            return temporary

        def reflinked(source, directory):
            wal = source + "-wal"
            try:
                metadata = os.lstat(wal)
            except FileNotFoundError:
                metadata = None
            if metadata is not None and not stat.S_ISREG(metadata.st_mode):
                raise BoundaryError
            # An unlocked clone proves these filesystems can clone before any writer has to wait.
            os.unlink(clone(source, directory))
            origin = sqlite3.connect("file:" + urllib.parse.quote(source) + "?mode=rw", uri=True, isolation_level=None, timeout=2)
            try:
                try:
                    origin.execute("BEGIN IMMEDIATE")
                except sqlite3.OperationalError:
                    raise UseSnapshot
                try:
                    database = clone(source, directory)
                    try:
                        log = clone(wal, directory) if os.path.lexists(wal) else None
                    except BaseException:
                        os.unlink(database)
                        raise
                finally:
                    origin.execute("ROLLBACK")
            finally:
                origin.close()
            return database, log

        def place(temporary, log, target, mode):
            os.chmod(temporary, mode)
            try:
                os.unlink(target + "-shm")
            except FileNotFoundError:
                pass
            if log is None:
                try:
                    os.unlink(target + "-wal")
                except FileNotFoundError:
                    pass
            else:
                os.chmod(log, mode)
                os.replace(log, target + "-wal")
            os.replace(temporary, target)

        def report(path, mode, copy):
            print(json.dumps({"bytes": os.lstat(path).st_size, "sha256": digest(path), "mode": mode, "copy": copy}))

        try:
            operation = sys.argv[1]
            if operation == "local":
                checkout, source, target_checkout, target = sys.argv[2:6]
                metadata = source_file(checkout, source)
                inside(target_checkout, target)
                walk(target_checkout)
                walk(os.path.dirname(target), create_below=target_checkout)
                try:
                    temporary, log = reflinked(source, os.path.dirname(target))
                    copy = "reflink"
                except UseSnapshot:
                    temporary, log = snapshot(source, os.path.dirname(target)), None
                    copy = "snapshot"
                place(temporary, log, target, stat.S_IMODE(metadata.st_mode))
                report(target, stat.S_IMODE(metadata.st_mode), copy)
            elif operation == "export":
                checkout, source, directory = sys.argv[2:5]
                metadata = source_file(checkout, source)
                if not directory.startswith("/tmp/orbit-sqlite-clone-"):
                    raise BoundaryError
                os.mkdir(normalized(directory), 0o700)
                temporary = snapshot(source, directory)
                os.replace(temporary, os.path.join(directory, "snapshot.sqlite"))
                report(os.path.join(directory, "snapshot.sqlite"), stat.S_IMODE(metadata.st_mode), "snapshot")
            elif operation == "prepare":
                target_checkout, target = sys.argv[2:4]
                inside(target_checkout, target)
                walk(target_checkout)
                walk(os.path.dirname(target), create_below=target_checkout)
                print("OK")
            elif operation == "install":
                target_checkout, target, incoming, size, expected, mode = sys.argv[2:8]
                inside(target_checkout, target)
                inside(target_checkout, incoming)
                if os.path.dirname(incoming) != os.path.dirname(target):
                    raise BoundaryError
                walk(os.path.dirname(target))
                metadata = os.lstat(incoming)
                if not stat.S_ISREG(metadata.st_mode) or metadata.st_uid != os.geteuid():
                    raise BoundaryError
                if metadata.st_size != int(size) or digest(incoming) != expected:
                    raise BoundaryError
                os.chmod(incoming, int(mode, 8) & 0o666)
                os.replace(incoming, target)
                print("OK")
            elif operation == "cleanup":
                directory = sys.argv[2]
                if not directory.startswith("/tmp/orbit-sqlite-clone-"):
                    raise BoundaryError
                for name in ("snapshot.sqlite",):
                    try:
                        os.unlink(os.path.join(normalized(directory), name))
                    except FileNotFoundError:
                        pass
                try:
                    os.rmdir(directory)
                except FileNotFoundError:
                    pass
                print("OK")
            elif operation == "remove":
                checkout, path = sys.argv[2:4]
                inside(checkout, path)
                try:
                    walk(os.path.dirname(path))
                    metadata = os.lstat(path)
                except FileNotFoundError:
                    print("OK")
                    raise SystemExit(0)
                if not stat.S_ISREG(metadata.st_mode):
                    raise BoundaryError
                os.unlink(path)
                print("OK")
            else:
                raise BoundaryError
        except SystemExit:
            raise
        except BoundaryError:
            print("REFUSED")
            raise SystemExit(42)
        except Exception:
            print("FAILED")
            raise SystemExit(43)
        PYTHON;

    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private SqliteSnapshotTransfer $transfer,
    ) {}

    public function copy(Instance $source, string $sourcePath, Instance $target, string $targetPath): void
    {
        InstanceSandboxGuard::assertHostOperation($source);
        InstanceSandboxGuard::assertHostOperation($target);
        $sourceNode = $source->node;
        $targetNode = $target->node;

        if ($sourceNode->id === $targetNode->id) {
            $this->report($this->run($sourceNode, ['local', $source->checkout_path, $sourcePath, $target->checkout_path, $targetPath]));

            return;
        }

        $directory = '/tmp/orbit-sqlite-clone-'.bin2hex(random_bytes(16));
        $incoming = dirname($targetPath).'/.orbit-sqlite-'.bin2hex(random_bytes(16));

        try {
            $snapshot = $this->report($this->run($sourceNode, ['export', $source->checkout_path, $sourcePath, $directory]));
            $this->expectOk($this->run($targetNode, ['prepare', $target->checkout_path, $targetPath]));

            try {
                $this->transfer->transfer(
                    $this->placement($source),
                    "{$directory}/snapshot.sqlite",
                    $this->placement($target),
                    $incoming,
                    $snapshot['bytes'],
                    $snapshot['sha256'],
                );
            } catch (Throwable $exception) {
                throw $this->failed($exception);
            }

            $this->expectOk($this->run($targetNode, [
                'install',
                $target->checkout_path,
                $targetPath,
                $incoming,
                (string) $snapshot['bytes'],
                $snapshot['sha256'],
                decoct($snapshot['mode']),
            ]));
        } finally {
            try {
                $this->run($sourceNode, ['cleanup', $directory]);
            } catch (Throwable) {
                // The snapshot directory is private to the managed user; a failed cleanup keeps the clone result.
            }
        }
    }

    public function remove(Instance $owner, string $path): void
    {
        InstanceSandboxGuard::assertHostOperation($owner);
        $this->expectOk($this->run($owner->node, ['remove', $owner->checkout_path, $path]));
    }

    /** @param non-empty-list<string> $arguments */
    private function run(Node $node, array $arguments): CommandResult
    {
        $host = $node->wireguard_ip;

        if (! is_string($host) || $host === '') {
            throw $this->failed();
        }

        try {
            return $this->ssh->execute(
                new SshConnection(
                    host: $host,
                    user: $node->user,
                    port: 22,
                    identityFile: $this->keys->privateKeyPath(),
                    knownHostsFile: $this->knownHosts->path(),
                ),
                new RemoteCommand(
                    ['python3', '-c', self::Program, ...$arguments],
                    maxOutputBytes: 4096,
                    timeout: 600.0,
                ),
            );
        } catch (Throwable $exception) {
            throw $this->failed($exception);
        }
    }

    /** @return array{bytes: int, sha256: string, mode: int} */
    private function report(CommandResult $result): array
    {
        if (! $result->succeeded() || $result->truncated) {
            throw $this->failed();
        }

        $decoded = json_decode(trim($result->stdout), true);

        if (
            ! is_array($decoded)
            || ! is_int($decoded['bytes'] ?? null)
            || ! is_string($decoded['sha256'] ?? null)
            || preg_match('/\A[0-9a-f]{64}\z/D', $decoded['sha256']) !== 1
            || ! is_int($decoded['mode'] ?? null)
        ) {
            throw $this->failed();
        }

        return ['bytes' => $decoded['bytes'], 'sha256' => $decoded['sha256'], 'mode' => $decoded['mode']];
    }

    private function expectOk(CommandResult $result): void
    {
        if (! $result->succeeded() || $result->truncated || $result->stdout !== "OK\n") {
            throw $this->failed();
        }
    }

    private function placement(Instance $instance): SqliteSeedPlacement
    {
        return new SqliteSeedPlacement(
            instanceId: $instance->id,
            environment: 'development',
            basePath: $instance->checkout_path,
            executionUser: $instance->node->user,
            node: $instance->node,
        );
    }

    private function failed(?Throwable $previous = null): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'instance.database_clone_failed',
            message: 'The SQLite database could not be copied.',
            status: 502,
            previous: $previous,
        );
    }
}
