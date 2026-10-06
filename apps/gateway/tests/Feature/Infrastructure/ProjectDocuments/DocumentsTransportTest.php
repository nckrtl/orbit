<?php

declare(strict_types=1);

use App\Actions\ProjectDocuments\ReconcileProbesAction;
use App\Actions\ProjectDocuments\UpdateDocumentStorageAction;
use App\Data\ProjectDocuments\DocumentBody;
use App\Data\ProjectDocuments\UpdateDocumentStorageData;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\ProjectDocuments\DocumentBodies;
use App\Infrastructure\ProjectDocuments\DocumentsFilesystem;
use App\Infrastructure\ProjectDocuments\ProbeJournal;
use App\Infrastructure\ProjectDocuments\ProbeResponseBuffer;
use App\Infrastructure\ProjectDocuments\VerifyDocumentStorage;
use App\Models\ProjectDocumentStorage;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->probeTransportHome = sys_get_temp_dir().'/orbit-probe-transport-'.Str::uuid();
    mkdir($this->probeTransportHome, 0700, true);
    config(['orbit.home' => $this->probeTransportHome]);
    $this->beforeApplicationDestroyed(function (): void {
        File::deleteDirectory($this->probeTransportHome);
    });
});

/** @return array{Process, S3Client, ProjectDocumentStorage} */
function probe_transport_fixture(string $mode): array
{
    $server = new Process(['python3', base_path('tests/Fixtures/ProjectDocuments/probe_http_server.py'), $mode, config()->string('orbit.home').'/http-state.json']);
    $server->start();
    $deadline = hrtime(true) + 3_000_000_000;
    while (trim($server->getOutput()) === '' && $server->isRunning() && hrtime(true) < $deadline) {
        usleep(10_000);
    }
    $port = trim($server->getOutput());
    if (! ctype_digit($port)) {
        $server->stop();
        throw new RuntimeException('Loopback S3 transport fixture did not start.');
    }
    $storage = new ProjectDocumentStorage([
        'endpoint' => ($mode === 'tls-stall' ? 'https' : 'http').'://127.0.0.1:'.$port,
        'region' => 'europe-2', 'bucket' => 'fixture-bucket',
        'access_key_id' => 'fixture-access', 'secret_access_key' => 'fixture-secret',
    ]);

    return [$server, app(DocumentsFilesystem::class)->forConfiguration($storage)->getClient(), $storage];
}

/** @return array{Process, DocumentBodies} */
function body_transport_fixture(string $mode): array
{
    DB::rollBack();
    $database = config()->string('orbit.home').'/bodies.sqlite';
    touch($database);
    config(['database.connections.sqlite.database' => $database]);
    DB::purge('sqlite');
    test()->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    [$server, , $storage] = probe_transport_fixture($mode);
    ProjectDocumentStorage::query()->findOrFail(1)->update([
        'endpoint' => $storage->endpoint, 'region' => $storage->region, 'bucket' => $storage->bucket,
        'access_key_id' => 'fixture-access', 'secret_access_key' => 'fixture-secret',
    ]);

    return [$server, app(DocumentBodies::class)];
}

/** @return array<string, mixed> */
function probe_transport_state(): array
{
    return json_decode(file_get_contents(config()->string('orbit.home').'/http-state.json'), true, flags: JSON_THROW_ON_ERROR);
}

describe('committed document body HTTP transport', function (): void {
    it('round trips and verifies empty and binary bodies through real bounded HTTP responses', function (string $encoded): void {
        [$server, $bodies] = body_transport_fixture('short');
        $body = DocumentBody::base64($encoded);
        $bytes = $body->bytes;
        try {
            $bodies->put('orbit-documents/1/1/fixture', $body);

            expect($bodies->get('orbit-documents/1/1/fixture', $body->sizeBytes, $body->sha256))->toBe($bytes);
            expect(array_column(probe_transport_state()['requests'], 'method'))->toBe(['PUT', 'GET', 'GET']);
            expect(probe_transport_state()['requests'][0]['acl'])->toBe('private');
        } finally {
            $server->stop();
            DB::disconnect('sqlite');
        }
    })->with(['empty' => '', 'binary' => 'YQD/Cg==']);

    it('distinguishes missing and corrupt bodies from provider outages through the real HTTP sink', function (string $mode, int $size, string $code, int $status): void {
        [$server, $bodies] = body_transport_fixture($mode);
        try {
            try {
                $bodies->get('orbit-documents/1/1/fixture', $size, hash('sha256', str_repeat('a', $size)));
                test()->fail('The provider failure must not return content.');
            } catch (ResourceOperationException $exception) {
                expect($exception->errorCode)->toBe($code);
                expect($exception->status)->toBe($status);
                expect($exception->getPrevious())->toBeNull();
                expect((string) $exception)->not->toContain('fixture-access', 'fixture-secret', 'orbit-documents/');
            }
            if ($mode !== 'tls-stall') {
                expect(probe_transport_state()['requests'])->toHaveCount(1);
                expect(probe_transport_state()['requests'][0]['method'])->toBe('GET');
            }
        } finally {
            $server->stop();
            DB::disconnect('sqlite');
        }
    })->with([
        'missing empty file' => ['get-404', 0, 'project_documents.body_unavailable', 502],
        'missing small file' => ['get-404', 5, 'project_documents.body_unavailable', 502],
        'oversized successful response' => ['oversized', 5, 'project_documents.body_unavailable', 502],
        'denied empty file' => ['get-403', 0, 'project_documents.storage_unavailable', 503],
        'server failure small file' => ['get-500', 5, 'project_documents.storage_unavailable', 503],
        'connection timeout' => ['tls-stall', 5, 'project_documents.storage_unavailable', 503],
        'successful headers followed by body timeout' => ['body-timeout', 32, 'project_documents.storage_unavailable', 503],
        'missing bucket is a provider failure' => ['get-404-bucket', 0, 'project_documents.storage_unavailable', 503],
        'oversized denied diagnostic' => ['get-403-oversized', 0, 'project_documents.storage_unavailable', 503],
        'oversized server diagnostic' => ['get-500-oversized', 5, 'project_documents.storage_unavailable', 503],
    ]);
});

