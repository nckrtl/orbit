<?php

declare(strict_types=1);

namespace App\Actions\AppInstances\Dependencies;

use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\Dependencies\DependencyEcosystem;
use App\Domain\AppInstances\Dependencies\DependencyUpdateInspection;
use App\Domain\AppInstances\Dependencies\DependencyUpdateStepResult;
use App\Infrastructure\AppInstances\YarnDependencyUpdatePresenceProgram;
use App\Infrastructure\Processes\ProcessCancelledException;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\AppInstance;
use Closure;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

final readonly class UpdateYarnDependenciesAction
{
    private const array PROBE_ERRORS = [
        'dependencies.unsafe_source',
        'dependencies.unreadable_source',
        'dependencies.invalid_manifest',
        'dependencies.unsupported_format',
        'dependencies.unsupported_layout',
        'dependencies.ambiguous_manager',
    ];

    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
    ) {}

    /** @param  (Closure(): bool)|null  $cancelled */
    public function inspect(AppInstance $instance, ?Closure $cancelled = null): DependencyUpdateInspection
    {
        if ($instance->environment === 'production') {
            return DependencyUpdateInspection::failed('dependencies.production_update_forbidden');
        }

        $connection = $this->connection($instance);
        if ($connection === null) {
            return DependencyUpdateInspection::failed('dependencies.unsafe_source');
        }

        try {
            $probe = $this->ssh->execute($connection, new RemoteCommand(
                arguments: ['/usr/bin/python3', '-I', '-', $instance->checkout_path],
                input: YarnDependencyUpdatePresenceProgram::render(),
                maxOutputBytes: 65_536,
                cancelled: $cancelled,
                timeout: 45.0,
            ));
        } catch (ProcessCancelledException) {
            return DependencyUpdateInspection::failed('dependencies.update_cancelled');
        } catch (ProcessTimedOutException) {
            return DependencyUpdateInspection::failed('dependencies.update_timeout');
        } catch (Throwable) {
            return DependencyUpdateInspection::failed('dependencies.unreadable_source');
        }

        $probeResult = $this->probeResult($probe->succeeded() && ! $probe->truncated && $probe->stderr === '' ? $probe->stdout : null);
        if ($probeResult['error'] !== null) {
            return DependencyUpdateInspection::failed($probeResult['error']);
        }

        if ($probeResult['status'] !== 'present') {
            return DependencyUpdateInspection::absent();
        }

        return DependencyUpdateInspection::failed('dependencies.unsupported_format');
    }

    /** @param  (Closure(): bool)|null  $cancelled */
    public function execute(AppInstance $instance, ?Closure $cancelled = null): DependencyUpdateStepResult
    {
        $inspection = $this->inspect($instance, $cancelled);
        if ($inspection->errorCode !== null) {
            return $this->failed($inspection->errorCode, false);
        }

        return DependencyUpdateStepResult::absent(DependencyEcosystem::Npm);
    }

    private function connection(AppInstance $instance): ?SshConnection
    {
        $node = $instance->node;
        $path = $instance->checkout_path;
        $user = $node->user;
        if ($instance->environment !== 'development'
            || ! in_array($instance->source_layout, array_column(AppInstanceSourceLayout::cases(), 'value'), true)
            || $instance->migration_required
            || ! str_starts_with($path, '/') || str_contains($path, "\0")
            || preg_match('/\A[a-z_][a-z0-9_-]*\z/D', $user) !== 1
            || ! is_string($node->wireguard_ip) || filter_var($node->wireguard_ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return new SshConnection(
            host: $node->wireguard_ip,
            user: $user,
            port: 22,
            identityFile: $this->keys->privateKeyPath(),
            knownHostsFile: $this->knownHosts->path(),
        );
    }

    /** @return array{error: ?string, status: ?string} */
    private function probeResult(?string $stdout): array
    {
        $invalid = ['error' => 'dependencies.unreadable_source', 'status' => null];
        if ($stdout === null) {
            return $invalid;
        }

        try {
            $payload = json_decode($stdout, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $invalid;
        }

        if (! is_array($payload) || $payload === []) {
            return $invalid;
        }

        if (isset($payload['error'])) {
            return count($payload) === 1 && in_array($payload['error'], self::PROBE_ERRORS, true)
                ? ['error' => $payload['error'], 'status' => null]
                : $invalid;
        }

        $status = $payload['status'] ?? null;
        if ($status === 'absent') {
            return count($payload) === 1
                ? ['error' => null, 'status' => 'absent']
                : $invalid;
        }

        if ($status !== 'present' || count($payload) !== 2 || ! in_array($payload['family'] ?? null, ['classic', 'modern'], true)) {
            return $invalid;
        }

        return ['error' => null, 'status' => 'present'];
    }

    private function failed(string $errorCode, bool $mayHaveMutated): DependencyUpdateStepResult
    {
        return DependencyUpdateStepResult::failed(DependencyEcosystem::Npm, $errorCode, $mayHaveMutated);
    }
}
