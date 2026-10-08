<?php

declare(strict_types=1);

use App\Domain\Compute\ComputeException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\SandboxPiArtifact;
use Symfony\Component\Process\Process;
use Tests\Support\UpCloudRuntimeWorkspace;

use function Pest\Laravel\mock;

it('streams a pinned artifact only to the enrolled owner and checks the guest receipt', function (bool $valid): void {
    $workspace = UpCloudRuntimeWorkspace::create();
    $file = tmpfile();
    $bytes = str_repeat('x', 128);
    fwrite($file, $bytes);
    $path = stream_get_meta_data($file)['uri'];
    config(['compute.pi.artifact_path' => $path, 'compute.pi.artifact_sha256' => hash('sha256', $bytes)]);
    mock(SshKeyProvider::class)->shouldReceive('privateKeyPath')->andReturn('/keys/private');
    mock(KnownHostsStore::class)->shouldReceive('path')->andReturn('/keys/known_hosts');
    mock(SshExecutor::class)->shouldReceive('execute')->once()->andReturnUsing(function ($connection, RemoteCommand $command) use ($workspace, $bytes, $valid): CommandResult {
        expect($connection->host)->toBe($workspace->node->wireguard_ip);
        expect($command->input)->toBeNull();
        $stream = $command->protectedInput->stream();
        $header = json_decode(fgets($stream), true, flags: JSON_THROW_ON_ERROR);
        expect($header)->toBe(['sandbox_id' => $workspace->task_sandbox_id, 'sha256' => hash('sha256', $bytes), 'size' => 128]);
        expect(stream_get_contents($stream))->toBe($bytes);

        return new CommandResult(0, json_encode(['sandbox_id' => $valid ? $workspace->task_sandbox_id : 'foreign', 'sha256' => hash('sha256', $bytes)]), '', 1, false);
    });
    try {
        if ($valid) {
            app(SandboxPiArtifact::class)->prepare($workspace);
        } else {
            expect(fn () => app(SandboxPiArtifact::class)->prepare($workspace))->toThrow(ComputeException::class);
        }
    } finally {
        fclose($file);
    }
})->with([true, false]);

it('refuses unsafe or changed artifacts before guest mutation', function (string $fault): void {
    $workspace = UpCloudRuntimeWorkspace::create();
    $file = tmpfile();
    fwrite($file, str_repeat('x', 128));
    $path = stream_get_meta_data($file)['uri'];
    config(['compute.pi.artifact_path' => $path, 'compute.pi.artifact_sha256' => hash('sha256', str_repeat('x', 128))]);
    match ($fault) {
        'digest' => config(['compute.pi.artifact_sha256' => str_repeat('a', 64)]),
        'writable' => chmod($path, 0o666),
        'size' => ftruncate($file, 10),
        'node' => $workspace->node->forceFill(['compute_sandbox_id' => null])->save(),
        'role' => $workspace->node->roles()->delete(),
        'enrollment' => $workspace->taskSandbox->forceFill(['enrolled_at' => null])->save(),
    };
    mock(SshExecutor::class)->shouldReceive('execute')->never();
    try {
        expect(fn () => app(SandboxPiArtifact::class)->prepare($workspace->fresh()))->toThrow(ComputeException::class);
    } finally {
        fclose($file);
    }
})->with(['digest', 'writable', 'size', 'node', 'role', 'enrollment']);

it('checks binary publication retries, drift, architecture, and failed transfer recovery', function (): void {
    $process = new Process(['python3', base_path('tests/Fixtures/Compute/guest_pi_artifact_test.py'), resource_path('compute/guest-pi-artifact.py')]);
    $process->mustRun();
    expect($process->getExitCode())->toBe(0);
});
