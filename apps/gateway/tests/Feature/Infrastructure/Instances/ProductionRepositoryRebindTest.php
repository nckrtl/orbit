<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Instances\ProductionRepositoryRecord;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppProd\ProductionSshExecutor;
use App\Infrastructure\Instances\RemoteProductionDeployment;
use App\Infrastructure\Instances\RemoteProductionRepositoryBinding;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\Support\AppDevFakeSshExecutor;

const REBIND_SSH_URL = 'git@github.com:acme/site.git';
const REBIND_HTTPS_URL = 'https://github.com/acme/site.git';

describe('ProductionRepositoryRecord', function (): void {
    it('treats SSH and HTTPS URLs of one repository as the same repository', function (): void {
        $record = new ProductionRepositoryRecord(REBIND_SSH_URL, REBIND_SSH_URL, ['a' => REBIND_SSH_URL, 'b' => REBIND_HTTPS_URL]);

        expect($record->sameRepositoryAs(REBIND_HTTPS_URL))->toBeTrue()
            ->and($record->sameRepositoryAs('https://github.com/acme/site'))->toBeTrue()
            ->and($record->sameRepositoryAs('https://github.com/acme/other.git'))->toBeFalse()
            ->and($record->boundTo(REBIND_HTTPS_URL))->toBeFalse()
            ->and($record->withRepository(REBIND_HTTPS_URL)->boundTo(REBIND_HTTPS_URL))->toBeTrue()
            ->and(new ProductionRepositoryRecord('not a url', null, [])->sameRepositoryAs(REBIND_HTTPS_URL))->toBeFalse();
    });

    it('round-trips through stored inventory', function (): void {
        $record = new ProductionRepositoryRecord(REBIND_SSH_URL, null, ['initial' => REBIND_SSH_URL]);

        expect(ProductionRepositoryRecord::fromArray($record->toArray()))->toEqual($record);
    });
});

describe('RemoteProductionRepositoryBinding', function (): void {
    it('re-binds a production home that records the SSH URL after the Project moved to HTTPS', function (): void {
        $sandbox = rebind_sandbox();
        $executor = rebind_local_executor($sandbox);
        [$instance, $deployment, $binding] = rebind_instance($executor, REBIND_HTTPS_URL, "{$sandbox}/home");

        try {
            // The repro: the Project URL changed, so selection refuses the home.
            expect(fn () => $deployment->selected($instance))
                ->toThrow(function (ResourceOperationException $exception): void {
                    expect($exception->errorCode)->toBe('deployment.repository_rebind_required')
                        ->and($exception->getMessage())->toContain('orbit project:update');
                });

            $record = $binding->inspect($instance);

            expect($record)->toEqual(new ProductionRepositoryRecord(
                REBIND_SSH_URL,
                REBIND_SSH_URL,
                ['20261001000000-a' => REBIND_SSH_URL, 'initial' => REBIND_SSH_URL],
            ));

            $binding->rebind($instance, $record, $record->withRepository(REBIND_HTTPS_URL));
            // A repeated re-bind is a no-op.
            $binding->rebind($instance, $record, $record->withRepository(REBIND_HTTPS_URL));

            expect($binding->inspect($instance)->boundTo(REBIND_HTTPS_URL))->toBeTrue()
                ->and(decoct(fileperms("{$sandbox}/state/release-layout") & 0o777))->toBe('600')
                ->and(file_get_contents("{$sandbox}/state/release-layout"))
                ->toBe(REBIND_HTTPS_URL."\0rebind\0{$sandbox}/home\0initial\0")
                ->and(file_get_contents("{$sandbox}/state/initial-clone"))
                ->toBe(REBIND_HTTPS_URL."\0rebind\0{$sandbox}/home\0")
                ->and($deployment->selected($instance)?->name)->toBe('initial')
                ->and($deployment->retained($instance, '20261001000000-a')->name)->toBe('20261001000000-a');

            // Rollback restores every recorded URL.
            $binding->rebind($instance, $record->withRepository(REBIND_HTTPS_URL), $record);

            expect($binding->inspect($instance))->toEqual($record);
        } finally {
            new Filesystem()->deleteDirectory($sandbox);
        }
    });

    it('refuses to rewrite a value that is neither the expected nor the target URL', function (): void {
        $sandbox = rebind_sandbox();
        $executor = rebind_local_executor($sandbox);
        [$instance, , $binding] = rebind_instance($executor, REBIND_HTTPS_URL, "{$sandbox}/home");

        try {
            $record = $binding->inspect($instance);
            $stale = new ProductionRepositoryRecord('git@github.com:acme/other.git', null, []);

            expect(fn () => $binding->rebind($instance, $stale, $stale->withRepository(REBIND_HTTPS_URL)))
                ->toThrow(function (ResourceOperationException $exception): void {
                    expect($exception->errorCode)->toBe('project.production_rebind_failed');
                });

            expect($binding->inspect($instance))->toEqual($record);
        } finally {
            new Filesystem()->deleteDirectory($sandbox);
        }
    });

    it('names a different repository instead of a re-bind', function (): void {
        $ssh = new AppDevFakeSshExecutor([
            new CommandResult(1, '', '', 1, false),
            new CommandResult(0, "MARKER\trelease-layout\t".base64_encode("git@github.com:acme/other.git\0orbit-app-1\0/home/orbit-app-1\0initial\0")."\n", '', 1, false),
        ]);
        [$instance, $deployment] = rebind_instance(rebind_executor($ssh), REBIND_HTTPS_URL, '/home/orbit-app-1', 'orbit-app-1');

        expect(fn () => $deployment->selected($instance))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('deployment.repository_mismatch');
            });
    });

    it('keeps the original selection error when the home records the Project URL', function (): void {
        $ssh = new AppDevFakeSshExecutor([
            new CommandResult(1, '', '', 1, false),
            new CommandResult(0, "MARKER\trelease-layout\t".base64_encode(REBIND_HTTPS_URL."\0orbit-app-1\0/home/orbit-app-1\0initial\0")."\n", '', 1, false),
        ]);
        [$instance, $deployment] = rebind_instance(rebind_executor($ssh), REBIND_HTTPS_URL, '/home/orbit-app-1', 'orbit-app-1');

        expect(fn () => $deployment->selected($instance))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('deployment.selection_invalid');
            });
    });
});

