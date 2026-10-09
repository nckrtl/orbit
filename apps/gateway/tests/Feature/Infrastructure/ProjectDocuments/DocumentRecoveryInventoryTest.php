<?php

declare(strict_types=1);

use App\Actions\ProjectDocuments\RecoverDocumentCleanupAction;
use App\Infrastructure\ProjectDocuments\CleanupGate;
use App\Infrastructure\ProjectDocuments\ProbeJournal;
use App\Infrastructure\ProjectDocuments\RecoveryReports;
use App\Models\Project;
use App\Models\ProjectDocumentCleanup;
use App\Models\ProjectDocumentEntry;
use App\Models\ProjectDocumentStorage;
use App\Models\ProjectDocumentUpload;
use App\Models\ProjectDocumentVersion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    DB::rollBack();
    $this->recoveryHome = sys_get_temp_dir().'/orbit-document-recovery-'.Str::uuid();
    mkdir($this->recoveryHome, 0700);
    touch($this->recoveryHome.'/database.sqlite');
    config(['orbit.home' => $this->recoveryHome, 'orbit.document_cleanup_runtime' => $this->recoveryHome.'/runtime',
        'database.connections.sqlite.database' => $this->recoveryHome.'/database.sqlite']);
    DB::purge('sqlite');
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    $this->beforeApplicationDestroyed(function (): void {
        if (isset($this->inventoryServer)) {
            $this->inventoryServer->stop();
        }
        DB::disconnect('sqlite');
        File::deleteDirectory($this->recoveryHome);
    });
});

/** @param array<string, string> $objects */
function recovery_bucket(array $objects, string $mode = ''): void
{
    $encoded = [];
    foreach ($objects as $key => $bytes) {
        $encoded[$key] = ['body' => base64_encode($bytes)];
    }
    file_put_contents(config()->string('orbit.home').'/bucket.json', json_encode(['objects' => $encoded, 'mode' => $mode], JSON_THROW_ON_ERROR));
}

function recovery_fixture(array $objects = []): RecoverDocumentCleanupAction
{
    recovery_bucket($objects);
    $home = config()->string('orbit.home');
    $server = new Process(['python3', base_path('tests/Fixtures/ProjectDocuments/inventory_http_server.py'), $home.'/bucket.json', $home.'/requests.json']);
    test()->inventoryServer = $server;
    $server->start();
    $deadline = hrtime(true) + 3_000_000_000;
    while (trim($server->getOutput()) === '' && $server->isRunning() && hrtime(true) < $deadline) {
        usleep(10_000);
    }
    $port = trim($server->getOutput());
    if (! ctype_digit($port)) {
        throw new RuntimeException('Disposable inventory provider did not start.');
    }
    ProjectDocumentStorage::query()->findOrFail(1)->update([
        'endpoint' => 'http://127.0.0.1:'.$port, 'region' => 'fixture-1', 'bucket' => 'fixture-bucket',
        'access_key_id' => 'fixture-access', 'secret_access_key' => 'fixture-secret',
    ]);
    app(CleanupGate::class)->invalidate();

    return app(RecoverDocumentCleanupAction::class);
}

