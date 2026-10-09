<?php

declare(strict_types=1);

use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Instances\Deployment\DeploymentRelease;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Routes\RouteWebRoot;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppProd\ProductionSshExecutor;
use App\Infrastructure\Instances\ProductionPhpRuntimeConfigRenderer;
use App\Infrastructure\Instances\ProductionWebRootProgram;
use App\Infrastructure\Instances\RemoteProductionDeployment;
use App\Infrastructure\Instances\RemoteProductionPhpRuntimeManager;
use App\Infrastructure\Instances\RemoteProductionRouteApplicationUrlWriter;
use App\Infrastructure\Instances\RemoteProductionWebRootManager;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\Support\AppDevFakeSshExecutor;

beforeEach(function (): void {
    $this->sandbox = storage_path('framework/testing/prodweb-'.bin2hex(random_bytes(6)));
    new Filesystem()->makeDirectory($this->sandbox.'/bin', 0o755, true);
    $this->sandbox = realpath($this->sandbox);
    // Production programs reach the production user through sudo. Locally the test user owns every file.
    file_put_contents($this->sandbox.'/bin/sudo', "#!/bin/bash\nif [ \"\$1\" = -u ]; then shift 2; fi\nif [ \"\$1\" = -H ]; then shift; fi\nexec \"\$@\"\n");
    file_put_contents($this->sandbox.'/bin/setfacl', "#!/bin/bash\nprintf '%s\\n' \"\$*\" >> \"\$ORBIT_TEST_ACL_LOG\"\n");
    chmod($this->sandbox.'/bin/sudo', 0o755);
    chmod($this->sandbox.'/bin/setfacl', 0o755);
});

afterEach(function (): void {
    new Filesystem()->deleteDirectory($this->sandbox);
});