function rebind_sandbox(): string
{
    $sandbox = sys_get_temp_dir().'/orbit-production-rebind-'.bin2hex(random_bytes(6));
    $home = "{$sandbox}/home";
    mkdir("{$sandbox}/state", 0o700, true);
    mkdir("{$sandbox}/bin", 0o755, true);
    file_put_contents(
        "{$sandbox}/bin/sudo",
        <<<'BASH'
            #!/usr/bin/env bash
            if [ "${1:-}" = -u ]; then shift 2; fi
            if [ "${1:-}" = -H ]; then shift; fi
            exec "$@"
            BASH,
    );
    chmod("{$sandbox}/bin/sudo", 0o755);
    mkdir("{$home}/releases", 0o700, true);
    file_put_contents("{$home}/.env", "APP_ENV=production\n");

    foreach (['initial', '20261001000000-a'] as $name) {
        $release = "{$home}/releases/{$name}";
        mkdir("{$release}/public", 0o700, true);
        file_put_contents("{$release}/public/index.php", "<?php\n");
        foreach ([
            ['git', 'init', '--quiet', $release],
            ['git', '-C', $release, 'remote', 'add', 'origin', REBIND_SSH_URL],
            ['git', '-C', $release, 'add', 'public/index.php'],
            ['git', '-C', $release, '-c', 'user.name=Orbit Test', '-c', 'user.email=orbit@example.test', 'commit', '--quiet', '-m', 'fixture'],
        ] as $arguments) {
            new Process($arguments)->mustRun();
        }
        symlink('../../.env', "{$release}/.env");
    }

    // A partial release has no origin and stays out of the record.
    mkdir("{$home}/releases/partial/.git", 0o700, true);
    symlink('releases/initial', "{$home}/current");

    foreach ([
        'release-layout' => REBIND_SSH_URL."\0rebind\0{$home}\0initial\0",
        'initial-clone' => REBIND_SSH_URL."\0rebind\0{$home}\0",
    ] as $marker => $content) {
        file_put_contents("{$sandbox}/state/{$marker}", $content);
        chmod("{$sandbox}/state/{$marker}", 0o600);
    }

    return $sandbox;
}

/**
 * Runs the real remote programs locally, as the test user, against the sandbox.
 */
function rebind_local_executor(string $sandbox): ProductionSshExecutor
{
    return rebind_executor(new class($sandbox) implements SshExecutor
    {
        public function __construct(private readonly string $sandbox) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $input = $command->protectedInput?->stream() !== null
                ? stream_get_contents($command->protectedInput->stream())
                : (string) $command->input;
            $input = str_replace(
                [
                    'state_directory="/var/lib/orbit/app-instance-sources/$instance"',
                    'test "$home" = "/home/$user"',
                    'root:root:700',
                    'root:root:600',
                    'sudo chown root:root -- "$temporary"',
                    '! -user "$user"',
                    '! -group "$user"',
                ],
                [
                    'state_directory="'.$this->sandbox.'/state"',
                    'test -d "$home"',
                    '"$(id -un):$(id -gn):700"',
                    '"$(id -un):$(id -gn):600"',
                    ':',
                    '! -uid "$(id -u)"',
                    '! -gid "$(id -g)"',
                ],
                $input,
            );
            $process = new Process(
                $command->arguments,
                env: ['PATH' => $this->sandbox.'/bin:'.getenv('PATH')],
                input: $input,
            );
            $process->run();

            return new CommandResult((int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput(), 1, false);
        }
    });
}

function rebind_executor(SshExecutor $ssh): ProductionSshExecutor
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

/** @return array{Instance, RemoteProductionDeployment, RemoteProductionRepositoryBinding} */
function rebind_instance(ProductionSshExecutor $executor, string $repository, string $home, string $user = 'rebind'): array
{
    $node = Node::query()->create([
        'name' => 'rebind-node',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.231',
        'wireguard_ip' => '10.44.0.231',
        'user' => 'orbit',
    ]);
    $node->roles()->create(['role' => 'app-prod', 'status' => 'active']);
    $project = Project::query()->create([
        'name' => 'Rebind',
        'slug' => 'rebind',
        'repository_url' => $repository,
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'production',
        'environment' => 'production',
        'source_layout' => 'release',
        'checkout_path' => "{$home}/releases/initial",
        'production_user' => $user,
        'production_home' => $home,
        'root' => 'public',
        'branch' => 'main',
        'provisioning_step' => 'active',
        'status' => 'active',
    ]);
    $binding = new RemoteProductionRepositoryBinding($executor);

    return [
        $instance,
        new RemoteProductionDeployment($executor, app(RepositoryReadAccess::class), null, $binding),
        $binding,
    ];
}