function recovery_version(string $key = 'orbit-documents/1/1/private', string $bytes = 'private body'): ProjectDocumentVersion
{
    $identity = 'recovery-'.Str::uuid();
    $project = Project::query()->create(['name' => $identity, 'slug' => $identity, 'repository_url' => 'https://github.com/example/'.$identity.'.git', 'apps' => fixture_apps(null)]);
    $entry = ProjectDocumentEntry::query()->create(['project_id' => $project->id, 'kind' => 'file', 'name' => 'private.txt', 'sibling_scope' => 0]);
    $upload = ProjectDocumentUpload::query()->create(['project_id' => $project->id, 'entry_id' => $entry->id, 'storage_key' => $key, 'state' => 'published']);
    $version = ProjectDocumentVersion::query()->create(['entry_id' => $entry->id, 'upload_id' => $upload->id, 'number' => 1, 'media_type' => 'text/plain',
        'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'storage_key' => $key, 'created_at' => now()]);
    $entry->update(['current_version_id' => $version->id]);

    return $version;
}

/** @return array<string, mixed> */
function recovery_report(array $output): array
{
    return app(RecoveryReports::class)->read($output['report_id']);
}

function recovery_assert_read_only(): void
{
    $requests = json_decode(file_get_contents(config()->string('orbit.home').'/requests.json'), true, flags: JSON_THROW_ON_ERROR);
    expect(array_unique(array_column($requests, 'method')))->toBe(['GET']);
}

describe('paused document inventory and fingerprint-bound resume', function (): void {
    it('verifies bodies and paginated accounted recovery work without publishing adopting or deleting', function (): void {
        $action = recovery_fixture(['live' => 'private body', 'active' => 'unpublished', 'fenced' => 'late put', 'removed' => 'removed body']);
        $version = recovery_version('live');
        ProjectDocumentUpload::query()->create(['project_id' => 999, 'storage_key' => 'active', 'state' => 'active']);
        ProjectDocumentUpload::query()->create(['project_id' => 999, 'storage_key' => 'fenced', 'state' => 'abandoned']);
        foreach (['fenced' => true, 'removed' => false, 'absent-removal' => false] as $key => $fence) {
            ProjectDocumentCleanup::query()->create(['storage_key' => $key, 'retained_fence' => $fence, 'next_attempt_at' => now()]);
        }
        $before = DB::table('project_document_uploads')->get()->toJson();

        $output = $action->reconcile();

        expect($output['report_state'])->toBe('complete');
        expect($output['difference_count'])->toBe(0);
        expect($output['cleanup_state'])->toBe('paused');
        $report = recovery_report($output);
        expect($report['inventory_counts']['bucket_objects'])->toBe(4);
        expect($report['verification'][0]['result'])->toBe('verified');
        expect($report['verification'][0]['version_id'])->toBe($version->id);
        expect(DB::table('project_document_uploads')->get()->toJson())->toBe($before);
        expect(ProjectDocumentCleanup::query()->count())->toBe(3);
        expect($action->resume($output['report_id'])['cleanup_state'])->toBe('running');
        recovery_assert_read_only();
    });

    it('preserves exact unknown key bytes across URL-encoded pages and requires actual resolution', function (string $mode): void {
        $keys = ["control\0key", "control\x01key", "control\x0Bkey", "line\rkey", "line\nkey", "line\r\nkey",
            'literal%key', 'literal%2Fkey', 'literal%252Fkey', 'literal/key', 'plus+key', 'plus key',
            '日本語/é', "日本語/e\u{0301}", '日本語/%C3%A9', '/root//./../key'];
        $objects = array_fill_keys($keys, 'preserve privately');
        $action = recovery_fixture($objects);
        recovery_bucket($objects, $mode);

        $output = $action->reconcile();

        expect($output['report_state'])->toBe('complete');
        expect($output['difference_count'])->toBe(count($keys));
        $report = recovery_report($output);
        sort($keys, SORT_STRING);
        expect(array_column($report['bucket_inventory'], 0))->toBe($keys);
        expect(array_column($report['differences'], 'key'))->toBe($keys);
        expect(array_unique(array_column($report['differences'], 'kind')))->toBe(['unknown']);
        expect($action->resume($output['report_id'])['error_code'])->toBe('project_documents.cleanup_unresolved');
        $repeat = $action->reconcile();
        expect(recovery_report($repeat)['bucket_fingerprint'])->toBe($report['bucket_fingerprint']);
        expect($repeat['difference_count'])->toBe(count($keys));
        $requests = json_decode(file_get_contents($this->recoveryHome.'/requests.json'), true, flags: JSON_THROW_ON_ERROR);
        $tokens = [];
        foreach ($requests as $request) {
            parse_str(parse_url($request['path'], PHP_URL_QUERY) ?? '', $query);
            expect($query['encoding-type'] ?? null)->toBe('url');
            if (isset($query['continuation-token'])) {
                $tokens[] = $query['continuation-token'];
            }
        }
        expect($tokens)->toContain('page+%2F-2', 'page+%2F-14');
        // The fixture operator removes objects only after preservation; reconciliation itself never does.
        recovery_bucket([], $mode);
        expect($action->resume($repeat['report_id'])['error_code'])->toBe('project_documents.cleanup_unresolved');
        $resolved = $action->reconcile();
        expect($resolved['difference_count'])->toBe(0);
        expect($action->resume($resolved['report_id'])['cleanup_state'])->toBe('running');
        recovery_assert_read_only();
    })->with(['opaque-tokens', 'literal-plus']);

    it('verifies committed keys without double decoding or path normalization across encoded pages', function (): void {
        $keys = ['literal%2Fkey', 'literal/key', 'plus+key', '日本語/é', "line\rkey"];
        $objects = array_fill_keys($keys, 'private body');
        $action = recovery_fixture($objects);
        recovery_bucket($objects, 'opaque-tokens');
        foreach ($keys as $key) {
            recovery_version($key);
        }

        $output = $action->reconcile();

        expect($output['report_state'])->toBe('complete');
        expect($output['difference_count'])->toBe(0);
        expect(array_column(recovery_report($output)['verification'], 'key'))->toBe($keys);
        expect(array_unique(array_column(recovery_report($output)['verification'], 'result')))->toBe(['verified']);
        expect($action->resume($output['report_id'])['cleanup_state'])->toBe('running');
        recovery_assert_read_only();
    });

    it('refuses incomplete listings without trusted URL encoding or with invalid percent escapes', function (string $mode): void {
        $objects = ['a%2F' => '', 'b+' => '', 'c' => ''];
        $action = recovery_fixture($objects);
        $prior = $action->reconcile();
        recovery_bucket($objects, $mode);

        $output = $action->reconcile();

        expect($output['report_state'])->toBe('incomplete');
        expect($output['error_code'])->toBe('project_documents.storage_unavailable');
        expect($output['reconciliation_report_id'])->toBeNull();
        expect($action->resume($prior['report_id'])['error_code'])->toBe('project_documents.cleanup_report_invalid');
        expect($action->resume($output['report_id'])['error_code'])->toBe('project_documents.cleanup_report_invalid');
        expect(app(CleanupGate::class)->status()->state)->toBe('paused');
        recovery_assert_read_only();
    })->with(['encoding-missing', 'encoding-missing-page', 'encoding-unsupported', 'encoding-malformed']);

    it('completes scans with missing corrupt and orphan differences but refuses their permits', function (string $kind): void {
        $action = recovery_fixture(match ($kind) {
            'missing' => [], 'corrupt' => ['live' => 'wrong digest'], 'oversized' => ['live' => str_repeat('x', 10000)],
            'unknown' => ['live' => 'private body', 'orphan' => 'preserve me'],
        });
        recovery_version('live');

        $output = $action->reconcile();

        expect($output['report_state'])->toBe('complete');
        expect($output['difference_count'])->toBe(1);
        expect(recovery_report($output)['differences'][0]['kind'])->toBe($kind === 'oversized' ? 'corrupt' : $kind);
        expect($action->resume($output['report_id'])['error_code'])->toBe('project_documents.cleanup_unresolved');
        expect(app(CleanupGate::class)->status()->state)->toBe('paused');
        expect(ProjectDocumentVersion::query()->count())->toBe(1);
        recovery_assert_read_only();
    })->with(['missing', 'corrupt', 'oversized', 'unknown']);

    it('leaves failed interrupted or unstable provider scans incomplete and invalidates an earlier clean report', function (string $mode): void {
        $action = recovery_fixture(['live' => 'private body']);
        recovery_version('live');
        $old = $action->reconcile();
        recovery_bucket(['live' => 'private body', 'a' => '', 'b' => '', 'c' => ''], $mode);

        $output = $action->reconcile();

        expect($output['report_state'])->toBe('incomplete');
        expect($output['error_code'])->toBe(in_array($mode, ['changing-list', 'database-change'], true) ? 'project_documents.cleanup_inventory_changed' : 'project_documents.storage_unavailable');
        expect($output['reconciliation_report_id'])->toBeNull();
        expect($action->resume($output['report_id'])['error_code'])->toBe('project_documents.cleanup_report_invalid');
        expect($action->resume($old['report_id'])['error_code'])->toBe('project_documents.cleanup_report_invalid');
        recovery_assert_read_only();
    })->with(['get-500', 'list-500', 'malformed-page', 'repeated-token', 'changing-list', 'database-change']);

    it('rechecks database bucket and digest changes using current credentials before granting a permit', function (string $change): void {
        $action = recovery_fixture(['live' => 'private body']);
        $version = recovery_version('live');
        $output = $action->reconcile();
        match ($change) {
            'entry' => ProjectDocumentEntry::query()->firstOrFail()->update(['name' => 'changed.txt']),
            'version' => DB::table('project_document_versions')->where('id', $version->id)->update(['media_type' => 'text/markdown']),
            'intent' => ProjectDocumentUpload::query()->create(['project_id' => 999, 'storage_key' => 'new', 'state' => 'active']),
            'tombstone' => ProjectDocumentCleanup::query()->create(['storage_key' => 'new', 'next_attempt_at' => now()]),
            'bucket' => recovery_bucket(['live' => 'private body', 'unknown' => '']),
            // Same size and unchanged ETag/LastModified: equality cannot replace a fresh body digest.
            'digest' => recovery_bucket(['live' => 'another body']),
            'credentials' => ProjectDocumentStorage::query()->findOrFail(1)->update(['access_key_id' => 'rotated', 'secret_access_key' => 'rotated-secret']),
            'provider' => recovery_bucket(['live' => 'private body'], 'get-500'),
            'destination' => ProjectDocumentStorage::query()->findOrFail(1)->update(['region' => 'changed-region']),
        };

        $resumed = $action->resume($output['report_id']);

        if ($change === 'credentials') {
            expect($resumed['cleanup_state'])->toBe('running');
        } else {
            expect($resumed['error_code'])->toBe($change === 'provider' ? 'project_documents.storage_unavailable' : 'project_documents.cleanup_inventory_changed');
            expect($resumed['cleanup_state'])->toBe('paused');
            expect(file_exists(config()->string('orbit.document_cleanup_runtime').'/permit.json'))->toBeFalse();
        }
        recovery_assert_read_only();
    })->with(['entry', 'version', 'intent', 'tombstone', 'bucket', 'digest', 'credentials', 'provider', 'destination']);

    it('binds additional worker eligibility fields without coercing opaque claim IDs', function (): void {
        $action = recovery_fixture();
        DB::statement('ALTER TABLE project_document_cleanups ADD COLUMN claim_id TEXT NULL');
        $id = DB::table('project_document_cleanups')->insertGetId(['storage_key' => 'removed', 'retained_fence' => false,
            'pending' => true, 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now(), 'claim_id' => 'claim-alpha']);
        $output = $action->reconcile();
        expect(recovery_report($output)['database_inventory']['project_document_cleanups'][0]['claim_id'])->toBe('claim-alpha');
        DB::table('project_document_cleanups')->where('id', $id)->update(['claim_id' => 'claim-beta']);

        $resumed = $action->resume($output['report_id']);

        expect($resumed['error_code'])->toBe('project_documents.cleanup_inventory_changed');
        expect($resumed['cleanup_state'])->toBe('paused');
        recovery_assert_read_only();
    });

    it('rejects wrong stale modified and unsafe reports without provider access', function (string $damage): void {
        $action = recovery_fixture();
        $output = $action->reconcile();
        $path = $output['report_path'];
        $id = $output['report_id'];
        match ($damage) {
            'wrong' => $id = str_repeat('f', 64),
            'pause' => app(CleanupGate::class)->invalidate(),
            'startup' => Artisan::call('project-documents:cleanup:invalidate'),
            'latest' => $action->reconcile(),
            'tampered' => file_put_contents($path, str_replace('"schema_version":1', '"schema_version":2', file_get_contents($path))),
            'public-file' => chmod($path, 0644),
            'public-directory' => chmod(dirname($path), 0755),
            'symlink' => (function () use ($path): void {
                rename($path, $path.'.saved');
                symlink($path.'.saved', $path);
            })(),
        };
        $before = file_get_contents($this->recoveryHome.'/requests.json');

        $resumed = $action->resume($id);

        expect($resumed['error_code'])->toBe('project_documents.cleanup_report_invalid');
        expect($resumed['cleanup_state'])->toBe('paused');
        expect(file_get_contents($this->recoveryHome.'/requests.json'))->toBe($before);
    })->with(['wrong', 'pause', 'startup', 'latest', 'tampered', 'public-file', 'public-directory', 'symlink']);

    it('accounts for a published removal handoff without retiring either row but refuses conflicting references', function (string $state): void {
        $action = recovery_fixture(['removed' => 'retain until authorized']);
        $upload = ProjectDocumentUpload::query()->create(['project_id' => 999, 'storage_key' => 'removed', 'state' => $state === 'active-conflict' ? 'active' : 'published']);
        if ($state !== 'no-tombstone') {
            ProjectDocumentCleanup::query()->create(['storage_key' => 'removed', 'next_attempt_at' => now()]);
        }

        $output = $action->reconcile();

        expect($output['report_state'])->toBe('complete');
        expect($output['difference_count'])->toBe($state === 'handoff' ? 0 : ($state === 'active-conflict' ? 2 : 1));
        if ($state === 'handoff') {
            expect(recovery_report($output)['classifications'][0]['classification'])->toBe('pending_removal_handoff');
            expect($action->resume($output['report_id'])['cleanup_state'])->toBe('running');
        } else {
            expect($action->resume($output['report_id'])['error_code'])->toBe('project_documents.cleanup_unresolved');
        }
        $this->assertModelExists($upload);
        recovery_assert_read_only();
    })->with(['handoff', 'no-tombstone', 'active-conflict']);

    it('verifies empty committed bodies and keeps an absent abandoned fence even with zero pending work', function (): void {
        $action = recovery_fixture(['empty' => '']);
        recovery_version('empty', '');
        ProjectDocumentUpload::query()->create(['project_id' => 999, 'storage_key' => 'absent-fence', 'state' => 'abandoned']);
        ProjectDocumentCleanup::query()->create(['storage_key' => 'absent-fence', 'retained_fence' => true, 'pending' => false, 'next_attempt_at' => now()]);

        $output = $action->reconcile();

        expect($output['pending_cleanup_count'])->toBe(0);
        expect($output['difference_count'])->toBe(0);
        expect(recovery_report($output)['verification'][0]['sha256'])->toBe('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855');
        expect(array_values(array_filter(recovery_report($output)['classifications'], static fn (array $row): bool => $row['classification'] === 'retained_fence'))[0]['present'])->toBeFalse();
        expect($action->resume($output['report_id'])['cleanup_state'])->toBe('running');
        expect(ProjectDocumentUpload::query()->where('state', 'abandoned')->count())->toBe(1);
        expect(ProjectDocumentCleanup::query()->where('retained_fence', true)->count())->toBe(1);
        recovery_assert_read_only();
    });

    it('rejects invalid or nonprivate resolution input before scanning or changing report eligibility', function (string $damage): void {
        $action = recovery_fixture(['unknown' => 'private']);
        $prior = $action->reconcile();
        $data = ['resolutions' => [['key' => 'unknown', 'action' => 'restore_bytes', 'evidence_reference' => 'operator backup']], 'prior_report_id' => $prior['report_id']];
        match ($damage) {
            'extra' => $data['extra'] = 'no',
            'unknown-key' => $data['resolutions'][0]['key'] = 'not-in-report',
            'action' => $data['resolutions'][0]['action'] = 'waive',
            'backup' => $data['resolutions'][0]['action'] = 'preserve_then_remove',
            'duplicate' => $data['resolutions'][] = $data['resolutions'][0],
            'public' => null,
        };
        $path = $this->recoveryHome.'/resolution.json';
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
        chmod($path, $damage === 'public' ? 0644 : 0600);
        $before = file_get_contents($this->recoveryHome.'/requests.json');

        $output = $action->reconcile($path);

        expect($output['error_code'])->toBe('project_documents.cleanup_input_invalid');
        expect($output['reconciliation_report_id'])->toBe($prior['report_id']);
        expect(file_get_contents($this->recoveryHome.'/requests.json'))->toBe($before);
        expect(app(CleanupGate::class)->status()->state)->toBe('paused');
    })->with(['extra', 'unknown-key', 'action', 'backup', 'duplicate', 'public']);

    it('refuses recovery while running and fails closed if private report publication is unsafe', function (): void {
        $action = recovery_fixture();
        $clean = $action->reconcile();
        expect($action->resume($clean['report_id'])['cleanup_state'])->toBe('running');
        expect($action->reconcile()['error_code'])->toBe('project_documents.cleanup_not_paused');
        expect($action->resume($clean['report_id'])['error_code'])->toBe('project_documents.cleanup_not_paused');
        app(CleanupGate::class)->invalidate();
        chmod(dirname($clean['report_path']), 0755);

        $output = $action->reconcile();

        expect($output['error_code'])->toBe('project_documents.cleanup_state_unavailable');
        expect($output['cleanup_state'])->toBe('paused');
        expect($output['reconciliation_report_id'])->toBeNull();
        expect(file_exists(config()->string('orbit.document_cleanup_runtime').'/permit.json'))->toBeFalse();
        recovery_assert_read_only();
    });

    it('classifies only tracked reserved probes at their exact destination separately', function (): void {
        $action = recovery_fixture();
        $probe = app(ProbeJournal::class)->create(ProjectDocumentStorage::query()->findOrFail(1));
        recovery_bucket([$probe->key() => 'synthetic']);

        $output = $action->reconcile();

        expect($output)->not->toHaveKey('error_code');
        expect($output['difference_count'])->toBe(0);
        expect(recovery_report($output)['classifications'][0]['classification'])->toBe('reserved_probe');
        recovery_bucket(['orbit-document-probes/untracked' => 'unknown']);
        expect($action->reconcile()['difference_count'])->toBe(1);
        recovery_assert_read_only();
    });

    it('copies private operator resolutions but never treats claims as waivers', function (): void {
        $action = recovery_fixture(['orphan' => 'preserve me']);
        $prior = $action->reconcile();
        $resolution = ['prior_report_id' => $prior['report_id'], 'resolutions' => [[
            'key' => 'orphan', 'action' => 'preserve_then_remove', 'evidence_reference' => 'operator verified backup',
            'recovery_backup' => '/private/recovery/orphan', 'size_bytes' => 11, 'sha256' => hash('sha256', 'preserve me'),
        ]]];
        $path = $this->recoveryHome.'/resolution.json';
        file_put_contents($path, json_encode($resolution, JSON_THROW_ON_ERROR));
        chmod($path, 0600);
        $unresolved = $action->reconcile($path);
        expect($unresolved)->not->toHaveKey('error_code');
        expect($action->resume($unresolved['report_id'])['error_code'])->toBe('project_documents.cleanup_unresolved');
        recovery_bucket([]);

        $clean = $action->reconcile($path);

        expect(recovery_report($clean)['resolution'])->toBe($resolution);
        expect($action->resume($clean['report_id'])['cleanup_state'])->toBe('running');
        recovery_assert_read_only();
    });

    it('prints only private report IDs paths and sanitized counts and validates local arguments', function (): void {
        recovery_fixture(['unknown-private-key' => 'private body']);
        $exit = Artisan::call('project-documents:cleanup:reconcile');
        $stdout = Artisan::output();
        $output = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);

        expect($exit)->toBe(0);
        expect($stdout)->not->toContain('unknown-private-key', 'private body', 'fixture-secret', 'fixture-access');
        expect(fileperms($output['report_path']) & 0777)->toBe(0600);
        expect(fileperms(dirname($output['report_path'])) & 0777)->toBe(0700);
        expect(file_get_contents($output['report_path']))->not->toContain('private body', 'fixture-secret', 'fixture-access');
        expect(Artisan::call('project-documents:cleanup:resume', ['--report' => '../arbitrary']))->toBe(2);
        expect(Artisan::call('project-documents:cleanup:reconcile', ['--resolution-file' => $output['report_path']]))->toBe(2);
        expect(Artisan::call('project-documents:cleanup:resume', ['--report' => $output['report_id']]))->toBe(1);
        expect(json_decode(Artisan::output(), true)['error_code'])->toBe('project_documents.cleanup_unresolved');
        recovery_assert_read_only();
    });

    it('keeps supported stopped-worker database restore paused until a fresh matching scan authorizes it', function (): void {
        $action = recovery_fixture(['live' => 'private body']);
        recovery_version('live');
        DB::statement('PRAGMA wal_checkpoint(TRUNCATE)');
        copy($this->recoveryHome.'/database.sqlite', $this->recoveryHome.'/backup.sqlite');
        $old = $action->reconcile();
        expect($action->resume($old['report_id'])['cleanup_state'])->toBe('running');
        // Supported sequence: pause; no API/upload/cleanup workers are running in this fixture.
        app(CleanupGate::class)->invalidate();
        ProjectDocumentEntry::query()->firstOrFail()->update(['name' => 'newer.txt']);
        DB::statement('PRAGMA wal_checkpoint(TRUNCATE)');
        DB::disconnect('sqlite');
        copy($this->recoveryHome.'/backup.sqlite', $this->recoveryHome.'/database.sqlite');
        DB::purge('sqlite');
        DB::getPdo();
        DB::rollBack();
        Artisan::call('project-documents:cleanup:invalidate');

        expect($action->resume($old['report_id'])['error_code'])->toBe('project_documents.cleanup_report_invalid');
        expect(app(CleanupGate::class)->executeWithPermit(fn () => throw new RuntimeException('Deletion must not run')))->toBeFalse();
        $fresh = $action->reconcile();
        expect($fresh)->not->toHaveKey('error_code');
        expect($fresh['difference_count'])->toBe(0);
        $resumed = $action->resume($fresh['report_id']);
        expect($resumed)->not->toHaveKey('error_code');
        expect($resumed['cleanup_state'])->toBe('running');
        recovery_assert_read_only();
    });
});
