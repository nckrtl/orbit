<?php

declare(strict_types=1);

use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\StorageRootResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Instances\RemoteDevelopmentInstanceCheckoutCopier;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\DeadlineOnCopySshExecutor;

it('reads source eligibility without a copy command', function (): void {
    [$source] = copy_checkout_pair();
    $head = str_repeat('a', 40);
    $ssh = new AppDevFakeSshExecutor([new CommandResult(0, "ready\n{$head}\n", '', 1, false)]);
    $copier = copy_checkout_copier($ssh);

    $inspection = $copier->inspect($source, 'feature');

    expect($inspection->head)->toBe($head)
        ->and($ssh->commands)->toHaveCount(1)
        ->and($ssh->commands[0]->arguments[0])->toBe('bash')
        ->and($ssh->commands[0]->input)->toContain('instance.copy_source_cold')
        ->and($ssh->commands[0]->input)->toContain('instance.copy_source_dirty')
        ->and($ssh->commands[0]->input)->toContain('instance.copy_source_layout_invalid')
        ->and($ssh->commands[0]->input)->not->toContain('cp ')
        ->and($ssh->commands[0]->arguments)->toContain(RuntimeHibernation::coldPath(RuntimeHibernation::key($source->id)));
});

it('maps a read-only refusal to its stable code', function (string $code): void {
    [$source] = copy_checkout_pair();
    $ssh = new AppDevFakeSshExecutor([new CommandResult(0, "refused\n{$code}\n", '', 1, false)]);
    $copier = copy_checkout_copier($ssh);

    expect(fn () => $copier->inspect($source, 'feature'))
        ->toThrow(fn (ResourceOperationException $exception) => $exception->errorCode === $code)
        ->and($ssh->commands)->toHaveCount(1);
})->with([
    'instance.copy_source_cold',
    'instance.copy_source_layout_invalid',
    'instance.copy_source_dirty',
    'instance.copy_branch_diverged',
]);

it('reports a reflink when cp --reflink=always succeeds and creates the branch on the target', function (): void {
    [$source, $target] = copy_checkout_pair();
    $head = str_repeat('b', 40);
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "ready\n", '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, "ready\n{$head}\n", '', 1, false),
    ]);
    $copier = copy_checkout_copier($ssh);

    $result = $copier->copy($source, $target, 'feature', $head, 'instance.path_taken');

    expect($result->mode)->toBe('reflink')
        ->and($result->head)->toBe($head)
        ->and($ssh->commands[1]->arguments)->toBe(['sync', '-f', '/srv/orbit/apps'])
        ->and($ssh->commands[2]->arguments)->toBe([
            'env', 'LC_ALL=C', 'cp', '-a', '--reflink=always', '--',
            '/srv/orbit/apps/acme/default',
            '/srv/orbit/apps/acme/feature',
        ])
        ->and(implode("\n", array_map(static fn ($command) => $command->shellCommand(), $ssh->commands)))->not->toContain('--reflink=auto')
        ->and($ssh->commands[3]->arguments)->toContain('/srv/orbit/apps/acme/default', '/srv/orbit/apps/acme/feature')
        ->and($ssh->commands[3]->input)->toContain('git -C "$dest" checkout --quiet --force -B "$branch"')
        ->and($ssh->commands[3]->input)->not->toContain('git fetch');
});

it('falls back to a plain copy only for a reflink errno and reports full', function (): void {
    [$source, $target] = copy_checkout_pair();
    $head = str_repeat('b', 40);
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "ready\n", '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(1, '', "cp: failed to clone 'file': Operation not supported\n", 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, "ready\n{$head}\n", '', 1, false),
    ]);
    $copier = copy_checkout_copier($ssh);

    $result = $copier->copy($source, $target, 'feature', $head, 'instance.path_taken');

    expect($result->mode)->toBe('full')
        ->and($ssh->commands[4]->arguments)->toBe([
            'env', 'LC_ALL=C', 'cp', '-a', '--',
            '/srv/orbit/apps/acme/default',
            '/srv/orbit/apps/acme/feature',
        ])
        ->and($ssh->commands[3]->input)->toContain('rm -rf -- "$dest"');
});

it('continues the copy when sync fails', function (): void {
    [$source, $target] = copy_checkout_pair();
    $head = str_repeat('b', 40);
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "ready\n", '', 1, false),
        new CommandResult(1, '', 'sync failed', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, "ready\n{$head}\n", '', 1, false),
    ]);

    expect(copy_checkout_copier($ssh)->copy($source, $target, 'feature', $head, 'instance.path_taken')->mode)
        ->toBe('reflink')
        ->and($ssh->commands[2]->arguments[4])->toBe('--reflink=always');
});

