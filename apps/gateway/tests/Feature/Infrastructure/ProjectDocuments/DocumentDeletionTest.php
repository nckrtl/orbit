<?php

declare(strict_types=1);

use App\Actions\ProjectDocuments\DocumentTreeAction;
use App\Actions\ProjectDocuments\RecoverDocumentCleanupAction;
use App\Actions\ProjectDocuments\UpdateDocumentStorageAction;
use App\Actions\ProjectDocuments\WorkDocumentCleanupAction;
use App\Actions\ProjectDocuments\WriteDocumentAction;
use App\Data\ProjectDocuments\DocumentBody;
use App\Data\ProjectDocuments\UpdateDocumentStorageData;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\ProjectDocuments\CleanupGate;
use App\Infrastructure\ProjectDocuments\ProbeJournal;
use App\Models\Project;
use App\Models\ProjectDocumentCleanup;
use App\Models\ProjectDocumentEntry;
use App\Models\ProjectDocumentStorage;
use App\Models\ProjectDocumentUpload;
use App\Models\ProjectDocumentVersion;
use Aws\Handler\Guzzle\GuzzleHandler;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

pest()->group('subprocess');

beforeEach(function (): void {
    DB::rollBack();
    $this->deletionHome = sys_get_temp_dir().'/orbit-document-deletion-'.Str::uuid();
    mkdir($this->deletionHome, 0700);
    touch($this->deletionHome.'/database.sqlite');
    config(['orbit.home' => $this->deletionHome, 'orbit.document_cleanup_runtime' => $this->deletionHome.'/runtime',
        'database.connections.sqlite.database' => $this->deletionHome.'/database.sqlite']);
    DB::purge('sqlite');
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    $this->beforeApplicationDestroyed(function (): void {
        if (isset($this->deletionServer)) {
            $this->deletionServer->stop();
        }
        DB::disconnect('sqlite');
        File::deleteDirectory($this->deletionHome);
    });
});

function deletion_bucket(array $objects, string $mode = ''): void
{
    $encoded = [];
    foreach ($objects as $key => $body) {
        $encoded[$key] = ['body' => base64_encode($body)];
    }
    file_put_contents(config()->string('orbit.home').'/bucket.json', json_encode(['objects' => (object) $encoded, 'mode' => $mode], JSON_THROW_ON_ERROR));
}

function deletion_provider(array $objects = []): void
{
    deletion_bucket($objects);
    $home = config()->string('orbit.home');
    $server = new Process(['python3', base_path('tests/Fixtures/ProjectDocuments/inventory_http_server.py'), $home.'/bucket.json', $home.'/requests.json']);
    test()->deletionServer = $server;
    $server->start();
    $deadline = microtime(true) + 3;
    while (trim($server->getOutput()) === '' && $server->isRunning() && microtime(true) < $deadline) {
        usleep(10_000);
    }
    $port = trim($server->getOutput());
    expect(ctype_digit($port))->toBeTrue();
    ProjectDocumentStorage::query()->findOrFail(1)->update([
        'endpoint' => 'http://127.0.0.1:'.$port, 'region' => 'fixture-1', 'bucket' => 'fixture-bucket',
        'access_key_id' => 'fixture-access', 'secret_access_key' => 'fixture-secret',
    ]);
    app(CleanupGate::class)->invalidate();
}

function deletion_resume(): void
{
    $action = app(RecoverDocumentCleanupAction::class);
    $report = $action->reconcile();
    expect($report['difference_count'])->toBe(0);
    expect($action->resume($report['report_id'])['cleanup_state'])->toBe('running');
}

function deletion_requests(): array
{
    $path = config()->string('orbit.home').'/requests.json';
    $requests = is_file($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];

    return array_values(array_filter($requests, fn (array $request): bool => $request['method'] === 'DELETE'));
}

function deletion_project(): Project
{
    $name = 'deletion-'.Str::uuid();

    return Project::query()->create(['name' => $name, 'slug' => $name, 'repository_url' => 'https://github.com/example/'.$name.'.git']);
}

function deletion_child(string $mode): Process
{
    return new Process([PHP_BINARY, base_path('tests/Fixtures/ProjectDocuments/interrupted_document_cleanup.php'), config()->string('orbit.home'), $mode],
        env: ['APP_KEY' => config()->string('app.key'), 'APP_ENV' => 'testing', 'DB_URL' => '', 'CACHE_STORE' => 'array']);
}

function deletion_work(): array
{
    return app(WorkDocumentCleanupAction::class)->handle();
}

