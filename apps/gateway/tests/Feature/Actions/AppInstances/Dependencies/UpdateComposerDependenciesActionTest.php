<?php

declare(strict_types=1);

use App\Actions\AppInstances\Dependencies\UpdateComposerDependenciesAction;
use App\Domain\AppInstances\Dependencies\DependencyEcosystem;
use App\Domain\AppInstances\Dependencies\DependencyUpdateStepStatus;
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
use App\Models\Node;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

use function Pest\Laravel\mock;

function composer_update_instance(bool $production = false, string $path = '/home/orbit/project'): AppInstance
{
    $instance = new AppInstance([
        'environment' => $production ? 'production' : 'development',
        'source_layout' => 'checkout',
        'checkout_path' => $path,
        'production_user' => $production ? 'app_sample' : null,
        'production_home' => $production ? '/home/app_sample' : null,
        'root' => 'public',
        'migration_required' => false,
    ]);
    $instance->setRelation('node', new Node(['user' => 'orbit', 'wireguard_ip' => '10.44.0.2']));

    return $instance;
}

function composer_update_keys(): void
{
    mock(SshKeyProvider::class)->shouldReceive('privateKeyPath')->andReturn('/keys/private');
    mock(KnownHostsStore::class)->shouldReceive('path')->andReturn('/keys/known_hosts');
}

/**
 * @param  list<array{0: callable(SshConnection, RemoteCommand): bool, 1: CommandResult|Throwable}>  $calls
 */
function composer_update_ssh(array $calls): void
{
    composer_update_keys();
    $index = 0;
    mock(SshExecutor::class)->shouldReceive('execute')->times(count($calls))->andReturnUsing(
        function (SshConnection $connection, RemoteCommand $command) use (&$index, $calls): CommandResult {
            [$assert, $result] = $calls[$index];
            $index++;
            expect($assert($connection, $command))->toBeTrue();
            if ($result instanceof Throwable) {
                throw $result;
            }

            return $result;
        },
    );
}

function composer_update_probe_command(RemoteCommand $command, string $path = '/home/orbit/project'): bool
{
    expect($command->arguments)->toBe(['/usr/bin/python3', '-I', '-', $path]);
    expect($command->input)->toBe(ComposerDependencyUpdatePresenceProgram::render());
    expect($command->timeout)->toBe(30.0);
    expect($command->maxOutputBytes)->toBe(65_536);

    return true;
}

function composer_update_command(RemoteCommand $command, bool $expectCancelled = false, string $path = '/home/orbit/project'): bool
{
    expect($command->arguments)->toBe([
        '/usr/bin/setsid',
        '--wait',
        '/usr/bin/bash',
        '-eu',
        '-c',
        ComposerDependencyUpdateProgram::render(),
        'composer-update',
        $path,
        '600',
    ]);
    expect($command->arguments)->not->toContain('--no-dev');
    expect($command->arguments)->not->toContain('--latest');
    expect($command->input)->toBeNull();
    expect($command->protectedInput)->toBeInstanceOf(ProtectedInput::class);
    expect($command->arguments[5])->toContain('/usr/bin/composer --working-dir "$root" update --no-interaction --no-ansi --no-progress --no-audit');
    expect($command->arguments[5])->not->toContain('--no-dev');
    expect($command->arguments[5])->not->toContain('--latest');
    expect($command->timeout)->toBe(610.0);
    expect($command->terminateGraceSeconds)->toBe(2.0);
    expect($command->maxOutputBytes)->toBe(8 * 1024 * 1024);
    if ($expectCancelled) {
        expect($command->cancelled)->not->toBeNull();
    }

    return true;
}

