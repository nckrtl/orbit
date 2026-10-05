<?php

declare(strict_types=1);

use App\Actions\ProjectDocuments\ReconcileProbesAction;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\ProjectDocuments\CleanupGate;
use App\Infrastructure\ProjectDocuments\ProbeJournal;
use App\Models\Activity;
use App\Models\Node;
use App\Models\ProjectDocumentStorage;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\Result;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->probeHome = sys_get_temp_dir().'/orbit-probe-recovery-'.Str::uuid();
    config(['orbit.home' => $this->probeHome]);
    $this->beforeApplicationDestroyed(function (): void {
        File::deleteDirectory($this->probeHome);
    });
});

/** @return array<string, string> */
function probe_recovery_input(): array
{
    return ['endpoint' => 'https://old-store.example.test', 'region' => 'europe-2', 'bucket' => 'fixture-bucket',
        'access_key_id' => 'fixture-access', 'secret_access_key' => 'fixture-secret'];
}

function probe_recovery_gateway(): void
{
    $gateway = Node::query()->create(['name' => 'probe-gateway', 'status' => LifecycleStatus::Active, 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']);
    test()->markAsGateway($gateway);
    test()->withServerVariables(['REMOTE_ADDR' => '10.44.0.1']);
}

function probe_recovery_database(): PDO
{
    return new PDO('sqlite:'.config()->string('orbit.home').'/project-document-probes/journal.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function probe_recovery_provider(): object
{
    $provider = (object) ['calls' => [], 'objects' => [], 'failDelete' => false, 'beforePut' => null];
    config(['filesystems.disks.documents.handler' => function (CommandInterface $command, RequestInterface $request) use ($provider): PromiseInterface {
        $provider->calls[] = ['operation' => $command->getName(), 'key' => $command['Key'], 'bucket' => $command['Bucket'], 'host' => $request->getUri()->getHost(), 'authorization' => $request->getHeaderLine('Authorization')];
        $key = $command['Key'];
        if ($command->getName() === 'PutObject') {
            if ($provider->beforePut !== null) {
                ($provider->beforePut)($command);
            }
            $provider->objects[$key] = (string) $command['Body'];
        }
        if ($command->getName() === 'DeleteObject') {
            if ($provider->failDelete) {
                return Create::rejectionFor(new AwsException('Rejected fixture-access fixture-secret', $command));
            }
            unset($provider->objects[$key]);
        }

        return Create::promiseFor(new Result($command->getName() === 'GetObject' ? ['Body' => Utils::streamFor($provider->objects[$key] ?? '')] : []));
    }]);

    return $provider;
}

describe('reserved probe recovery', function (): void {
    it('reconciles only tracked synthetic probes while the document-body gate remains paused', function (): void {
        mkdir($this->probeHome, 0700);
        config(['orbit.document_cleanup_runtime' => $this->probeHome.'/runtime']);
        $gate = app(CleanupGate::class);
        $generation = $gate->invalidate()->generation;
        $provider = probe_recovery_provider();
        $record = app(ProbeJournal::class)->create(new ProjectDocumentStorage(probe_recovery_input()));
        $provider->objects[$record->key()] = str_repeat('p', 32);
        $provider->objects['project-documents/private-body'] = 'private document bytes';
        $this->travel(2)->minutes();

        expect(app(ReconcileProbesAction::class)->handle())->toBe(['attempted' => 1, 'succeeded' => 1, 'failed' => 0]);
        expect(array_column($provider->calls, 'key'))->toBe([$record->key()]);
        expect($provider->objects)->toBe(['project-documents/private-body' => 'private document bytes']);
        expect($gate->status()->state)->toBe('paused');
        expect($gate->status()->generation)->toBe($generation);
        expect($gate->executeWithPermit(fn () => throw new RuntimeException('Document deletion ran')))->toBeFalse();
    });

    it('commits encrypted credentials independently before PUT and fails configuration without losing that journal', function (): void {
        probe_recovery_gateway();
        $provider = probe_recovery_provider();
        $provider->failDelete = true;
        $provider->beforePut = function (CommandInterface $command): void {
            expect(DB::transactionLevel())->toBeGreaterThan(0);
            $independent = probe_recovery_database();
            $row = $independent->query('SELECT * FROM probes')->fetch(PDO::FETCH_ASSOC);
            expect($row['id'])->toBe(substr($command['Key'], strlen('orbit-document-probes/')));
            expect($row['endpoint'])->toBe('https://old-store.example.test');
            expect($row['bucket'])->toBe($command['Bucket']);
            expect(Crypt::decryptString($row['access_key_id']))->toBe('fixture-access');
            expect(Crypt::decryptString($row['secret_access_key']))->toBe('fixture-secret');
            expect($independent->inTransaction())->toBeFalse();
        };

        $this->putJson('/api/v1/project-document-storage', probe_recovery_input())->assertStatus(503);
        expect(ProjectDocumentStorage::query()->findOrFail(1)->endpoint)->toBeNull();
        expect(probe_recovery_database()->query('SELECT COUNT(*) FROM probes')->fetchColumn())->toBe(1);
        $key = $provider->calls[0]['key'];
        expect($provider->objects)->toHaveKey($key);
        $provider->failDelete = false;
        $this->travel(2)->minutes();
        expect(app(ReconcileProbesAction::class)->handle())->toBe(['attempted' => 1, 'succeeded' => 1, 'failed' => 0]);
        expect($provider->objects)->not->toHaveKey($key);
        expect(probe_recovery_database()->query('SELECT COUNT(*) FROM probes')->fetchColumn())->toBe(1);
        expect(file_get_contents($this->probeHome.'/project-document-probes/journal.sqlite'))->not->toContain('fixture-access', 'fixture-secret');
        expect(Activity::query()->get()->toJson())->not->toContain('fixture-access', 'fixture-secret');
    });

    it('retains the independently committed journal when configuration persistence rolls back after verification', function (): void {
        probe_recovery_gateway();
        $provider = probe_recovery_provider();
        ProjectDocumentStorage::saving(static function (): never {
            throw new RuntimeException('Configuration persistence failed.');
        });
        try {
            $this->putJson('/api/v1/project-document-storage', probe_recovery_input())->assertStatus(500);
        } finally {
            ProjectDocumentStorage::flushEventListeners();
        }

        expect(ProjectDocumentStorage::query()->findOrFail(1)->endpoint)->toBeNull();
        expect(array_column($provider->calls, 'operation'))->toBe(['PutObject', 'GetObject', 'DeleteObject']);
        expect($provider->objects)->toBe([]);
        expect(probe_recovery_database()->query('SELECT COUNT(*) FROM probes')->fetchColumn())->toBe(1);
        $provider->objects[$provider->calls[0]['key']] = 'late PUT after configuration rollback';
        $this->travel(2)->minutes();
        expect(app(ReconcileProbesAction::class)->handle()['succeeded'])->toBe(1);
        expect($provider->objects)->toBe([]);
    });

    it('fails closed before PUT when the journal cannot securely commit', function (): void {
        probe_recovery_gateway();
        $provider = probe_recovery_provider();
        mkdir($this->probeHome.'/project-document-probes', 0700, true);
        chmod($this->probeHome.'/project-document-probes', 0777);

        $this->putJson('/api/v1/project-document-storage', probe_recovery_input())->assertStatus(503);
        expect($provider->calls)->toBe([]);
        expect(ProjectDocumentStorage::query()->findOrFail(1)->endpoint)->toBeNull();
    });

    it('recovers a journal after the request process is killed immediately after PUT', function (): void {
        $process = new Process([PHP_BINARY, base_path('tests/Fixtures/ProjectDocuments/interrupted_probe.php'), $this->probeHome]);
        $process->setTimeout(15);
        expect(fn () => $process->run())->toThrow(ProcessSignaledException::class);
        expect($process->hasBeenSignaled())->toBeTrue();
        expect($process->getTermSignal())->toBe(9);
        $object = json_decode(file_get_contents($this->probeHome.'/remote-object.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($object['configuration_transaction_level'])->toBeGreaterThan(0);
        $id = substr($object['key'], strlen('orbit-document-probes/'));
        $record = app(ProbeJournal::class)->find($id);
        expect($record->key())->toBe($object['key']);
        $provider = probe_recovery_provider();
        $provider->objects[$object['key']] = 'interrupted probe';
        $this->travel(2)->minutes();

        expect(app(ReconcileProbesAction::class)->handle())->toBe(['attempted' => 1, 'succeeded' => 1, 'failed' => 0]);
        expect($provider->objects)->toBe([]);
        expect((new ProbeJournal)->find($id)->key())->toBe($object['key']);
    });

    it('retains successful and absent DELETE observations so a late PUT is deleted after restart', function (): void {
        $provider = probe_recovery_provider();
        $record = app(ProbeJournal::class)->create(new ProjectDocumentStorage(probe_recovery_input()));
        $provider->objects[$record->key()] = 'first PUT';
        $this->travel(2)->minutes();
        $action = app(ReconcileProbesAction::class);
        expect($action->handle()['succeeded'])->toBe(1);
        $this->travel(2)->minutes();
        expect($action->handle()['succeeded'])->toBe(1);
        $provider->objects[$record->key()] = 'late PUT after absent DELETE';
        $this->travel(2)->minutes();
        app()->forgetInstance(ProbeJournal::class);
        app()->forgetInstance(ReconcileProbesAction::class);

        expect(app(ReconcileProbesAction::class)->handle()['succeeded'])->toBe(1);
        expect($provider->objects)->toBe([]);
        expect(app(ProbeJournal::class)->find($record->id)->key())->toBe($record->key());
    });

    it('uses the recorded destination rather than retargeting old cleanup after a configuration change', function (): void {
        probe_recovery_gateway();
        $provider = probe_recovery_provider();
        $this->putJson('/api/v1/project-document-storage', probe_recovery_input())->assertOk();
        $this->putJson('/api/v1/project-document-storage', [...probe_recovery_input(), 'endpoint' => 'https://new-store.example.test', 'region' => 'us-east-1', 'bucket' => 'new-fixture-bucket', 'access_key_id' => 'new-fixture-access', 'secret_access_key' => 'new-fixture-secret'])->assertOk();
        $provider->calls = [];
        $this->travel(2)->minutes();

        expect(app(ReconcileProbesAction::class)->handle()['succeeded'])->toBe(2);
        expect(array_column($provider->calls, 'host'))->toContain('old-store.example.test', 'new-store.example.test');
        expect(array_column($provider->calls, 'bucket'))->toContain('fixture-bucket', 'new-fixture-bucket');
        $byHost = collect($provider->calls)->keyBy('host');
        expect($byHost['old-store.example.test']['authorization'])->toContain('fixture-access/', '/europe-2/s3/aws4_request');
        expect($byHost['new-store.example.test']['authorization'])->toContain('new-fixture-access/', '/us-east-1/s3/aws4_request');
        foreach ($provider->calls as $call) {
            expect($call['operation'])->toBe('DeleteObject');
            expect($call['key'])->toStartWith('orbit-document-probes/');
        }
    });

    it('refuses document keys, untracked keys and malformed journal IDs without provider access', function (): void {
        $provider = probe_recovery_provider();
        $journal = app(ProbeJournal::class);
        $record = $journal->create(new ProjectDocumentStorage(probe_recovery_input()));
        $action = app(ReconcileProbesAction::class);
        foreach (['documents/1/1', Str::uuid()->toString(), $record->key()] as $untracked) {
            expect(fn () => $action->deleteTracked($untracked))->toThrow(RuntimeException::class);
        }
        probe_recovery_database()->prepare('UPDATE probes SET id = ? WHERE id = ?')->execute(['documents/1/1', $record->id]);
        $this->travel(2)->minutes();

        expect($action->handle())->toBe(['attempted' => 1, 'succeeded' => 0, 'failed' => 1]);
        expect($provider->calls)->toBe([]);
        expect(probe_recovery_database()->query('SELECT COUNT(*) FROM probes')->fetchColumn())->toBe(1);
    });

    it('makes fair progress in finite batches, retains failed attempts and backs them off', function (): void {
        $this->freezeTime();
        $provider = probe_recovery_provider();
        for ($index = 0; $index < 25; $index++) {
            app(ProbeJournal::class)->create(new ProjectDocumentStorage(probe_recovery_input()));
        }
        $provider->failDelete = true;
        $this->travel(2)->minutes();
        $action = app(ReconcileProbesAction::class);
        expect($action->handle())->toBe(['attempted' => 20, 'succeeded' => 0, 'failed' => 20]);
        expect($action->handle())->toBe(['attempted' => 5, 'succeeded' => 0, 'failed' => 5]);
        expect($action->handle()['attempted'])->toBe(0);
        expect(array_unique(array_column($provider->calls, 'key')))->toHaveCount(25);
        $this->travel(61)->seconds();
        $action->handle();
        $action->handle();
        expect($action->handle()['attempted'])->toBe(0);
        expect((int) probe_recovery_database()->query('SELECT MAX(next_attempt_at - last_attempt_at) FROM probes')->fetchColumn())->toBe(120);
        expect(probe_recovery_database()->query('SELECT COUNT(*) FROM probes')->fetchColumn())->toBe(25);
    });

    it('retries expired claims after process interruption without discarding records', function (): void {
        $record = app(ProbeJournal::class)->create(new ProjectDocumentStorage(probe_recovery_input()));
        $this->travel(2)->minutes();
        expect(app(ProbeJournal::class)->claimDue())->toBe([$record->id]);
        expect(app(ProbeJournal::class)->claimDue())->toBe([]);
        $this->travel(11)->minutes();

        expect((new ProbeJournal)->claimDue())->toBe([$record->id]);
    });

    it('keeps lost-key records until a local protected-file repair restores usable credentials', function (): void {
        $provider = probe_recovery_provider();
        $record = app(ProbeJournal::class)->create(new ProjectDocumentStorage(probe_recovery_input()));
        $old = new Encrypter(str_repeat('x', 32), 'AES-256-CBC');
        probe_recovery_database()->prepare('UPDATE probes SET access_key_id = ?, secret_access_key = ? WHERE id = ?')->execute([$old->encryptString('old-access'), $old->encryptString('old-secret'), $record->id]);
        $this->travel(2)->minutes();
        expect(app(ReconcileProbesAction::class)->handle()['failed'])->toBe(1);
        expect($provider->calls)->toBe([]);
        expect(probe_recovery_database()->query('SELECT last_error_code FROM probes')->fetchColumn())->toBe('project_documents.storage_unavailable');
        foreach (['access' => 'repaired-access', 'secret' => 'repaired-secret'] as $file => $value) {
            file_put_contents($this->probeHome.'/'.$file, $value."\n");
            chmod($this->probeHome.'/'.$file, 0600);
        }

        $this->artisan('project-documents:probes:repair', ['record' => $record->id, '--access-key-id-file' => $this->probeHome.'/access', '--secret-access-key-file' => $this->probeHome.'/secret'])->assertSuccessful();
        expect(app(ReconcileProbesAction::class)->handle()['succeeded'])->toBe(1);
        $repaired = app(ProbeJournal::class)->find($record->id);
        expect($repaired->endpoint)->toBe('https://old-store.example.test');
        expect($repaired->key())->toBe($record->key());
        expect(print_r($repaired, true).json_encode($repaired))->not->toContain('repaired-access', 'repaired-secret');
        expect($provider->calls[0]['authorization'])->toContain('repaired-access');
    });

    it('refuses unsafe repair files without exposing credentials or changing the journal', function (string $contents, int $mode): void {
        $record = app(ProbeJournal::class)->create(new ProjectDocumentStorage(probe_recovery_input()));
        file_put_contents($this->probeHome.'/public-secret', $contents);
        chmod($this->probeHome.'/public-secret', $mode);

        $this->artisan('project-documents:probes:repair', ['record' => $record->id, '--access-key-id-file' => $this->probeHome.'/public-secret', '--secret-access-key-file' => $this->probeHome.'/public-secret'])->assertFailed();
        expect(app(ProbeJournal::class)->find($record->id)->configuration()->access_key_id)->toBe('fixture-access');
    })->with([
        'public credentials' => ['unsafe-secret', 0644],
        'empty credentials' => ['', 0600],
        'oversized credentials' => [str_repeat('a', 1027), 0600],
        'invalid UTF-8' => ["\xff", 0600],
    ]);

    it('schedules bounded probe reconciliation every minute outside document-body cleanup', function (): void {
        $this->artisan('project-documents:probes:reconcile')->expectsOutput('{"attempted":0,"succeeded":0,"failed":0}')->assertSuccessful();
        $events = collect(app(Schedule::class)->events())->filter(fn ($event): bool => str_contains($event->command ?? '', 'project-documents:probes:reconcile'));
        expect($events)->toHaveCount(1);
        expect($events->first()->expression)->toBe('* * * * *');
    });
});
