<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Domain\AppInstances\Environment\AppInstanceEnvironmentContext;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentReader;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentValidator;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentWriter;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentWriteResult;
use App\Domain\AppInstances\Environment\AppInstanceOperationPreflight;
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

/** @mago-expect lint:cyclomatic-complexity The adapter keeps one fixed remote program and its bounded receipts in one infrastructure boundary. */
final readonly class RemoteAppInstanceEnvironmentAccess implements
    AppInstanceOperationPreflight,
    AppInstanceEnvironmentReader,
    AppInstanceEnvironmentWriter
{
    private const string AccessProgram = <<<'PYTHON'
        import base64, os, pwd, stat, sys

        mode = sys.argv[1]
        base = sys.argv[2]
        expected_user = sys.argv[3]
        maximum = int(sys.argv[4])
        require_home = sys.argv[5] == "1"
        required_capacity = int(sys.argv[6])
        descriptors = []
        candidate_name = None
        candidate_identity = None
        replacement_installed = False

        class BoundaryError(Exception):
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
                descriptor = os.open(".env", os.O_PATH | os.O_NOFOLLOW, dir_fd=directory)
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
                if metadata_descriptor is None or metadata is None or metadata.st_size > maximum:
                    raise BoundaryError
                value = os.open(f"/proc/self/fd/{metadata_descriptor}", os.O_RDONLY | os.O_NONBLOCK)
                descriptors.append(value)
                opened = os.fstat(value)
                if (opened.st_dev, opened.st_ino) != destination_identity:
                    raise BoundaryError

            if mode in ("write-check", "write"):
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
            elif mode == "write":
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
                while True:
                    chunk = sys.stdin.buffer.read(65536)
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
                    os.replace(candidate_name, ".env", src_dir_fd=current, dst_dir_fd=current)
                    replacement_installed = True
                    candidate_name = None
                    candidate_identity = None
                    os.fsync(current)
                    print("CHANGED")
            else:
                raise BoundaryError
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

    public function assertEnvironmentReadable(AppInstanceEnvironmentContext $context): void
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
        AppInstanceEnvironmentContext $context,
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

    public function read(AppInstanceEnvironmentContext $context): string
    {
        $result = $this->execute($context, 'read', maximumOutputBytes: 1_398_104);

        if (! $result->succeeded() || $result->truncated || $result->stderr !== '') {
            $this->failRead();
        }

        $contents = base64_decode($result->stdout, true);

        if (! is_string($contents) || strlen($contents) > AppInstanceEnvironmentValidator::MaximumFileBytes) {
            $this->failRead();
        }

        return $contents;
    }

    public function write(
        AppInstanceEnvironmentContext $context,
        #[SensitiveParameter]
        string $contents,
    ): AppInstanceEnvironmentWriteResult {
        try {
            $input = ProtectedInput::fromString($contents);
        } catch (Throwable) {
            $this->failWrite();
        }

        try {
            $result = $this->execute(
                $context,
                'write',
                maximumOutputBytes: 64,
                protectedInput: $input,
            );
        } catch (Throwable) {
            return AppInstanceEnvironmentWriteResult::unconfirmed();
        }

        if ($result->truncated || $result->stderr !== '') {
            return AppInstanceEnvironmentWriteResult::unconfirmed();
        }

        if ($result->succeeded() && $result->stdout === "CHANGED\n") {
            return AppInstanceEnvironmentWriteResult::changed();
        }

        if ($result->succeeded() && $result->stdout === "UNCHANGED\n") {
            return AppInstanceEnvironmentWriteResult::unchanged();
        }

        if ($result->exitCode === 42 && $result->stdout === "REFUSED\n") {
            $this->failWrite();
        }

        if ($result->exitCode === 43 && $result->stdout === "FAILED\n") {
            $this->failWrite();
        }

        return AppInstanceEnvironmentWriteResult::unconfirmed();
    }

    private function execute(
        AppInstanceEnvironmentContext $context,
        string $mode,
        int $maximumOutputBytes,
        int $requiredCapacityBytes = 0,
        ?ProtectedInput $protectedInput = null,
    ): CommandResult {
        $host = $context->node->wireguard_ip;

        if (! is_string($host) || $host === '') {
            if ($mode === 'read' || $mode === 'read-check') {
                $this->failRead();
            }

            if ($mode === 'write-check') {
                $this->failWritePreflight();
            }

            throw new \RuntimeException('The recorded AppInstance host is unavailable.');
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
                        (string) AppInstanceEnvironmentValidator::MaximumFileBytes,
                        $context->environment === 'production' ? '1' : '0',
                        (string) $requiredCapacityBytes,
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
            message: 'The recorded AppInstance environment file cannot be read safely.',
            status: 409,
        );
    }

    private function failWritePreflight(): never
    {
        throw new ResourceOperationException(
            errorCode: 'env.write_preflight_failed',
            message: 'The recorded AppInstance environment file cannot be replaced safely.',
            status: 409,
        );
    }

    private function failWrite(): never
    {
        throw new ResourceOperationException(
            errorCode: 'env.write_failed',
            message: 'The AppInstance environment file replacement failed safely.',
            status: 409,
        );
    }
}
