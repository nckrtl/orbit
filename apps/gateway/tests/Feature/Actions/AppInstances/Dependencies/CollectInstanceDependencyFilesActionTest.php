<?php

declare(strict_types=1);

use App\Actions\AppInstances\Dependencies\CollectInstanceDependencyFilesAction;
use App\Domain\AppInstances\Dependencies\DependencyCollectionException;
use App\Infrastructure\AppInstances\DependencyFilesProgram;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\AppInstance;
use App\Models\Node;

use function Pest\Laravel\mock;

/** @return array<string, mixed> */
function dependency_collection_receipt(string $root = '/home/orbit/project', ?string $reference = null): array
{
    $files = array_fill_keys(DependencyFilesProgram::FILES, ['content' => null, 'hash' => null, 'error' => null]);
    $files['composer.json'] = ['content' => base64_encode('{}'), 'hash' => hash('sha256', '{}'), 'error' => null];

    return ['root' => $root, 'reference' => $reference, 'identity' => str_repeat('a', 64), 'files' => $files];
}

function dependency_collection_instance(bool $production = false): AppInstance
{
    $instance = new AppInstance([
        'environment' => $production ? 'production' : 'development',
        'source_layout' => 'checkout', 'checkout_path' => '/home/orbit/project',
        'production_user' => $production ? 'app_sample' : null,
        'production_home' => $production ? '/home/app_sample' : null,
        'root' => 'public', 'migration_required' => false,
    ]);
    $instance->setRelation('node', new Node(['user' => 'orbit', 'wireguard_ip' => '10.44.0.2']));

    return $instance;
}

function dependency_collection_keys(): void
{
    mock(SshKeyProvider::class)->shouldReceive('privateKeyPath')->once()->andReturn('/keys/private');
    mock(KnownHostsStore::class)->shouldReceive('path')->once()->andReturn('/keys/known_hosts');
}

