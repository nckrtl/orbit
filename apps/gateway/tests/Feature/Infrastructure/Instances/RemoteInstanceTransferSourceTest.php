<?php

declare(strict_types=1);

use App\Domain\AppDev\AnnotatorEndpoint;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\Transfer\TransferSourceCapture;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Tests\Support\LocalInstanceTransferTransport;
use Tests\Support\PerAppAnnotatorTransferFixture;

it('per-app transfer journals separate private annotator archives and stores with no checkout staging fallback', function (array $names, bool $rollback, string $failure): void {
    [$sandbox, $capture, $source, $destination] = remote_transfer_archive();
    $transport = new LocalInstanceTransferTransport($sandbox);
    $instance = Instance::query()->findOrFail($capture->instanceId);
    $instance->project->update(['apps' => array_map(static fn (string $app): array => ['name' => $app, 'type' => 'laravel-app', 'path' => 'apps/'.$app, 'web_root' => 'public'], $names)]);
    $instance->refresh()->update(['app_overrides' => []]);
    $journal = [];
    foreach ($names as $app) {
        $store = AnnotatorEndpoint::forInstance($instance, $app);
        $local = $transport->storePath($source->wireguard_ip, basename($store));
        new Filesystem()->ensureDirectoryExists($local, 0700);
        file_put_contents($local.'/annotations.json', 'durable '.$app);
        new Filesystem()->ensureDirectoryExists($sandbox.'/source/apps/'.$app, 0700);
        file_put_contents($sandbox.'/source/apps/'.$app.'/.env', 'APP_KEY='.$app);
        chmod($sandbox.'/source/apps/'.$app.'/.env', 0644);
        file_put_contents($sandbox.'/source/apps/'.$app.'/.env.testing', 'APP_KEY=test-'.$app);
        chmod($sandbox.'/source/apps/'.$app.'/.env.testing', 0644);
        $journal[$app] = ['source_route_id' => null, 'destination_route_id' => null, 'destination_domain' => null, 'source_router_node_id' => null, 'imported_environment_keys' => [], 'annotator' => ['source_store' => $store]];
    }
    $transfer = InstanceTransfer::query()->create([
        'instance_id' => $instance->id, 'source_node_id' => $source->id, 'destination_node_id' => $destination->id,
        'destination_name' => 'main', 'destination_path' => $sandbox.'/destination', 'source_path' => $sandbox.'/source',
        'source_layout' => 'checkout', 'status' => 'in_progress', 'current_step' => 'reserved', 'app_journal' => $journal,
    ]);
    $captured = null;
    try {
        if (in_array($failure, ['missing', 'symlink'], true)) {
            $local = $transport->storePath($source->wireguard_ip, 'instance-'.$instance->id.'-docs');
            new Filesystem()->deleteDirectory($local);
            if ($failure === 'symlink') {
                symlink(dirname($local), $local);
            }
            expect(fn () => $transport->source()->capture($instance))->toThrow(RuntimeConvergenceException::class);
            expect(is_dir($sandbox.'/destination'))->toBeFalse();

            return;
        }
        $captured = $transport->source()->capture($instance);
        if ($failure === 'stale-stage') {
            foreach ($captured->annotatorArchives as $app => $entry) {
                $directory = storage_path('app/transfer-staging/'.$transfer->id.'/'.$entry['attempt'].'/annotator');
                new Filesystem()->ensureDirectoryExists($directory, 0700);
                file_put_contents($directory.'/'.$app.'.tar', 'interrupted download');
            }
        }
        if ($failure === 'foreign-destination') {
            $foreign = $transport->storePath($destination->wireguard_ip, 'instance-'.$instance->id.'-docs');
            new Filesystem()->ensureDirectoryExists($foreign, 0700);
            file_put_contents($foreign.'/.orbit-transfer-owner', 'another-transfer');
            file_put_contents($foreign.'/annotations.json', 'foreign');
        }
        if ($failure === 'lost-response') {
            $transport->lostAnnotatorRestoreResponse = true;
        }
        if (in_array($failure, ['foreign-destination', 'lost-response'], true)) {
            expect(fn () => $transport->source()->materialize($captured, $destination, StoragePath::parse($sandbox.'/destination')))->toThrow(RuntimeConvergenceException::class);
            if ($failure === 'foreign-destination') {
                expect(fn () => $transport->source()->discardDestination($destination, StoragePath::parse($sandbox.'/destination')))->toThrow(RuntimeConvergenceException::class);
                expect(file_get_contents($foreign.'/annotations.json'))->toBe('foreign');
            } else {
                $transport->source()->discardDestination($destination, StoragePath::parse($sandbox.'/destination'));
                expect(is_dir($transport->storePath($destination->wireguard_ip, 'instance-'.$instance->id.'-web')))->toBeFalse();
            }

            return;
        }
        $transport->source()->materialize($captured, $destination, StoragePath::parse($sandbox.'/destination'));
        expect(array_keys($captured->annotatorArchives))->toBe($names)
            ->and(is_dir($sandbox.'/destination/.orbit/annotator'))->toBeFalse();
        foreach ($names as $app) {
            $entry = $transfer->refresh()->app_journal[$app]['annotator'];
            $old = $transport->storePath($source->wireguard_ip, basename($entry['source_store']));
            $new = $transport->storePath($destination->wireguard_ip, basename($entry['restored_store']));
            expect(file_get_contents($new.'/annotations.json'))->toBe('durable '.$app)
                ->and(is_dir($old))->toBeTrue()
                ->and(file_get_contents($new.'/.orbit-transfer-owner'))->toContain($transfer->id.':'.$entry['attempt'].':'.$instance->id.':'.$app)
                ->and(fileperms($sandbox.'/source/apps/'.$app.'/.env') & 0007)->toBe(0)
                ->and(fileperms($sandbox.'/destination/apps/'.$app.'/.env') & 0007)->toBe(0)
                ->and(fileperms($sandbox.'/source/apps/'.$app.'/.env.testing') & 0007)->toBe(0)
                ->and(fileperms($sandbox.'/destination/apps/'.$app.'/.env.testing') & 0007)->toBe(0);
            $staging = storage_path('app/transfer-staging/'.$transfer->id.'/'.$entry['attempt'].'/annotator/'.$app.'.tar');
            expect($transport->stagedPaths)->toContain($staging)->and(is_file($staging))->toBeFalse();
        }
        if ($rollback) {
            $transport->source()->discardDestination($destination, StoragePath::parse($sandbox.'/destination'));
        } else {
            $transport->source()->cleanupSource($transfer);
        }
        foreach ($names as $app) {
            $entry = $transfer->app_journal[$app]['annotator'];
            $old = $transport->storePath($source->wireguard_ip, basename($entry['source_store']));
            $new = $transport->storePath($destination->wireguard_ip, basename($entry['restored_store']));
            expect(is_dir($old))->toBe($rollback)->and(is_dir($new))->toBe(! $rollback);
        }
    } catch (RuntimeConvergenceException $exception) {
        throw new RuntimeException(
            'Local transfer fixture failed at '.$exception->step.': '.($exception->result?->stderr ?? 'No process diagnostics.'),
            previous: $exception,
        );
    } finally {
        if ($captured !== null && is_file($captured->archiveIdentity)) {
            unlink($captured->archiveIdentity);
        }
        foreach ($transfer->refresh()->app_journal as $entry) {
            if (isset($entry['annotator']['archive']) && is_file($entry['annotator']['archive'])) {
                unlink($entry['annotator']['archive']);
            }
        }
        new Filesystem()->deleteDirectory(storage_path('app/transfer-staging/'.$transfer->id));
        new Filesystem()->deleteDirectory($sandbox);
    }
})->with([[['web'], false, 'none'], [['web', 'docs'], false, 'none'], [['web', 'docs'], true, 'none'], [['web', 'docs'], true, 'missing'], [['web', 'docs'], true, 'symlink'], [['web', 'docs'], true, 'foreign-destination'], [['web', 'docs'], true, 'lost-response'], [['web', 'docs'], false, 'stale-stage']]);

