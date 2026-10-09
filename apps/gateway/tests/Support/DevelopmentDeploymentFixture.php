<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\CheckoutRemovalBoundary;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Instances\RemoteDevelopmentDeployment;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Filesystem\Filesystem;
use Mockery;
use Symfony\Component\Process\Process;

/** Real release programs and Git, with only SSH replaced by local process execution. */
final class DevelopmentDeploymentFixture
{
    public readonly string $sandbox;

    public readonly string $home;

    public readonly string $origin;

    public readonly string $source;

    public readonly Instance $instance;

    public readonly RemoteDevelopmentDeployment $deployment;

    public readonly string $initialCommit;

    private int $sequence = 0;

    public function __construct()
    {
        $this->sandbox = storage_path('framework/testing/dev935-'.bin2hex(random_bytes(8)));
        $this->home = $this->sandbox.'/apps/dev935/default';
        $this->origin = $this->sandbox.'/origin.git';
        $this->source = $this->sandbox.'/source';
        new Filesystem()->makeDirectory($this->home, 0o755, true);
        self::command(['git', 'init', '-b', 'main', $this->source]);
        self::command(['git', '-C', $this->source, 'config', 'user.email', 'dev935@example.test']);
        self::command(['git', '-C', $this->source, 'config', 'user.name', 'Development deployment test']);
        file_put_contents($this->source.'/.gitignore', "vendor/\nnode_modules/\n.cache/\n.env\nreleases/\ncurrent\n");
        mkdir($this->source.'/public');
        file_put_contents($this->source.'/public/index.php', '<?php echo "initial";');
        file_put_contents($this->source.'/old.txt', 'obsolete');
        self::command(['git', '-C', $this->source, 'add', '.']);
        self::command(['git', '-C', $this->source, 'commit', '-m', 'initial']);
        $this->initialCommit = trim(self::command(['git', '-C', $this->source, 'rev-parse', 'HEAD']));
        self::command(['git', 'clone', '--bare', $this->source, $this->origin]);
        self::command(['git', 'clone', $this->origin, $this->home]);
        self::command(['git', '-C', $this->source, 'remote', 'add', 'origin', $this->origin]);
        $url = 'https://example.test/dev935.git';
        self::command(['git', '-C', $this->home, 'remote', 'set-url', 'origin', $url]);
        $global = $this->sandbox.'/gitconfig';
        self::command(['git', 'config', '--file', $global, 'url.'.$this->origin.'.insteadOf', $url]);
        new Filesystem()->makeDirectory($this->home.'/apps/gateway/vendor', 0o755, true);
        mkdir($this->home.'/.cache');
        file_put_contents($this->home.'/apps/gateway/vendor/dependency.bin', random_bytes(1024 * 1024));
        file_put_contents($this->home.'/.cache/warm', 'previous-cache');
        file_put_contents($this->home.'/.env', "APP_ENV=development\n");
        self::command(['git', '-C', $this->home, 'worktree', 'add', '-b', 't3code-ab12', $this->sandbox.'/apps/dev935/t3code-ab12', 'HEAD']);
        self::command(['git', '-C', $this->home, 'worktree', 'add', '-b', 'task-935-e2e', $this->sandbox.'/apps/dev935/task-935-e2e', 'HEAD']);

        $node = Node::query()->create(['name' => 'dev935', 'platform' => 'linux', 'status' => 'active', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.35', 'wireguard_ip' => '10.44.0.35']);
        $node->roles()->create(['role' => 'app-dev', 'status' => 'active']);
        $project = Project::query()->create(['name' => 'dev935', 'slug' => 'dev935', 'type' => 'monorepo', 'repository_url' => $url, 'default_branch' => 'main', 'apps' => fixture_apps('public', 'monorepo')]);
        $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => $this->home, 'source_layout' => 'checkout', 'branch' => 'main', 'status' => 'active']);
        $account = new ManagedUserAccount('orbit', 'orbit', $this->sandbox.'/user-home');
        $accounts = Mockery::mock(ManagedUserAccountResolver::class);
        $accounts->shouldReceive('resolve')->andReturn($account);
        $keys = Mockery::mock(SshKeyProvider::class);
        $keys->shouldReceive('privateKeyPath')->andReturn('/unused');
        $hosts = Mockery::mock(KnownHostsStore::class);
        $hosts->shouldReceive('path')->andReturn('/unused');
        $transport = new class($global) implements SshExecutor
        {
            public function __construct(private readonly string $global) {}

            public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
            {
                return new NativeProcessRunner()->run(new ProcessInvocation(
                    $command->arguments,
                    timeout: $command->timeout ?? 30,
                    input: $command->input,
                    protectedInput: $command->protectedInput,
                    output: $command->output,
                    cancelled: $command->cancelled,
                    environment: ['GIT_CONFIG_GLOBAL' => $this->global, 'GIT_CONFIG_NOSYSTEM' => '1'],
                ));
            }
        };
        $this->deployment = new RemoteDevelopmentDeployment(
            new DevelopmentSshExecutor($transport, $keys, $hosts),
            $accounts,
            app(CheckoutRemovalBoundary::class),
            app(RepositoryReadAccess::class),
            fn (): string => 'release-'.++$this->sequence,
        );
    }

    public function push(string $message): string
    {
        file_put_contents($this->source.'/public/index.php', '<?php echo '.var_export($message, true).';');
        @unlink($this->source.'/old.txt');
        self::command(['git', '-C', $this->source, 'add', '-A']);
        self::command(['git', '-C', $this->source, 'commit', '-m', $message]);
        self::command(['git', '-C', $this->source, 'push', 'origin', 'main']);

        return trim(self::command(['git', '-C', $this->source, 'rev-parse', 'HEAD']));
    }

    public function supportsReflinks(): bool
    {
        $process = new Process(['cp', '--reflink=always', $this->home.'/apps/gateway/vendor/dependency.bin', $this->sandbox.'/probe']);
        $process->run();

        return $process->isSuccessful();
    }

    public function cleanup(): void
    {
        new Filesystem()->deleteDirectory($this->sandbox);
    }

    /** @param list<string> $arguments */
    public static function command(array $arguments): string
    {
        $process = new Process($arguments);
        $process->mustRun();

        return $process->getOutput();
    }
}
