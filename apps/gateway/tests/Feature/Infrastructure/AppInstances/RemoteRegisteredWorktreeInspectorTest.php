<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\AppInstances\RemoteRegisteredWorktreeInspector;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\App as OrbitApp;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $identity = posix_getpwuid(posix_geteuid());
    $groupIdentity = posix_getgrgid(posix_getegid());
    $this->user = is_array($identity) && is_string($identity['name'] ?? null) ? $identity['name'] : 'orbit';
    $this->group = is_array($groupIdentity) && is_string($groupIdentity['name'] ?? null)
        ? $groupIdentity['name']
        : $this->user;
    $this->home = is_array($identity) && is_string($identity['dir'] ?? null) ? $identity['dir'] : '/home/orbit';
    $this->sandbox = $this->home.'/orbit-tests/'.Str::uuid();
    $this->repository = $this->sandbox.'/repository';
    $this->checkout = $this->sandbox.'/worktrees/dfb5/example';
    $this->origin = 'https://github.com/acme/example.git';
    $files = new Filesystem;
    $files->makeDirectory($this->sandbox.'/worktrees/dfb5', 0o755, true);
    orb105_run(['git', 'init', '-b', 'main', $this->repository]);
    orb105_run(['git', '-C', $this->repository, 'config', 'user.name', 'Orbit Test']);
    orb105_run(['git', '-C', $this->repository, 'config', 'user.email', 'orbit@example.test']);
    $files->makeDirectory($this->repository.'/public', 0o755, true);
    file_put_contents($this->repository.'/public/index.php', "<?php echo 'ok';\n");
    orb105_run(['git', '-C', $this->repository, 'add', 'public/index.php']);
    orb105_run(['git', '-C', $this->repository, 'commit', '-m', 'Initial']);
    orb105_run(['git', '-C', $this->repository, 'remote', 'add', 'origin', $this->origin]);
    orb105_run(['git', '-C', $this->repository, 'worktree', 'add', '--detach', $this->checkout, 'HEAD']);
    file_put_contents($this->checkout.'/public/index.php', "<?php echo 'dirty';\n");
    file_put_contents($this->checkout.'/untracked.txt', "untracked\n");

    $account = new ManagedUserAccount($this->user, $this->group, $this->home);
    $accounts = new class($account) implements ManagedUserAccountResolver {
        public function __construct(
            private readonly ManagedUserAccount $account,
        ) {}

        public function resolve(Node $node): ManagedUserAccount
        {
            return $this->account;
        }
    };
    $this->transport = new Orb105LocalSshExecutor;
    $ssh = new AppDevSshExecutor(
        $this->transport,
        new class implements SshKeyProvider {
            public function privateKeyPath(): string
            {
                return '/tmp/orbit-test-key';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 test';
            }
        },
        new class implements KnownHostsStore {
            public function path(): string
            {
                return '/tmp/orbit-test-known-hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
    );
    $this->inspector = new RemoteRegisteredWorktreeInspector($ssh, $accounts);
    $this->node = new Node([
        'name' => 'app-dev',
        'wireguard_ip' => '10.44.0.10',
        'user' => $this->user,
    ]);
    $this->orbitApp = new OrbitApp([
        'slug' => 'example',
        'repository_url' => $this->origin,
        'root' => 'public',
    ]);
});

afterEach(function (): void {
    new Filesystem()->deleteDirectory($this->sandbox);
});

it('observes a detached dirty linked worktree without changing any Git state', function (): void {
    $before = orb105_git_evidence($this->repository, $this->checkout);

    $observation = $this->inspector->inspect($this->node, $this->orbitApp, $this->checkout, 'public');

    expect($observation->checkoutPath)
        ->toBe($this->checkout)
        ->and($observation->branch)
        ->toBeNull()
        ->and($observation->startingCommit)
        ->toBe(trim(orb105_run(['git', '-C', $this->checkout, 'rev-parse', 'HEAD'])->stdout))
        ->and($observation->sourceIdentity)
        ->toMatch('/\A[0-9a-f]{64}\z/D')
        ->and(orb105_git_evidence($this->repository, $this->checkout))
        ->toBe($before);

    $script = $this->transport->commands[0]->input ?? '';
    expect($script)
        ->toContain('worktree list --porcelain', 'symbolic-ref --quiet --short HEAD')
        ->not->toContain(
            'git clone',
            'git fetch',
            'git pull',
            'git push',
            'git checkout',
            'git switch',
            'git reset',
            'git clean',
            'git add',
            'git commit',
            'worktree add',
            'worktree remove',
            'worktree prune',
        );
});

it('rejects invalid worktree identities through bounded inspection failure', function (string $mutation): void {
    $checkout = $this->checkout;
    $root = 'public';

    if ($mutation === 'origin') {
        $this->orbitApp->repository_url = 'https://github.com/acme/wrong.git';
    } elseif ($mutation === 'primary') {
        $checkout = $this->repository;
    } elseif ($mutation === 'independent') {
        $checkout = $this->sandbox.'/independent';
        orb105_run(['git', 'clone', '--no-hardlinks', $this->repository, $checkout]);
        orb105_run(['git', '-C', $checkout, 'remote', 'set-url', 'origin', $this->origin]);
    } elseif ($mutation === 'symlink') {
        $checkout = $this->sandbox.'/worktrees/link';
        symlink($this->checkout, $checkout);
    } elseif ($mutation === 'mode') {
        chmod($checkout, 0o777);
    } else {
        $root = 'missing';
    }

    expect(fn () => $this->inspector->inspect($this->node, $this->orbitApp, $checkout, $root))
        ->toThrow(RuntimeConvergenceException::class, 'App development step [registered-worktree-inspect] failed');
})->with(['origin', 'primary', 'independent', 'symlink', 'mode', 'root']);

/** @param non-empty-list<string> $arguments */
function orb105_run(array $arguments, ?string $input = null): CommandResult
{
    $result = new NativeProcessRunner()->run(new ProcessInvocation($arguments, input: $input));
    expect($result->succeeded())->toBeTrue($result->stderr);

    return $result;
}

/** @return array{head: string, status: string, refs: string, worktrees: string} */
function orb105_git_evidence(string $repository, string $checkout): array
{
    return [
        'head' => orb105_run(['git', '-C', $checkout, 'rev-parse', 'HEAD'])->stdout,
        'status' => orb105_run(['git', '-C', $checkout, 'status', '--porcelain=v1', '--untracked-files=all'])->stdout,
        'refs' => orb105_run(['git', '-C', $repository, 'show-ref'])->stdout,
        'worktrees' => orb105_run(['git', '-C', $repository, 'worktree', 'list', '--porcelain'])->stdout,
    ];
}

final class Orb105LocalSshExecutor implements SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->commands[] = $command;

        return new NativeProcessRunner()->run(new ProcessInvocation(
            arguments: $command->arguments,
            input: $command->input,
        ));
    }
}