it('per-app transfer recovers interrupted receipt creation extraction and atomic publication', function (string $app, string $point): void {
    $fixture = new PerAppAnnotatorTransferFixture;
    $transfer = $fixture->transfer();
    $source = $fixture->transport->source();
    $path = StoragePath::parse($transfer->destination_path);
    try {
        $capture = $source->capture($fixture->instance);
        $fixture->transport->annotatorFailure = $point;
        $fixture->transport->annotatorFailureApp = $app;

        expect(fn () => $source->materialize($capture, $fixture->destination, $path))->toThrow(RuntimeConvergenceException::class);

        $entry = $transfer->refresh()->app_journal[$app]['annotator'];
        $stage = $fixture->transport->storePath($fixture->destination->wireguard_ip, basename($entry['staging_store']));
        $receipt = $fixture->transport->storePath($fixture->destination->wireguard_ip, basename($entry['ownership_receipt']));
        $store = $fixture->transport->storePath($fixture->destination->wireguard_ip, basename($entry['restored_store']));
        expect(is_dir($stage))->toBe(in_array($point, ['creation', 'extraction', 'publication'], true));
        expect(is_file($receipt))->toBeTrue();
        expect(is_dir($store))->toBe($point === 'published');
        expect(is_file($receipt.'.pending'))->toBe($point === 'receipt');

        // Reclaim every artifact using the attempt's durable receipts before a fresh capture.
        $fixture->transport->annotatorFailure = null;
        $source->discardDestination($fixture->destination, $path);
        foreach (['docs', 'web'] as $name) {
            $owned = $transfer->refresh()->app_journal[$name]['annotator'];
            foreach (['restored_store', 'staging_store', 'ownership_receipt'] as $field) {
                if (isset($owned[$field])) {
                    $local = $fixture->transport->storePath($fixture->destination->wireguard_ip, basename($owned[$field]));
                    expect(file_exists($local))->toBeFalse();
                    expect(file_exists($local.'.pending'))->toBeFalse();
                }
            }
            expect(file_get_contents($fixture->transport->storePath($fixture->source->wireguard_ip, 'instance-'.$fixture->instance->id.'-'.$name).'/annotations.json'))->toBe('durable '.$name);
        }
        expect(is_dir($path->value))->toBeFalse();
        $transfer->update(['status' => 'failed', 'current_step' => 'reserved', 'recovery_evidence' => null, 'imported_environment_keys' => []]);
        $fresh = $fixture->transfer();
        $source->materialize($source->capture($fixture->instance), $fixture->destination, $path);
        foreach (['docs', 'web'] as $name) {
            $local = $fixture->transport->storePath($fixture->destination->wireguard_ip, 'instance-'.$fixture->instance->id.'-'.$name);
            expect(file_get_contents($local.'/annotations.json'))->toBe('durable '.$name);
        }
        $source->discardDestination($fixture->destination, $path);
        expect(is_dir($path->value))->toBeFalse();
        expect($fresh->refresh()->app_journal[$app]['annotator']['attempt'])->not->toBe($entry['attempt']);
    } finally {
        $fixture->dispose();
    }
})->with(['docs', 'web'])->with(['receipt', 'creation', 'extraction', 'publication', 'published']);

