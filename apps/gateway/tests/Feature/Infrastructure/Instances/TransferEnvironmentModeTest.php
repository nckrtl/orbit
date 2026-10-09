<?php

declare(strict_types=1);

use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\Support\LocalInstanceTransferTransport;

describe('TaskCheckWorkerUser', function (): void {
    it('reconstructs a transferred worktree with worker Git without replacing dirty files or restoring selected SQLite', function (bool $detached): void {
        config()->set('orbit.tasks.worker_user', 'nobody');
        $root = sys_get_temp_dir().'/orbit-worker-transfer-'.bin2hex(random_bytes(6));
        $checkout = $root.'/source';
        $primary = $root.'/primary';
        $files = new Filesystem;
        (new Process(['git', 'init', '-q', '-b', 'main', $primary]))->mustRun();
        file_put_contents($primary.'/README.md', "Committed readme\n");
        file_put_contents($primary.'/database.sqlite', 'committed database placeholder');
        (new Process(['git', '-C', $primary, 'add', '.']))->mustRun();
        (new Process(['git', '-C', $primary, '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-q', '-m', 'start']))->mustRun();
        (new Process(['git', '-C', $primary, 'worktree', 'add', '-q', '-b', 'transfer', $checkout]))->mustRun();
        if ($detached) {
            (new Process(['git', '-C', $checkout, 'checkout', '-q', '--detach']))->mustRun();
        }
        file_put_contents($checkout.'/README.md', "Dirty readme\n");
        file_put_contents($checkout.'/database.sqlite-wal', 'selected WAL');
        file_put_contents($checkout.'/database.sqlite-shm', 'selected SHM');
        $node = Node::query()->create(['name' => 'worker-transfer', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '10.44.0.53', 'wireguard_ip' => '10.44.0.53']);
        $project = Project::query()->create(['name' => 'Shop', 'slug' => 'shop', 'repository_url' => 'git@example.test:shop.git', 'default_branch' => 'main', 'apps' => fixture_apps(null)]);
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'dev', 'checkout_path' => $checkout, 'source_layout' => 'worktree', 'status' => 'source_resolved']);
        $source = new LocalInstanceTransferTransport($root)->source();
        $capture = null;

        try {
            $capture = $source->capture($instance, $checkout.'/database.sqlite');
            $source->materialize($capture, $node, StoragePath::parse($root.'/destination'));
            clearstatcache();

            expect(fileowner($root.'/destination'))->toBe(posix_geteuid())
                ->and(fileowner($root.'/destination/.git'))->toBe(posix_geteuid())
                ->and(fileowner($root.'/destination/.git/index'))->toBe(65534)
                ->and(file_get_contents($root.'/destination/README.md'))->toBe("Dirty readme\n")
                ->and(file_exists($root.'/destination/database.sqlite'))->toBeFalse()
                ->and(file_exists($root.'/destination/database.sqlite-wal'))->toBeFalse()
                ->and(file_exists($root.'/destination/database.sqlite-shm'))->toBeFalse();
            expect(trim(new Process(['git', '-C', $root.'/destination', 'rev-parse', 'HEAD'])->mustRun()->getOutput()))->toBe($capture->head)
                ->and(trim(new Process(['git', '-C', $root.'/destination', 'rev-parse', '--abbrev-ref', 'HEAD'])->mustRun()->getOutput()))->toBe($detached ? 'HEAD' : 'transfer');
        } finally {
            $files->deleteDirectory($root);
            if ($capture !== null && is_file($capture->archiveIdentity)) {
                unlink($capture->archiveIdentity);
            }
        }
    })->with(['branch' => false, 'detached' => true]);
})->group('privileged');

it('materializes a transferred checkout with an environment that other local users cannot read', function (string $webRoot, string $suffix): void {
    $root = sys_get_temp_dir().'/orbit-transfer-env-'.bin2hex(random_bytes(4));
    $name = 'source-'.bin2hex(random_bytes(4));
    $files = new Filesystem;
    $files->ensureDirectoryExists($root.'/'.$name);

    try {
        foreach ([
            ['git', 'init', '--quiet', '--initial-branch=main'],
            ['git', '-c', 'user.name=Orbit', '-c', 'user.email=orbit@example.test', 'commit', '--quiet', '--allow-empty', '-m', 'Start'],
        ] as $command) {
            new Process($command, $root.'/'.$name)->mustRun();
        }
        $files->ensureDirectoryExists($root.'/'.$name.$suffix);
        file_put_contents($root.'/'.$name.$suffix.'/.env', "APP_KEY=secret\n");
        chmod($root.'/'.$name.$suffix.'/.env', 0o664);
        file_put_contents($root.'/'.$name.'/README.md', "Readme\n");
        chmod($root.'/'.$name.'/README.md', 0o664);

        $node = static fn (string $name, string $address): Node => Node::query()->create([
            'name' => $name, 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'user' => 'orbit',
            'public_ssh_host' => $address, 'wireguard_ip' => $address,
        ]);
        $project = Project::query()->create(['name' => 'Shop', 'slug' => 'shop', 'repository_url' => 'git@example.test:shop.git', 'default_branch' => 'main', 'apps' => fixture_apps(null)]);
        $instance = Instance::query()->create([
            'project_id' => $project->id, 'node_id' => $node('transfer-from', '10.44.0.51')->id, 'name' => 'dev',
            'checkout_path' => $root.'/'.$name, 'source_layout' => 'checkout', 'status' => 'source_resolved',
            'app_overrides' => fixture_app_overrides($webRoot), 'source_is_laravel' => true,
        ]);
        $source = new LocalInstanceTransferTransport($root)->source();

        $capture = $source->capture($instance);
        $source->materialize($capture, $node('transfer-to', '10.44.0.52'), StoragePath::parse($root.'/destination'));
        clearstatcache();

        expect(file_get_contents($root.'/destination'.$suffix.'/.env'))->toBe("APP_KEY=secret\n")
            ->and(fileperms($root.'/destination'.$suffix.'/.env') & 0o007)->toBe(0)
            ->and(fileperms($root.'/destination'.$suffix.'/.env') & 0o600)->toBe(0o600)
            ->and(fileperms($root.'/destination/README.md') & 0o004)->toBe(0o004);
    } finally {
        $files->deleteDirectory($root);
        // The capture script writes its archive to /tmp, named after the checkout directory.
        foreach (glob("/tmp/orbit-transfer-{$name}-*.tar") ?: [] as $archive) {
            @unlink($archive);
        }
    }
})->with(['root public' => ['public', ''], 'nested Laravel' => ['server/web/public', '/server/web']]);
