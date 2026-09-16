<?php

declare(strict_types=1);

namespace App\Actions\AppInstances\Dependencies;

use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\Dependencies\DependencyEcosystem;
use App\Domain\AppInstances\Dependencies\DependencyUpdateStepResult;
use App\Infrastructure\AppInstances\ComposerDependencyUpdatePresenceProgram;
use App\Infrastructure\AppInstances\ComposerDependencyUpdateProgram;
use App\Infrastructure\Processes\CommandResult;
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

final readonly class UpdateComposerDependenciesAction
{
    private const array PROBE_ERRORS = ['dependencies.unsafe_source', 'dependencies.unreadable_source'];

    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
    ) {}

    /** @param  (Closure(): bool)|null  $cancelled */
    public function execute(AppInstance $instance, ?Closure $cancelled = null): DependencyUpdateStepResult
    {
        if ($instance->environment === 'production') {
            return $this->failed('dependencies.production_update_forbidden', false);
        }

        $node = $instance->node;
        $path = $instance->checkout_path;
        $user = $node->user;
        if ($instance->environment !== 'development'
            || ! in_array($instance->source_layout, array_column(AppInstanceSourceLayout::cases(), 'value'), true)
            || $instance->migration_required
            || ! str_starts_with($path, '/') || str_contains($path, "\0")
            || preg_match('/\A[a-z_][a-z0-9_-]*\z/D', $user) !== 1
            || ! is_string($node->wireguard_ip) || filter_var($node->wireguard_ip, FILTER_VALIDATE_IP) === false) {
            return $this->failed('dependencies.unsafe_source', false);
        }

        $connection = new SshConnection(
            host: $node->wireguard_ip,
            user: $user,
            port: 22,
            identityFile: $this->keys->privateKeyPath(),
            knownHostsFile: $this->knownHosts->path(),
        );

        try {
            $probe = $this->ssh->execute($connection, new RemoteCommand(
                arguments: ['/usr/bin/python3', '-I', '-', $path],
                input: ComposerDependencyUpdatePresenceProgram::render(),
                maxOutputBytes: 65_536,
                cancelled: $cancelled,
                timeout: 30.0,
            ));
        } catch (ProcessCancelledException) {
            return $this->failed('dependencies.update_cancelled', false);
        } catch (ProcessTimedOutException) {
            return $this->failed('dependencies.update_timeout', false);
        } catch (Throwable) {
            return $this->failed('dependencies.unreadable_source', false);
        }

        $status = $this->probeStatus($probe);
        if ($status !== 'present') {
            return match ($status) {
                'absent' => DependencyUpdateStepResult::absent(DependencyEcosystem::Composer),
                'incomplete' => $this->failed('dependencies.incomplete_source', false),
                default => $this->failed($status, false),
            };
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
                    $path,
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
