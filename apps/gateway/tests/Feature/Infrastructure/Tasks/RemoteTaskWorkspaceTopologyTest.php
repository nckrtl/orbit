<?php

declare(strict_types=1);

use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\RemoteTaskWorkspaceTopology;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Tests\Support\LocalShellSshExecutor;

/** A workspace whose stub harness logs its arguments and answers `status` from a marker file. */
function topology_workspace(bool $harness, bool $held, bool $exits = false): array
{
    $checkout = sys_get_temp_dir().'/orbit-topology-'.Str::uuid();
    mkdir($checkout.'/bin', 0755, true);
    if ($harness) {
        file_put_contents($checkout.'/bin/e2e-topology', <<<'BASH'
            #!/bin/bash
            printf '%s\n' "$*" >> "$(dirname "$0")/../calls"
            if [ "$1" = status ]; then
                if [ -e "$(dirname "$0")/../held" ]; then echo 'discovery 0123'; elif [ -e "$(dirname "$0")/../exits" ]; then exit 1; else echo absent; fi
                exit 0
            fi
            BASH);
        chmod($checkout.'/bin/e2e-topology', 0755);
    }
    if ($held) {
        touch($checkout.'/held');
    }
    if ($exits) {
        touch($checkout.'/exits');
    }
    $node = Node::query()->create(['name' => 'topology-'.Str::random(6), 'wireguard_ip' => '10.44.0.'.random_int(150, 250), 'user' => 'orbit', 'public_ssh_host' => '127.0.0.1']);
    $instance = new Instance(['checkout_path' => $checkout]);
    $instance->setRelation('node', $node);
    $topology = new RemoteTaskWorkspaceTopology(new DevelopmentSshExecutor(new LocalShellSshExecutor, new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/tmp/orbit-test-key';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 test';
        }
    }, new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/tmp/orbit-test-known-hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    }));

    return [$topology, $instance, $checkout];
}

it('acquires only when the group holds no topology and releases only one it holds', function (string $operation, bool $held, array $calls): void {
    [$topology, $instance, $checkout] = topology_workspace(true, $held);

    try {
        $topology->{$operation}($instance, 42);

        expect(file($checkout.'/calls', FILE_IGNORE_NEW_LINES))->toBe($calls);
    } finally {
        new Filesystem()->deleteDirectory($checkout);
    }
})->with([
    'acquire a missing topology' => ['acquire', false, ['status TASK-42', 'acquire TASK-42 .']],
    'keep a held topology' => ['acquire', true, ['status TASK-42']],
    'release a held topology' => ['release', true, ['status TASK-42', 'release TASK-42']],
    'skip release without one' => ['release', false, ['status TASK-42']],
]);

it('treats a harness whose status exits non-zero as holding no topology', function (): void {
    [$topology, $instance, $checkout] = topology_workspace(true, false, true);

    try {
        $topology->acquire($instance, 42);
        $topology->release($instance, 42);

        expect(file($checkout.'/calls', FILE_IGNORE_NEW_LINES))->toBe(['status TASK-42', 'acquire TASK-42 .', 'status TASK-42']);
    } finally {
        new Filesystem()->deleteDirectory($checkout);
    }
});

it('does nothing in a workspace without the harness', function (string $operation): void {
    [$topology, $instance, $checkout] = topology_workspace(false, false);

    try {
        $topology->{$operation}($instance, 42);

        expect(file_exists($checkout.'/calls'))->toBeFalse();
    } finally {
        new Filesystem()->deleteDirectory($checkout);
    }
})->with(['acquire', 'release']);