it('per-app transfer validates every archive entry before creating a store or ownership receipt', function (string $app, string $invalid): void {
    $fixture = new PerAppAnnotatorTransferFixture;
    $transfer = $fixture->transfer();
    $source = $fixture->transport->source();
    try {
        $capture = $source->capture($fixture->instance);
        $archive = $capture->annotatorArchives[$app]['archive'];
        new Process(['python3', '-c', <<<'PY'
            import io, tarfile, sys
            with tarfile.open(sys.argv[1], 'w') as archive:
                safe = tarfile.TarInfo('safe.json')
                safe.size = 4
                archive.addfile(safe, io.BytesIO(b'safe'))
                entry = tarfile.TarInfo('invalid')
                kind = sys.argv[2]
                if kind == 'parent': entry.name = '../escape.json'
                if kind == 'absolute': entry.name = '/tmp/escape.json'
                if kind == 'symlink': entry.type, entry.linkname = tarfile.SYMTYPE, '../../escape'
                if kind == 'hardlink': entry.type, entry.linkname = tarfile.LNKTYPE, '../../escape'
                if kind == 'device': entry.type = tarfile.CHRTYPE
                if kind == 'fifo': entry.type = tarfile.FIFOTYPE
                if kind == 'ownership': entry.uid = 42
                if kind == 'group': entry.gid = 42
                if kind == 'root': entry.name, entry.type, entry.uid = '.', tarfile.DIRTYPE, 42
                if kind == 'mode': entry.mode = 0o4755
                if kind == 'pax': entry.pax_headers = {'comment': 'unsupported metadata'}
                if kind == 'duplicate': entry.name = 'safe.json'
                if kind == 'receipt': entry.name = '.orbit-transfer-owner'
                if kind == 'containment': entry.name = 'safe.json/child'
                archive.addfile(entry)
            if kind == 'truncated':
                with open(sys.argv[1], 'r+b') as incomplete:
                    incomplete.truncate(512)
            PY, $archive, $invalid])->mustRun();

        expect(fn () => $source->materialize($capture, $fixture->destination, StoragePath::parse($transfer->destination_path)))->toThrow(RuntimeConvergenceException::class);

        $entry = $transfer->refresh()->app_journal[$app]['annotator'];
        foreach (['restored_store', 'staging_store', 'ownership_receipt'] as $field) {
            expect(file_exists($fixture->transport->storePath($fixture->destination->wireguard_ip, basename($entry[$field]))))->toBeFalse();
        }
        expect(file_get_contents($fixture->transport->storePath($fixture->source->wireguard_ip, 'instance-'.$fixture->instance->id.'-'.$app).'/annotations.json'))->toBe('durable '.$app);
        $source->discardDestination($fixture->destination, StoragePath::parse($transfer->destination_path));
        expect(is_dir($transfer->destination_path))->toBeFalse();
    } finally {
        $fixture->dispose();
    }
})->with(['docs', 'web'])->with(['parent', 'absolute', 'symlink', 'hardlink', 'device', 'fifo', 'ownership', 'group', 'root', 'mode', 'pax', 'truncated', 'duplicate', 'receipt', 'containment']);