describe('bounded Composer dependency updates', function (): void {
    it('runs composer update as the instance user in the recorded root within declared constraints', function (): void {
        composer_update_ssh([
            [
                function (SshConnection $connection, RemoteCommand $command): bool {
                    expect($connection->host)->toBe('10.44.0.2');
                    expect($connection->user)->toBe('orbit');
                    expect($connection->identityFile)->toBe('/keys/private');
                    expect($connection->knownHostsFile)->toBe('/keys/known_hosts');

                    return composer_update_probe_command($command);
                },
                new CommandResult(0, '{"status":"present"}', '', 1, false),
            ],
            [
                function (SshConnection $connection, RemoteCommand $command): bool {
                    expect($connection->user)->toBe('orbit');

                    return composer_update_command($command);
                },
                new CommandResult(0, 'secret package output', 'secret stderr', 12, false),
            ],
        ]);

        $result = app(UpdateComposerDependenciesAction::class)->execute(composer_update_instance());

        expect($result->ecosystem)->toBe(DependencyEcosystem::Composer);
        expect($result->status)->toBe(DependencyUpdateStepStatus::Succeeded);
        expect($result->mayHaveMutated)->toBeTrue();
        expect($result->errorCode)->toBeNull();
        expect($result->completed())->toBeTrue();
    });

    it('runs Composer in a recorded root that contains a space', function (): void {
        $path = '/home/orbit/project spaced';
        composer_update_ssh([
            [
                fn (SshConnection $connection, RemoteCommand $command): bool => composer_update_probe_command($command, $path),
                new CommandResult(0, '{"status":"present"}', '', 1, false),
            ],
            [
                fn (SshConnection $connection, RemoteCommand $command): bool => composer_update_command($command, path: $path),
                new CommandResult(0, '', '', 12, false),
            ],
        ]);

        $result = app(UpdateComposerDependenciesAction::class)->execute(composer_update_instance(path: $path));

        expect($result->status)->toBe(DependencyUpdateStepStatus::Succeeded);
        expect($result->mayHaveMutated)->toBeTrue();
        expect($result->errorCode)->toBeNull();
    });

    it('skips Composer when both root files are absent', function (): void {
        composer_update_ssh([
            [
                fn (SshConnection $connection, RemoteCommand $command): bool => composer_update_probe_command($command),
                new CommandResult(0, '{"status":"absent"}', '', 1, false),
            ],
        ]);

        $result = app(UpdateComposerDependenciesAction::class)->execute(composer_update_instance());

        expect($result->status)->toBe(DependencyUpdateStepStatus::Absent);
        expect($result->mayHaveMutated)->toBeFalse();
        expect($result->errorCode)->toBeNull();
        expect($result->completed())->toBeTrue();
    });

    it('refuses production before SSH', function (): void {
        mock(SshExecutor::class)->shouldNotReceive('execute');

        $result = app(UpdateComposerDependenciesAction::class)->execute(composer_update_instance(true));

        expect($result->status)->toBe(DependencyUpdateStepStatus::Failed);
        expect($result->errorCode)->toBe('dependencies.production_update_forbidden');
        expect($result->mayHaveMutated)->toBeFalse();
    });

    it('rejects unsafe development identity before SSH', function (array $attributes): void {
        mock(SshExecutor::class)->shouldNotReceive('execute');
        $instance = composer_update_instance();
        $instance->forceFill($attributes);

        $result = app(UpdateComposerDependenciesAction::class)->execute($instance);

        expect($result->errorCode)->toBe('dependencies.unsafe_source');
        expect($result->mayHaveMutated)->toBeFalse();
    })->with([
        [['checkout_path' => 'relative']],
        [['source_layout' => 'nested']],
        [['migration_required' => true]],
        [['environment' => 'staging']],
    ]);

    it('fails incomplete Composer sources without mutation', function (): void {
        composer_update_ssh([
            [
                fn (SshConnection $connection, RemoteCommand $command): bool => composer_update_probe_command($command),
                new CommandResult(0, '{"status":"incomplete"}', '', 1, false),
            ],
        ]);

        $result = app(UpdateComposerDependenciesAction::class)->execute(composer_update_instance());

        expect($result->status)->toBe(DependencyUpdateStepStatus::Failed);
        expect($result->errorCode)->toBe('dependencies.incomplete_source');
        expect($result->mayHaveMutated)->toBeFalse();
    });

    it('preserves Composer failure without a rollback claim', function (): void {
        composer_update_ssh([
            [
                fn (SshConnection $connection, RemoteCommand $command): bool => composer_update_probe_command($command),
                new CommandResult(0, '{"status":"present"}', '', 1, false),
            ],
            [
                fn (SshConnection $connection, RemoteCommand $command): bool => composer_update_command($command),
                new CommandResult(1, 'lock changed', 'solver failed', 8, false),
            ],
        ]);

        $result = app(UpdateComposerDependenciesAction::class)->execute(composer_update_instance());

        expect($result->status)->toBe(DependencyUpdateStepStatus::Failed);
        expect($result->errorCode)->toBe('dependencies.update_failed');
        expect($result->mayHaveMutated)->toBeTrue();
        expect($result->completed())->toBeFalse();
    });

    it('reports cancellation during Composer without restoring source', function (): void {
        composer_update_ssh([
            [
                fn (SshConnection $connection, RemoteCommand $command): bool => composer_update_probe_command($command),
                new CommandResult(0, '{"status":"present"}', '', 1, false),
            ],
            [
                function (SshConnection $connection, RemoteCommand $command): bool {
                    expect($command->cancelled)->not->toBeNull();
                    expect(($command->cancelled)())->toBeTrue();

                    return composer_update_command($command, true);
                },
                new ProcessCancelledException,
            ],
        ]);

        $result = app(UpdateComposerDependenciesAction::class)->execute(composer_update_instance(), fn (): bool => true);

        expect($result->errorCode)->toBe('dependencies.update_cancelled');
        expect($result->mayHaveMutated)->toBeTrue();
    });

    it('reports probe cancellation without mutation', function (): void {
        composer_update_ssh([
            [
                function (SshConnection $connection, RemoteCommand $command): bool {
                    expect($command->cancelled)->not->toBeNull();

                    return composer_update_probe_command($command);
                },
                new ProcessCancelledException,
            ],
        ]);

        $result = app(UpdateComposerDependenciesAction::class)->execute(composer_update_instance(), fn (): bool => true);

        expect($result->errorCode)->toBe('dependencies.update_cancelled');
        expect($result->mayHaveMutated)->toBeFalse();
    });

    it('maps a remote Composer deadline to a timeout without restoring source', function (): void {
        composer_update_ssh([
            [
                fn (SshConnection $connection, RemoteCommand $command): bool => composer_update_probe_command($command),
                new CommandResult(0, '{"status":"present"}', '', 1, false),
            ],
            [
                fn (SshConnection $connection, RemoteCommand $command): bool => composer_update_command($command),
                new CommandResult(124, '', '', 600_000, false),
            ],
        ]);

        $result = app(UpdateComposerDependenciesAction::class)->execute(composer_update_instance());

        expect($result->errorCode)->toBe('dependencies.update_timeout');
        expect($result->mayHaveMutated)->toBeTrue();
    });

    it('maps a remote Composer supervisor cancellation without restoring source', function (): void {
        composer_update_ssh([
            [
                fn (SshConnection $connection, RemoteCommand $command): bool => composer_update_probe_command($command),
                new CommandResult(0, '{"status":"present"}', '', 1, false),
            ],
            [
                fn (SshConnection $connection, RemoteCommand $command): bool => composer_update_command($command),
                new CommandResult(143, '', '', 2_000, false),
            ],
        ]);

        $result = app(UpdateComposerDependenciesAction::class)->execute(composer_update_instance());

        expect($result->errorCode)->toBe('dependencies.update_cancelled');
        expect($result->mayHaveMutated)->toBeTrue();
    });

    it('reports Composer timeout as a possible mutation', function (): void {
        composer_update_ssh([
            [
                fn (SshConnection $connection, RemoteCommand $command): bool => composer_update_probe_command($command),
                new CommandResult(0, '{"status":"present"}', '', 1, false),
            ],
            [
                fn (SshConnection $connection, RemoteCommand $command): bool => composer_update_command($command),
                new ProcessTimedOutException(new Process(['true']), ProcessTimedOutException::TYPE_GENERAL),
            ],
        ]);

        $result = app(UpdateComposerDependenciesAction::class)->execute(composer_update_instance());

        expect($result->errorCode)->toBe('dependencies.update_timeout');
        expect($result->mayHaveMutated)->toBeTrue();
    });

    it('treats truncated Composer output as a failed mutation', function (): void {
        composer_update_ssh([
            [
                fn (SshConnection $connection, RemoteCommand $command): bool => composer_update_probe_command($command),
                new CommandResult(0, '{"status":"present"}', '', 1, false),
            ],
            [
                fn (SshConnection $connection, RemoteCommand $command): bool => composer_update_command($command),
                new CommandResult(0, str_repeat('a', 100), '', 3, true),
            ],
        ]);

        $result = app(UpdateComposerDependenciesAction::class)->execute(composer_update_instance());

        expect($result->errorCode)->toBe('dependencies.update_failed');
        expect($result->mayHaveMutated)->toBeTrue();
    });

    it('maps probe errors without Composer and without process text', function (): void {
        composer_update_ssh([
            [
                fn (SshConnection $connection, RemoteCommand $command): bool => composer_update_probe_command($command),
                new CommandResult(0, '{"error":"dependencies.unreadable_source"}', 'password=secret', 1, false),
            ],
        ]);

        $result = app(UpdateComposerDependenciesAction::class)->execute(composer_update_instance());

        expect($result->errorCode)->toBe('dependencies.unreadable_source');
        expect($result->mayHaveMutated)->toBeFalse();
    });
});
