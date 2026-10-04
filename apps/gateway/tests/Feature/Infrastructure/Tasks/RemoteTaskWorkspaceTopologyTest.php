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
            root=$(dirname "$0")/..
            if [ "$1" = acquire ]; then
                if [ -e "$root/acquire-fails" ]; then
                    if [ -e "$root/stdout-failure" ]; then
                        # E2ECommand::outputFailure uses Laravel error(), which writes to stdout.
                        echo 'No topology capacity; token=topology-secret-value'
                    else
                        echo 'No topology capacity; token=topology-secret-value' >&2
                    fi
                    exit 7
                fi
                if [ -e "$root/interrupt-acquisition" ]; then
                    touch "$root/incomplete"
                    echo 'Construction failed and rollback was refused; lease retained.'
                    exit 7
                fi
                touch "$root/held"
            fi
            if [ "$1" = status ]; then
                if [ -e "$root/exits" ]; then echo 'Cannot read topology status'; exit 1; fi
                if [ -e "$root/incomplete" ]; then
                    if [ "${3:-}" = --json ]; then echo '{"state":"discovery","attempt_id":"0123","topology":null}'; else echo 'discovery 0123'; fi
                elif [ -e "$root/held" ]; then
                    if [ "${3:-}" = --json ]; then echo '{"state":"discovery","attempt_id":"0123","topology":{"purpose":"discovery","attempt_id":"0123"}}'; else echo 'discovery 0123'; fi
                else
                    if [ "${3:-}" = --json ]; then echo '{"state":"absent","proof":null}'; else echo absent; fi
                fi
                exit 0
            fi
            if [ "$1" = release ]; then rm -f "$root/incomplete" "$root/held"; fi
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
        $result = $topology->{$operation}($instance, 42);

        expect(file($checkout.'/calls', FILE_IGNORE_NEW_LINES))->toBe(str_replace('{checkout}', (string) realpath($checkout), $calls));
        if ($operation === 'acquire') {
            expect($result)->toBe(! $held);
        }
    } finally {
        new Filesystem()->deleteDirectory($checkout);
    }
})->with([
    'acquire a missing topology' => ['acquire', false, ['status TASK-42 --json', 'acquire TASK-42 {checkout}', 'status TASK-42 --json']],
    'keep a held topology' => ['acquire', true, ['status TASK-42 --json']],
    'release a held topology' => ['release', true, ['status TASK-42', 'release TASK-42']],
    'skip release without one' => ['release', false, ['status TASK-42']],
]);

it('reports the acquisition failure reason from stdout or stderr without claiming ready or leaking secrets', function (bool $stdout): void {
    [$topology, $instance, $checkout] = topology_workspace(true, false);
    touch($checkout.'/acquire-fails');
    if ($stdout) {
        touch($checkout.'/stdout-failure');
    }
    try {
        $failure = null;
        try {
            $topology->acquire($instance, 42);
        } catch (RuntimeException $exception) {
            $failure = $exception->getMessage();
        }
        expect($failure)->not->toBeNull()
            ->and($failure)->toContain('No topology capacity')
            ->and($failure)->not->toContain('topology-secret-value')
            ->and(file($checkout.'/calls', FILE_IGNORE_NEW_LINES))->toBe(['status TASK-42 --json', 'acquire TASK-42 '.realpath($checkout)]);
    } finally {
        new Filesystem()->deleteDirectory($checkout);
    }
})->with([false, true]);

it('does not acquire when topology status cannot be read', function (): void {
    [$topology, $instance, $checkout] = topology_workspace(true, false, true);

    try {
        expect(fn () => $topology->acquire($instance, 42))->toThrow(RuntimeException::class, 'Cannot read topology status');

        expect(file($checkout.'/calls', FILE_IGNORE_NEW_LINES))->toBe(['status TASK-42 --json']);
    } finally {
        new Filesystem()->deleteDirectory($checkout);
    }
});

it('reports retained incomplete acquisition state instead of already held and preserves it for normal cleanup', function (): void {
    [$topology, $instance, $checkout] = topology_workspace(true, false);
    touch($checkout.'/interrupt-acquisition');

    try {
        expect(fn () => $topology->acquire($instance, 42))->toThrow(RuntimeException::class, 'lease retained');
        expect(fn () => $topology->acquire($instance, 42))->toThrow(RuntimeException::class, 'without a complete discovery topology');
        expect(file_exists($checkout.'/incomplete'))->toBeTrue()
            ->and(file($checkout.'/calls', FILE_IGNORE_NEW_LINES))->toBe([
                'status TASK-42 --json', 'acquire TASK-42 '.realpath($checkout), 'status TASK-42 --json',
            ]);

        $topology->release($instance, 42);
        expect(file_exists($checkout.'/incomplete'))->toBeFalse()
            ->and(array_slice(file($checkout.'/calls', FILE_IGNORE_NEW_LINES), -2))->toBe(['status TASK-42', 'release TASK-42']);
    } finally {
        new Filesystem()->deleteDirectory($checkout);
    }
});

it('reports an unavailable acquisition but skips release in a workspace without the harness', function (string $operation): void {
    [$topology, $instance, $checkout] = topology_workspace(false, false);

    try {
        if ($operation === 'acquire') {
            expect(fn () => $topology->acquire($instance, 42))->toThrow(RuntimeException::class, 'no executable bin/e2e-topology harness');
        } else {
            $topology->release($instance, 42);
        }

        expect(file_exists($checkout.'/calls'))->toBeFalse();
    } finally {
        new Filesystem()->deleteDirectory($checkout);
    }
})->with(['acquire', 'release']);
