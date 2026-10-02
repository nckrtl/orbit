<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Instances\RemoteInstanceDependencyCopier;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/orbit-dependency-copier-'.bin2hex(random_bytes(6));
    mkdir("{$this->root}/acme/default/vendor/composer", 0755, true);
    mkdir("{$this->root}/acme/default/node_modules/.bin", 0755, true);
    mkdir("{$this->root}/acme/default/node_modules/vite/bin", 0755, true);
    mkdir("{$this->root}/acme/feature", 0755, true);
    file_put_contents("{$this->root}/acme/default/vendor/autoload.php", '<?php // autoload');
    file_put_contents("{$this->root}/acme/default/vendor/composer/installed.json", '{"packages":[]}');
    file_put_contents("{$this->root}/acme/default/node_modules/vite/bin/vite.js", 'vite');
    symlink('../vite/bin/vite.js', "{$this->root}/acme/default/node_modules/.bin/vite");
    file_put_contents("{$this->root}/acme/default/.env", 'APP_URL=https://default.acme.test');

    $this->project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/site.git',
    ]);
    $this->node = dependency_copier_node('app-dev', '10.44.0.21');
    $this->default = dependency_copier_instance($this->project, $this->node, 'default', "{$this->root}/acme/default");
    $this->feature = dependency_copier_instance($this->project, $this->node, 'feature', "{$this->root}/acme/feature");
});

afterEach(function (): void {
    (new NativeProcessRunner)->run(new ProcessInvocation(['chmod', '-R', 'u+rwX', '--', $this->root]));
    (new NativeProcessRunner)->run(new ProcessInvocation(['rm', '-rf', '--', $this->root]));
});

function dependency_copier_node(string $name, string $address): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => $address,
        'wireguard_ip' => $address,
        'user' => (string) posix_getpwuid(posix_geteuid())['name'],
    ]);
}

function dependency_copier_instance(Project $project, Node $node, string $name, string $checkout): Instance
{
    return Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => $name,
        'checkout_path' => $checkout,
    ]);
}

function dependency_copier(DependencyCopierLocalSsh $ssh): RemoteInstanceDependencyCopier
{
    return new RemoteInstanceDependencyCopier(
        $ssh,
        new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/tmp/key';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 synthetic';
            }
        },
        new class implements KnownHostsStore
        {
            public function path(): string
            {
                return '/tmp/known-hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
    );
}

describe('RemoteInstanceDependencyCopier', function (): void {
    it('copies vendor and node_modules into the target checkout and nothing else', function (): void {
        $ssh = new DependencyCopierLocalSsh;

        dependency_copier($ssh)->copy($this->default, $this->feature);

        expect(file_get_contents("{$this->root}/acme/feature/vendor/autoload.php"))->toBe('<?php // autoload')
            ->and(file_get_contents("{$this->root}/acme/feature/vendor/composer/installed.json"))->toBe('{"packages":[]}')
            ->and(readlink("{$this->root}/acme/feature/node_modules/.bin/vite"))->toBe('../vite/bin/vite.js')
            ->and(file_get_contents("{$this->root}/acme/feature/node_modules/.bin/vite"))->toBe('vite')
            ->and(file_exists("{$this->root}/acme/feature/.env"))->toBeFalse()
            ->and(glob("{$this->root}/acme/.orbit-copy.*") ?: [])->toBe([])
            ->and($ssh->prefix)->toBe(['timeout', '-k', '5', '120', 'sh', '-c'])
            ->and($ssh->hosts)->toBe(['10.44.0.21'])
            ->and($ssh->arguments)->toBe(["{$this->root}/acme/default", "{$this->root}/acme/feature", 'vendor', 'node_modules']);
    });

    it('skips a directory the source lacks, a symlinked source directory, and a directory the target has', function (): void {
        (new NativeProcessRunner)->run(new ProcessInvocation(['rm', '-rf', '--', "{$this->root}/acme/default/node_modules"]));
        mkdir("{$this->root}/shared-vendor");
        rename("{$this->root}/acme/default/vendor", "{$this->root}/shared-vendor/vendor");
        symlink("{$this->root}/shared-vendor/vendor", "{$this->root}/acme/default/vendor");
        $other = dependency_copier_instance($this->project, $this->node, 'other', "{$this->root}/acme/other");
        mkdir("{$this->root}/acme/other/vendor", 0755, true);
        file_put_contents("{$this->root}/acme/other/vendor/committed.php", 'tracked');

        dependency_copier(new DependencyCopierLocalSsh)->copy($this->default, $this->feature);
        dependency_copier(new DependencyCopierLocalSsh)->copy($this->default, $other);

        expect(file_exists("{$this->root}/acme/feature/vendor"))->toBeFalse()
            ->and(file_exists("{$this->root}/acme/feature/node_modules"))->toBeFalse()
            ->and(scandir("{$this->root}/acme/other/vendor"))->toBe(['.', '..', 'committed.php'])
            ->and(glob("{$this->root}/acme/.orbit-copy.*") ?: [])->toBe([]);
    });

    it('removes the staging copy and leaves no partial directory when cp fails', function (): void {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('root can read an unreadable file');
        }

        chmod("{$this->root}/acme/default/node_modules/vite/bin/vite.js", 0000);

        expect(fn () => dependency_copier(new DependencyCopierLocalSsh)->copy($this->default, $this->feature))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.dependency_copy_failed')
                ->and($exception->details['exit_code'])->toBe('1')
                ->and($exception->details['stderr'])->toContain('vite.js'));

        expect(file_exists("{$this->root}/acme/feature/vendor/autoload.php"))->toBeTrue()
            ->and(file_exists("{$this->root}/acme/feature/node_modules"))->toBeFalse()
            ->and(glob("{$this->root}/acme/.orbit-copy.*") ?: [])->toBe([]);
    });

    it('removes a staging copy that an interrupted copy left behind', function (): void {
        mkdir("{$this->root}/acme/.orbit-copy.feature.vendor/partial", 0755, true);

        dependency_copier(new DependencyCopierLocalSsh)->copy($this->default, $this->feature);

        expect(file_exists("{$this->root}/acme/feature/vendor/partial"))->toBeFalse()
            ->and(file_exists("{$this->root}/acme/feature/vendor/autoload.php"))->toBeTrue()
            ->and(glob("{$this->root}/acme/.orbit-copy.*") ?: [])->toBe([]);
    });

    it('refuses a source on another Node without running a command', function (): void {
        $ssh = new DependencyCopierLocalSsh;
        $remote = dependency_copier_instance($this->project, dependency_copier_node('other', '10.44.0.22'), 'remote', "{$this->root}/acme/remote");

        expect(fn () => dependency_copier($ssh)->copy($remote, $this->feature))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.dependency_copy_failed'));

        expect($ssh->hosts)->toBe([]);
    });
});

final class DependencyCopierLocalSsh implements SshExecutor
{
    /** @var list<string> */
    public array $hosts = [];

    /** @var list<string> */
    public array $arguments = [];

    /** @var list<string> */
    public array $prefix = [];

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->hosts[] = $connection->host;
        $this->prefix = array_slice($command->arguments, 0, 6);
        $this->arguments = array_slice($command->arguments, 8);

        return (new NativeProcessRunner)->run(new ProcessInvocation(
            arguments: $command->arguments,
            maxOutputBytes: $command->maxOutputBytes,
        ));
    }
}