describe('managed dependency collection transport', function (): void {
    it('uses pinned SSH and bounded fixed argv for the recorded source', function (bool $production): void {
        $instance = dependency_collection_instance($production);
        $root = $production ? '/home/app_sample/releases/selected' : '/home/orbit/project';
        $receipt = dependency_collection_receipt($root, $production ? 'selected' : null);
        dependency_collection_keys();
        mock(SshExecutor::class)->shouldReceive('execute')->once()->withArgs(function (SshConnection $connection, RemoteCommand $command) use ($production): bool {
            expect($connection->host)->toBe('10.44.0.2');
            expect($connection->user)->toBe('orbit');
            expect($connection->identityFile)->toBe('/keys/private');
            expect($connection->knownHostsFile)->toBe('/keys/known_hosts');
            expect($command->arguments)->toBe($production
                ? ['sudo', '-n', '-u', 'app_sample', '-H', '--', '/usr/bin/python3', '-I', '-', 'production', '/home/app_sample']
                : ['/usr/bin/python3', '-I', '-', 'development', '/home/orbit/project']);
            expect($command->timeout)->toBe(30.0);
            expect($command->maxOutputBytes)->toBe(48 * 1024 * 1024);
            expect($command->input)->toBe(DependencyFilesProgram::render());

            return true;
        })->andReturn(new CommandResult(0, json_encode($receipt, JSON_THROW_ON_ERROR), '', 1, false));

        $files = app(CollectInstanceDependencyFilesAction::class)->execute($instance);

        expect($files->projectRoot)->toBe($root);
        expect($files->contents['composer.json'])->toBe('{}');
        expect($files->hashes['package.json'])->toBeNull();
    })->with([false, true]);

    it('rejects invalid identities before SSH', function (array $attributes): void {
        $instance = dependency_collection_instance();
        $instance->forceFill($attributes);
        mock(SshExecutor::class)->shouldNotReceive('execute');

        expect(fn () => app(CollectInstanceDependencyFilesAction::class)->execute($instance))
            ->toThrow(DependencyCollectionException::class, 'dependencies.unsafe_source');
    })->with([
        [['checkout_path' => 'relative']], [['environment' => 'staging']],
        [['source_layout' => 'nested']], [['migration_required' => true]],
        [['environment' => 'production', 'production_user' => '-root', 'production_home' => '/home/-root']],
        [['environment' => 'production', 'production_user' => 'app_sample', 'production_home' => '/tmp/wrong']],
    ]);

    it('redacts failed and malformed remote results', function (string $kind, string $code): void {
        $receipt = dependency_collection_receipt();
        $exit = 0;
        $stderr = '';
        $truncated = false;
        if ($kind === 'missing-file') {
            unset($receipt['files']['package.json']);
        } elseif ($kind === 'hash') {
            $receipt['files']['composer.json']['hash'] = str_repeat('b', 64);
        } elseif ($kind === 'base64') {
            $receipt['files']['composer.json']['content'] = '!';
        } elseif ($kind === 'path') {
            $receipt['root'] = '/elsewhere';
        } elseif ($kind === 'identity') {
            $receipt['identity'] = 'https://secret';
        } elseif ($kind === 'error') {
            $receipt = ['error' => 'dependencies.source_changed'];
        } elseif ($kind === 'unsafe-error') {
            $receipt = ['error' => 'password=secret'];
        } elseif ($kind === 'stderr') {
            $stderr = 'password=secret';
        } elseif ($kind === 'exit') {
            $exit = 1;
        } elseif ($kind === 'truncated') {
            $truncated = true;
        }
        dependency_collection_keys();
        mock(SshExecutor::class)->shouldReceive('execute')->once()->andReturn(new CommandResult($exit,
            $kind === 'json' ? 'secret raw output' : json_encode($receipt, JSON_THROW_ON_ERROR), $stderr, 1, $truncated));

        expect(fn () => app(CollectInstanceDependencyFilesAction::class)->execute(dependency_collection_instance()))
            ->toThrow(function (DependencyCollectionException $error) use ($code): void {
                expect($error->getMessage())->toBe($code);
                expect($error->getPrevious())->toBeNull();
            });
    })->with([
        ['missing-file', 'dependencies.invalid_collection'], ['hash', 'dependencies.invalid_collection'],
        ['base64', 'dependencies.invalid_collection'], ['path', 'dependencies.invalid_collection'],
        ['identity', 'dependencies.invalid_collection'], ['error', 'dependencies.source_changed'],
        ['unsafe-error', 'dependencies.invalid_collection'], ['stderr', 'dependencies.unreadable_source'],
        ['exit', 'dependencies.unreadable_source'], ['truncated', 'dependencies.unreadable_source'], ['json', 'dependencies.invalid_collection'],
    ]);

    it('retains per-file failures separately from absence', function (): void {
        $receipt = dependency_collection_receipt();
        $receipt['files']['package.json']['error'] = 'dependencies.unreadable_source';
        dependency_collection_keys();
        mock(SshExecutor::class)->shouldReceive('execute')->once()->andReturn(new CommandResult(0, json_encode($receipt, JSON_THROW_ON_ERROR), '', 1, false));

        $files = app(CollectInstanceDependencyFilesAction::class)->execute(dependency_collection_instance());

        expect($files->errors)->toBe(['package.json' => 'dependencies.unreadable_source']);
        expect($files->contents['composer.json'])->toBe('{}');
    });

    it('discards transport exception text', function (): void {
        dependency_collection_keys();
        mock(SshExecutor::class)->shouldReceive('execute')->once()->andThrow(new RuntimeException('password=secret'));

        expect(fn () => app(CollectInstanceDependencyFilesAction::class)->execute(dependency_collection_instance()))
            ->toThrow(function (DependencyCollectionException $error): void {
                expect($error->getMessage())->toBe('dependencies.unreadable_source');
                expect($error->getPrevious())->toBeNull();
            });
    });
});
