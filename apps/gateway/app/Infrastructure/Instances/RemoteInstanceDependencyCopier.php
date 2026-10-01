<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\Instances\DependencyCopy\InstanceDependencyCopier;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use Throwable;

/**
 * Runs `cp -a --reflink=auto` as the Node's managed user, who owns both checkouts. Each directory
 * is copied to a staging path beside the target checkout and renamed into place, so it is complete
 * or absent. A directory the source lacks, a symlinked source directory, or a directory the target
 * already has is skipped. `timeout` stops the copy on the Node after TIME_LIMIT seconds, and the
 * program then removes its staging path.
 */
final readonly class RemoteInstanceDependencyCopier implements InstanceDependencyCopier
{
    /** Seconds the copy may take on the Node. A slower copy is stopped, and setup installs instead. */
    public const int TIME_LIMIT = 120;

    public const string Program = <<<'SH'
        set -eu
        source=$1
        target=$2
        shift 2
        staging=
        trap '[ -z "$staging" ] || rm -rf -- "$staging"; exit 1' HUP INT TERM
        [ -d "$target" ] && [ ! -L "$target" ] || exit 1
        for directory in "$@"; do
            staging="${target%/*}/.orbit-copy.${target##*/}.$directory"
            rm -rf -- "$staging"
            if [ -L "$source/$directory" ] || [ ! -d "$source/$directory" ] || [ -e "$target/$directory" ] || [ -L "$target/$directory" ]; then
                continue
            fi
            if ! cp -a --reflink=auto -- "$source/$directory" "$staging" || ! mv -T -- "$staging" "$target/$directory"; then
                rm -rf -- "$staging"
                exit 1
            fi
        done
        echo OK
        SH;

    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
    ) {}

    public function copy(Instance $source, Instance $target): void
    {
        $node = $target->node;
        $host = $node->wireguard_ip;

        if ($source->node_id !== $node->id || ! is_string($host) || $host === '') {
            throw $this->failed();
        }

        try {
            $result = $this->ssh->execute(
                new SshConnection(
                    host: $host,
                    user: $node->user,
                    port: 22,
                    identityFile: $this->keys->privateKeyPath(),
                    knownHostsFile: $this->knownHosts->path(),
                ),
                new RemoteCommand(
                    [
                        'timeout', '-k', '5', (string) self::TIME_LIMIT,
                        'sh', '-c', self::Program, 'sh', $source->checkout_path, $target->checkout_path, ...self::DIRECTORIES,
                    ],
                    maxOutputBytes: 4096,
                    timeout: self::TIME_LIMIT + 15.0,
                ),
            );
        } catch (Throwable $exception) {
            throw $this->failed($exception);
        }

        $this->expectOk($result);
    }

    private function expectOk(CommandResult $result): void
    {
        if (! $result->succeeded() || $result->truncated || $result->stdout !== "OK\n") {
            throw $this->failed(details: [
                'exit_code' => (string) $result->exitCode,
                'stderr' => mb_substr(trim($result->stderr), -1000),
            ]);
        }
    }

    /** @param  array<string, string>  $details */
    private function failed(?Throwable $previous = null, array $details = []): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'instance.dependency_copy_failed',
            message: 'The dependency directories could not be copied.',
            status: 502,
            previous: $previous,
            details: $details,
        );
    }
}
