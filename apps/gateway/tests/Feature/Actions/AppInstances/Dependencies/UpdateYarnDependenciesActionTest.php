<?php

declare(strict_types=1);

use App\Actions\AppInstances\Dependencies\UpdateYarnDependenciesAction;
use App\Domain\AppInstances\Dependencies\DependencyEcosystem;
use App\Domain\AppInstances\Dependencies\DependencyUpdateStepStatus;
use App\Infrastructure\AppInstances\YarnDependencyUpdatePresenceProgram;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessCancelledException;
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

function yarn_update_instance(bool $production = false, string $path = '/home/orbit/project'): AppInstance
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

function yarn_update_keys(): void
{
    mock(SshKeyProvider::class)->shouldReceive('privateKeyPath')->andReturn('/keys/private');
    mock(KnownHostsStore::class)->shouldReceive('path')->andReturn('/keys/known_hosts');
}

/**
 * @param  list<array{0: callable(SshConnection, RemoteCommand): bool, 1: CommandResult|Throwable}>  $calls
 */
function yarn_update_ssh(array $calls): void
{
    yarn_update_keys();
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

function yarn_update_never_ssh(): void
{
    yarn_update_keys();
    mock(SshExecutor::class)->shouldReceive('execute')->never();
}

function yarn_update_probe_command(RemoteCommand $command, string $path = '/home/orbit/project'): bool
{
    expect($command->arguments)->toBe(['/usr/bin/python3', '-I', '-', $path]);
    expect($command->input)->toBe(YarnDependencyUpdatePresenceProgram::render());
    expect($command->timeout)->toBe(45.0);
    expect($command->maxOutputBytes)->toBe(65_536);
    expect($command->arguments)->not->toContain('yarn');
    expect($command->arguments)->not->toContain('vp');
    expect($command->arguments)->not->toContain('/usr/bin/setsid');
    expect($command->input)->not->toContain('subprocess');
    expect($command->input)->not->toContain('yarn upgrade');
    expect($command->input)->not->toContain('yarn up');
    expect($command->input)->not->toContain('vp update');
    expect($command->input)->not->toContain('--latest');

    return true;
}

function yarn_update_probe_present(string $family): CommandResult
{
    return new CommandResult(0, json_encode(['status' => 'present', 'family' => $family], JSON_THROW_ON_ERROR), '', 1, false);
}

describe('Yarn Vite+ update refusal', function (): void {
    it('refuses production before SSH', function (): void {
        yarn_update_never_ssh();

        $result = app(UpdateYarnDependenciesAction::class)->execute(yarn_update_instance(production: true));

        expect($result->status)->toBe(DependencyUpdateStepStatus::Failed);
        expect($result->errorCode)->toBe('dependencies.production_update_forbidden');
        expect($result->mayHaveMutated)->toBeFalse();
        expect($result->ecosystem)->toBe(DependencyEcosystem::Npm);
    });

    it('refuses unsafe instance identity before SSH', function (): void {
        yarn_update_never_ssh();

        $result = app(UpdateYarnDependenciesAction::class)->execute(yarn_update_instance(path: 'relative/root'));

        expect($result->errorCode)->toBe('dependencies.unsafe_source');
        expect($result->mayHaveMutated)->toBeFalse();
    });

    it('refuses Classic and modern Yarn before any update command', function (string $family): void {
        yarn_update_ssh([
            [
                function (SshConnection $connection, RemoteCommand $command): bool {
                    expect($connection->host)->toBe('10.44.0.2');
                    expect($connection->user)->toBe('orbit');

                    return yarn_update_probe_command($command);
                },
                yarn_update_probe_present($family),
            ],
        ]);

        $result = app(UpdateYarnDependenciesAction::class)->execute(yarn_update_instance());

        expect($result->status)->toBe(DependencyUpdateStepStatus::Failed);
        expect($result->errorCode)->toBe('dependencies.unsupported_format');
        expect($result->mayHaveMutated)->toBeFalse();
        expect($result->completed())->toBeFalse();
    })->with(['classic', 'modern']);

    it('skips roots without Yarn signals', function (): void {
        yarn_update_ssh([
            [
                fn (SshConnection $connection, RemoteCommand $command): bool => yarn_update_probe_command($command),
                new CommandResult(0, '{"status":"absent"}', '', 1, false),
            ],
        ]);

        $result = app(UpdateYarnDependenciesAction::class)->execute(yarn_update_instance());

        expect($result->status)->toBe(DependencyUpdateStepStatus::Absent);
        expect($result->mayHaveMutated)->toBeFalse();
        expect($result->errorCode)->toBeNull();
    });

    it('probes a recorded root that contains a space', function (): void {
        $path = '/home/orbit/yarn project';
        yarn_update_ssh([
            [
                fn (SshConnection $connection, RemoteCommand $command): bool => yarn_update_probe_command($command, $path),
                yarn_update_probe_present('classic'),
            ],
        ]);

        $result = app(UpdateYarnDependenciesAction::class)->execute(yarn_update_instance(path: $path));

        expect($result->errorCode)->toBe('dependencies.unsupported_format');
        expect($result->mayHaveMutated)->toBeFalse();
    });

    it('passes through explicit probe refusals without mutation', function (string $error): void {
        yarn_update_ssh([
            [
                fn (SshConnection $connection, RemoteCommand $command): bool => yarn_update_probe_command($command),
                new CommandResult(0, json_encode(['error' => $error], JSON_THROW_ON_ERROR), '', 1, false),
            ],
        ]);

        $result = app(UpdateYarnDependenciesAction::class)->execute(yarn_update_instance());

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

    it('rejects malformed receipts and Vite+ delegation payloads', function (string $stdout): void {
        yarn_update_ssh([
            [
                fn (SshConnection $connection, RemoteCommand $command): bool => yarn_update_probe_command($command),
                new CommandResult(0, $stdout, '', 1, false),
            ],
        ]);

        $result = app(UpdateYarnDependenciesAction::class)->execute(yarn_update_instance());

        expect($result->errorCode)->toBe('dependencies.unreadable_source');
        expect($result->mayHaveMutated)->toBeFalse();
    })->with([
        'not JSON' => ['garbage'],
        'present without family' => ['{"status":"present"}'],
        'unknown family' => ['{"status":"present","family":"berry"}'],
        'vite plus path' => ['{"status":"present","family":"classic","vp":{"path":"/usr/local/bin/vp","version":"0.3.0"}}'],
        'absent with extra keys' => ['{"status":"absent","family":"classic"}'],
        'unknown error code' => ['{"error":"dependencies.bogus"}'],
    ]);

    it('keeps cancellation and timeout before probing as non-mutating', function (Throwable $exception, string $error): void {
        yarn_update_ssh([
            [
                fn (SshConnection $connection, RemoteCommand $command): bool => yarn_update_probe_command($command),
                $exception,
            ],
        ]);

        $result = app(UpdateYarnDependenciesAction::class)->execute(yarn_update_instance());

        expect($result->errorCode)->toBe($error);
        expect($result->mayHaveMutated)->toBeFalse();
    })->with([
        'cancelled' => [new ProcessCancelledException, 'dependencies.update_cancelled'],
        'timeout' => [new ProcessTimedOutException(new Process(['true']), ProcessTimedOutException::TYPE_GENERAL), 'dependencies.update_timeout'],
    ]);
});
