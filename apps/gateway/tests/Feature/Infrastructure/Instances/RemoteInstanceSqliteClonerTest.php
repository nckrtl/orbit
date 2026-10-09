<?php

declare(strict_types=1);

use App\Domain\Instances\Sqlite\SqliteSeedPlacement;
use App\Domain\Instances\Sqlite\SqliteSnapshotTransfer;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Instances\RemoteInstanceSqliteCloner;
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
    $this->root = sys_get_temp_dir().'/orbit-sqlite-cloner-'.bin2hex(random_bytes(6));
    mkdir("{$this->root}/default/database", 0755, true);
    mkdir("{$this->root}/feature", 0755, true);
    $database = new PDO("sqlite:{$this->root}/default/database/database.sqlite");
    $database->exec('CREATE TABLE users (name TEXT); INSERT INTO users VALUES (\'ada\');');
    $database = null;
    chmod("{$this->root}/default/database/database.sqlite", 0640);

    $this->project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/site.git',
        'apps' => fixture_apps(null),
    ]);
    $this->sourceNode = sqlite_cloner_node('source', '10.44.0.11');
    $this->default = sqlite_cloner_instance($this->project, $this->sourceNode, 'default', "{$this->root}/default");
});

afterEach(function (): void {
    (new NativeProcessRunner)->run(new ProcessInvocation(['rm', '-rf', '--', $this->root]));
});

function sqlite_cloner_node(string $name, string $address): Node
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

function sqlite_cloner_instance(Project $project, Node $node, string $name, string $checkout): Instance
{
    return Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => $name,
        'checkout_path' => $checkout,
    ]);
}

function sqlite_cloner(SqliteClonerLocalSsh $ssh): RemoteInstanceSqliteCloner
{
    return new RemoteInstanceSqliteCloner(
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
        new class implements SqliteSnapshotTransfer
        {
            public function transfer(
                SqliteSeedPlacement $source,
                string $sourcePath,
                SqliteSeedPlacement $target,
                string $targetPath,
                int $expectedBytes,
                string $expectedDigest,
            ): void {
                if (filesize($sourcePath) !== $expectedBytes || hash_file('sha256', $sourcePath) !== $expectedDigest) {
                    throw new RuntimeException('The snapshot changed in transit.');
                }

                copy($sourcePath, $targetPath);
            }
        },
    );
}

/** Whether `cp` can clone a file inside the directory, the same block cloning the program uses. */
function sqlite_cloner_can_reflink(string $directory): bool
{
    file_put_contents("{$directory}/reflink-probe", 'probe');
    $result = (new NativeProcessRunner)->run(new ProcessInvocation(['cp', '--reflink=always', "{$directory}/reflink-probe", "{$directory}/reflink-probe-copy"]));
    @unlink("{$directory}/reflink-probe");
    @unlink("{$directory}/reflink-probe-copy");

    return $result->succeeded();
}

function sqlite_cloner_rows(string $path): array
{
    return (new PDO("sqlite:{$path}"))->query('SELECT name FROM users')->fetchAll(PDO::FETCH_COLUMN);
}

