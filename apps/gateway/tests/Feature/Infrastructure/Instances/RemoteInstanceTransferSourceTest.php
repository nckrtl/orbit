<?php

declare(strict_types=1);

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

it('transfers the private annotator store through the source archive and deletes the old store only at cleanup', function (): void {
    [$sandbox, $capture, $source, $destination] = remote_transfer_archive();
    $transport = new LocalInstanceTransferTransport($sandbox);
    $project = Project::query()->create(['name' => 'Annotations', 'slug' => 'annotations', 'repository_url' => 'git@example.test:annotations.git']);
    $instance = Instance::query()->findOrFail($capture->instanceId);
    $instance->update(['project_id' => $project->id, 'name' => 'main', 'annotator_port' => 4848]);
    new Filesystem()->ensureDirectoryExists($sandbox.'/annotator-store', 0700);
    file_put_contents($sandbox.'/annotator-store/annotations.json', 'durable annotations');
    $captured = null;
    try {
        $captured = $transport->source()->capture($instance);
        $transport->source()->materialize($captured, $destination, StoragePath::parse($sandbox.'/destination'));
        expect(file_get_contents($sandbox.'/destination/.orbit/annotator/annotations.json'))->toBe('durable annotations')
            ->and(is_dir($sandbox.'/annotator-store'))->toBeTrue();
        $transfer = new InstanceTransfer(['instance_id' => $instance->id, 'source_node_id' => $source->id, 'source_path' => $sandbox.'/source', 'source_layout' => 'checkout']);
        $transport->source()->cleanupSource($transfer);
        expect(is_dir($sandbox.'/annotator-store'))->toBeFalse()
            ->and(file_get_contents($sandbox.'/destination/.orbit/annotator/annotations.json'))->toBe('durable annotations');
    } finally {
        if ($captured !== null && is_file($captured->archiveIdentity)) {
            unlink($captured->archiveIdentity);
        }
        new Filesystem()->deleteDirectory($sandbox);
    }
});

it('stages the archive on Gateway disk outside the system temporary directory and removes it after upload', function (): void {
    [$sandbox, $capture, $source, $destination] = remote_transfer_archive();
    $transport = new LocalInstanceTransferTransport($sandbox);

    try {
        $transport->source()->materialize($capture, $destination, StoragePath::parse($sandbox.'/destination'));

        expect($transport->stagedPaths)->toHaveCount(2)
            ->and($transport->stagedPaths[0])->toBe($transport->stagedPaths[1])
            ->and(dirname($transport->stagedPaths[0]))->toBe(storage_path('app/transfer-staging'))
            ->and(str_starts_with($transport->stagedPaths[0], sys_get_temp_dir().'/'))->toBeFalse()
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
    $project = Project::query()->create(['name' => 'Transfer', 'slug' => 'transfer', 'repository_url' => 'https://example.test/transfer.git']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $source->id, 'name' => 'source', 'checkout_path' => $sandbox.'/source']);
    $capture = new TransferSourceCapture(
        instanceId: $instance->id, nodeId: $source->id, layout: InstanceSourceLayout::Checkout,
        sourcePath: $sandbox.'/source', commonRepositoryPath: null, head: $head, branch: 'main', detached: false,
        archiveIdentity: $sandbox.'/archive.tar', refs: ['main'],
    );

    return [$sandbox, $capture, $source, $destination];
}