describe('production web roots', function (): void {
    it('passes the served web roots to release preparation and activation only when a Route has one', function (): void {
        [$instance] = prodweb_instance();
        $ssh = new AppDevFakeSshExecutor(array_fill(0, 4, new CommandResult(0, "20261009-a1\t".str_repeat('b', 40)."\n", '', 1, false)));
        $deployment = new RemoteProductionDeployment(prodweb_executor($ssh), app(RepositoryReadAccess::class), static fn (): string => '20261009-a1');
        $deployment->prepare($instance, 'main');
        prodweb_route($instance, 'docs.example.com', 'apps/docs/public');
        $release = $deployment->prepare($instance, 'main');
        $deployment->activate($instance, new DeploymentRelease($release->name, $release->path, $release->commit));
        [$before, $prepare, $activate] = $ssh->commands;

        expect(count($before->arguments))->toBe(10)
            ->and(base64_decode($prepare->arguments[array_key_last($prepare->arguments)], true))->toBe("apps/docs/public\tapps/docs\n")
            ->and(base64_decode($activate->arguments[array_key_last($activate->arguments)], true))->toBe("apps/docs/public\tapps/docs\n")
            ->and($prepare->input)->toContain('link_served_environments "$release"')
            ->and(strpos((string) $activate->input, "grant_served_web_roots \"\$release\"\ntemporary="))->toBeInt()
            ->and(strpos((string) $activate->input, "link_served_environments \"\$release\"\ngrant_served_web_roots"))->toBeInt();
    });

    it('links each served directory to its stable environment and grants Caddy each web root', function (): void {
        $release = $this->sandbox.'/home/releases/r1';
        foreach (['apps/docs/public', 'apps/admin/public', 'public'] as $directory) {
            mkdir("{$release}/{$directory}", 0o755, true);
        }
        $entries = ProductionWebRootProgram::entries([
            ['web_root' => 'apps/docs/public', 'directory' => 'apps/docs', 'suffix' => 'a'],
            ['web_root' => 'public', 'directory' => '', 'suffix' => 'b'],
        ]);

        $first = prodweb_run($this, $release, $entries);
        expect($first->getErrorOutput())->toBe('')
            ->and($first->getExitCode())->toBe(0)
            ->and(readlink("{$release}/apps/docs/.env"))->toBe('../../../../env/apps/docs/.env')
            ->and(readlink("{$release}/.env"))->toBe('../../env/.env')
            ->and(file_exists("{$release}/apps/admin/.env") || is_link("{$release}/apps/admin/.env"))->toBeFalse()
            ->and(file_get_contents($this->sandbox.'/acl.log'))
            ->toContain("-P -R -m u:caddy:r-X {$release}/apps/docs/public\n")
            ->toContain("-m u:caddy:--x {$release}/apps/docs\n")
            ->toContain("-P -R -m u:caddy:r-X {$release}/public\n");
        expect(prodweb_run($this, $release, $entries)->getExitCode())->toBe(0)
            ->and(prodweb_run($this, $release, '')->getExitCode())->toBe(0);

        // A web root that is its own application directory would hold its .env link, so it is refused.
        expect(prodweb_run($this, $release, ProductionWebRootProgram::entries([['web_root' => 'apps/docs', 'directory' => 'apps/docs', 'suffix' => 'a']]))->getExitCode())->not->toBe(0);
        symlink('/etc', "{$release}/apps/docs/public/escape");
        expect(prodweb_run($this, $release, $entries)->getExitCode())->not->toBe(0);
        unlink("{$release}/apps/docs/public/escape");
        unlink("{$release}/apps/docs/.env");
        file_put_contents("{$release}/apps/docs/.env", "APP_URL=https://committed.example.com\n");
        expect(prodweb_run($this, $release, $entries)->getExitCode())->not->toBe(0);
        expect(prodweb_run($this, $release, ProductionWebRootProgram::entries([['web_root' => 'apps/gone/public', 'directory' => 'apps/gone', 'suffix' => 'c']]))->getExitCode())->not->toBe(0);
    });

    it('checks a web root and its application directory in the release without changing it', function (): void {
        $release = $this->sandbox.'/home/releases/r1';
        mkdir("{$release}/apps/docs/public", 0o755, true);
        $check = function (string $webRoot) use ($release): int {
            $process = new Process(['bash', '-seu', '--', $release, ProductionWebRootProgram::entries([['web_root' => $webRoot, 'directory' => RouteWebRoot::relativeDirectory($webRoot), 'suffix' => '']])], env: ['PATH' => $this->sandbox.'/bin:'.getenv('PATH')]);
            $process->setInput(ProductionWebRootProgram::functions()."\nuser=\$(id -un)\nserved_web_roots=\$2\ncheck_served_web_roots \"\$1\"\n");

            return $process->run();
        };

        expect($check('apps/docs/public'))->toBe(0)
            ->and($check('apps/doc/public'))->not->toBe(0)
            ->and(file_exists("{$release}/apps/docs/.env") || is_link("{$release}/apps/docs/.env"))->toBeFalse();
        symlink('/etc', "{$release}/apps/docs/public/escape");
        expect($check('apps/docs/public'))->not->toBe(0);
    });

    it('prepares the selected release for a Route operation and asks for a deployment first', function (): void {
        [$instance] = prodweb_instance();
        prodweb_route($instance, 'docs.example.com', 'apps/docs/public');
        $ssh = new AppDevFakeSshExecutor([new CommandResult(0, '', '', 1, false), new CommandResult(3, '', '', 1, false)]);
        $manager = new RemoteProductionWebRootManager(prodweb_executor($ssh));
        $manager->prepare($instance);

        expect($ssh->commands[0]->arguments)->toBe(['bash', '-seu', '--', 'orbit-acme', '/home/orbit-acme', base64_encode("apps/docs/public\tapps/docs\n")])
            ->and($ssh->commands[0]->input)->toContain("link_served_environments \"\$selected\"\ngrant_served_web_roots \"\$selected\"");
        expect(fn () => $manager->prepare($instance))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('route.web_root_release_missing'));
    });

    it('writes APP_URL into the stable environment of a directory that the selected release serves', function (): void {
        [$instance] = prodweb_instance();
        $home = $this->sandbox.'/home';
        mkdir("{$home}/releases/r1/apps/docs", 0o755, true);
        mkdir("{$home}/releases/r1/apps/blog", 0o755, true);
        file_put_contents("{$home}/releases/r1/apps/docs/artisan", "<?php\n");
        file_put_contents("{$home}/releases/r1/apps/docs/.env.example", "APP_NAME=Laravel\nAPP_KEY=\nAPP_URL=http://localhost\nDB_CONNECTION=sqlite\n");
        $write = function (string $directory, string $url) use ($instance, $home): Process {
            $ssh = new AppDevFakeSshExecutor;
            new RemoteProductionRouteApplicationUrlWriter(prodweb_executor($ssh))->configureDirectoryUrl($instance, $directory, $url);
            $command = $ssh->commands[0];
            expect(array_slice($command->arguments, 0, 6))->toBe(['sudo', '-u', 'orbit-acme', '-H', 'python3', '-c'])
                ->and(array_slice($command->arguments, 7))->toBe(['/home/orbit-acme', $directory]);
            $process = new Process(['python3', '-c', $command->arguments[6], $home, $directory]);
            $process->setInput(stream_get_contents($command->protectedInput?->stream()));
            $process->run();

            return $process;
        };

        expect($write('apps/docs', 'https://docs.example.com')->getExitCode())->toBe(0)
            ->and(file_exists("{$home}/env"))->toBeFalse();
        symlink('releases/r1', "{$home}/current");
        expect($write('apps/docs', 'https://docs.example.com')->getExitCode())->toBe(0);
        $environment = file_get_contents("{$home}/env/apps/docs/.env");
        preg_match('/^APP_KEY=(.+)$/m', $environment, $key);

        expect($environment)->toContain("APP_NAME=\"Acme\"\n", "APP_URL=https://docs.example.com\n", "DB_CONNECTION=sqlite\n")
            ->and($key[1] ?? '')->toStartWith('"base64:')
            ->and(fileperms("{$home}/env/apps/docs/.env") & 0o777)->toBe(0o600)
            ->and(fileperms("{$home}/env/apps/docs") & 0o777)->toBe(0o700);
        expect($write('apps/docs', 'https://docs-two.example.com')->getExitCode())->toBe(0);
        expect(file_get_contents("{$home}/env/apps/docs/.env"))->toBe(str_replace('https://docs.example.com', 'https://docs-two.example.com', $environment));
        expect($write('apps/blog', 'https://blog.example.com')->getExitCode())->toBe(0)
            ->and(file_exists("{$home}/env/apps/blog/.env"))->toBeFalse();
    });

    it('adds a pool for each served directory and checks its directory and socket on the Node', function (): void {
        [$instance] = prodweb_instance();
        $runtime = new AppDevFakeSshExecutor;
        $manager = new RemoteProductionPhpRuntimeManager(new ProductionPhpRuntimeConfigRenderer, prodweb_executor($runtime), '/run/lock/orbit');
        $manager->converge($instance);
        prodweb_route($instance, 'docs.example.com', 'apps/docs/public');
        $manager->converge($instance);
        $manager->remove($instance);
        $converges = collect($runtime->commands)->filter(static fn (RemoteCommand $command): bool => ($command->arguments[4] ?? null) === 'converge')->values();
        $remove = collect($runtime->commands)->first(static fn (RemoteCommand $command): bool => ($command->arguments[4] ?? null) === 'remove');
        $suffix = substr(hash('sha256', 'apps/docs'), 0, 8);
        $pool = base64_decode($converges[1]->arguments[18], true);

        expect(count($converges[1]->arguments))->toBe(count($converges[0]->arguments))
            ->and($pool)->toStartWith(base64_decode($converges[0]->arguments[18], true))
            ->toContain("[orbit-orbit-acme-{$suffix}]\nuser = orbit-acme\n")
            ->toContain("listen = /run/php/orbit-acme.{$suffix}.sock\n")
            ->toContain("chdir = /home/orbit-acme/current/apps/docs\n")
            ->and(base64_decode($converges[1]->arguments[23], true))->toContain("chdir = /home/orbit-acme/releases/initial/apps/docs\n")
            ->and($converges[1]->input)->toContain("sed -n 's/^chdir = //p' | tail -n +2", "sed -n 's/^listen = //p' | tail -n +2")
            ->and($converges[0]->input)->not->toContain('served_directory')
            ->and($remove?->input)->not->toContain('served');
    });
});

