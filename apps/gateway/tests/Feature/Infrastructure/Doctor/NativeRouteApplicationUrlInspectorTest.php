<?php

declare(strict_types=1);

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Doctor\NativeRouteApplicationUrlInspector;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Support\AppDevFakeSshExecutor;

it('checks APP_URL in each application directory with one read-only command', function (): void {
    $checkout = sys_get_temp_dir().'/orbit-doctor-app-url-'.bin2hex(random_bytes(4));
    $application = static function (string $directory, ?string $environment) use ($checkout): void {
        File::ensureDirectoryExists($checkout.'/'.$directory);
        file_put_contents($checkout.'/'.$directory.'/artisan', '#!/usr/bin/env php');
        if ($environment !== null) {
            file_put_contents($checkout.'/'.$directory.'/.env', $environment);
        }
    };
    $application('apps/docs', "APP_NAME=Docs\nAPP_URL=https://docs.shop.test\n");
    $application('apps/quoted', "APP_URL=\"https://quoted.shop.test\"\n");
    $application('apps/stale', "APP_URL=https://old.shop.test\n");
    $application('apps/twice', "APP_URL=https://twice.shop.test\nAPP_URL=https://twice.shop.test\n");
    $application('apps/missing', null);
    File::ensureDirectoryExists($checkout.'/apps/static');
    file_put_contents($checkout.'/apps/static/.env', "APP_URL=https://other.shop.test\n");

    $expected = [
        'apps/docs' => 'https://docs.shop.test',
        'apps/quoted' => 'https://quoted.shop.test',
        'apps/stale' => 'https://stale.shop.test',
        'apps/twice' => 'https://twice.shop.test',
        'apps/missing' => 'https://missing.shop.test',
        'apps/static' => 'https://static.shop.test',
        'apps/absent' => 'https://absent.shop.test',
    ];
    $instance = route_application_url_instance($checkout.'/');
    $before = route_application_url_tree($checkout);
    $capture = new AppDevFakeSshExecutor([new CommandResult(0, '', '', 1, false)]);

    try {
        try {
            route_application_url_inspector($capture)->inspect($instance, $expected);
        } catch (DoctorInspectionException) {
            // The empty answer only captures the command.
        }
        $command = $capture->commands[0];
        $output = route_application_url_execute($command);
        $after = route_application_url_tree($checkout);
    } finally {
        File::deleteDirectory($checkout);
    }
    $ssh = new AppDevFakeSshExecutor([new CommandResult(0, $output, '', 1, false)]);
    $matches = route_application_url_inspector($ssh)->inspect($instance, $expected);

    expect($output)
        ->toBe("0=1\n1=1\n2=0\n3=0\n4=0\n5=1\n6=1\n")
        ->and($matches)
        ->toBe([
            'apps/docs' => true,
            'apps/quoted' => true,
            'apps/stale' => false,
            'apps/twice' => false,
            'apps/missing' => false,
            'apps/static' => true,
            'apps/absent' => true,
        ])
        ->and($after)
        ->toBe($before)
        ->and($ssh->commands)
        ->toHaveCount(1)
        ->and(array_slice($command->arguments, 0, 5))
        ->toBe(['sudo', 'bash', '-seu', '--', $checkout]);
});

it('checks the checkout itself when a Route serves it', function (): void {
    $instance = route_application_url_instance('/srv/apps/shop');
    $ssh = new AppDevFakeSshExecutor([new CommandResult(0, "0=0\n", '', 1, false)]);

    $matches = route_application_url_inspector($ssh)->inspect($instance, ['' => 'https://root.shop.test']);

    expect($matches)
        ->toBe(['' => false])
        ->and(array_slice($ssh->commands[0]->arguments, 4))
        ->toBe(['/srv/apps/shop', '', 'https://root.shop.test']);
});

it('fails closed on a failed, short, or malformed observation and on an invalid directory', function (array $expected, CommandResult $result): void {
    $instance = route_application_url_instance('/srv/apps/shop');

    expect(fn (): array => route_application_url_inspector(new AppDevFakeSshExecutor([$result]))->inspect($instance, $expected))
        ->toThrow(DoctorInspectionException::class);
})->with([
    'failed command' => [['apps/docs' => 'https://docs.test'], new CommandResult(1, '', 'permission denied', 1, false)],
    'short output' => [['apps/docs' => 'https://docs.test', 'apps/admin' => 'https://admin.test'], new CommandResult(0, "0=1\n", '', 1, false)],
    'malformed output' => [['apps/docs' => 'https://docs.test'], new CommandResult(0, "0=yes\n", '', 1, false)],
    'invalid directory' => [['../docs' => 'https://docs.test'], new CommandResult(0, "0=1\n", '', 1, false)],
]);

function route_application_url_instance(string $checkout): Instance
{
    $project = Project::query()->create([
        'name' => 'Doctor App URL',
        'slug' => 'doctor-app-url-'.uniqid(),
        'repository_url' => 'https://git.example.test/acme/doctor-app-url.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $node = Node::query()->create([
        'name' => 'doctor-app-url-'.uniqid(),
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.90',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.9.'.random_int(2, 250),
    ]);
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);

    return Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'development',
        'environment' => 'development',
        'checkout_path' => $checkout,
        'branch' => 'development',
        'starting_commit' => str_repeat('a', 40),
        'status' => InstanceState::Active,
    ])->fresh(['node', 'project']);
}

/** Runs the remote script locally without `sudo`, as the Node would run it. */
function route_application_url_execute(RemoteCommand $command): string
{
    $process = new Process(['bash', ...array_slice($command->arguments, 2)], timeout: 30);
    $process->setInput($command->input);
    $process->mustRun();

    return $process->getOutput();
}

/** @return array<string, string> */
function route_application_url_tree(string $root): array
{
    $tree = [];
    foreach (File::allFiles($root, true) as $file) {
        $tree[$file->getRelativePathname()] = (string) file_get_contents($file->getPathname());
    }
    ksort($tree);

    return $tree;
}

function route_application_url_inspector(AppDevFakeSshExecutor $ssh): NativeRouteApplicationUrlInspector
{
    return new NativeRouteApplicationUrlInspector(
        new DevelopmentSshExecutor($ssh, route_application_url_keys(), route_application_url_hosts()),
        new CommandDeadline,
    );
}

function route_application_url_keys(): SshKeyProvider
{
    return new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/tmp/doctor-key';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 AAAA';
        }
    };
}

function route_application_url_hosts(): KnownHostsStore
{
    return new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/tmp/doctor-known-hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };
}
