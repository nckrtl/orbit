<?php

declare(strict_types=1);

use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\Nodes\NodeAgentFootprint;
use App\Infrastructure\Nodes\NodeBootstrapCommandFactory;
use App\Infrastructure\Nodes\Roles\NodeRolePrerequisiteCommandFactory;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\IncusTaskVmImageBuilder;
use App\Jobs\TaskVms\RefreshTaskVmBaseImages;
use App\Models\Node;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

const IMAGE_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakeGatewayPublicKeyMaterial0123456789abcd orbit@gateway';

const IMAGE_NEW = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

const IMAGE_OLD = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

const IMAGE_USED = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

const IMAGE_STOCK = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';

/**
 * An Incus host that answers by argv: `$state` holds its images (fingerprint => aliases) and instances
 * (name => base image), and `$fail` names an operation that fails.
 */
final class FakeIncusImageHost implements SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    /** @var array<string, list<string>> */
    public array $images = [];

    /** @var array<string, ?string> */
    public array $instances = [];

    public ?string $fail = null;

    private int $cloudInitPolls = 0;

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->commands[] = $command;
        $argv = $command->arguments;
        if ($argv[3] === 'query') {
            return $this->answer('query', fn () => $this->retarget(json_decode($argv[7], true)['target']));
        }
        $operation = array_slice($argv, 5);

        return match (true) {
            $operation[0] === 'list' => $this->answer('list', fn () => json_encode(array_map(
                static fn (string $name, ?string $image): array => ['name' => $name, 'config' => $image === null ? [] : ['volatile.base_image' => $image]],
                array_keys($this->instances),
                $this->instances,
            ))),
            $operation[0] === 'image' && $operation[1] === 'list' => $this->answer('image list', fn () => json_encode(array_map(
                static fn (string $fingerprint, array $aliases): array => ['fingerprint' => $fingerprint, 'aliases' => array_map(static fn (string $name): array => ['name' => $name], $aliases)],
                array_keys($this->images),
                $this->images,
            ))),
            $operation[0] === 'launch' => $this->answer('launch', fn () => $this->instances[end($operation)] = $this->fingerprintOf($operation[count($operation) - 2])),
            $operation[0] === 'exec' => $this->exec($operation[array_search('--', $operation, true) + 1], array_slice($operation, array_search('--', $operation, true) + 2)),
            $operation[0] === 'stop' => $this->answer('stop'),
            $operation[0] === 'publish' => $this->answer('publish', fn () => $this->images[IMAGE_NEW] = [$operation[4]]),
            $operation[0] === 'delete' => $this->answer('delete '.end($operation), function () use ($operation): void {
                unset($this->instances[end($operation)]);
            }),
            $operation[0] === 'image' && $operation[1] === 'alias' => $this->answer('image alias', fn () => $this->images[$operation[5]][] = $operation[4]),
            $operation[0] === 'image' && $operation[1] === 'delete' => $this->answer('image delete', function () use ($operation): void {
                unset($this->images[end($operation)]);
            }),
            default => throw new RuntimeException('Unexpected incus command: '.implode(' ', $argv)),
        };
    }

    /** @param  list<string>  $command */
    private function exec(string $name, array $command): CommandResult
    {
        return match ($command[0]) {
            // The guest agent is not up on the first poll, cloud-init runs on the second.
            'cloud-init' => match (true) {
                $this->fail === 'cloud-init' => new CommandResult(1, '{"status":"error","errors":["apt failed"]}', '', 1, false),
                $this->cloudInitPolls++ % 3 === 0 => new CommandResult(1, '', 'Error: VM agent isn\'t currently running', 1, false),
                $this->cloudInitPolls % 3 === 2 => new CommandResult(0, '{"status":"running","errors":[]}', '', 1, false),
                default => new CommandResult(0, '{"status":"done","errors":[]}', '', 1, false),
            },
            'uname' => new CommandResult(0, "x86_64\n", '', 1, false),
            'ssh-keygen' => $this->answer('ssh-keygen', static fn (): string => "256 SHA256:tHGyu/bpS80ShKGdwihLDLg9TzFRZCdsWqnuyInfRsg root@smoke (ED25519)\n"),
            'bash' => $command[1] === '-s'
                ? ($this->fail === 'clean' ? new CommandResult(1, '', "base-image-clean: an SSH host key remains\n", 1, false) : new CommandResult(0, "{\"ok\":true}\n", '', 1, false))
                : $this->answer('agent'),
            default => $this->answer($command[0] === 'runuser' ? 'program' : 'exec'),
        };
    }

    private function fingerprintOf(string $alias): ?string
    {
        foreach ($this->images as $fingerprint => $aliases) {
            if (in_array($alias, $aliases, true)) {
                return $fingerprint;
            }
        }

        return null;
    }

    private function retarget(string $fingerprint): void
    {
        foreach ($this->images as $image => $aliases) {
            $this->images[$image] = array_values(array_diff($aliases, [TaskVmHost::BaseImage]));
        }
        $this->images[$fingerprint][] = TaskVmHost::BaseImage;
    }

    private function answer(string $operation, ?Closure $effect = null): CommandResult
    {
        if ($this->fail === $operation) {
            return new CommandResult(1, '', "Error: {$operation} failed\n", 1, false);
        }
        $output = $effect instanceof Closure ? $effect() : null;

        return new CommandResult(0, is_string($output) ? $output : '', '', 1, false);
    }
}