it('per-app transfer refuses a foreign attempt-qualified staging path without adopting or deleting it', function (string $app): void {
    $fixture = new PerAppAnnotatorTransferFixture;
    $transfer = $fixture->transfer();
    $source = $fixture->transport->source();
    try {
        $capture = $source->capture($fixture->instance);
        $attempt = $capture->annotatorArchives[$app]['attempt'];
        $stage = $fixture->transport->storePath($fixture->destination->wireguard_ip, 'instance-'.$fixture->instance->id.'-'.$app.'.transfer-'.$transfer->id.'-'.$attempt);
        new Filesystem()->ensureDirectoryExists($stage, 0700);
        file_put_contents($stage.'/foreign.json', 'foreign');

        expect(fn () => $source->materialize($capture, $fixture->destination, StoragePath::parse($transfer->destination_path)))
            ->toThrow(fn (RuntimeConvergenceException $error) => expect($error->errorCode)->toBe('instance.transfer_cleanup_conflict'));
        expect(fn () => $source->discardDestination($fixture->destination, StoragePath::parse($transfer->destination_path)))->toThrow(RuntimeConvergenceException::class);

        expect(file_get_contents($stage.'/foreign.json'))->toBe('foreign');
        expect(is_file($stage.'.owner'))->toBeFalse();
    } finally {
        $fixture->dispose();
    }
})->with(['docs', 'web']);

it('per-app transfer refuses recapture while prepared ownership receipts remain', function (): void {
    $fixture = new PerAppAnnotatorTransferFixture;
    $transfer = $fixture->transfer();
    $source = $fixture->transport->source();
    try {
        $source->materialize($source->capture($fixture->instance), $fixture->destination, StoragePath::parse($transfer->destination_path));
        $journal = $transfer->refresh()->app_journal;
        $digests = [];
        foreach ($journal as $app => $entry) {
            $digests[$app] = hash_file('sha256', $entry['annotator']['archive']);
        }

        expect(fn () => $source->capture($fixture->instance))->toThrow(ResourceOperationException::class);

        expect($transfer->refresh()->app_journal)->toBe($journal);
        foreach ($journal as $app => $entry) {
            expect(hash_file('sha256', $entry['annotator']['archive']))->toBe($digests[$app]);
        }
        $source->discardDestination($fixture->destination, StoragePath::parse($transfer->destination_path));
        expect(is_dir($transfer->destination_path))->toBeFalse();
    } finally {
        $fixture->dispose();
    }
});

