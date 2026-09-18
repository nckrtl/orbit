<?php

declare(strict_types=1);

use App\Actions\AppInstances\Dependencies\UpdateBunDependenciesAction;
use App\Domain\AppInstances\Dependencies\DependencyEcosystem;
use App\Domain\AppInstances\Dependencies\DependencyUpdateStepStatus;
use App\Infrastructure\AppInstances\BunDependencyUpdatePresenceProgram;
use App\Infrastructure\AppInstances\BunDependencyUpdateProgram;
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

const BUN_UPDATE_VP_PATH = '/home/orbit/.local/share/vite-plus/bin/vp';

function bun_update_instance(bool $production = false, string $path = '/home/orbit/project'): AppInstance
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

function bun_update_keys(): void
{
    mock(SshKeyProvider::class)->shouldReceive('privateKeyPath')->andReturn('/keys/private');
    mock(KnownHostsStore::class)->shouldReceive('path')->andReturn('/keys/known_hosts');
}

/**
 * @param  list<array{0: callable(SshConnection, RemoteCommand): bool, 1: CommandResult|Throwable}>  $calls
 */
function bun_update_ssh(array $calls): void
{
    bun_update_keys();
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

function bun_update_never_ssh(): void
{
    bun_update_keys();
    mock(SshExecutor::class)->shouldReceive('execute')->never();
}

function bun_update_probe_command(RemoteCommand $command, string $path = '/home/orbit/project'): bool
{
    expect($command->arguments)->toBe(['/usr/bin/python3', '-I', '-', $path]);
    expect($command->input)->toBe(BunDependencyUpdatePresenceProgram::render());
    expect($command->timeout)->toBe(45.0);
    expect($command->maxOutputBytes)->toBe(65_536);

    return true;
}

function bun_update_update_command(RemoteCommand $command, bool $expectCancelled = false, string $path = '/home/orbit/project', string $vpPath = BUN_UPDATE_VP_PATH): bool
{
    expect($command->arguments)->toBe([
        '/usr/bin/setsid',
        '--wait',
        '/usr/bin/bash',
        '-eu',
        '-c',
        BunDependencyUpdateProgram::render(),
        'bun-update',
        $path,
        $vpPath,
        '600',
    ]);
    expect($command->input)->toBeNull();
    expect($command->protectedInput)->toBeInstanceOf(ProtectedInput::class);
    expect($command->arguments[5])->toContain('/usr/bin/setsid --wait "$vp" update --no-save -- --lockfile-only --save-text-lockfile </dev/null');
    expect($command->arguments[5])->toContain('export VP_HOME=/opt/orbit/vite-plus');
    expect($command->arguments[5])->toContain('--no-save');
    expect($command->arguments[5])->toContain('--lockfile-only');
    expect($command->arguments[5])->toContain('--save-text-lockfile');
    expect($command->arguments[5])->not->toContain('--latest');
    expect($command->arguments[5])->not->toContain('--no-optional');
    expect($command->arguments[5])->not->toContain('--recursive');
    expect($command->arguments[5])->not->toContain('--filter');
    expect($command->arguments[5])->not->toContain('--global');
    expect($command->arguments[5])->not->toContain('--interactive');
    expect($command->arguments[5])->not->toContain('--workspace');
    expect($command->arguments[5])->not->toContain('/usr/bin/npm');
    expect($command->arguments[5])->not->toContain('/usr/bin/pnpm');
    expect($command->arguments[5])->not->toContain('/usr/bin/bun');
    expect($command->arguments)->not->toContain('npm');
    expect($command->arguments)->not->toContain('pnpm');
    expect($command->arguments)->not->toContain('bun');
    expect($command->arguments[5])->toBe(BunDependencyUpdateProgram::render());
    expect($command->timeout)->toBe(610.0);
    expect($command->terminateGraceSeconds)->toBe(2.0);
    expect($command->maxOutputBytes)->toBe(8 * 1024 * 1024);
    if ($expectCancelled) {
        expect($command->cancelled)->not->toBeNull();
    }

    return true;
}

function bun_update_probe_present(string $version = '0.3.0', string $vpPath = BUN_UPDATE_VP_PATH): CommandResult
{
    $vp = $version === ''
        ? 'null'
        : json_encode(['path' => $vpPath, 'version' => $version], JSON_THROW_ON_ERROR);

    return new CommandResult(0, '{"status":"present","vp":'.$vp.'}', '', 1, false);
}

describe('bounded Vite+ bun dependency updates', function (): void {
    it('reuses the shared Vite+ supervisor and runs the verified bun pass-through within declared constraints', function (): void {
        bun_update_ssh([
            [
                function (SshConnection $connection, RemoteCommand $command): bool {
                    expect($connection->host)->toBe('10.44.0.2');
                    expect($connection->user)->toBe('orbit');
                    expect($connection->identityFile)->toBe('/keys/private');
                    expect($connection->knownHostsFile)->toBe('/keys/known_hosts');

                    return bun_update_probe_command($command);
                },
                bun_update_probe_present(),
            ],
            [
                fn (SshConnection $connection, RemoteCommand $command): bool => bun_update_update_command($command),
                new CommandResult(0, '', '', 12, false),
            ],
        ]);

        $result = app(UpdateBunDependenciesAction::class)->execute(bun_update_instance());

        expect($result->ecosystem)->toBe(DependencyEcosystem::Npm);
        expect($result->status)->toBe(DependencyUpdateStepStatus::Succeeded);
        expect($result->mayHaveMutated)->toBeTrue();
        expect($result->errorCode)->toBeNull();
    });

    it('accepts every Orbit-managed Vite+ installation layout', function (string $vpPath): void {
        bun_update_ssh([
            [
                fn (SshConnection $connection, RemoteCommand $command): bool => bun_update_probe_command($command),
                bun_update_probe_present(vpPath: $vpPath),
            ],
            [
                fn (SshConnection $connection, RemoteCommand $command): bool => bun_update_update_command($command, vpPath: $vpPath),
                new CommandResult(0, '', '', 12, false),
            ],
        ]);

        $result = app(UpdateBunDependenciesAction::class)->execute(bun_update_instance());

        expect($result->status)->toBe(DependencyUpdateStepStatus::Succeeded);
        expect($result->mayHaveMutated)->toBeTrue();
        expect($result->errorCode)->toBeNull();
    })->with([
        'canonical launcher' => ['/usr/local/bin/vp'],
        'opt home' => ['/opt/orbit/vite-plus/bin/vp'],
        'user vite-plus home' => ['/home/orbit/.vite-plus/bin/vp'],
        'user vite-plus current' => ['/home/orbit/.vite-plus/current/bin/vp'],
        'XDG home' => ['/home/orbit/.local/share/vite-plus/bin/vp'],
        'XDG current' => ['/home/orbit/.local/share/vite-plus/current/bin/vp'],
    ]);

    it('runs Vite+ in a recorded root that contains a space', function (): void {
        $path = '/home/orbit/project spaced';
        bun_update_ssh([
            [
                fn (SshConnection $connection, RemoteCommand $command): bool => bun_update_probe_command($command, $path),
                bun_update_probe_present(),
            ],
            [
                fn (SshConnection $connection, RemoteCommand $command): bool => bun_update_update_command($command, path: $path),
                new CommandResult(0, '', '', 12, false),
            ],
        ]);

        $result = app(UpdateBunDependenciesAction::class)->execute(bun_update_instance(path: $path));

        expect($result->status)->toBe(DependencyUpdateStepStatus::Succeeded);
        expect($result->mayHaveMutated)->toBeTrue();
        expect($result->errorCode)->toBeNull();
    });

    it('skips an absent bun ecosystem without running Vite+', function (): void {
        bun_update_ssh([
            [
                fn (SshConnection $connection, RemoteCommand $command): bool => bun_update_probe_command($command),
                new CommandResult(0, '{"status":"absent"}', '', 1, false),
            ],
        ]);

        $result = app(UpdateBunDependenciesAction::class)->execute(bun_update_instance());

        expect($result->status)->toBe(DependencyUpdateStepStatus::Absent);
        expect($result->mayHaveMutated)->toBeFalse();
        expect($result->completed())->toBeTrue();
    });

    it('refuses production before SSH', function (): void {
        bun_update_never_ssh();

        $result = app(UpdateBunDependenciesAction::class)->execute(bun_update_instance(production: true));

        expect($result->status)->toBe(DependencyUpdateStepStatus::Failed);
        expect($result->errorCode)->toBe('dependencies.production_update_forbidden');
        expect($result->mayHaveMutated)->toBeFalse();
    });

    it('refuses unsafe instance identity before SSH', function (): void {
        bun_update_never_ssh();

        $instance = bun_update_instance(path: 'relative/root');
        $result = app(UpdateBunDependenciesAction::class)->execute($instance);

        expect($result->errorCode)->toBe('dependencies.unsafe_source');
        expect($result->mayHaveMutated)->toBeFalse();
    });

    it('fails an incomplete bun project without mutation', function (): void {
        bun_update_ssh([
            [
                fn (SshConnection $connection, RemoteCommand $command): bool => bun_update_probe_command($command),
                new CommandResult(0, '{"status":"incomplete"}', '', 1, false),
            ],
        ]);

        $result = app(UpdateBunDependenciesAction::class)->execute(bun_update_instance());

        expect($result->errorCode)->toBe('dependencies.incomplete_source');
        expect($result->mayHaveMutated)->toBeFalse();
    });

    it('passes through explicit probe refusals without mutation', function (string $error): void {
        bun_update_ssh([
            [
                fn (SshConnection $connection, RemoteCommand $command): bool => bun_update_probe_command($command),
                new CommandResult(0, json_encode(['error' => $error], JSON_THROW_ON_ERROR), '', 1, false),
            ],
        ]);

        $result = app(UpdateBunDependenciesAction::class)->execute(bun_update_instance());

        expect($result->errorCode)->toBe($error);
        expect($result->mayHaveMutated)->toBeFalse();
    })->with([
        'dependencies.unsafe_source',
        'dependencies.unreadable_source',
        'dependencies.invalid_manifest',
        'dependencies.unsupported_format',
        'dependencies.unsupported_layout',
        'dependencies.ambiguous_manager',
    ]);

    it('fails a missing Vite+ installation as unsupported delegation before mutation', function (): void {
        bun_update_ssh([
            [
                fn (SshConnection $connection, RemoteCommand $command): bool => bun_update_probe_command($command),
                bun_update_probe_present(version: ''),
            ],
        ]);

        $result = app(UpdateBunDependenciesAction::class)->execute(bun_update_instance());

        expect($result->errorCode)->toBe('dependencies.unsupported_delegation');
        expect($result->mayHaveMutated)->toBeFalse();
    });

    it('fails an unverified Vite+ version as unsupported delegation before mutation', function (): void {
        bun_update_ssh([
            [
                fn (SshConnection $connection, RemoteCommand $command): bool => bun_update_probe_command($command),
                bun_update_probe_present(version: '0.2.6'),
            ],
        ]);

        $result = app(UpdateBunDependenciesAction::class)->execute(bun_update_instance());

        expect($result->errorCode)->toBe('dependencies.unsupported_delegation');
        expect($result->mayHaveMutated)->toBeFalse();
    });

    it('rejects malformed probe receipts and Vite+ paths', function (string $stdout): void {
        bun_update_ssh([
            [
                fn (SshConnection $connection, RemoteCommand $command): bool => bun_update_probe_command($command),
                new CommandResult(0, $stdout, '', 1, false),
            ],
        ]);

        $result = app(UpdateBunDependenciesAction::class)->execute(bun_update_instance());

        expect($result->errorCode)->toBe('dependencies.unreadable_source');
        expect($result->mayHaveMutated)->toBeFalse();
    })->with([
        'not JSON' => ['garbage'],
        'unknown error code' => ['{"error":"dependencies.bogus"}'],
        'error with extra keys' => ['{"error":"dependencies.unsafe_source","status":"present"}'],
        'absent with extra keys' => ['{"status":"absent","vp":null}'],
        'present without vp key' => ['{"status":"present"}'],
        'vp path outside the standard layout' => ['{"status":"present","vp":{"path":"/tmp/vp","version":"0.3.0"}}'],
        'relative vp path' => ['{"status":"present","vp":{"path":"vp","version":"0.3.0"}}'],
        'malformed version' => ['{"status":"present","vp":{"path":"'.BUN_UPDATE_VP_PATH.'","version":"v0.3.0"}}'],
        'extra payload keys' => ['{"status":"present","vp":null,"extra":true}'],
    ]);

    it('rejects probe stderr and truncated output', function (): void {
        bun_update_ssh([
            [
                fn (SshConnection $connection, RemoteCommand $command): bool => bun_update_probe_command($command),
                new CommandResult(0, '{"status":"absent"}', 'noise', 1, false),
            ],
        ]);

        expect(app(UpdateBunDependenciesAction::class)->execute(bun_update_instance())->errorCode)
            ->toBe('dependencies.unreadable_source');
    });

    it('maps probe cancellation, timeout and transport failure without mutation', function (Throwable $failure, string $error): void {
        bun_update_ssh([
            [
                fn (SshConnection $connection, RemoteCommand $command): bool => bun_update_probe_command($command),
                $failure,
            ],
        ]);

        $result = app(UpdateBunDependenciesAction::class)->execute(bun_update_instance());

        expect($result->errorCode)->toBe($error);
        expect($result->mayHaveMutated)->toBeFalse();
    })->with([
        'cancelled' => [new ProcessCancelledException, 'dependencies.update_cancelled'],
        'timeout' => [new ProcessTimedOutException(new Process(['true']), ProcessTimedOutException::TYPE_GENERAL), 'dependencies.update_timeout'],
        'transport' => [new RuntimeException('ssh failed'), 'dependencies.unreadable_source'],
    ]);

    it('maps update failure, truncation and unexpected transport failure with possible mutation', function (CommandResult|Throwable $failure, string $error): void {
        $calls = [
            [
                fn (SshConnection $connection, RemoteCommand $command): bool => bun_update_probe_command($command),
                bun_update_probe_present(),
            ],
            [
                fn (SshConnection $connection, RemoteCommand $command): bool => bun_update_update_command($command),
                $failure,
            ],
        ];
        bun_update_ssh($calls);

        $result = app(UpdateBunDependenciesAction::class)->execute(bun_update_instance());

        expect($result->errorCode)->toBe($error);
        expect($result->mayHaveMutated)->toBeTrue();
    })->with([
        'nonzero exit' => [new CommandResult(1, '', '', 40, false), 'dependencies.update_failed'],
        'truncated output' => [new CommandResult(0, str_repeat('x', 100), '', 40, true), 'dependencies.update_failed'],
        'remote deadline' => [new CommandResult(124, '', '', 600, false), 'dependencies.update_timeout'],
        'remote cancellation' => [new CommandResult(143, '', '', 12, false), 'dependencies.update_cancelled'],
        'local cancellation' => [new ProcessCancelledException, 'dependencies.update_cancelled'],
        'local timeout' => [new ProcessTimedOutException(new Process(['true']), ProcessTimedOutException::TYPE_GENERAL), 'dependencies.update_timeout'],
        'transport failure' => [new RuntimeException('ssh failed'), 'dependencies.update_failed'],
    ]);

    it('passes the cancellation callback through both remote calls', function (): void {
        bun_update_ssh([
            [
                function (SshConnection $connection, RemoteCommand $command): bool {
                    expect($command->cancelled)->not->toBeNull();

                    return bun_update_probe_command($command);
                },
                bun_update_probe_present(),
            ],
            [
                fn (SshConnection $connection, RemoteCommand $command): bool => bun_update_update_command($command, expectCancelled: true),
                new CommandResult(0, '', '', 12, false),
            ],
        ]);

        $cancelled = fn (): bool => false;
        $result = app(UpdateBunDependenciesAction::class)->execute(bun_update_instance(), $cancelled);

        expect($result->status)->toBe(DependencyUpdateStepStatus::Succeeded);
    });
});