describe('document probe HTTP transport', function (): void {
    it('rejects small HTTP DELETE error and redirect responses without retrying or following redirects', function (int $status): void {
        [$server, $client] = probe_transport_fixture('delete-'.$status);
        try {
            expect(fn () => $client->deleteObject([
                'Bucket' => 'fixture-bucket', 'Key' => 'orbit-document-probes/fixture',
                '@http' => ['stream' => false, 'sink' => new ProbeResponseBuffer(65_536)],
            ]))->toThrow(RuntimeException::class);
            expect(probe_transport_state()['requests'])->toHaveCount(1);
            expect(probe_transport_state()['requests'][0]['status'])->toBe($status);
        } finally {
            $server->stop();
        }
    })->with(['denied' => 403, 'not found' => 404, 'server failure' => 500, 'redirect' => 302]);

    it('completes a private filesystem PUT GET DELETE round trip with the selected transport', function (): void {
        [$server, $client, $storage] = probe_transport_fixture('short');
        $disk = app(DocumentsFilesystem::class)->forConfiguration($storage);
        $key = 'orbit-document-probes/private-round-trip';
        $body = "private synthetic bytes\0\xff";
        try {
            expect($disk->put($key, $body))->toBeTrue();
            expect($disk->get($key))->toBe($body);
            expect($disk->delete($key))->toBeTrue();
            $state = probe_transport_state();
            expect(array_column($state['requests'], 'method'))->toBe(['PUT', 'GET', 'DELETE']);
            expect(array_column($state['requests'], 'status'))->toBe([200, 200, 204]);
            expect(array_column($state['requests'], 'authorized'))->toBe([true, true, true]);
            expect($state['requests'][0]['acl'])->toBe('private');
            expect($state['object_count'])->toBe(0);
        } finally {
            $server->stop();
        }
    });

    it('verifies successful real PUT GET DELETE responses and keeps the cleanup fence', function (): void {
        [$server, $client, $storage] = probe_transport_fixture('short');
        try {
            app(VerifyDocumentStorage::class)->handle($storage);
            $state = probe_transport_state();
            expect(array_column($state['requests'], 'method'))->toBe(['PUT', 'GET', 'DELETE']);
            expect(array_column($state['requests'], 'status'))->toBe([200, 200, 204]);
            expect($state['object_count'])->toBe(0);
            $this->travel(2)->minutes();
            expect(app(ReconcileProbesAction::class)->handle())->toBe(['attempted' => 1, 'succeeded' => 1, 'failed' => 0]);
        } finally {
            $server->stop();
        }
    });

    it('preserves prior configuration when real PUT GET succeed but DELETE is denied', function (): void {
        [$server, $client, $storage] = probe_transport_fixture('delete-403');
        $prior = ProjectDocumentStorage::query()->findOrFail(1);
        $prior->fill(['endpoint' => 'https://prior.example.test', 'region' => 'prior-region', 'bucket' => 'prior-bucket',
            'access_key_id' => 'prior-access', 'secret_access_key' => 'prior-secret'])->save();
        $original = $prior->getRawOriginal();
        $data = new UpdateDocumentStorageData($storage->endpoint, $storage->region, $storage->bucket, $storage->access_key_id, $storage->secret_access_key);
        try {
            try {
                app(UpdateDocumentStorageAction::class)->handle($data);
                test()->fail('Denied DELETE must reject configuration.');
            } catch (ResourceOperationException $exception) {
                expect($exception->status)->toBe(503);
                expect($exception->errorCode)->toBe('project_documents.storage_unavailable');
                expect($exception->getPrevious())->toBeNull();
                expect((string) $exception)->not->toContain('fixture-access', 'fixture-secret');
            }
            expect($prior->refresh()->getRawOriginal())->toBe($original);
            $state = probe_transport_state();
            expect(array_column($state['requests'], 'method'))->toBe(['PUT', 'GET', 'DELETE', 'DELETE']);
            expect(array_column($state['requests'], 'status'))->toBe([200, 200, 403, 403]);
            expect($state['object_count'])->toBe(1);
            $this->travel(2)->minutes();
            expect(app(ReconcileProbesAction::class)->handle())->toBe(['attempted' => 1, 'succeeded' => 0, 'failed' => 1]);
        } finally {
            $server->stop();
        }
    });

    it('records reconciliation failure and backoff for small HTTP DELETE errors and redirects', function (int $status): void {
        $this->freezeTime();
        [$server, $client, $storage] = probe_transport_fixture('delete-'.$status);
        $record = app(ProbeJournal::class)->create($storage);
        try {
            $client->putObject(['Bucket' => $storage->bucket, 'Key' => $record->key(), 'Body' => 'synthetic probe']);
            $this->travel(2)->minutes();
            expect(app(ReconcileProbesAction::class)->handle())->toBe(['attempted' => 1, 'succeeded' => 0, 'failed' => 1]);
            expect(app(ProbeJournal::class)->find($record->id)->failureCount)->toBe(1);
            $database = new PDO('sqlite:'.config()->string('orbit.home').'/project-document-probes/journal.sqlite');
            $row = $database->query('SELECT * FROM probes')->fetch(PDO::FETCH_ASSOC);
            expect($row['last_error_code'])->toBe('project_documents.storage_unavailable');
            expect($row['next_attempt_at'])->toBe(now()->getTimestamp() + 60);
            expect(app(ReconcileProbesAction::class)->handle())->toBe(['attempted' => 0, 'succeeded' => 0, 'failed' => 0]);
            $state = probe_transport_state();
            expect(array_column($state['requests'], 'status'))->toBe([200, $status]);
            expect($state['object_count'])->toBe(1);
        } finally {
            $server->stop();
        }
    })->with(['denied' => 403, 'not found' => 404, 'server failure' => 500, 'redirect' => 302]);

    it('enforces the connection bound during a stalled TLS handshake', function (): void {
        [$server, $client] = probe_transport_fixture('tls-stall');
        $started = hrtime(true);
        try {
            expect(fn () => $client->getObject([
                'Bucket' => 'fixture-bucket', 'Key' => 'orbit-document-probes/fixture',
                '@http' => ['stream' => false, 'sink' => new ProbeResponseBuffer],
            ]))->toThrow(RuntimeException::class);
            expect((hrtime(true) - $started) / 1_000_000_000)->toBeGreaterThan(1.5)->toBeLessThan(3.5);
        } finally {
            $server->stop();
        }
    });

    it('enforces the total request deadline when a response body stalls', function (): void {
        [$server, $client] = probe_transport_fixture('body-stall');
        $started = hrtime(true);
        try {
            expect(fn () => $client->getObject([
                'Bucket' => 'fixture-bucket', 'Key' => 'orbit-document-probes/fixture',
                '@http' => ['stream' => false, 'sink' => new ProbeResponseBuffer],
            ]))->toThrow(RuntimeException::class);
            expect((hrtime(true) - $started) / 1_000_000_000)->toBeGreaterThan(4.5)->toBeLessThan(7);
        } finally {
            $server->stop();
        }
    });

    it('accepts a complete body transferred in short HTTP chunks', function (): void {
        [$server, $client] = probe_transport_fixture('short');
        try {
            $result = $client->getObject([
                'Bucket' => 'fixture-bucket', 'Key' => 'orbit-document-probes/fixture', 'Range' => 'bytes=0-32',
                '@http' => ['stream' => false, 'sink' => new ProbeResponseBuffer],
            ]);
            expect((string) $result['Body'])->toBe(str_repeat('a', 32));
        } finally {
            $server->stop();
        }
    });

    it('bounds DELETE error responses used by reconciliation', function (): void {
        [$server, $client] = probe_transport_fixture('oversized');
        $buffer = new ProbeResponseBuffer(65_536);
        try {
            expect(fn () => $client->deleteObject([
                'Bucket' => 'fixture-bucket', 'Key' => 'orbit-document-probes/fixture',
                '@http' => ['stream' => false, 'sink' => $buffer],
            ]))->toThrow(RuntimeException::class);
            expect($buffer->getSize())->toBe(65_536);
        } finally {
            $server->stop();
        }
    });

    it('bounds buffered bytes when an HTTP server ignores Range', function (): void {
        [$server, $client] = probe_transport_fixture('oversized');
        $buffer = new ProbeResponseBuffer;
        try {
            expect(fn () => $client->getObject([
                'Bucket' => 'fixture-bucket', 'Key' => 'orbit-document-probes/fixture', 'Range' => 'bytes=0-32',
                '@http' => ['stream' => false, 'sink' => $buffer],
            ]))->toThrow(RuntimeException::class);
            expect($buffer->getSize())->toBe(33);
        } finally {
            $server->stop();
        }
    });
});
