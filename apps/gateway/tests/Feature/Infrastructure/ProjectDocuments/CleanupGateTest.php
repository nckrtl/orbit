<?php

declare(strict_types=1);

use App\Data\ProjectDocuments\DocumentStorageData;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\ProjectDocuments\CleanupGate;
use App\Models\ProjectDocumentCleanup;
use App\Models\ProjectDocumentStorage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

pest()->group('subprocess');

beforeEach(function (): void {
    $this->gateDirectory = sys_get_temp_dir().'/orbit-cleanup-gate-'.Str::uuid();
    config(['orbit.document_cleanup_runtime' => $this->gateDirectory]);
    $this->beforeApplicationDestroyed(function (): void {
        if (is_dir($this->gateDirectory)) {
            chmod($this->gateDirectory, 0700);
            File::deleteDirectory($this->gateDirectory);
        }
    });
});

/** Simulate a future recovery authorizer; this slice supplies no permit-granting operation. */
function cleanup_gate_permit(string $directory): string
{
    $state = json_decode(file_get_contents($directory.'/generation.json'), true, flags: JSON_THROW_ON_ERROR);
    $state['report_id'] = str_repeat('b', 64);
    $state['report_sha256'] = str_repeat('c', 64);
    $bytes = json_encode($state, JSON_THROW_ON_ERROR);
    file_put_contents($directory.'/generation.json', $bytes);
    file_put_contents($directory.'/permit.json', $bytes);
    chmod($directory.'/permit.json', 0600);

    return $bytes;
}

function cleanup_gate_command(string $directory, string $command, array $arguments = []): Process
{
    return new Process([PHP_BINARY, base_path('artisan'), $command, '--no-interaction', ...$arguments], base_path(), [
        'ORBIT_DOCUMENT_CLEANUP_RUNTIME' => $directory,
        // Exercise the real entry point even in the environment where Laravel normally suppresses events.
        'APP_ENV' => 'testing',
        'DB_DATABASE' => $directory.'/unavailable.sqlite',
    ], timeout: 15);
}