beforeEach(function (): void {
    Sleep::fake();
    $this->host = Node::query()->create([
        'name' => 'beast', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.7', 'wireguard_ip' => '10.44.0.7', 'user' => 'nckrtl',
    ]);
    $this->settings = new TaskVmSettings(true, 4, '10.44.0.128/25', [
        new TaskVmHost($this->host->id, 'orbit-tasks', 'orbittask0', '10.251.77.0/24', 2, 2, '4GiB', '20GiB'),
    ], 'http://10.44.0.3:8317', null, null, []);
    app()->instance(TaskVmSettings::class, $this->settings);
    $this->incus = new FakeIncusImageHost;
    $this->incus->images = [IMAGE_STOCK => [TaskVmHost::SourceImage]];
    app()->instance(SshExecutor::class, $this->incus);
    app()->instance(SshKeyProvider::class, new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/keys/id_ed25519';
        }

        public function publicKey(): string
        {
            return IMAGE_KEY;
        }
    });
    app()->instance(KnownHostsStore::class, new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/keys/known_hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    });
    $this->build = function (array &$stages = []): string {
        return app(IncusTaskVmImageBuilder::class)->build($this->settings->host($this->host->id), $this->host, function (string $stage) use (&$stages): void {
            $stages[] = $stage;
        });
    };
    $this->argv = fn (): array => array_map(static fn (RemoteCommand $command): string => implode(' ', array_slice($command->arguments, 5)), $this->incus->commands);
});

