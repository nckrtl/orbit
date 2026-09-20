<?php

declare(strict_types=1);

namespace App\Actions\AppInstances\Dependencies;

use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\Dependencies\DependencyEcosystem;
use App\Domain\AppInstances\Dependencies\DependencyUpdateInspection;
use App\Domain\AppInstances\Dependencies\DependencyUpdateStepResult;
use App\Infrastructure\AppInstances\BunDependencyUpdatePresenceProgram;
use App\Infrastructure\AppInstances\BunDependencyUpdateProgram;
use App\Infrastructure\Processes\ProcessCancelledException;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\AppInstance;
use Closure;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

final readonly class UpdateBunDependenciesAction
{
    /** Vite+ versions verified to delegate constrained bun updates through `vp update --no-save`. */
    private const array SUPPORTED_VITE_PLUS_VERSIONS = ['0.3.0'];

    private const string VITE_PLUS_LAUNCHER = '/usr/local/bin/vp';

    private const string VITE_PLUS_OPT_BINARY = '/opt/orbit/vite-plus/bin/vp';

    /** @var list<string> */
    private const array VITE_PLUS_HOME_SUFFIXES = [
        '/.vite-plus/bin/vp',
        '/.vite-plus/current/bin/vp',
        '/.local/share/vite-plus/bin/vp',
        '/.local/share/vite-plus/current/bin/vp',
    ];

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
        if ($instance->placedOnAppProd()) {
            return DependencyUpdateInspection::failed('dependencies.production_update_forbidden');
        }

        $connection = $this->connection($instance);
        if ($connection === null) {
            return DependencyUpdateInspection::failed('dependencies.unsafe_source');
        }

        try {
            $probe = $this->ssh->execute($connection, new RemoteCommand(
                arguments: ['/usr/bin/python3', '-I', '-', $instance->checkout_path],
                input: BunDependencyUpdatePresenceProgram::render(),
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

        $status = $probeResult['status'];
        if ($status !== 'present') {
            return match ($status) {
                'absent' => DependencyUpdateInspection::absent(),
                default => DependencyUpdateInspection::failed('dependencies.incomplete_source'),
            };
        }

        $vitePlus = $probeResult['vp'];
        if ($vitePlus === null) {
            return DependencyUpdateInspection::failed('dependencies.unsupported_delegation');
        }

        [$vpPath, $vpVersion] = $vitePlus;
        if (! in_array($vpVersion, self::SUPPORTED_VITE_PLUS_VERSIONS, true)) {
            return DependencyUpdateInspection::failed('dependencies.unsupported_delegation');
        }

        return DependencyUpdateInspection::ready($vpPath);
    }

    /** @param  (Closure(): bool)|null  $cancelled */
    public function execute(AppInstance $instance, ?Closure $cancelled = null): DependencyUpdateStepResult
    {
        $inspection = $this->inspect($instance, $cancelled);
        if ($inspection->errorCode !== null) {
            return $this->failed($inspection->errorCode, false);
        }
        if (! $inspection->present) {
            return DependencyUpdateStepResult::absent(DependencyEcosystem::Npm);
        }
        if ($inspection->toolPath === null) {
            return $this->failed('dependencies.unsupported_delegation', false);
        }

        return $this->apply($instance, $inspection->toolPath, $cancelled);
    }

    /** @param  (Closure(): bool)|null  $cancelled */
    public function apply(AppInstance $instance, string $vpPath, ?Closure $cancelled = null): DependencyUpdateStepResult
    {
        $connection = $this->connection($instance);
        if ($connection === null) {
            return $this->failed('dependencies.unsafe_source', false);
        }

        try {
            $result = $this->ssh->execute($connection, new RemoteCommand(

                arguments: [
                    '/usr/bin/setsid',
                    '--wait',
                    '/usr/bin/bash',
                    '-eu',
                    '-c',
                    BunDependencyUpdateProgram::render(),
                    'bun-update',
                    $instance->checkout_path,
                    $vpPath,
                    (string) BunDependencyUpdateProgram::DeadlineSeconds,
                ],
                protectedInput: ProtectedInput::holdOpen(),
                maxOutputBytes: 8 * 1024 * 1024,
                cancelled: $cancelled,
                timeout: BunDependencyUpdateProgram::SshTimeoutSeconds,
                terminateGraceSeconds: BunDependencyUpdateProgram::TerminateGraceSeconds,
            ));
        } catch (ProcessCancelledException) {
            return $this->failed('dependencies.update_cancelled', true);
        } catch (ProcessTimedOutException) {
            return $this->failed('dependencies.update_timeout', true);
        } catch (Throwable) {
            return $this->failed('dependencies.update_failed', true);
        }

        if ($result->truncated) {
            return $this->failed('dependencies.update_failed', true);
        }

        if ($result->exitCode === 124) {
            return $this->failed('dependencies.update_timeout', true);
        }

        if ($result->exitCode === 143) {
            return $this->failed('dependencies.update_cancelled', true);
        }

        if (! $result->succeeded()) {
            return $this->failed('dependencies.update_failed', true);
        }

        return DependencyUpdateStepResult::succeeded(DependencyEcosystem::Npm);
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

    /** @return array{error: ?string, status: ?string, vp: ?array{0: string, 1: string}} */
    private function probeResult(?string $stdout): array
    {
        $invalid = ['error' => 'dependencies.unreadable_source', 'status' => null, 'vp' => null];
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
                ? ['error' => $payload['error'], 'status' => null, 'vp' => null]
                : $invalid;
        }

        $status = $payload['status'] ?? null;
        if (in_array($status, ['absent', 'incomplete'], true)) {
            return count($payload) === 1
                ? ['error' => null, 'status' => $status, 'vp' => null]
                : $invalid;
        }

        if ($status !== 'present' || count($payload) !== 2 || ! array_key_exists('vp', $payload)) {
            return $invalid;
        }

        $vp = $payload['vp'];
        if ($vp === null) {
            return ['error' => null, 'status' => 'present', 'vp' => null];
        }

        if (! is_array($vp) || count($vp) !== 2
            || ! is_string($vp['path'] ?? null) || ! is_string($vp['version'] ?? null)
            || ! $this->managedVitePlusPath($vp['path'])
            || preg_match('/\A(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\z/D', $vp['version']) !== 1) {
            return $invalid;
        }

        return ['error' => null, 'status' => 'present', 'vp' => [$vp['path'], $vp['version']]];
    }

    private function failed(string $errorCode, bool $mayHaveMutated): DependencyUpdateStepResult
    {
        return DependencyUpdateStepResult::failed(DependencyEcosystem::Npm, $errorCode, $mayHaveMutated);
    }

    private function managedVitePlusPath(string $path): bool
    {
        if (! str_starts_with($path, '/') || str_contains($path, "\0") || preg_match('/[[:cntrl:]]/', $path) === 1) {
            return false;
        }

        if ($path === self::VITE_PLUS_LAUNCHER || $path === self::VITE_PLUS_OPT_BINARY) {
            return true;
        }

        return array_any(self::VITE_PLUS_HOME_SUFFIXES, fn (string $suffix): bool => str_ends_with($path, $suffix));
    }
}