it('is a paused no-op including due claims and expired active intents', function (): void {
    deletion_provider(['removed' => 'body']);
    $record = ProjectDocumentCleanup::query()->create(['storage_key' => 'removed', 'next_attempt_at' => now()]);
    $upload = ProjectDocumentUpload::query()->create(['project_id' => 999, 'storage_key' => 'active', 'state' => 'active', 'created_at' => now()->subHours(2)]);
    $before = $record->fresh()->getAttributes();
    expect(deletion_work())->toMatchArray(['claimed_count' => 0, 'deleted_count' => 0, 'failed_count' => 0]);
    expect($record->fresh()->getAttributes())->toBe($before);
    expect($upload->fresh()->state)->toBe('active');
    expect(deletion_requests())->toBe([]);
});

it('requires one real successful HTTP DELETE and retries failures with bounded backoff', function (string $mode): void {
    deletion_provider(['removed' => 'body']);
    $record = ProjectDocumentCleanup::query()->create(['storage_key' => 'removed', 'next_attempt_at' => now()]);
    deletion_resume();
    deletion_bucket(['removed' => 'body'], $mode);
    expect(deletion_work())->toMatchArray(['claimed_count' => 1, 'deleted_count' => 0, 'failed_count' => 1]);
    expect(deletion_requests())->toHaveCount(1);
    expect($record->fresh())->pending->toBeTrue()->attempts->toBe(1)->last_error_code->toBe('project_documents.storage_unavailable')->claim_token->toBeNull();
    expect(deletion_work()['claimed_count'])->toBe(0);
    $record->refresh()->update(['attempts' => 100, 'next_attempt_at' => now()]);
    deletion_work();
    expect($record->fresh()->next_attempt_at->diffInSeconds(now(), absolute: true))->toBeBetween(3598, 3601);
    $record->refresh()->update(['next_attempt_at' => now()]);
    deletion_bucket(['removed' => 'body']);
    expect(deletion_work()['deleted_count'])->toBe(1);
    expect($record->fresh())->toBeNull();
    expect(deletion_requests())->toHaveCount(3);
})->with(['delete-500', 'delete-redirect']);

it('retains absent abandoned fences and deletes a late PUT using rotated credentials', function (): void {
    deletion_provider();
    $upload = ProjectDocumentUpload::query()->create(['project_id' => 999, 'storage_key' => 'late', 'state' => 'abandoned']);
    $record = ProjectDocumentCleanup::query()->create(['storage_key' => 'late', 'retained_fence' => true, 'next_attempt_at' => now()]);
    deletion_resume();
    expect(deletion_work()['deleted_count'])->toBe(1);
    expect($record->fresh())->pending->toBeFalse()->retained_fence->toBeTrue();
    foreach (['endpoint', 'region', 'bucket'] as $field) {
        $changes = array_fill_keys(['endpoint', 'region', 'bucket'], null);
        $changes[$field] = $field === 'endpoint' ? 'https://changed.example.test' : 'changed';
        expect(fn () => app(UpdateDocumentStorageAction::class)->handle(
            new UpdateDocumentStorageData($changes['endpoint'], $changes['region'], $changes['bucket'], null, null),
        ))->toThrow(ResourceOperationException::class, 'in use');
    }
    expect($upload->fresh()->state)->toBe('abandoned');
    expect(deletion_work()['claimed_count'])->toBe(0);
    deletion_bucket(['late' => 'late PUT']);
    ProjectDocumentStorage::query()->findOrFail(1)->update(['access_key_id' => 'rotated-fixture-access', 'secret_access_key' => 'rotated-fixture-secret']);
    $record->refresh()->update(['next_attempt_at' => now()]);
    expect(deletion_work()['deleted_count'])->toBe(1);
    expect($record->fresh()->pending)->toBeFalse();
    expect(deletion_requests())->toHaveCount(2);
    expect(json_decode(file_get_contents($this->deletionHome.'/bucket.json'), true)['objects'])->toBe([]);
});

it('recovers expired process claims without consuming unexpired claims and bounds a batch to 100', function (): void {
    deletion_provider();
    for ($i = 0; $i < 102; $i++) {
        ProjectDocumentCleanup::query()->create(['storage_key' => 'removed-'.$i, 'next_attempt_at' => now(),
            'claim_token' => str_repeat('a', 64), 'claim_expires_at' => $i === 0 ? now()->addMinutes(10) : now()->subSecond()]);
    }
    deletion_resume();
    expect(deletion_work())->toMatchArray(['claimed_count' => 100, 'deleted_count' => 100, 'failed_count' => 0]);
    expect(ProjectDocumentCleanup::query()->count())->toBe(2);
    expect(deletion_work()['deleted_count'])->toBe(1);
    expect(ProjectDocumentCleanup::query()->first()->storage_key)->toBe('removed-0');
});

