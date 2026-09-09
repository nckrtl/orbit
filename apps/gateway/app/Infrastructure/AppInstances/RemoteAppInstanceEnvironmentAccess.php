<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Domain\AppInstances\Environment\AppInstanceEnvironmentContext;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentReader;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentValidator;
use App\Domain\AppInstances\Environment\AppInstanceOperationPreflight;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use Throwable;

final readonly class RemoteAppInstanceEnvironmentAccess implements
    AppInstanceOperationPreflight,
    AppInstanceEnvironmentReader
{
    private const string AccessProgram = <<<'PYTHON'
        import base64, os, pwd, stat, sys

        mode, base, maximum, require_home = sys.argv[1], sys.argv[2], int(sys.argv[3]), sys.argv[4] == "1"
        descriptors = []
        try:
            account = pwd.getpwuid(os.geteuid())
            if require_home and account.pw_dir != base:
                raise RuntimeError
            if not os.path.isabs(base) or os.path.normpath(base) != base:
                raise RuntimeError
            segments = base.split("/")[1:]
            if not segments or any(segment in ("", ".", "..") for segment in segments):
                raise RuntimeError
            current = os.open("/", os.O_PATH | os.O_DIRECTORY)
            descriptors.append(current)
            for segment in segments:
                current = os.open(segment, os.O_PATH | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=current)
                descriptors.append(current)
            metadata_descriptor = os.open(".env", os.O_PATH | os.O_NOFOLLOW, dir_fd=current)
            descriptors.append(metadata_descriptor)
            metadata = os.fstat(metadata_descriptor)
            if not stat.S_ISREG(metadata.st_mode) or metadata.st_uid != os.geteuid() or metadata.st_size > maximum:
                raise RuntimeError
            value = os.open(f"/proc/self/fd/{metadata_descriptor}", os.O_RDONLY | os.O_NONBLOCK)
            descriptors.append(value)
            opened = os.fstat(value)
            if (opened.st_dev, opened.st_ino) != (metadata.st_dev, metadata.st_ino):
                raise RuntimeError
            if mode == "check":
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
                        raise RuntimeError
                sys.stdout.write(base64.b64encode(b"".join(chunks)).decode())
            else:
                raise RuntimeError
        except Exception:
            raise SystemExit(42)
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
        $result = $this->execute($context, 'check', maximumOutputBytes: 64);

        if (
            ! $result->succeeded()
            || $result->truncated
            || $result->stdout !== "OK\n"
            || $result->stderr !== ''
        ) {
            $this->fail();
        }
    }

    public function read(AppInstanceEnvironmentContext $context): string
    {
        $result = $this->execute($context, 'read', maximumOutputBytes: 1_398_104);

        if (! $result->succeeded() || $result->truncated || $result->stderr !== '') {
            $this->fail();
        }

        $contents = base64_decode($result->stdout, true);

        if (! is_string($contents) || strlen($contents) > AppInstanceEnvironmentValidator::MaximumFileBytes) {
            $this->fail();
        }

        return $contents;
    }

    private function execute(
        AppInstanceEnvironmentContext $context,
        string $mode,
        int $maximumOutputBytes,
    ): \App\Infrastructure\Processes\CommandResult {
        $host = $context->node->wireguard_ip;

        if (! is_string($host) || $host === '') {
            $this->fail();
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
                new RemoteCommand([
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
                    (string) AppInstanceEnvironmentValidator::MaximumFileBytes,
                    $context->environment === 'production' ? '1' : '0',
                ], maxOutputBytes: $maximumOutputBytes),
            );
        } catch (Throwable) {
            $this->fail();
        }
    }

    private function fail(): never
    {
        throw new ResourceOperationException(
            errorCode: 'env.import_preflight_failed',
            message: 'The recorded AppInstance environment file cannot be read safely.',
            status: 409,
        );
    }
}