it('per-app transfer never replaces a foreign store that appears at atomic publication', function (string $app): void {
    $fixture = new PerAppAnnotatorTransferFixture;
    $transfer = $fixture->transfer();
    $source = $fixture->transport->source();
    try {
        $capture = $source->capture($fixture->instance);
        $fixture->transport->annotatorPublicationConflict = true;
        $fixture->transport->annotatorFailureApp = $app;

        expect(fn () => $source->materialize($capture, $fixture->destination, StoragePath::parse($transfer->destination_path)))
            ->toThrow(fn (RuntimeConvergenceException $error) => expect($error->errorCode)->toBe('instance.transfer_cleanup_conflict'));

        $store = $fixture->transport->storePath($fixture->destination->wireguard_ip, 'instance-'.$fixture->instance->id.'-'.$app);
        expect(file_get_contents($store.'/foreign.json'))->toBe('foreign');
        expect(is_file($store.'/annotations.json'))->toBeFalse();
        expect(fn () => $source->discardDestination($fixture->destination, StoragePath::parse($transfer->destination_path)))->toThrow(RuntimeConvergenceException::class);
        expect(file_get_contents($store.'/foreign.json'))->toBe('foreign');
        // Remove only the foreign store created by this disposable fixture, then retry owned cleanup.
        new Filesystem()->deleteDirectory($store);
        $source->discardDestination($fixture->destination, StoragePath::parse($transfer->destination_path));
        $entry = $transfer->refresh()->app_journal[$app]['annotator'];
        expect(file_exists($fixture->transport->storePath($fixture->destination->wireguard_ip, basename($entry['staging_store']))))->toBeFalse();
        expect(file_exists($fixture->transport->storePath($fixture->destination->wireguard_ip, basename($entry['ownership_receipt']))))->toBeFalse();
    } finally {
        $fixture->dispose();
    }
})->with(['docs', 'web']);

it('per-app transfer refuses a foreign archive symlink before SCP can overwrite its target', function (string $app): void {
    $fixture = new PerAppAnnotatorTransferFixture;
    $transfer = $fixture->transfer();
    $source = $fixture->transport->source();
    $remote = null;
    try {
        $capture = $source->capture($fixture->instance);
        $remote = substr($capture->annotatorArchives[$app]['archive'], 0, -4).'-restore.tar';
        $foreign = $fixture->sandbox.'/foreign.json';
        file_put_contents($foreign, 'foreign');
        symlink($foreign, $remote);

        expect(fn () => $source->materialize($capture, $fixture->destination, StoragePath::parse($transfer->destination_path)))
            ->toThrow(fn (RuntimeConvergenceException $error) => expect($error->errorCode)->toBe('instance.transfer_cleanup_conflict'));

        expect(file_get_contents($foreign))->toBe('foreign');
        expect(is_link($remote))->toBeTrue();
        unlink($remote);
        $source->discardDestination($fixture->destination, StoragePath::parse($transfer->destination_path));
        expect(is_dir($transfer->destination_path))->toBeFalse();
    } finally {
        if ($remote !== null && is_link($remote) && readlink($remote) === $fixture->sandbox.'/foreign.json') {
            unlink($remote);
        }
        $fixture->dispose();
    }
})->with(['docs', 'web']);

it('per-app transfer adapter verifies all final stores before initially deleting any source store or checkout', function (string $app): void {
    $fixture = new PerAppAnnotatorTransferFixture;
    $transfer = $fixture->transfer();
    $source = $fixture->transport->source();
    try {
        $source->materialize($source->capture($fixture->instance), $fixture->destination, StoragePath::parse($transfer->destination_path));
        $journal = $transfer->refresh()->app_journal;
        $store = $fixture->transport->storePath($fixture->destination->wireguard_ip, 'instance-'.$fixture->instance->id.'-'.$app);
        unlink($store.'/.orbit-transfer-owner');

        expect(fn () => $source->cleanupSource($transfer))
            ->toThrow(fn (RuntimeConvergenceException $error) => expect($error->errorCode)->toBe('instance.transfer_cleanup_conflict'));

        expect(is_dir($transfer->source_path))->toBeTrue();
        foreach (['docs', 'web'] as $name) {
            $entry = $journal[$name]['annotator'];
            $original = $fixture->transport->storePath($fixture->source->wireguard_ip, basename($entry['source_store']));
            expect(file_get_contents($original.'/annotations.json'))->toBe('durable '.$name);
            expect(is_file($entry['archive']))->toBeTrue();
        }
    } finally {
        $fixture->dispose();
    }
})->with(['docs', 'web']);

