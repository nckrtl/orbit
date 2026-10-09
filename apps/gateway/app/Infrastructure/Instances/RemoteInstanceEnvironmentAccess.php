<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\Instances\Environment\InstanceEnvironmentContext;
use App\Domain\Instances\Environment\InstanceEnvironmentReader;
use App\Domain\Instances\Environment\InstanceEnvironmentValidator;
use App\Domain\Instances\Environment\InstanceEnvironmentWriter;
use App\Domain\Instances\Environment\InstanceEnvironmentWriteResult;
use App\Domain\Instances\Environment\InstanceOperationPreflight;
use App\Domain\Instances\Environment\InstanceTestEnvironmentWriter;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use SensitiveParameter;
use Throwable;

final readonly class RemoteInstanceEnvironmentAccess implements InstanceEnvironmentReader, InstanceEnvironmentWriter, InstanceOperationPreflight, InstanceTestEnvironmentWriter
{
    private const string AccessProgram = <<<'PYTHON'
        import base64, os, pwd, re, stat, subprocess, sys

        mode = sys.argv[1]
        base = sys.argv[2]
        expected_user = sys.argv[3]
        maximum = int(sys.argv[4])
        require_home = sys.argv[5] == "1"
        required_capacity = int(sys.argv[6])
        target_name = sys.argv[7] if len(sys.argv) > 7 else ".env"
        managed_keys = sys.argv[8].split(",") if len(sys.argv) > 8 else []
        assignment = re.compile(rb"[ \t]*(?:export[ \t]+)?([A-Za-z_][A-Za-z0-9_.]*)[ \t]*=")
        descriptors = []
        candidate_name = None
        candidate_identity = None
        replacement_installed = False

        class BoundaryError(Exception):
            pass

        class MissingEnvironment(Exception):
            pass

        class TrackingUnknown(Exception):
            pass

        def open_base():
            opened = []
            current = os.open("/", os.O_RDONLY | os.O_DIRECTORY)
            opened.append(current)
            for segment in base.split("/")[1:]:
                current = os.open(segment, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=current)
                opened.append(current)
            return opened, current

        def destination_metadata(directory):
            try:
                descriptor = os.open(target_name, os.O_PATH | os.O_NOFOLLOW, dir_fd=directory)
            except FileNotFoundError:
                return None, None
            metadata = os.fstat(descriptor)
            if not stat.S_ISREG(metadata.st_mode) or metadata.st_uid != os.geteuid():
                os.close(descriptor)
                raise BoundaryError
            return descriptor, metadata

        def same_file(left, right):
            offset = 0
            while True:
                left_chunk = os.pread(left, 65536, offset)
                right_chunk = os.pread(right, 65536, offset)
                if left_chunk != right_chunk:
                    return False
                if not left_chunk:
                    return True
                offset += len(left_chunk)

        def boundary_unchanged(directory, directory_metadata, destination_identity):
            fresh_descriptors, fresh_directory = open_base()
            try:
                fresh_directory_metadata = os.fstat(fresh_directory)
                if (fresh_directory_metadata.st_dev, fresh_directory_metadata.st_ino) != directory_metadata:
                    raise BoundaryError
                fresh_destination, fresh_metadata = destination_metadata(fresh_directory)
                try:
                    fresh_identity = None if fresh_metadata is None else (fresh_metadata.st_dev, fresh_metadata.st_ino)
                    if fresh_identity != destination_identity:
                        raise BoundaryError
                finally:
                    if fresh_destination is not None:
                        os.close(fresh_destination)
            finally:
                for descriptor in reversed(fresh_descriptors):
                    os.close(descriptor)

        def tracked():
            def git(*arguments):
                try:
                    return subprocess.run(
                        ["git", "-C", base, *arguments],
                        stdin=subprocess.DEVNULL,
                        stdout=subprocess.PIPE,
                        stderr=subprocess.PIPE,
                        env=dict(os.environ, GIT_OPTIONAL_LOCKS="0", LC_ALL="C"),
                        timeout=30,
                    )
                except (OSError, subprocess.SubprocessError):
                    raise TrackingUnknown
            inside = git("rev-parse", "--is-inside-work-tree")
            reason = inside.stderr.splitlines()[0].lower() if inside.stderr else b""
            if inside.returncode == 128 and reason.startswith(b"fatal: not a git repository (or any "):
                return False
            if inside.returncode != 0 or inside.stdout != b"true\n":
                raise TrackingUnknown
            listed = git("ls-files", "--error-unmatch", "--", target_name)
            if listed.returncode in (0, 1):
                return listed.returncode == 0
            raise TrackingUnknown

        def existing_contents(descriptor, metadata, identity):
            if descriptor is None or metadata is None:
                return b""
            if metadata.st_size > maximum:
                raise BoundaryError
            existing = os.open(f"/proc/self/fd/{descriptor}", os.O_RDONLY | os.O_NONBLOCK)
            descriptors.append(existing)
            opened = os.fstat(existing)
            if (opened.st_dev, opened.st_ino) != identity:
                raise BoundaryError
            chunks, total = [], 0
            while True:
                chunk = os.read(existing, min(65536, maximum + 1 - total))
                if not chunk:
                    break
                chunks.append(chunk)
                total += len(chunk)
                if total > maximum:
                    raise BoundaryError
            return b"".join(chunks)

        def managed_lines(supplied):
            managed, updates = {key.encode("ascii") for key in managed_keys}, {}
            for line in supplied.splitlines(keepends=True):
                key = line.split(b"=", 1)[0]
                if not line.endswith(b"\n") or (key in managed and key in updates):
                    raise BoundaryError
                if key in managed:
                    updates[key] = line
            if not updates:
                raise BoundaryError
            return managed, updates

        def merged(existing, managed, updates):
            lines, written = [], set()
            for line in existing.splitlines(keepends=True):
                match = assignment.match(line)
                key = match.group(1) if match else None
                if key in managed:
                    if key in updates and key not in written:
                        lines.append(updates[key])
                        written.add(key)
                    continue
                lines.append(line)
            missing = [line for key, line in updates.items() if key not in written]
            if missing and lines and not lines[-1].endswith(b"\n"):
                lines[-1] += b"\n"
            return b"".join(lines + missing)

        def cleanup_candidate(directory):
            if candidate_name is None or candidate_identity is None:
                return
            try:
                metadata = os.stat(candidate_name, dir_fd=directory, follow_symlinks=False)
                if (metadata.st_dev, metadata.st_ino) == candidate_identity:
                    os.unlink(candidate_name, dir_fd=directory)
            except FileNotFoundError:
                pass

        try:
            account = pwd.getpwuid(os.geteuid())
            if account.pw_name != expected_user or (require_home and account.pw_dir != base):
                raise BoundaryError
            if not os.path.isabs(base) or os.path.normpath(base) != base:
                raise BoundaryError
            if target_name not in (".env", ".env.testing"):
                raise BoundaryError
            if mode == "merge" and (
                target_name != ".env.testing"
                or not managed_keys
                or any(re.fullmatch(r"[A-Z][A-Z0-9_]*", key) is None for key in managed_keys)
            ):
                raise BoundaryError
            segments = base.split("/")[1:]
            if not segments or any(segment in ("", ".", "..") for segment in segments):
                raise BoundaryError
            descriptors, current = open_base()
            directory_stat = os.fstat(current)
            directory_identity = (directory_stat.st_dev, directory_stat.st_ino)
            metadata_descriptor, metadata = destination_metadata(current)
            if metadata_descriptor is not None:
                descriptors.append(metadata_descriptor)
            destination_identity = None if metadata is None else (metadata.st_dev, metadata.st_ino)

            if mode in ("read-check", "read"):
                if metadata_descriptor is None or metadata is None:
                    if mode == "read":
                        raise MissingEnvironment
                    raise BoundaryError
                if metadata.st_size > maximum:
                    raise BoundaryError
                value = os.open(f"/proc/self/fd/{metadata_descriptor}", os.O_RDONLY | os.O_NONBLOCK)
                descriptors.append(value)
                opened = os.fstat(value)
                if (opened.st_dev, opened.st_ino) != destination_identity:
                    raise BoundaryError

            if mode in ("write-check", "write", "merge"):
                if required_capacity < 0:
                    raise BoundaryError
                if not os.access(base, os.W_OK | os.X_OK, effective_ids=True):
                    raise BoundaryError
                filesystem = os.statvfs(current)
                if filesystem.f_flag & getattr(os, "ST_RDONLY", 1):
                    raise BoundaryError
                if filesystem.f_bavail * filesystem.f_frsize < required_capacity:
                    raise BoundaryError

            if mode in ("read-check", "write-check"):
                boundary_unchanged(current, directory_identity, destination_identity)
                print("OK")
            elif mode == "read":
                chunks, total = [], 0
                while True:
                    chunk = os.read(value, min(65536, maximum + 1 - total))
                    if not chunk:
                        break
                    chunks.append(chunk)
                    total += len(chunk)
                    if total > maximum:
                        raise BoundaryError
                sys.stdout.write(base64.b64encode(b"".join(chunks)).decode())
            elif mode in ("write", "merge"):
                payload = None
                if mode == "merge":
                    if tracked():
                        boundary_unchanged(current, directory_identity, destination_identity)
                        print("TRACKED")
                        raise SystemExit(0)
                    payload = sys.stdin.buffer.read(maximum + 1)
                    managed, updates = managed_lines(payload)
                    if metadata is not None:
                        payload = merged(
                            existing_contents(metadata_descriptor, metadata, destination_identity),
                            managed,
                            updates,
                        )
                    if len(payload) > maximum:
                        raise BoundaryError
                for _ in range(8):
                    candidate_name = f".env.orbit-{os.urandom(16).hex()}"
                    try:
                        candidate = os.open(
                            candidate_name,
                            os.O_RDWR | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW,
                            0o600,
                            dir_fd=current,
                        )
                        break
                    except FileExistsError:
                        candidate_name = None
                else:
                    raise OSError
                descriptors.append(candidate)
                candidate_stat = os.fstat(candidate)
                candidate_identity = (candidate_stat.st_dev, candidate_stat.st_ino)
                remaining = payload
                while True:
                    if payload is None:
                        chunk = sys.stdin.buffer.read(65536)
                    else:
                        chunk, remaining = remaining[:65536], remaining[65536:]
                    if not chunk:
                        break
                    offset = 0
                    while offset < len(chunk):
                        written = os.write(candidate, chunk[offset:])
                        if written <= 0:
                            raise OSError
                        offset += written
                os.fchmod(candidate, 0o600)
                os.fsync(candidate)

                unchanged = False
                if metadata_descriptor is not None and metadata is not None and stat.S_IMODE(metadata.st_mode) == 0o600:
                    existing = os.open(f"/proc/self/fd/{metadata_descriptor}", os.O_RDONLY | os.O_NONBLOCK)
                    descriptors.append(existing)
                    opened = os.fstat(existing)
                    if (opened.st_dev, opened.st_ino) != destination_identity:
                        raise BoundaryError
                    unchanged = same_file(existing, candidate)

                boundary_unchanged(current, directory_identity, destination_identity)
                if unchanged:
                    cleanup_candidate(current)
                    candidate_name = None
                    candidate_identity = None
                    print("UNCHANGED")
                else:
                    os.replace(candidate_name, target_name, src_dir_fd=current, dst_dir_fd=current)
                    replacement_installed = True
                    candidate_name = None
                    candidate_identity = None
                    os.fsync(current)
                    print("CHANGED")
            else:
                raise BoundaryError
        except MissingEnvironment:
            print("MISSING")
            raise SystemExit(41)
        except TrackingUnknown:
            print("TRACKING_UNKNOWN")
            raise SystemExit(45)
        except BoundaryError:
            if "current" in locals():
                cleanup_candidate(current)
            if replacement_installed:
                print("FAILED")
                raise SystemExit(44)
            print("REFUSED")
            raise SystemExit(42)
        except Exception:
            if "current" in locals():
                cleanup_candidate(current)
            if replacement_installed:
                print("FAILED")
                raise SystemExit(44)
            print("FAILED")
            raise SystemExit(43)
        finally:
            for descriptor in reversed(descriptors):
                os.close(descriptor)
        PYTHON;

    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
    ) {}

    public function assertEnvironmentReadable(InstanceEnvironmentContext $context): void
    {
        $result = $this->execute($context, 'read-check', maximumOutputBytes: 64);

        if (
            ! $result->succeeded()
            || $result->truncated
            || $result->stdout !== "OK\n"
            || $result->stderr !== ''
        ) {
            $this->failRead();
        }
    }

    public function assertEnvironmentWritable(
        InstanceEnvironmentContext $context,
        int $requiredCapacityBytes,
    ): void {
        $result = $this->execute(
            $context,
            'write-check',
            maximumOutputBytes: 64,
            requiredCapacityBytes: $requiredCapacityBytes,
        );

        if (
            ! $result->succeeded()
            || $result->truncated
            || $result->stdout !== "OK\n"
            || $result->stderr !== ''
        ) {
            $this->failWritePreflight();
        }
    }

    public function read(InstanceEnvironmentContext $context): string
    {
        $result = $this->execute($context, 'read', maximumOutputBytes: 1_398_104);

        if ($result->exitCode === 41 && $result->stdout === "MISSING\n" && $result->stderr === '') {
            throw new ResourceOperationException(
                errorCode: 'env.import_source_missing',
                message: 'The recorded Instance environment file does not exist.',
                status: 404,
            );
        }

        if (! $result->succeeded() || $result->truncated || $result->stderr !== '') {
            $this->failRead();
        }

        $contents = base64_decode($result->stdout, true);

        if (! is_string($contents) || strlen($contents) > InstanceEnvironmentValidator::MaximumFileBytes) {
            $this->failRead();
        }

        return $contents;
    }

    public function write(
        InstanceEnvironmentContext $context,
        #[SensitiveParameter]
        string $contents,
    ): InstanceEnvironmentWriteResult {
        return $this->replace($context, $contents, '.env');
    }

    public function mergeTesting(
        InstanceEnvironmentContext $context,
        #[SensitiveParameter]
        string $contents,
        array $managedKeys,
    ): InstanceEnvironmentWriteResult {
        return $this->replace($context, $contents, InstanceTestEnvironmentWriter::FILE, $managedKeys);
    }

    /** @param  list<string>|null  $managedKeys  the keys to merge into the file; null replaces it */
    private function replace(
        InstanceEnvironmentContext $context,
        #[SensitiveParameter]
        string $contents,
        string $file,
        ?array $managedKeys = null,
    ): InstanceEnvironmentWriteResult {
        try {
            $input = ProtectedInput::fromString($contents);
        } catch (Throwable) {
            $this->failWrite();
        }

        try {
            $result = $this->execute(
                $context,
                $managedKeys === null ? 'write' : 'merge',
                maximumOutputBytes: 64,
                protectedInput: $input,
                file: $file,
                managedKeys: $managedKeys ?? [],
            );
        } catch (Throwable) {
            return InstanceEnvironmentWriteResult::unconfirmed();
        }

        if ($result->truncated || $result->stderr !== '') {
            return InstanceEnvironmentWriteResult::unconfirmed();
        }

        if ($result->succeeded() && $result->stdout === "CHANGED\n") {
            return InstanceEnvironmentWriteResult::changed();
        }

        if ($result->succeeded() && $result->stdout === "UNCHANGED\n") {
            return InstanceEnvironmentWriteResult::unchanged();
        }

        if ($managedKeys !== null && $result->succeeded() && $result->stdout === "TRACKED\n") {
            return InstanceEnvironmentWriteResult::tracked();
        }

        if ($managedKeys !== null && $result->exitCode === 45 && $result->stdout === "TRACKING_UNKNOWN\n") {
            throw new ResourceOperationException(
                errorCode: 'env.testing_tracking_unknown',
                message: 'Git cannot report whether the checkout tracks .env.testing, so the file was left unchanged.',
                status: 409,
            );
        }

        if ($result->exitCode === 42 && $result->stdout === "REFUSED\n") {
            $this->failWrite();
        }

        if ($result->exitCode === 43 && $result->stdout === "FAILED\n") {
            $this->failWrite();
        }

        return InstanceEnvironmentWriteResult::unconfirmed();
    }

    /** @param  list<string>  $managedKeys */
    private function execute(
        InstanceEnvironmentContext $context,
        string $mode,
        int $maximumOutputBytes,
        int $requiredCapacityBytes = 0,
        ?ProtectedInput $protectedInput = null,
        string $file = '.env',
        array $managedKeys = [],
    ): CommandResult {
        $host = $context->node->wireguard_ip;

        if (! is_string($host) || $host === '') {
            if ($mode === 'read' || $mode === 'read-check') {
                $this->failRead();
            }

            if ($mode === 'write-check') {
                $this->failWritePreflight();
            }

            throw new \RuntimeException('The recorded Instance host is unavailable.');
        }

        try {
            return $this->ssh->execute(
                new SshConnection(
                    host: $host,
                    user: $context->node->user,
                    port: 22,
                    identityFile: $this->keys->privateKeyPath(),
                    knownHostsFile: $this->knownHosts->path(),
                ),
                new RemoteCommand(
                    [
                        'sudo',
                        '-n',
                        '-u',
                        $context->executionUser,
                        '--',
                        'python3',
                        '-c',
                        self::AccessProgram,
                        $mode,
                        $context->path,
                        $context->executionUser,
                        (string) InstanceEnvironmentValidator::MaximumFileBytes,
                        $context->environment === 'production' ? '1' : '0',
                        (string) $requiredCapacityBytes,
                        ...($file === '.env' ? [] : [$file]),
                        ...($managedKeys === [] ? [] : [implode(',', $managedKeys)]),
                    ],
                    protectedInput: $protectedInput,
                    maxOutputBytes: $maximumOutputBytes,
                ),
            );
        } catch (Throwable $exception) {
            if ($mode === 'read' || $mode === 'read-check') {
                $this->failRead();
            }

            if ($mode === 'write-check') {
                $this->failWritePreflight();
            }

            throw $exception;
        }
    }

    private function failRead(): never
    {
        throw new ResourceOperationException(
            errorCode: 'env.import_preflight_failed',
            message: 'The recorded Instance environment file cannot be read safely.',
            status: 409,
        );
    }

    private function failWritePreflight(): never
    {
        throw new ResourceOperationException(
            errorCode: 'env.write_preflight_failed',
            message: 'The recorded Instance environment file cannot be replaced safely.',
            status: 409,
        );
    }

    private function failWrite(): never
    {
        throw new ResourceOperationException(
            errorCode: 'env.write_failed',
            message: 'The Instance environment file replacement failed safely.',
            status: 409,
        );
    }
}