describe('a build', function (): void {
    it('boots the stock image, runs the enrollment programs, cleans, publishes, smoke-tests and promotes the image', function (): void {
        $this->travelTo('2026-10-10 03:00:00');
        $stages = [];

        $fingerprint = ($this->build)($stages);

        $node = new Node(['name' => 'task-vm-base-image', 'platform' => 'linux', 'user' => 'orbit']);
        $programs = [
            app(NodeBootstrapCommandFactory::class)->makeWithPasswordlessSudo($node, 'orbit'),
            app(NodeRolePrerequisiteCommandFactory::class)->caddyPackage($node, RoleName::AppDev),
            app(NodeRolePrerequisiteCommandFactory::class)->make($node, RoleName::AppDev, new ManagedUserAccount('orbit', 'orbit', '/home/orbit')),
        ];
        $execs = array_values(array_filter($this->incus->commands, static fn (RemoteCommand $command): bool => array_slice($command->arguments, 5, 11) === [
            'exec', '--cwd', '/home/orbit', '--env', 'HOME=/home/orbit', '--env', 'USER=orbit', '--', 'tvm-image-20261010030000', 'runuser', '-u',
        ]));
        $launches = array_values(array_filter($this->incus->commands, static fn (RemoteCommand $command): bool => $command->arguments[5] === 'launch'));

        expect($fingerprint)->toBe(IMAGE_NEW)
            ->and($stages)->toBe(['sweep', 'launch', 'cloud-init', 'bootstrap', 'caddy-package', 'app-dev-prerequisites', 'agent-binary', 'clean', 'publish', 'delete-builder', 'smoke', 'promote', 'prune'])
            ->and(array_map(static fn (RemoteCommand $command): array => [array_slice($command->arguments, 18), $command->input], $execs))
            ->toBe(array_map(static fn (?RemoteCommand $program): array => [$program?->arguments, $program?->input], $programs))
            ->and(array_slice($launches[0]->arguments, -3))->toBe(['--', 'ubuntu-26.04-vm', 'tvm-image-20261010030000'])
            ->and($launches[0]->input)->toContain('package_upgrade')->toContain('libnss3')->toContain('openssh-server')
            ->and(array_slice($launches[1]->arguments, -3))->toBe(['--', 'orbit-task-base-20261010030000', 'tvm-image-20261010030000-smoke'])
            ->and($launches[1]->input)->not->toContain('packages')
            ->and(($this->argv)())->toContain(
                'stop -- tvm-image-20261010030000',
                'publish --compression none --alias orbit-task-base-20261010030000 -- tvm-image-20261010030000',
                'delete --force -- tvm-image-20261010030000',
                'delete --force -- tvm-image-20261010030000-smoke',
                'image alias create -- orbit-task-base '.IMAGE_NEW,
            )
            ->and($this->incus->images)->toBe([IMAGE_STOCK => ['ubuntu-26.04-vm'], IMAGE_NEW => ['orbit-task-base-20261010030000', 'orbit-task-base']])
            ->and($this->incus->instances)->toBe([]);

        $agent = collect($this->incus->commands)->first(static fn (RemoteCommand $command): bool => ($command->arguments[8] ?? null) === 'bash' && $command->arguments[9] === '-ceu');
        $clean = collect($this->incus->commands)->first(static fn (RemoteCommand $command): bool => array_slice($command->arguments, 8) === ['bash', '-s']);
        expect(array_slice($agent->arguments, -2))->toBe([NodeAgentFootprint::downloadUrl('x86_64'), NodeAgentFootprint::checksum('x86_64')])
            ->and($clean->input)->toBe(file_get_contents(resource_path('task-vms/base-image-clean.sh')));
    });

    it('moves the base alias in one request, keeps an image a VM uses and deletes the unused ones', function (): void {
        $this->incus->images += [
            IMAGE_OLD => ['orbit-task-base-20261009030000', TaskVmHost::BaseImage],
            IMAGE_USED => ['orbit-task-base-20261008030000'],
        ];
        $this->incus->instances = ['tvm-3' => IMAGE_USED, 'tvm-4' => IMAGE_OLD];

        ($this->build)();

        $query = collect($this->incus->commands)->first(static fn (RemoteCommand $command): bool => $command->arguments[3] === 'query');
        expect($query->arguments)->toBe(['sudo', '-n', 'incus', 'query', '-X', 'PATCH', '--data', '{"target":"'.IMAGE_NEW.'"}', '--', '/1.0/images/aliases/orbit-task-base?project=orbit-tasks'])
            ->and(array_keys($this->incus->images))->toBe([IMAGE_STOCK, IMAGE_OLD, IMAGE_USED, IMAGE_NEW])
            ->and($this->incus->images[IMAGE_NEW])->toContain(TaskVmHost::BaseImage);

        unset($this->incus->instances['tvm-3'], $this->incus->instances['tvm-4']);
        $this->travel(1)->days();
        ($this->build)();

        expect(array_keys($this->incus->images))->toBe([IMAGE_STOCK, IMAGE_NEW]);
    });

    it('deletes the builder and smoke VMs that an interrupted build left', function (): void {
        $this->incus->instances = ['tvm-image-20261009030000' => IMAGE_STOCK, 'tvm-image-20261009030000-smoke' => null, 'tvm-7' => IMAGE_OLD];

        ($this->build)();

        expect(($this->argv)())->toContain('delete --force -- tvm-image-20261009030000', 'delete --force -- tvm-image-20261009030000-smoke')
            ->not->toContain('delete --force -- tvm-7')
            ->and(array_keys($this->incus->instances))->toBe(['tvm-7']);
    });
});

describe('a failed build', function (): void {
    it('keeps the current image and leaves nothing behind', function (string $fail, string $stage, array $images): void {
        $this->travelTo('2026-10-10 03:00:00');
        $this->incus->images += [IMAGE_OLD => ['orbit-task-base-20261009030000', TaskVmHost::BaseImage]];
        $this->incus->fail = $fail;

        expect(fn () => ($this->build)())->toThrow(fn (TaskVmException $e) => expect([$e->errorCode, $e->status, $e->getMessage()])
            ->toMatchArray([0 => 'task_vm.image_build_failed', 1 => 502])
            ->and($e->getMessage())->toStartWith("The base image build of Node [beast] failed at stage [{$stage}]: "));

        expect($this->incus->instances)->toBe([])
            ->and($this->incus->images)->toBe($images);
    })->with([
        'cloud-init error' => ['cloud-init', 'cloud-init', [IMAGE_STOCK => ['ubuntu-26.04-vm'], IMAGE_OLD => ['orbit-task-base-20261009030000', 'orbit-task-base']]],
        'identity left' => ['clean', 'clean', [IMAGE_STOCK => ['ubuntu-26.04-vm'], IMAGE_OLD => ['orbit-task-base-20261009030000', 'orbit-task-base']]],
        'smoke VM' => ['ssh-keygen', 'smoke', [IMAGE_STOCK => ['ubuntu-26.04-vm'], IMAGE_OLD => ['orbit-task-base-20261009030000', 'orbit-task-base']]],
        'alias move' => ['query', 'promote', [IMAGE_STOCK => ['ubuntu-26.04-vm'], IMAGE_OLD => ['orbit-task-base-20261009030000', 'orbit-task-base']]],
        'prune, after the new image is the base image' => ['image delete', 'prune', [IMAGE_STOCK => ['ubuntu-26.04-vm'], IMAGE_OLD => ['orbit-task-base-20261009030000'], IMAGE_NEW => ['orbit-task-base-20261010030000', 'orbit-task-base']]],
    ]);

    it('refuses a second build of the same host while one runs', function (): void {
        $lock = Cache::lock('task-vms:image-build:'.$this->host->id, 60);
        $lock->get();

        expect(fn () => ($this->build)())->toThrow(TaskVmException::class, 'A base image build of Node [beast] is already running.');
        expect($this->incus->commands)->toBe([]);
        $lock->release();
    });
});