it('stages the archive on Gateway disk outside the system temporary directory and removes it after upload', function (): void {
    [$sandbox, $capture, $source, $destination] = remote_transfer_archive();
    $transport = new LocalInstanceTransferTransport($sandbox);

    try {
        $transport->source()->materialize($capture, $destination, StoragePath::parse($sandbox.'/destination'));

        $stagingDirectory = storage_path('app/transfer-staging');
        $systemTemporaryDirectory = realpath(sys_get_temp_dir()) ?: rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);

        expect($transport->stagedPaths)->toHaveCount(2)
            ->and($transport->stagedPaths[0])->toBe($transport->stagedPaths[1])
            ->and(dirname($transport->stagedPaths[0]))->toBe($stagingDirectory)
            ->and($stagingDirectory)->not->toBe($systemTemporaryDirectory)
            ->and($transport->stagedModes)->toBe([0600, 0600])
            ->and(file_exists($transport->stagedPaths[0]))->toBeFalse()
            ->and(file_get_contents($sandbox.'/destination/README.md'))->toBe('checkout archive');
    } finally {
        new Filesystem()->deleteDirectory($sandbox);
    }
});

it('logs the failed staging step and exit code without raw output and removes the partial archive', function (string $step): void {
    [$sandbox, $capture, $source, $destination] = remote_transfer_archive();
    $transport = new LocalInstanceTransferTransport($sandbox);
    $transport->failure = $step;
    Log::spy();

    try {
        expect(fn () => $transport->source()->materialize($capture, $destination, StoragePath::parse($sandbox.'/destination')))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.transfer_failed'));

        expect($transport->stagedPaths)->not->toBeEmpty();
        foreach ($transport->stagedPaths as $path) {
            expect(file_exists($path))->toBeFalse();
        }
        Log::shouldHaveReceived('warning')->once()->with('Instance transfer archive staging failed.', [
            'step' => 'app-instance-transfer-'.$step,
            'exit_code' => 23,
        ]);
        expect(is_dir($sandbox.'/destination'))->toBeFalse();
    } finally {
        new Filesystem()->deleteDirectory($sandbox);
    }
})->with(['download', 'upload']);

/** @return array{string, TransferSourceCapture, Node, Node} */
function remote_transfer_archive(): array
{
    $sandbox = sys_get_temp_dir().'/orbit-transfer-stage-'.bin2hex(random_bytes(8));
    new Filesystem()->ensureDirectoryExists($sandbox.'/source', 0700);
    file_put_contents($sandbox.'/source/README.md', 'checkout archive');
    new Process(['git', 'init', '--quiet', '--initial-branch=main'], $sandbox.'/source')->mustRun();
    new Process(['git', '-c', 'user.name=Orbit', '-c', 'user.email=orbit@example.test', 'commit', '--quiet', '--allow-empty', '-m', 'Start'], $sandbox.'/source')->mustRun();
    $head = trim(new Process(['git', 'rev-parse', 'HEAD'], $sandbox.'/source')->mustRun()->getOutput());
    new Process(['tar', '-cf', $sandbox.'/archive.tar', '.'], $sandbox.'/source')->mustRun();
    $node = static fn (string $name): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit',
        'public_ssh_host' => '127.0.0.1', 'wireguard_ip' => '127.0.0.'.(Node::query()->count() + 1),
    ]);
    $source = $node('staging-source');
    $destination = $node('staging-destination');
    $project = Project::query()->create(['name' => 'Transfer', 'slug' => 'transfer', 'repository_url' => 'https://example.test/transfer.git', 'apps' => fixture_apps(null)]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $source->id, 'name' => 'source', 'checkout_path' => $sandbox.'/source']);
    $capture = new TransferSourceCapture(
        instanceId: $instance->id, nodeId: $source->id, layout: InstanceSourceLayout::Checkout,
        sourcePath: $sandbox.'/source', commonRepositoryPath: null, head: $head, branch: 'main', detached: false,
        archiveIdentity: $sandbox.'/archive.tar', refs: ['main'],
    );

    return [$sandbox, $capture, $source, $destination];
}