it('retries one missing reset path and does not treat another missing path as a fallback', function (string $stderr, bool $retried): void {
    [$source, $target] = copy_checkout_pair();
    $head = str_repeat('b', 40);
    $results = [
        new CommandResult(0, "ready\n", '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(1, '', $stderr, 1, false),
    ];

    if ($retried) {
        $results[] = new CommandResult(0, '', '', 1, false);
        $results[] = new CommandResult(0, '', '', 1, false);
        $results[] = new CommandResult(0, "ready\n{$head}\n", '', 1, false);
    } else {
        $results[] = new CommandResult(0, '', '', 1, false);
    }

    $ssh = new AppDevFakeSshExecutor($results);
    $copy = fn () => copy_checkout_copier($ssh)->copy($source, $target, 'feature', $head, 'instance.path_taken');

    if ($retried) {
        expect($copy()->mode)->toBe('reflink')
            ->and($ssh->commands[4]->arguments)->toContain('--reflink=always');

        return;
    }

    expect($copy)->toThrow(fn (ResourceOperationException $exception) => $exception->errorCode === 'instance.copy_failed' && ($exception->details['copy_started'] ?? '') === '1')
        ->and(array_values(array_filter(
            $ssh->commands,
            static fn ($command): bool => in_array('--reflink=always', $command->arguments, true),
        )))->toHaveCount(1)
        ->and(array_values(array_filter(
            $ssh->commands,
            static fn ($command): bool => in_array('0', $command->arguments, true),
        )))->not->toBeEmpty();
})->with([
    'reset path' => ["cp: cannot stat '/srv/orbit/apps/acme/default/storage/logs/laravel.log': No such file or directory\n", true],
    'tracked path' => ["cp: cannot stat '/srv/orbit/apps/acme/default/composer.json': No such file or directory\n", false],
]);

it('removes a partial tree when reflink fails for another reason', function (): void {
    [$source, $target] = copy_checkout_pair();
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "ready\n", '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(1, '', "cp: failed: Permission denied\n", 1, false),
        new CommandResult(0, '', '', 1, false),
    ]);

    expect(fn () => copy_checkout_copier($ssh)->copy($source, $target, 'feature', str_repeat('b', 40), 'instance.path_taken'))
        ->toThrow(fn (ResourceOperationException $exception) => $exception->errorCode === 'instance.copy_failed' && ($exception->details['copy_started'] ?? '') === '1')
        ->and($ssh->commands[3]->arguments)->toContain('0');
});

it('discards the owned tree when cp exceeds the deadline and keeps that error', function (): void {
    [$source, $target] = copy_checkout_pair();
    $head = str_repeat('b', 40);
    $ssh = new DeadlineOnCopySshExecutor($head);

    expect(fn () => copy_checkout_copier($ssh)->copy($source, $target, 'feature', $head, 'instance.path_taken'))
        ->toThrow(fn (ResourceOperationException $exception): bool => $exception->errorCode === 'command.deadline_exceeded'
            && $exception->status === 504
            && ($exception->details['copy_started'] ?? '') === '1');

    $discard = $ssh->commands[array_key_last($ssh->commands)];

    expect($discard->input)->toContain('keep_marker')
        ->and($discard->arguments)->toContain('0', '/srv/orbit/apps/acme/feature');
});

it('refuses an occupied destination before copying', function (): void {
    [$source, $target] = copy_checkout_pair();
    $ssh = new AppDevFakeSshExecutor([new CommandResult(0, "occupied\n", '', 1, false)]);

    expect(fn () => copy_checkout_copier($ssh)->copy($source, $target, 'feature', str_repeat('b', 40), 'instance.path_taken'))
        ->toThrow(fn (ResourceOperationException $exception) => $exception->errorCode === 'instance.path_taken' && $exception->details === [])
        ->and($ssh->commands)->toHaveCount(1);
});

it('removes the partial target when the source HEAD moves', function (): void {
    [$source, $target] = copy_checkout_pair();
    $head = str_repeat('b', 40);
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "ready\n", '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, "changed\n", '', 1, false),
        new CommandResult(0, '', '', 1, false),
    ]);

    expect(fn () => copy_checkout_copier($ssh)->copy($source, $target, 'feature', $head, 'instance.path_taken'))
        ->toThrow(fn (ResourceOperationException $exception) => $exception->errorCode === 'instance.copy_source_changed');
});

/** @return array{Instance, Instance} */
function copy_checkout_pair(): array
{
    $node = Node::query()->create([
        'name' => 'copy-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'tld' => 'test',
        'public_ssh_host' => '192.0.2.60',
        'wireguard_ip' => '10.44.0.60',
        'user' => 'orbit',
        'settings' => ['apps' => ['path' => '/srv/orbit/apps']],
    ]);
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/site.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $source = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'default',
        'source_layout' => InstanceSourceLayout::Checkout,
        'checkout_path' => '/srv/orbit/apps/acme/default',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'status' => InstanceState::Active,
    ]);
    $target = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'feature',
        'source_layout' => InstanceSourceLayout::Checkout,
        'checkout_path' => '/srv/orbit/apps/acme/feature',
        'branch_override' => 'feature',
        'status' => InstanceState::Reserved,
    ]);

    return [$source->refresh(), $target->refresh()];
}

function copy_checkout_copier(SshExecutor $ssh): RemoteDevelopmentInstanceCheckoutCopier
{
    return new RemoteDevelopmentInstanceCheckoutCopier(
        ssh: $ssh,
        keys: new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/orbit/ssh/id_ed25519';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 AAAA orbit';
            }
        },
        knownHosts: new class implements KnownHostsStore
        {
            public function path(): string
            {
                return '/orbit/ssh/known_hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
        accounts: new class implements ManagedUserAccountResolver
        {
            public function resolve(Node $node): ManagedUserAccount
            {
                return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
            }
        },
        storageRoots: app(StorageRootResolver::class),
        nodeSettings: app(NodeSettingsNormalizer::class),
    );
}