/** @return array{Instance} */
function prodweb_instance(): array
{
    $node = Node::query()->create([
        'name' => 'prod-web',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.72',
        'wireguard_ip' => '10.44.0.72',
        'user' => 'orbit',
    ]);
    orbit_test_set_app_placement_role($node, true);
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'type' => 'laravel-app',
        'repository_url' => 'https://example.test/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'production',
        'environment' => 'production',
        'source_layout' => 'checkout',
        'checkout_path' => '/home/orbit-acme/releases/initial',
        'production_user' => 'orbit-acme',
        'production_home' => '/home/orbit-acme',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => '8.4',
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => InstanceState::Active,
    ]);
    $instance->update(ProductionPhpRuntimeIdentity::forProvisioning($instance->refresh(), '8.4')->attributes());
    prodweb_route($instance, 'acme.example.com');

    return [$instance->refresh()];
}

function prodweb_route(Instance $instance, string $domain, ?string $webRoot = null): Route
{
    $route = Route::query()->create([
        'project_id' => $instance->project_id,
        'node_id' => $instance->node_id,
        'domain' => $domain,
        ...($webRoot === null ? [] : ['web_root' => $webRoot]),
        'provenance' => 'explicit',
        'publication' => 'private',
        'status' => 'pending',
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);

    return $route->refresh();
}

function prodweb_run(object $test, string $release, string $entries): Process
{
    $process = new Process(
        ['bash', '-seu', '--', $release, $entries],
        env: ['PATH' => $test->sandbox.'/bin:'.getenv('PATH'), 'ORBIT_TEST_ACL_LOG' => $test->sandbox.'/acl.log'],
    );
    $process->setInput(ProductionWebRootProgram::functions()."\nuser=\$(id -un)\nhome=".escapeshellarg(dirname($release, 2))."\nserved_web_roots=\$2\nlink_served_environments \"\$1\"\ngrant_served_web_roots \"\$1\"\n");
    $process->run();

    return $process;
}

function prodweb_executor(AppDevFakeSshExecutor $ssh): ProductionSshExecutor
{
    return new ProductionSshExecutor(
        $ssh,
        new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/tmp/orbit-test-key';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 test';
            }
        },
        new class implements KnownHostsStore
        {
            public function path(): string
            {
                return '/tmp/orbit-test-known-hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
    );
}