it('claims pending tombstones and pending fences before more than 100 older idle fences', function (): void {
    $this->freezeTime();
    deletion_provider();
    for ($i = 0; $i < 101; $i++) {
        $key = 'idle-'.$i;
        ProjectDocumentUpload::query()->create(['project_id' => 999, 'storage_key' => $key, 'state' => 'abandoned']);
        ProjectDocumentCleanup::query()->create(['storage_key' => $key, 'retained_fence' => true,
            'pending' => false, 'next_attempt_at' => now()->subMinutes(10)]);
    }
    $tombstone = ProjectDocumentCleanup::query()->create(['storage_key' => 'pending-removal', 'next_attempt_at' => now()]);
    ProjectDocumentUpload::query()->create(['project_id' => 999, 'storage_key' => 'pending-fence', 'state' => 'abandoned']);
    $fence = ProjectDocumentCleanup::query()->create(['storage_key' => 'pending-fence', 'retained_fence' => true, 'next_attempt_at' => now()]);
    deletion_resume();

    expect(deletion_work())->toMatchArray(['claimed_count' => 100, 'deleted_count' => 100, 'failed_count' => 0]);

    expect($tombstone->fresh())->toBeNull();
    expect($fence->fresh())->pending->toBeFalse()->retained_fence->toBeTrue();
    expect(array_slice(array_column(deletion_requests(), 'path'), 0, 2))
        ->toBe(['/fixture-bucket/pending-removal', '/fixture-bucket/pending-fence']);
    expect(ProjectDocumentCleanup::query()->where('retained_fence', true)->count())->toBe(102);
    expect(deletion_work())->toMatchArray(['claimed_count' => 3, 'deleted_count' => 3, 'failed_count' => 0]);
    expect(ProjectDocumentCleanup::query()->where('next_attempt_at', '<=', now())->count())->toBe(0);
});

it('commits the retained published-row handoff but never deletes conflicting committed or active keys', function (): void {
    deletion_provider(['removed' => 'body']);
    $published = ProjectDocumentUpload::query()->create(['project_id' => 999, 'storage_key' => 'removed', 'state' => 'published']);
    ProjectDocumentCleanup::query()->create(['storage_key' => 'removed', 'next_attempt_at' => now()]);
    deletion_resume();
    expect(deletion_work()['deleted_count'])->toBe(1);
    expect($published->fresh())->toBeNull();
    $active = ProjectDocumentUpload::query()->create(['project_id' => 999, 'storage_key' => 'active', 'state' => 'active']);
    $record = ProjectDocumentCleanup::query()->create(['storage_key' => 'active', 'next_attempt_at' => now()]);
    expect(deletion_work()['failed_count'])->toBe(1);
    expect($record->fresh()->last_error_code)->toBe('project_documents.cleanup_reference_conflict');
    expect($active->fresh()->state)->toBe('active');
    expect(deletion_requests())->toHaveCount(1);
});