describe('RemoteInstanceSqliteCloner', function (): void {
    it('copies a consistent snapshot into the same relative path on the same Node', function (): void {
        $ssh = new SqliteClonerLocalSsh;
        $target = sqlite_cloner_instance($this->project, $this->sourceNode, 'feature', "{$this->root}/feature");

        sqlite_cloner($ssh)->copy($this->default, "{$this->root}/default/database/database.sqlite", $target, "{$this->root}/feature/database/database.sqlite");

        expect(sqlite_cloner_rows("{$this->root}/feature/database/database.sqlite"))->toBe(['ada'])
            ->and(fileperms("{$this->root}/feature/database/database.sqlite") & 0777)->toBe(0640)
            ->and(glob("{$this->root}/feature/database/.orbit-sqlite-*") ?: [])->toBe([])
            ->and(array_column($ssh->commands, 0))->toBe(['local'])
            ->and($ssh->hosts)->toBe(['10.44.0.11']);
    });

    it('copies writes that are still in the WAL, by reflink where the filesystem can clone and by snapshot otherwise', function (): void {
        $source = "{$this->root}/default/database/database.sqlite";
        $writer = new PDO("sqlite:{$source}");
        $writer->exec('PRAGMA journal_mode=WAL; PRAGMA wal_autocheckpoint=0;');
        $writer->exec("INSERT INTO users VALUES ('grace')");
        $ssh = new SqliteClonerLocalSsh;
        $target = sqlite_cloner_instance($this->project, $this->sourceNode, 'feature', "{$this->root}/feature");

        expect(filesize("{$source}-wal"))->toBeGreaterThan(0);

        sqlite_cloner($ssh)->copy($this->default, $source, $target, "{$this->root}/feature/database/database.sqlite");

        expect(sqlite_cloner_rows("{$this->root}/feature/database/database.sqlite"))->toBe(['ada', 'grace'])
            ->and(json_decode($ssh->outputs[0], true)['copy'])->toBe(sqlite_cloner_can_reflink("{$this->root}/feature") ? 'reflink' : 'snapshot')
            ->and(glob("{$this->root}/feature/database/.orbit-sqlite-*") ?: [])->toBe([]);

        $writer = null;
    });

    it('copies a snapshot instead of waiting when a writer holds the lock', function (): void {
        $source = "{$this->root}/default/database/database.sqlite";
        $writer = new PDO("sqlite:{$source}");
        $writer->exec('PRAGMA journal_mode=WAL;');
        $writer->exec('BEGIN IMMEDIATE');
        $writer->exec("INSERT INTO users VALUES ('uncommitted')");
        $ssh = new SqliteClonerLocalSsh;
        $target = sqlite_cloner_instance($this->project, $this->sourceNode, 'feature', "{$this->root}/feature");
        $started = hrtime(true);

        sqlite_cloner($ssh)->copy($this->default, $source, $target, "{$this->root}/feature/database/database.sqlite");

        expect(sqlite_cloner_rows("{$this->root}/feature/database/database.sqlite"))->toBe(['ada'])
            ->and(json_decode($ssh->outputs[0], true)['copy'])->toBe('snapshot')
            ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(10.0);

        $writer->exec('ROLLBACK');
        $writer = null;
    });

    it('moves the snapshot through the Gateway to another Node and removes the source snapshot', function (): void {
        $ssh = new SqliteClonerLocalSsh;
        $target = sqlite_cloner_instance($this->project, sqlite_cloner_node('target', '10.44.0.12'), 'feature', "{$this->root}/feature");

        sqlite_cloner($ssh)->copy($this->default, "{$this->root}/default/database/database.sqlite", $target, "{$this->root}/feature/database/database.sqlite");

        $exportDirectory = $ssh->commands[0][3];

        expect(sqlite_cloner_rows("{$this->root}/feature/database/database.sqlite"))->toBe(['ada'])
            ->and(array_column($ssh->commands, 0))->toBe(['export', 'prepare', 'install', 'cleanup'])
            ->and($ssh->hosts)->toBe(['10.44.0.11', '10.44.0.12', '10.44.0.12', '10.44.0.11'])
            ->and(is_dir($exportDirectory))->toBeFalse()
            ->and(glob("{$this->root}/feature/database/.orbit-sqlite-*") ?: [])->toBe([]);
    });

    it('refuses a source outside the checkout or through a symlink', function (string $source): void {
        symlink("{$this->root}/default/database", "{$this->root}/default/linked");
        $target = sqlite_cloner_instance($this->project, $this->sourceNode, 'feature', "{$this->root}/feature");
        $source = str_replace('ROOT', $this->root, $source);

        expect(fn () => sqlite_cloner(new SqliteClonerLocalSsh)->copy($this->default, $source, $target, "{$this->root}/feature/database.sqlite"))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.database_clone_failed'));

        expect(file_exists("{$this->root}/feature/database.sqlite"))->toBeFalse();
    })->with([
        'outside' => ['ROOT/feature/../default/database/database.sqlite'],
        'symlink' => ['ROOT/default/linked/database.sqlite'],
    ]);

    it('removes a copied file and treats a missing checkout as removed', function (): void {
        $target = sqlite_cloner_instance($this->project, $this->sourceNode, 'feature', "{$this->root}/feature");
        touch("{$this->root}/feature/database.sqlite");
        $cloner = sqlite_cloner(new SqliteClonerLocalSsh);

        $cloner->remove($target, "{$this->root}/feature/database.sqlite");
        $cloner->remove(sqlite_cloner_instance($this->project, $this->sourceNode, 'gone', "{$this->root}/gone"), "{$this->root}/gone/database.sqlite");

        expect(file_exists("{$this->root}/feature/database.sqlite"))->toBeFalse();
    });
});

final class SqliteClonerLocalSsh implements SshExecutor
{
    /** @var list<list<string>> */
    public array $commands = [];

    /** @var list<string> */
    public array $hosts = [];

    /** @var list<string> */
    public array $outputs = [];

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->hosts[] = $connection->host;
        $this->commands[] = array_slice($command->arguments, 3);

        $result = (new NativeProcessRunner)->run(new ProcessInvocation(
            arguments: $command->arguments,
            maxOutputBytes: $command->maxOutputBytes,
        ));
        $this->outputs[] = $result->stdout;

        return $result;
    }
}