describe('task-vms:build-image', function (): void {
    it('prints every stage and the new image', function (): void {
        $this->artisan('task-vms:build-image', ['node' => 'beast'])
            ->expectsOutputToContain('app-dev-prerequisites')
            ->expectsOutputToContain('Node [beast] has the base image [orbit-task-base] (aaaaaaaaaaaa)')
            ->assertSuccessful();
    });

    it('fails with the code of the failed stage', function (): void {
        $this->incus->fail = 'publish';

        $this->artisan('task-vms:build-image', ['node' => (string) $this->host->id])
            ->expectsOutputToContain('[task_vm.image_build_failed] The base image build of Node [beast] failed at stage [publish]')
            ->assertFailed();
    });

    it('refuses a Node that is not a task VM host', function (): void {
        Node::query()->create(['name' => 'shark', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '192.0.2.8', 'wireguard_ip' => '10.44.0.11', 'user' => 'nckrtl']);

        $this->artisan('task-vms:build-image', ['node' => 'shark'])
            ->expectsOutputToContain('[task_vm.unknown_host]')
            ->assertFailed();
        expect($this->incus->commands)->toBe([]);
    });
});

describe('the nightly refresh', function (): void {
    it('rebuilds only the hosts that already have a base image', function (): void {
        $shark = Node::query()->create(['name' => 'shark', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '192.0.2.8', 'wireguard_ip' => '10.44.0.11', 'user' => 'nckrtl']);
        $settings = new TaskVmSettings(true, 4, '10.44.0.128/25', [
            new TaskVmHost($shark->id, 'orbit-tasks', 'orbittask0', '10.251.78.0/24', 2, 2, '4GiB', '20GiB'),
            ...$this->settings->hosts,
        ], 'http://10.44.0.3:8317', null, null, []);
        app()->instance(TaskVmSettings::class, $settings);

        app()->call([new RefreshTaskVmBaseImages, 'handle']);
        expect(($this->argv)())->toBe(['image list --format json', 'image list --format json']);

        $this->incus->images += [IMAGE_OLD => ['orbit-task-base-20261009030000', TaskVmHost::BaseImage]];
        app()->call([new RefreshTaskVmBaseImages, 'handle']);
        expect($this->incus->images[IMAGE_NEW])->toContain(TaskVmHost::BaseImage);
    });

    it('fails after the other hosts when a build fails', function (): void {
        $this->incus->images += [IMAGE_OLD => ['orbit-task-base-20261009030000', TaskVmHost::BaseImage]];
        $this->incus->fail = 'launch';

        expect(fn () => app()->call([new RefreshTaskVmBaseImages, 'handle']))
            ->toThrow(TaskVmException::class, 'The base image refresh failed on Node [beast]: task_vm.image_build_failed.');
    });

    it('is queued on the task VM queue every night at 03:00 while task VMs are enabled', function (): void {
        Artisan::call('schedule:list');
        $refresh = collect(app(Schedule::class)->events())->first(static fn ($event): bool => $event instanceof CallbackEvent && $event->description === RefreshTaskVmBaseImages::class);

        config(['task_vms.enabled' => false]);
        expect($refresh)->not->toBeNull()
            ->and($refresh->expression)->toBe('0 3 * * *')
            ->and($refresh->filtersPass(app()))->toBeFalse()
            ->and([new RefreshTaskVmBaseImages()->connection, new RefreshTaskVmBaseImages()->queue])->toBe(['task-vms', 'task-vms']);

        config(['task_vms.enabled' => true]);
        expect($refresh->filtersPass(app()))->toBeTrue();
    });
});