it('deletes Project removal bodies without touching unknown objects or tracked probes', function (): void {
    deletion_provider(['published' => 'body']);
    $name = 'deletion-'.Str::uuid();
    $project = Project::query()->create(['name' => $name, 'slug' => $name, 'repository_url' => 'https://github.com/example/'.$name.'.git']);
    $entry = ProjectDocumentEntry::query()->create(['project_id' => $project->id, 'kind' => 'file', 'name' => 'file.txt', 'sibling_scope' => 0]);
    $upload = ProjectDocumentUpload::query()->create(['project_id' => $project->id, 'entry_id' => $entry->id, 'storage_key' => 'published', 'state' => 'published']);
    $version = ProjectDocumentVersion::query()->create(['entry_id' => $entry->id, 'upload_id' => $upload->id, 'number' => 1,
        'media_type' => 'text/plain', 'size_bytes' => 4, 'sha256' => hash('sha256', 'body'), 'storage_key' => 'published', 'created_at' => now()]);
    $entry->update(['current_version_id' => $version->id]);
    deletion_resume();
    // A conflicting tombstone cannot delete committed bytes even with a running permit.
    $conflict = ProjectDocumentCleanup::query()->create(['storage_key' => 'published', 'next_attempt_at' => now()]);
    expect(deletion_work()['failed_count'])->toBe(1);
    expect(deletion_requests())->toBe([]);
    DB::transaction(function () use ($project): void {
        app(DocumentTreeAction::class)->removeProject($project->id);
        $project->delete();
    });
    expect($upload->fresh()->state)->toBe('published');
    expect($version->fresh())->toBeNull();
    expect($conflict->fresh())->not->toBeNull();
    $conflict->refresh()->update(['next_attempt_at' => now()]);
    $journal = app(ProbeJournal::class);
    $probe = $journal->create(ProjectDocumentStorage::query()->findOrFail(1));
    deletion_bucket(['published' => 'body', 'unknown' => 'preserve', $probe->key() => 'synthetic']);
    expect(deletion_work()['deleted_count'])->toBe(1);
    expect($upload->fresh())->toBeNull();
    expect(array_column(deletion_requests(), 'path'))->toBe(['/fixture-bucket/published']);
    expect(array_keys(json_decode(file_get_contents($this->deletionHome.'/bucket.json'), true)['objects']))->toBe(['unknown', $probe->key()]);
    expect($journal->find($probe->id)->failureCount)->toBe(0);
});

it('recovers process death before or after real DELETE without retiring durable authority early', function (string $mode): void {
    deletion_provider(['removed' => 'body']);
    $upload = ProjectDocumentUpload::query()->create(['project_id' => 999, 'storage_key' => 'removed', 'state' => 'published']);
    $record = ProjectDocumentCleanup::query()->create(['storage_key' => 'removed', 'next_attempt_at' => now()]);
    deletion_resume();
    $child = deletion_child($mode);
    $child->start();
    try {
        $child->wait();
    } catch (ProcessSignaledException) {
        // SIGKILL deliberately prevents any result transaction or claim release.
    }
    expect($child->getTermSignal())->toBe(9);
    if ($mode === 'before-handoff') {
        expect($upload->fresh()->state)->toBe('published');
    } else {
        expect($upload->fresh())->toBeNull();
    }
    expect($record->fresh())->pending->toBeTrue()->attempts->toBe(0);
    expect($record->fresh()->claim_token)->not->toBeNull();
    expect(deletion_requests())->toHaveCount($mode === 'after-delete' ? 1 : 0);
    expect(deletion_work()['claimed_count'])->toBe(0);
    $record->refresh()->update(['claim_expires_at' => now()->subSecond()]);
    expect(deletion_work()['deleted_count'])->toBe(1);
    expect($record->fresh())->toBeNull();
    expect(deletion_requests())->toHaveCount($mode === 'after-delete' ? 2 : 1);
})->with(['before-handoff', 'before-delete', 'after-delete']);

it('serializes publication and abandonment and catches a PUT finishing after an absent-key DELETE', function (): void {
    deletion_provider();
    $project = deletion_project();
    deletion_resume();
    $http = new GuzzleHandler(new Client);
    $interleave = true;
    config(['filesystems.disks.documents.http_handler' => function (RequestInterface $request, array $options) use ($http, &$interleave) {
        if ($request->getMethod() === 'PUT' && $interleave) {
            $interleave = false;
            ProjectDocumentUpload::query()->where('state', 'active')->update(['created_at' => now()->subHours(2)]);
            expect(deletion_work()['deleted_count'])->toBe(1);
            expect(ProjectDocumentCleanup::query()->first()->pending)->toBeFalse();
        }

        return $http($request, $options);
    }]);
    $writer = app(WriteDocumentAction::class);
    expect(fn () => $writer->create($project->id, 'late.txt', null, DocumentBody::text('late PUT'), null))
        ->toThrow(ResourceOperationException::class, 'abandoned');
    expect(ProjectDocumentVersion::query()->count())->toBe(0);
    expect(ProjectDocumentUpload::query()->first()->state)->toBe('abandoned');
    expect(ProjectDocumentCleanup::query()->first()->retained_fence)->toBeTrue();
    expect(deletion_work()['deleted_count'])->toBe(1);
    // If publication wins first, age alone never authorizes cleanup.
    $writer->create($project->id, 'winner.txt', null, DocumentBody::text('published'), null);
    ProjectDocumentUpload::query()->where('state', 'published')->update(['created_at' => now()->subHours(2)]);
    expect(deletion_work()['deleted_count'])->toBe(0);
    expect(ProjectDocumentVersion::query()->count())->toBe(1);
    expect(deletion_requests())->toHaveCount(2);
});

