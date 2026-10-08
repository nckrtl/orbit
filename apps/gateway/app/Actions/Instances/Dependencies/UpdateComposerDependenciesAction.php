<?php

declare(strict_types=1);

namespace App\Actions\Instances\Dependencies;

use App\Domain\Instances\Dependencies\DependencyEcosystem;
use App\Domain\Instances\Dependencies\DependencyUpdateInspection;
use App\Domain\Instances\Dependencies\DependencyUpdateStepResult;
use App\Domain\Instances\InstanceSandboxGuard;
use App\Domain\Instances\InstanceSourceLayout;
use App\Infrastructure\Instances\ComposerDependencyUpdatePresenceProgram;
use App\Infrastructure\Instances\ComposerDependencyUpdateProgram;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessCancelledException;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use Closure;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

final readonly class UpdateComposerDependenciesAction
{
    private const array PROBE_ERRORS = ['dependencies.unsafe_source', 'dependencies.unreadable_source'];

    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
    ) {}

    /** @param  (Closure(): bool)|null  $cancelled */
    public function inspect(Instance $instance, ?Closure $cancelled = null): DependencyUpdateInspection
    {
        InstanceSandboxGuard::assertHostOperation($instance);
        if ($instance->placedOnAppProd()) {
            return DependencyUpdateInspection::failed('dependencies.production_update_forbidden');
        }

        $connection = $this->connection($instance);
        if ($connection === null) {
            return DependencyUpdateInspection::failed('dependencies.unsafe_source');
        }

        try {
            $probe = $this->ssh->execute($connection, new RemoteCommand(
                arguments: ['/usr/bin/python3', '-I', '-', $instance->dependencyDirectory()],
                input: ComposerDependencyUpdatePresenceProgram::render(),
                maxOutputBytes: 65_536,
                cancelled: $cancelled,
                timeout: 30.0,
            ));
        } catch (ProcessCancelledException) {
            return DependencyUpdateInspection::failed('dependencies.update_cancelled');
        } catch (ProcessTimedOutException) {
            return DependencyUpdateInspection::failed('dependencies.update_timeout');
        } catch (Throwable) {
            return DependencyUpdateInspection::failed('dependencies.unreadable_source');
        }

        $status = $this->probeStatus($probe);

        return match ($status) {
            'present' => DependencyUpdateInspection::ready(),
            'absent' => DependencyUpdateInspection::absent(),
            'incomplete' => DependencyUpdateInspection::failed('dependencies.incomplete_source'),
            default => DependencyUpdateInspection::failed($status),
        };
    }

    /** @param  (Closure(): bool)|null  $cancelled */
    public function execute(Instance $instance, ?Closure $cancelled = null): DependencyUpdateStepResult
    {
        InstanceSandboxGuard::assertHostOperation($instance);
        $inspection = $this->inspect($instance, $cancelled);
        if ($inspection->errorCode !== null) {
            return $this->failed($inspection->errorCode, false);
        }
        if (! $inspection->present) {
            return DependencyUpdateStepResult::absent(DependencyEcosystem::Composer);
        }

        return $this->apply($instance, $cancelled);
    }

    /** @param  (Closure(): bool)|null  $cancelled */
    public function apply(Instance $instance, ?Closure $cancelled = null): DependencyUpdateStepResult
    {
        InstanceSandboxGuard::assertHostOperation($instance);
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
                    ComposerDependencyUpdateProgram::render(),
                    'composer-update',
                    $instance->dependencyDirectory(),
                    (string) ComposerDependencyUpdateProgram::DeadlineSeconds,
                ],
                protectedInput: ProtectedInput::holdOpen(),
                maxOutputBytes: 8 * 1024 * 1024,
                cancelled: $cancelled,
                timeout: ComposerDependencyUpdateProgram::SshTimeoutSeconds,
                terminateGraceSeconds: ComposerDependencyUpdateProgram::TerminateGraceSeconds,
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

        return DependencyUpdateStepResult::succeeded(DependencyEcosystem::Composer);
    }

    private function connection(Instance $instance): ?SshConnection
    {
        $node = $instance->node;
        $path = $instance->dependencyDirectory();
        $user = $node->user;
        if (! $instance->placedOnAppDev()
            || ! in_array($instance->source_layout, array_column(InstanceSourceLayout::cases(), 'value'), true)
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

    private function probeStatus(CommandResult $result): string
    {
        if (! $result->succeeded() || $result->truncated || $result->stderr !== '') {
            return 'dependencies.unreadable_source';
        }

        try {
            $payload = json_decode($result->stdout, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return 'dependencies.unreadable_source';
        }

        if (! is_array($payload) || count($payload) !== 1) {
            return 'dependencies.unreadable_source';
        }

        if (isset($payload['error']) && in_array($payload['error'], self::PROBE_ERRORS, true)) {
            return $payload['error'];
        }

        $status = $payload['status'] ?? null;
        if (in_array($status, ['present', 'absent', 'incomplete'], true)) {
            return $status;
        }

        return 'dependencies.unreadable_source';
    }

    private function failed(string $errorCode, bool $mayHaveMutated): DependencyUpdateStepResult
    {
        return DependencyUpdateStepResult::failed(DependencyEcosystem::Composer, $errorCode, $mayHaveMutated);
    }
}
