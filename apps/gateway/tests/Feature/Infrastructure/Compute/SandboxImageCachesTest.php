<?php

declare(strict_types=1);

use App\Domain\Compute\ComputeException;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubRepository;
use App\Infrastructure\Compute\GitHubSandboxImageCacheSources;
use App\Infrastructure\Compute\SshSandboxImageGuest;
use App\Infrastructure\Compute\UpCloudCloudInit;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Project;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\Feature\GitHub\GitHubTestSupport;

function caches_lock(): string
{
    return json_encode(['content-hash' => 'abc', 'packages' => [
        ['name' => 'laravel/framework', 'version' => 'v13.0.0', 'notification-url' => 'https://packagist.org/downloads/'],
        ['name' => 'laravel/nova', 'version' => '5.0.0', 'dist' => ['url' => 'https://nova.laravel.com/dist/nova.zip']],
    ], 'packages-dev' => [['name' => 'pestphp/pest', 'version' => 'v5.0.0', 'notification-url' => 'https://packagist.org/downloads/']]], JSON_THROW_ON_ERROR);
}

describe('cache sources', function (): void {
    it('reads lock files from the default branch with a read token and keeps only public packages', function (): void {
        GitHubTestSupport::storeApp();
        $github = Mockery::mock(GitHubApi::class);
        $github->shouldReceive('repositoryInstallation')->andReturnUsing(fn ($credentials, GitHubRepository $repository): ?int => $repository->name === 'shop' ? 7 : null);
        $github->shouldReceive('repositoryReadToken')->once()->andReturn('ghs_read_only');
        app()->instance(GitHubApi::class, $github);
        Project::query()->create(['name' => 'Shop', 'slug' => 'shop', 'repository_url' => 'git@github.com:acme/shop.git', 'default_branch' => 'trunk',
            'root' => 'apps/site/public', 'task_compute' => 'vm']);
        Project::query()->create(['name' => 'Blog', 'slug' => 'blog', 'repository_url' => 'https://github.com/acme/blog.git', 'default_branch' => 'main', 'task_compute' => 'vm']);
        Project::query()->create(['name' => 'Shared', 'slug' => 'shared', 'repository_url' => 'https://github.com/acme/shared.git', 'default_branch' => 'main']);
        Project::query()->create(['name' => 'Orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/acme/orbit.git', 'default_branch' => 'main', 'task_compute' => 'vm']);
        Http::preventStrayRequests();
        Http::fake(['https://api.github.com/repos/acme/*' => function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            expect($request->header('Accept'))->toBe(['application/vnd.github.raw']);

            return match ($path) {
                '/repos/acme/shop/contents/apps/site/composer.lock' => $request->hasHeader('Authorization', 'Bearer ghs_read_only') && $query === ['ref' => 'trunk']
                    ? Http::response(caches_lock()) : Http::response('', 401),
                '/repos/acme/shop/contents/apps/site/package.json' => Http::response('{"private": true}'),
                '/repos/acme/shop/contents/apps/site/package-lock.json' => Http::response(json_encode(['packages' => ['' => [],
                    'node_modules/vite' => ['resolved' => 'https://registry.npmjs.org/vite/-/vite-7.0.0.tgz']]])),
                '/repos/acme/blog/contents/package.json' => Http::response('{}'),
                '/repos/acme/blog/contents/package-lock.json' => ! $request->hasHeader('Authorization') ? Http::response(json_encode(['packages' => [
                    'node_modules/@acme/ui' => ['resolved' => 'https://npm.pkg.github.com/@acme/ui/-/ui-1.0.0.tgz']]])) : Http::response('', 500),
                default => Http::response('', 404),
            };
        }]);

        $result = app(GitHubSandboxImageCacheSources::class)->collect();

        expect(array_keys($result['projects']))->toBe(['shop'])
            ->and($result['skipped'])->toBe(['blog' => ['composer' => 'missing', 'npm' => 'other_source']]);
        $lock = json_decode($result['projects']['shop']['composer.lock'], true);
        expect(array_column($lock['packages'], 'name'))->toBe(['laravel/framework'])
            ->and(array_column($lock['packages-dev'], 'name'))->toBe(['pestphp/pest'])
            ->and(json_decode($result['projects']['shop']['composer.json'], true))->toBe(['name' => 'orbit/cache-warm', 'require' => []])
            ->and($result['projects']['shop']['package.json'])->toBe('{"private": true}');
    });

    it('skips a Project whose files cannot be read and continues', function (): void {
        Project::query()->create(['name' => 'Shop', 'slug' => 'shop', 'repository_url' => 'https://github.com/acme/shop.git', 'default_branch' => 'main', 'task_compute' => 'vm']);
        Project::query()->create(['name' => 'Blog', 'slug' => 'blog', 'repository_url' => 'https://github.com/acme/blog.git', 'default_branch' => 'main', 'task_compute' => 'vm']);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.github.com/repos/acme/blog/*' => Http::response('', 500),
            'https://api.github.com/repos/acme/shop/contents/composer.lock*' => Http::response(caches_lock()),
            'https://api.github.com/repos/acme/shop/*' => Http::response('', 404),
        ]);

        $result = app(GitHubSandboxImageCacheSources::class)->collect();

        expect(array_keys($result['projects']))->toBe(['shop'])->and($result['skipped']['blog'])->toBe(['composer' => 'unreadable', 'npm' => 'unreadable'])
            ->and($result['skipped']['shop'])->toBe(['npm' => 'missing']);
    });
});

describe('build VM commands', function (): void {
    beforeEach(function (): void {
        $hosts = Mockery::mock(KnownHostsStore::class);
        $hosts->shouldReceive('put')->withArgs(fn (string $host, int $port): bool => $host === '203.0.113.5' && $port === 22);
        $hosts->shouldReceive('path')->andReturn('/known-hosts');
        app()->instance(KnownHostsStore::class, $hosts);
        $keys = Mockery::mock(SshKeyProvider::class);
        $keys->shouldReceive('privateKeyPath')->andReturn('/private-key');
        app()->instance(SshKeyProvider::class, $keys);
        $this->key = new HostKey('ssh-ed25519', 'AAAA', 'SHA256:build');
    });

    function guest_answers(string ...$stdout): MockInterface
    {
        $ssh = Mockery::mock(SshExecutor::class);
        $results = array_map(fn (string $out): CommandResult => new CommandResult(0, $out, '', 1, false), $stdout);
        $ssh->shouldReceive('execute')->withArgs(fn (SshConnection $connection): bool => $connection->host === '203.0.113.5'
            && $connection->user === 'orbit' && ! $connection->shareConnection)->andReturn(...$results);
        app()->instance(SshExecutor::class, $ssh);

        return $ssh;
    }

    it('reads unit states from systemd', function (string $stdout, string $state): void {
        guest_answers($stdout);
        expect(app(SshSandboxImageGuest::class)->unitState('203.0.113.5', $this->key, 'orbit-image-install'))->toBe($state);
    })->with([
        ["LoadState=not-found\nActiveState=inactive\nSubState=dead\nResult=success\n", 'missing'],
        ["LoadState=loaded\nActiveState=active\nSubState=running\nResult=success\n", 'running'],
        ["LoadState=loaded\nActiveState=active\nSubState=exited\nResult=success\n", 'succeeded'],
        ["LoadState=loaded\nActiveState=failed\nSubState=failed\nResult=exit-code\n", 'failed'],
    ]);

    it('starts a script as a named unit that systemd refuses to start twice', function (): void {
        $sent = null;
        $ssh = Mockery::mock(SshExecutor::class);
        $ssh->shouldReceive('execute')->once()->andReturnUsing(function (SshConnection $connection, RemoteCommand $command) use (&$sent): CommandResult {
            $sent = $command;

            return new CommandResult(0, '', '', 1, false);
        });
        app()->instance(SshExecutor::class, $ssh);
        app(SshSandboxImageGuest::class)->startUnit('203.0.113.5', $this->key, 'orbit-image-install', "#!/bin/sh\n", 'install');
        expect($sent->input)->toBe("#!/bin/sh\n")->and($sent->arguments[4])
            ->toContain('systemd-run --quiet --unit=orbit-image-install --property=RemainAfterExit=yes /bin/sh /root/orbit-image-install.sh install');
        expect(fn () => app(SshSandboxImageGuest::class)->startUnit('203.0.113.5', $this->key, 'orbit-image-install; reboot', '', 'install'))
            ->toThrow(ComputeException::class, 'unit name');
    });

    it('requires the clean phase to confirm before the disk is templated', function (): void {
        guest_answers("removed\norbit-image: clean\n", 'removed');
        app(SshSandboxImageGuest::class)->clean('203.0.113.5', $this->key, '#!/bin/sh');
        expect(fn () => app(SshSandboxImageGuest::class)->clean('203.0.113.5', $this->key, '#!/bin/sh'))->toThrow(ComputeException::class, 'did not confirm');
    });

    it('reads only well-formed cache results', function (): void {
        guest_answers("shop composer ok\nshop npm failed\nbad line\n../etc composer ok\n");
        expect(app(SshSandboxImageGuest::class)->cacheResults('203.0.113.5', $this->key))->toBe(['shop' => ['composer' => 'ok', 'npm' => 'failed']]);
    });

    it('reports an unreachable VM without SSH detail', function (): void {
        $ssh = Mockery::mock(SshExecutor::class);
        $ssh->shouldReceive('execute')->andThrow(new RuntimeException('ssh: connect to host 203.0.113.5 port 22: secret detail'));
        app()->instance(SshExecutor::class, $ssh);
        expect(fn () => app(SshSandboxImageGuest::class)->cloudInitDone('203.0.113.5', $this->key))
            ->toThrow(ComputeException::class, 'could not reach the build VM');
    });
});

describe('base template scripts', function (): void {
    it('split the setup script into an install and a clean phase that audits the identity removal', function (): void {
        $script = (string) file_get_contents(resource_path('compute/upcloud-base-image.sh'));
        expect($script)->toContain('install) install_packages ;;')->toContain('clean) clean_identity ;;')
            ->toContain('all) install_packages; clean_identity ;;')->toContain('rm -rf /var/tmp/orbit-warm')
            ->toContain('echo "orbit-image: clean"');
    });

    it('warm caches without running Project code and remove the temporary Node', function (): void {
        $script = (string) file_get_contents(resource_path('compute/upcloud-warm-caches.sh'));
        expect($script)->toContain('composer install --no-scripts --no-plugins')->toContain('npm ci --ignore-scripts')
            ->toContain('sha256sum -c')->toContain('rm -rf "$work" "$root/node"')->not->toContain('composer update');
    });

    it('admit SSH only from the Gateway on the build VM', function (): void {
        $rules = app(UpCloudCloudInit::class)->imageBuildFirewall('1.1.1.1');
        $inbound = array_values(array_filter($rules, fn (array $rule): bool => $rule['direction'] === 'in' && $rule['action'] === 'accept'));
        expect($inbound)->toBe([['direction' => 'in', 'action' => 'accept', 'family' => 'IPv4', 'protocol' => 'tcp', 'source_address_start' => '1.1.1.1',
            'source_address_end' => '1.1.1.1', 'destination_port_start' => '22', 'destination_port_end' => '22']])
            ->and(array_filter($rules, fn (array $rule): bool => ($rule['protocol'] ?? null) === 'udp' && ($rule['destination_port_start'] ?? null) !== '53'))->toBe([]);
    });
});