function deletion_scheduler(string $command = 'schedule:work', ?string $staleToken = null): Process
{
    $home = config()->string('orbit.home');

    return new Process([PHP_BINARY, base_path('tests/Fixtures/ProjectDocuments/document_scheduler.php'), $command, '--no-interaction'], base_path(), [
        'ORBIT_HOME' => $home, 'ORBIT_DOCUMENT_CLEANUP_RUNTIME' => $home.'/runtime', 'APP_ENV' => 'testing',
        'DB_DATABASE' => $home.'/database.sqlite', 'DB_URL' => '', 'APP_KEY' => config()->string('app.key'),
        'ORBIT_DOCUMENT_SCHEDULER_SESSION' => $staleToken ?? false,
    ]);
}

function deletion_scheduler_clock(string $clock): void
{
    $home = config()->string('orbit.home');
    file_put_contents($home.'/scheduler-clock.next', $clock);
    rename($home.'/scheduler-clock.next', $home.'/scheduler-clock');
}

function deletion_scheduler_start(Process $consumer): void
{
    $home = config()->string('orbit.home');
    if (is_file($home.'/scheduler-ready')) {
        unlink($home.'/scheduler-ready');
    }
    $consumer->start();
    $deadline = microtime(true) + 10;
    while (! is_file($home.'/scheduler-ready') && $consumer->isRunning() && microtime(true) < $deadline) {
        clearstatcache();
        usleep(10_000);
    }
    expect(is_file($home.'/scheduler-ready'))->toBeTrue($consumer->getOutput().$consumer->getErrorOutput());
}

function deletion_scheduler_tick(Process $consumer, string $clock): array
{
    deletion_scheduler_clock($clock);
    $path = config()->string('orbit.home').'/tick-'.str_replace(':', '-', $clock);
    $deadline = microtime(true) + 15;
    while (! is_file($path) && $consumer->isRunning() && microtime(true) < $deadline) {
        clearstatcache();
        usleep(10_000);
    }
    expect(is_file($path))->toBeTrue($consumer->getOutput().$consumer->getErrorOutput());
    $tick = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    expect($tick['exit_code'])->toBe(0);

    return $tick;
}