describe('document cleanup authorization', function (): void {
    it('is paused without runtime state and never creates state on a read or execution', function (): void {
        $gate = app(CleanupGate::class);
        expect($gate->status()->state)->toBe('paused');
        expect($gate->status()->generation)->toBeNull();
        expect($gate->status()->errorCode)->toBe('project_documents.cleanup_state_unavailable');
        expect(fn () => $gate->executeWithPermit(fn () => throw new RuntimeException('Deletion ran')))->toThrow(ResourceOperationException::class);
        expect(is_dir($this->gateDirectory))->toBeFalse();
        // Arrange an empty disposable directory for teardown only.
        mkdir($this->gateDirectory, 0700);
    });

    it('rotates on every pause and rejects a replayed permit or report association', function (): void {
        $gate = app(CleanupGate::class);
        $first = $gate->invalidate();
        $oldPermit = cleanup_gate_permit($this->gateDirectory);
        expect($gate->status()->state)->toBe('running');
        $calls = 0;
        expect($gate->executeWithPermit(function () use (&$calls): void {
            $calls++;
        }))->toBeTrue();
        expect($calls)->toBe(1);
        $inode = fileinode($this->gateDirectory.'/execution.lock');

        $paused = $gate->invalidate();
        expect($paused->state)->toBe('paused');
        expect($paused->generation)->not->toBe($first->generation);
        expect($paused->reportId)->toBeNull();
        expect(file_exists($this->gateDirectory.'/permit.json'))->toBeFalse();
        expect(fileinode($this->gateDirectory.'/execution.lock'))->toBe($inode);
        file_put_contents($this->gateDirectory.'/permit.json', $oldPermit);
        chmod($this->gateDirectory.'/permit.json', 0600);
        expect($gate->status()->state)->toBe('paused');
        expect(fn () => $gate->executeWithPermit(fn () => throw new RuntimeException('Deletion ran')))->toThrow(ResourceOperationException::class);
        expect($gate->invalidate()->generation)->not->toBe($paused->generation);
    });

    it('fails closed for unsafe or unreadable local state', function (string $damage): void {
        $gate = app(CleanupGate::class);
        $gate->invalidate();
        cleanup_gate_permit($this->gateDirectory);
        $generation = $this->gateDirectory.'/generation.json';
        match ($damage) {
            'unreadable' => chmod($generation, 0000),
            'public' => chmod($generation, 0644),
            'malformed' => file_put_contents($generation, '{'),
            'missing' => unlink($generation),
            'unknown-schema' => file_put_contents($generation, json_encode([...json_decode(file_get_contents($generation), true), 'schema_version' => 2])),
            'lock-replaced' => (function (): void {
                rename($this->gateDirectory.'/execution.lock', $this->gateDirectory.'/original.lock');
                file_put_contents($this->gateDirectory.'/execution.lock', '');
                chmod($this->gateDirectory.'/execution.lock', 0600);
            })(),
            'symlink' => (function () use ($generation): void {
                rename($generation, $generation.'.saved');
                symlink($generation.'.saved', $generation);
            })(),
            'directory' => chmod($this->gateDirectory, 0755),
            'permit' => chmod($this->gateDirectory.'/permit.json', 0644),
            'lock' => unlink($this->gateDirectory.'/execution.lock'),
            'lock-symlink' => (function (): void {
                rename($this->gateDirectory.'/execution.lock', $this->gateDirectory.'/saved.lock');
                symlink($this->gateDirectory.'/saved.lock', $this->gateDirectory.'/execution.lock');
            })(),
        };

        $status = $gate->status();
        expect($status->state)->toBe('paused');
        expect($status->errorCode)->toBe('project_documents.cleanup_state_unavailable');
        expect(fn () => $gate->executeWithPermit(fn () => throw new RuntimeException('Deletion ran')))->toThrow(ResourceOperationException::class);
        if (in_array($damage, ['unreadable', 'public', 'symlink', 'directory', 'permit', 'lock', 'lock-symlink', 'lock-replaced', 'unknown-schema'], true)) {
            expect(fn () => $gate->invalidate())->toThrow(ResourceOperationException::class);
        }
    })->with(['unreadable', 'public', 'malformed', 'missing', 'symlink', 'directory', 'permit', 'lock', 'lock-symlink', 'lock-replaced', 'unknown-schema']);

    it('never recreates a missing stable lock even if all state files have disappeared', function (): void {
        $gate = app(CleanupGate::class);
        $gate->invalidate();
        unlink($this->gateDirectory.'/generation.json');
        unlink($this->gateDirectory.'/execution.lock');

        expect(fn () => $gate->invalidate())->toThrow(ResourceOperationException::class);
        expect(file_exists($this->gateDirectory.'/execution.lock'))->toBeFalse();
        expect($gate->status()->state)->toBe('paused');
    });

    it('requires the current report integrity binding not merely a generation string', function (): void {
        $gate = app(CleanupGate::class);
        $gate->invalidate();
        cleanup_gate_permit($this->gateDirectory);
        $permit = json_decode(file_get_contents($this->gateDirectory.'/permit.json'), true);
        $permit['report_sha256'] = str_repeat('d', 64);
        file_put_contents($this->gateDirectory.'/permit.json', json_encode($permit));

        expect($gate->status()->state)->toBe('paused');
        expect(fn () => $gate->executeWithPermit(fn () => throw new RuntimeException('Deletion ran')))->toThrow(ResourceOperationException::class);
    });

    it('waits for the in-flight provider result before pause returns and denies subsequent execution', function (): void {
        $gate = app(CleanupGate::class);
        $gate->invalidate();
        cleanup_gate_permit($this->gateDirectory);
        $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/ProjectDocuments/cleanup_gate_execution.php'), $this->gateDirectory], timeout: 15);
        $worker->start();
        expect($worker->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'provider-started')))->toBeTrue();
        $pause = new Process([PHP_BINARY, base_path('tests/Fixtures/ProjectDocuments/cleanup_gate_execution.php'), $this->gateDirectory, 'pause'], timeout: 15);
        $pause->start();
        // The child signals that pause is attempting its lock; synchronize without assuming scheduler timing.
        expect($pause->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'pause-starting')))->toBeTrue();
        expect(file_exists($this->gateDirectory.'/permit.json'))->toBeTrue();
        expect($pause->isRunning())->toBeTrue();
        file_put_contents($this->gateDirectory.'/finish-provider', 'finish');
        expect($worker->wait())->toBe(0, $worker->getErrorOutput());
        expect($pause->wait())->toBe(0, $pause->getErrorOutput());
        expect($gate->status()->state)->toBe('paused');
        expect($gate->executeWithPermit(fn () => throw new RuntimeException('Deletion ran')))->toBeFalse();
    });

    it('releases the execution lock after provider failure without claiming deletion success', function (): void {
        $gate = app(CleanupGate::class);
        $gate->invalidate();
        cleanup_gate_permit($this->gateDirectory);
        expect(fn () => $gate->executeWithPermit(fn () => throw new RuntimeException('Provider failed')))->toThrow(RuntimeException::class, 'Provider failed');
        expect($gate->invalidate()->state)->toBe('paused');
    });
});