it('runs real recurring scheduler children after resume without losing authorization and pauses on restart', function (): void {
    deletion_provider(['restored' => 'preserved body', 'next' => 'next body']);
    $restored = ProjectDocumentCleanup::query()->create(['storage_key' => 'restored', 'next_attempt_at' => now()]);
    $next = ProjectDocumentCleanup::query()->create(['storage_key' => 'next', 'next_attempt_at' => now()->addYears(20)]);
    deletion_resume();
    $oldGeneration = app(CleanupGate::class)->status()->generation;
    deletion_scheduler_clock('2030-01-01T00:00:01Z');
    $consumer = deletion_scheduler();
    $restart = deletion_scheduler();
    try {
        deletion_scheduler_start($consumer);
        $generation = app(CleanupGate::class)->status()->generation;
        expect($generation)->not->toBe($oldGeneration);
        expect(deletion_scheduler_tick($consumer, '2030-01-01T00:05:00Z'))->toMatchArray(['cleanup_state' => 'paused', 'cleanup_generation' => $generation]);
        expect(deletion_requests())->toBe([]);
        expect($restored->fresh()->pending)->toBeTrue();

        // Reconcile and resume after actual consumer startup, not before it.
        deletion_resume();
        expect(deletion_scheduler_tick($consumer, '2030-01-01T00:10:00Z'))->toMatchArray(['cleanup_state' => 'running', 'cleanup_generation' => $generation]);
        expect($restored->fresh())->toBeNull();
        expect($next->fresh()->pending)->toBeTrue();
        expect(array_column(deletion_requests(), 'path'))->toBe(['/fixture-bucket/restored']);
        $next->update(['next_attempt_at' => now()]);
        expect(deletion_scheduler_tick($consumer, '2030-01-01T00:15:00Z'))->toMatchArray(['cleanup_state' => 'running', 'cleanup_generation' => $generation]);
        expect($next->fresh())->toBeNull();
        expect(array_column(deletion_requests(), 'path'))->toBe(['/fixture-bucket/restored', '/fixture-bucket/next']);

        $paused = app(CleanupGate::class)->invalidate();
        deletion_bucket(['paused' => 'preserve after pause']);
        $pending = ProjectDocumentCleanup::query()->create(['storage_key' => 'paused', 'next_attempt_at' => now()]);
        expect(deletion_scheduler_tick($consumer, '2030-01-01T00:20:00Z'))->toMatchArray(['cleanup_state' => 'paused', 'cleanup_generation' => $paused->generation]);
        expect($pending->fresh()->pending)->toBeTrue();
        expect(deletion_requests())->toHaveCount(2);
        deletion_resume();
        expect(deletion_scheduler_tick($consumer, '2030-01-01T00:25:00Z'))->toMatchArray(['cleanup_state' => 'running', 'cleanup_generation' => $paused->generation]);
        expect($pending->fresh())->toBeNull();
        expect(deletion_requests())->toHaveCount(3);

        $staleToken = file_get_contents($this->deletionHome.'/runtime/scheduler.session');
        $consumer->signal(SIGKILL);
        try {
            $consumer->wait();
        } catch (ProcessSignaledException) {
            // The retained session file must not stand in for the daemon's lifetime lock.
        }
        deletion_bucket(['restart' => 'preserve after death']);
        $afterDeath = ProjectDocumentCleanup::query()->create(['storage_key' => 'restart', 'next_attempt_at' => now()]);
        deletion_scheduler_clock('2030-01-01T00:30:00Z');
        $standalone = deletion_scheduler('schedule:run', $staleToken);
        expect($standalone->run())->toBe(0, $standalone->getErrorOutput());
        expect(app(CleanupGate::class)->status()->state)->toBe('paused');
        expect($afterDeath->fresh()->pending)->toBeTrue();
        expect(deletion_requests())->toHaveCount(3);

        deletion_scheduler_clock('2030-01-01T00:30:01Z');
        deletion_scheduler_start($restart);
        $restartedGeneration = app(CleanupGate::class)->status()->generation;
        expect($restartedGeneration)->not->toBe($paused->generation);
        expect(deletion_scheduler_tick($restart, '2030-01-01T00:35:00Z'))->toMatchArray(['cleanup_state' => 'paused', 'cleanup_generation' => $restartedGeneration]);
        expect($afterDeath->fresh()->pending)->toBeTrue();
        expect(deletion_requests())->toHaveCount(3);
        deletion_resume();
        expect(deletion_scheduler_tick($restart, '2030-01-01T00:40:00Z'))->toMatchArray(['cleanup_state' => 'running', 'cleanup_generation' => $restartedGeneration]);
        expect($afterDeath->fresh())->toBeNull();
        expect(array_column(deletion_requests(), 'path'))->toBe(['/fixture-bucket/restored', '/fixture-bucket/next', '/fixture-bucket/paused', '/fixture-bucket/restart']);
    } finally {
        $consumer->stop();
        $restart->stop();
    }
});

it('holds the execution lock through real HTTP completion and pause defeats the next batch', function (): void {
    deletion_provider(['removed' => 'body']);
    ProjectDocumentCleanup::query()->create(['storage_key' => 'removed', 'next_attempt_at' => now()]);
    deletion_resume();
    $worker = deletion_child('hold');
    $pause = new Process([PHP_BINARY, base_path('tests/Fixtures/ProjectDocuments/cleanup_gate_execution.php'), $this->deletionHome.'/runtime', 'pause']);
    try {
        $worker->start();
        $deadline = microtime(true) + 5;
        while (! is_file($this->deletionHome.'/authorized') && $worker->isRunning() && microtime(true) < $deadline) {
            usleep(10_000);
        }
        expect(is_file($this->deletionHome.'/authorized'))->toBeTrue($worker->getErrorOutput());
        $pause->start();
        $deadline = microtime(true) + 3;
        while (! str_contains($pause->getOutput(), 'pause-starting') && $pause->isRunning() && microtime(true) < $deadline) {
            usleep(10_000);
        }
        expect($pause->isRunning())->toBeTrue();
        expect(deletion_requests())->toBe([]);
        touch($this->deletionHome.'/release');
        $worker->wait();
        $pause->wait();
        expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
        expect($pause->isSuccessful())->toBeTrue($pause->getErrorOutput());
        expect(deletion_requests())->toHaveCount(1);
        ProjectDocumentCleanup::query()->create(['storage_key' => 'next', 'next_attempt_at' => now()]);
        expect(deletion_work()['claimed_count'])->toBe(0);
        expect(deletion_requests())->toHaveCount(1);
    } finally {
        touch($this->deletionHome.'/release');
        $worker->stop();
        $pause->stop();
    }
});