describe('local cleanup commands and storage status', function (): void {
    it('emits shared redacted counts and gate fields without authorizing recovery', function (): void {
        $gate = app(CleanupGate::class);
        $gate->invalidate();
        cleanup_gate_permit($this->gateDirectory);
        ProjectDocumentCleanup::query()->create(['storage_key' => 'private-document-key', 'pending' => true, 'next_attempt_at' => now(), 'last_error_code' => 'project_documents.storage_unavailable']);

        expect(Artisan::call('project-documents:cleanup:pause'))->toBe(0);
        $paused = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($paused)->toHaveKeys(['cleanup_state', 'cleanup_generation', 'reconciliation_report_id', 'pending_cleanup_count', 'oldest_pending_cleanup_at', 'last_cleanup_error_code']);
        expect($paused['cleanup_state'])->toBe('paused');
        expect($paused['pending_cleanup_count'])->toBe(1);
        expect($paused['last_cleanup_error_code'])->toBe('project_documents.storage_unavailable');
        expect(Artisan::output())->not->toContain('private-document-key');
        expect(Artisan::call('project-documents:cleanup:status'))->toBe(0);
        expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe($paused);
        $storage = DocumentStorageData::fromModel(ProjectDocumentStorage::query()->findOrFail(1))->toArray();
        expect($storage['cleanup_state'])->toBe('paused');
        expect($storage['cleanup_generation'])->toBe($paused['cleanup_generation']);
        expect($storage['reconciliation_report_id'])->toBeNull();
        expect(Artisan::all())->toHaveKey('project-documents:cleanup:work');
        expect(Artisan::call('project-documents:cleanup:resume'))->toBe(2);
        expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error_code'])->toBe('project_documents.cleanup_input_invalid');
        expect($gate->status()->state)->toBe('paused');
    });

    it('returns a sanitized nonzero status for unreadable state without reporting running', function (): void {
        app(CleanupGate::class)->invalidate();
        cleanup_gate_permit($this->gateDirectory);
        chmod($this->gateDirectory.'/generation.json', 0000);
        expect(Artisan::call('project-documents:cleanup:status'))->toBe(1);
        $data = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($data['cleanup_state'])->toBe('paused');
        expect($data['cleanup_generation'])->toBeNull();
        expect($data['error_code'])->toBe('project_documents.cleanup_state_unavailable');
        expect(Artisan::output())->not->toContain($this->gateDirectory);
    });

    it('rejects invalid command input with JSON and exit 2 before changing authorization', function (): void {
        $gate = app(CleanupGate::class);
        $initial = $gate->invalidate();
        cleanup_gate_permit($this->gateDirectory);
        $process = cleanup_gate_command($this->gateDirectory, 'project-documents:cleanup:pause', ['--report=unexpected']);
        expect($process->run())->toBe(2, $process->getOutput().$process->getErrorOutput());
        $data = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect($data['error_code'])->toBe('project_documents.cleanup_input_invalid');
        expect($gate->status()->state)->toBe('running');
        expect($gate->status()->generation)->toBe($initial->generation);
    });
});

describe('Gateway startup entry points', function (): void {
    it('invalidates authorization before a real Artisan consumer begins', function (string $command, array $arguments): void {
        $gate = app(CleanupGate::class);
        $first = $gate->invalidate();
        cleanup_gate_permit($this->gateDirectory);
        $process = cleanup_gate_command($this->gateDirectory, $command, $arguments);
        $process->start();
        try {
            $deadline = microtime(true) + 8;
            do {
                clearstatcache();
                if (! file_exists($this->gateDirectory.'/permit.json')) {
                    break;
                }
                if (! $process->isRunning()) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            expect(file_exists($this->gateDirectory.'/permit.json'))->toBeFalse($process->getOutput().$process->getErrorOutput());
            expect($gate->status()->generation)->not->toBe($first->generation);
            expect($gate->status()->reportId)->toBeNull();
        } finally {
            $process->stop();
        }
    })->with([
        'scheduler tick' => ['schedule:run', []],
        'scheduler daemon' => ['schedule:work', []],
        'queue worker' => ['queue:work', ['--once']],
        'queue listener' => ['queue:listen', []],
    ]);

    it('keeps authorization when the task VM worker starts, because it runs no document job', function (): void {
        $gate = app(CleanupGate::class);
        $first = $gate->invalidate();
        cleanup_gate_permit($this->gateDirectory);

        cleanup_gate_command($this->gateDirectory, 'queue:work', ['task-vms', '--queue=task-vms', '--stop-when-empty'])->run();

        expect(file_exists($this->gateDirectory.'/permit.json'))->toBeTrue()
            ->and($gate->status()->generation)->toBe($first->generation);
    });

    it('refuses consumer startup when the gate cannot be invalidated', function (): void {
        app(CleanupGate::class)->invalidate();
        chmod($this->gateDirectory.'/execution.lock', 0000);
        $process = cleanup_gate_command($this->gateDirectory, 'queue:work', ['--once']);
        expect($process->run())->toBe(1);
        expect($process->getOutput().$process->getErrorOutput())->toContain('Document cleanup state is unavailable.');
        expect($process->getOutput().$process->getErrorOutput())->not->toContain('unavailable.sqlite');
    });

    it('does not invalidate a healthy permit during ordinary application boot or reads', function (): void {
        $gate = app(CleanupGate::class);
        $initial = $gate->invalidate();
        cleanup_gate_permit($this->gateDirectory);
        $process = cleanup_gate_command($this->gateDirectory, 'list');
        expect($process->run())->toBe(0, $process->getOutput().$process->getErrorOutput());
        expect($gate->status()->state)->toBe('running');
        expect($gate->status()->generation)->toBe($initial->generation);
    });
});
