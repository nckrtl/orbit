<?php

declare(strict_types=1);

use App\Actions\ProjectDocuments\DocumentTreeAction;
use App\Actions\ProjectDocuments\RecoverDocumentUploadsAction;
use App\Actions\ProjectDocuments\UpdateDocumentStorageAction;
use App\Actions\ProjectDocuments\WriteDocumentAction;
use App\Actions\Projects\RemoveProjectAction;
use App\Data\ProjectDocuments\DocumentBody;
use App\Data\ProjectDocuments\DocumentStorageData;
use App\Data\ProjectDocuments\UpdateDocumentStorageData;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Project;
use App\Models\ProjectDocumentCleanup;
use App\Models\ProjectDocumentEntry;
use App\Models\ProjectDocumentStorage;
use App\Models\ProjectDocumentUpload;
use App\Models\ProjectDocumentVersion;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\Result;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    // These lifecycle tests need real commits, not RefreshDatabase's enclosing transaction.
    DB::rollBack();
    $home = sys_get_temp_dir().'/orbit-document-domain-'.Str::uuid();
    mkdir($home, 0700, true);
    touch($home.'/database.sqlite');
    config(['database.connections.sqlite.database' => $home.'/database.sqlite']);
    DB::purge('sqlite');
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    $this->beforeApplicationDestroyed(static function () use ($home): void {
        DB::disconnect('sqlite');
        File::deleteDirectory($home);
    });
});

function domain_documents_project(string $slug = 'documents'): Project
{
    return Project::query()->create(['name' => $slug, 'slug' => $slug, 'repository_url' => 'https://github.com/example/'.$slug.'.git']);
}

/** @return object{objects: array<string, string>, calls: array, fail: bool, corrupt: bool, afterPut: Closure|null, beforePut: Closure|null, requests: array} */
function domain_documents_provider(): object
{
    ProjectDocumentStorage::query()->findOrFail(1)->update([
        'endpoint' => 'https://documents.example.test', 'region' => 'test-1', 'bucket' => 'test-documents',
        'access_key_id' => 'private-key', 'secret_access_key' => 'private-secret',
    ]);
    $provider = (object) ['objects' => [], 'calls' => [], 'fail' => false, 'corrupt' => false, 'afterPut' => null, 'beforePut' => null, 'requests' => []];
    config(['filesystems.disks.documents.handler' => function (CommandInterface $command, RequestInterface $request) use ($provider): PromiseInterface {
        $provider->calls[] = $command->toArray();
        $provider->requests[] = $request;
        if ($provider->fail) {
            return Create::rejectionFor(new RuntimeException('private-secret provider diagnostic'));
        }
        $key = $command['Key'];
        if ($command->getName() === 'PutObject') {
            if ($provider->beforePut !== null) {
                ($provider->beforePut)($key);
            }
            $provider->objects[$key] = (string) $command['Body'];
            if ($provider->afterPut !== null) {
                ($provider->afterPut)();
            }
        }
        if ($command->getName() === 'GetObject') {
            if (! array_key_exists($key, $provider->objects)) {
                return Create::rejectionFor(new AwsException('Missing body', $command, ['code' => 'NoSuchKey', 'response' => new Response(404)]));
            }

            return Create::promiseFor(new Result(['Body' => Utils::streamFor($provider->corrupt ? 'corrupt' : $provider->objects[$key])]));
        }

        return Create::promiseFor(new Result);
    }]);

    return $provider;
}

function domain_documents_file(Project $project, string $name = 'note.txt', ?int $parent = null): ProjectDocumentEntry
{
    return app(WriteDocumentAction::class)->create($project->id, $name, $parent, DocumentBody::text('first'), null);
}

describe('document hierarchy', function (): void {
    it('persists empty folders and stable identities through renames and subtree moves', function (): void {
        $project = domain_documents_project();
        $tree = app(DocumentTreeAction::class);
        $folder = $tree->folder($project->id, 'empty');
        $child = $tree->folder($project->id, 'child', $folder->id);
        $destination = $tree->folder($project->id, 'destination');

        $moved = $tree->update($project->id, $folder->id, 1, ['name' => 'renamed', 'parent_id' => $destination->id]);

        expect($moved->id)->toBe($folder->id);
        expect($tree->path($child))->toBe('destination/renamed/child');
        expect($child->refresh()->revision)->toBe(1);
        expect($moved->revision)->toBe(2);
        expect(ProjectDocumentVersion::query()->count())->toBe(0);
    });

    it('normalizes names and reserves archived sibling names case sensitively', function (): void {
        $project = domain_documents_project();
        $tree = app(DocumentTreeAction::class);
        $folder = $tree->folder($project->id, "cafe\u{0301}");
        $tree->archive($project->id, $folder->id, 1, true);
        $other = $tree->folder($project->id, 'CAFÉ');

        expect(fn () => $tree->folder($project->id, 'café'))->toThrow(ResourceOperationException::class, 'sibling');
        expect($folder->refresh()->name)->toBe('café');
        $this->assertModelExists($other);
    });

    it('rejects unsafe names without persisting entries', function (string $name): void {
        $project = domain_documents_project();

        expect(fn () => app(DocumentTreeAction::class)->folder($project->id, $name))->toThrow(ValidationException::class);
        expect(ProjectDocumentEntry::query()->count())->toBe(0);
    })->with(['', '.', '..', '../escape', 'a/b', 'a\\b', ' trailing ', "x\0y", str_repeat('x', 256)]);

    it('rejects cross Project parents and lookups without touching the tree', function (): void {
        $one = domain_documents_project();
        $two = domain_documents_project('other');
        $tree = app(DocumentTreeAction::class);
        $folder = $tree->folder($one->id, 'private');

        expect(fn () => $tree->folder($two->id, 'child', $folder->id))->toThrow(ResourceOperationException::class, 'not found');
        expect(fn () => $tree->entry($two->id, $folder->id))->toThrow(ResourceOperationException::class, 'not found');
        expect(ProjectDocumentEntry::query()->count())->toBe(1);
    });

    it('refuses cycles and checks deepest descendant on moves', function (): void {
        $project = domain_documents_project();
        $tree = app(DocumentTreeAction::class);
        $root = $tree->folder($project->id, 'root');
        $child = $tree->folder($project->id, 'child', $root->id);
        expect(fn () => $tree->update($project->id, $root->id, 1, ['parent_id' => $child->id]))->toThrow(ValidationException::class);
        $parent = null;
        for ($i = 1; $i <= 31; $i++) {
            $parent = $tree->folder($project->id, 'level-'.$i, $parent)->id;
        }

        expect(fn () => $tree->update($project->id, $root->id, 1, ['parent_id' => $parent]))->toThrow(ValidationException::class);
        expect($root->refresh()->parent_id)->toBeNull();
        expect($root->revision)->toBe(1);
    });

    it('preserves independent child archives and refuses mutations below an archived parent', function (): void {
        $project = domain_documents_project();
        $tree = app(DocumentTreeAction::class);
        $folder = $tree->folder($project->id, 'folder');
        $child = $tree->folder($project->id, 'child', $folder->id);
        $tree->archive($project->id, $child->id, 1, true);
        $tree->archive($project->id, $folder->id, 1, true);
        expect($tree->isArchived($child->refresh()))->toBeTrue();
        expect(fn () => $tree->folder($project->id, 'new', $folder->id))->toThrow(ResourceOperationException::class, 'archived');
        expect(fn () => $tree->archive($project->id, $child->id, 2, false))->toThrow(ResourceOperationException::class, 'archived');
        $tree->archive($project->id, $folder->id, 2, false);

        expect($child->refresh()->archived_at)->not->toBeNull();
        expect($tree->archive($project->id, $child->id, 2, true)->revision)->toBe(2);
        expect($tree->archive($project->id, $child->id, 2, false)->revision)->toBe(3);
    });
});

describe('immutable bodies and publication', function (): void {
    it('commits an intent visible on an independent connection before PUT without an open transaction', function (): void {
        $project = domain_documents_project();
        $provider = domain_documents_provider();
        $provider->beforePut = function (string $key): void {
            expect(DB::transactionLevel())->toBe(0);
            expect(DB::getPdo()->inTransaction())->toBeFalse();
            $independent = new PDO('sqlite:'.config()->string('database.connections.sqlite.database'));
            $intent = $independent->query('SELECT * FROM project_document_uploads')->fetch(PDO::FETCH_ASSOC);
            expect($intent['storage_key'])->toBe($key);
            expect($intent['state'])->toBe('active');
            expect($independent->query('SELECT COUNT(*) FROM project_document_versions')->fetchColumn())->toBe(0);
        };

        $file = domain_documents_file($project);

        $this->assertModelExists($file);
        expect(ProjectDocumentUpload::query()->first()->state)->toBe('published');
        expect(count($provider->objects))->toBe(1);
    });

    it('rejects body operations inside an enclosing transaction before any provider access', function (string $operation): void {
        $project = domain_documents_project();
        $provider = domain_documents_provider();
        $file = domain_documents_file($project);
        $calls = count($provider->calls);
        $writer = app(WriteDocumentAction::class);

        expect(fn () => DB::transaction(function () use ($operation, $writer, $project, $file): void {
            match ($operation) {
                'create' => domain_documents_file($project, 'nested'),
                'write' => $writer->write($project->id, $file->id, 1, DocumentBody::text('nested'), null),
                'restore' => $writer->restoreVersion($project->id, $file->id, 1, $file->current_version_id, null),
                'read' => $writer->read($project->id, $file->id),
            };
            throw new RuntimeException('Outer rollback after provider access is too late.');
        }))->toThrow(LogicException::class, 'database transaction');
        expect(count($provider->calls))->toBe($calls);
        expect(ProjectDocumentUpload::query()->count())->toBe(1);
        expect(ProjectDocumentVersion::query()->count())->toBe(1);
        expect(ProjectDocumentCleanup::query()->count())->toBe(0);
        expect(ProjectDocumentEntry::query()->count())->toBe(1);
    })->with(['create', 'write', 'restore', 'read']);

    it('rejects a raw PDO transaction before preparing an upload or contacting storage', function (): void {
        $project = domain_documents_project();
        $provider = domain_documents_provider();
        $pdo = DB::getPdo();
        $pdo->beginTransaction();
        try {
            expect(DB::transactionLevel())->toBe(0);
            expect(fn () => domain_documents_file($project))->toThrow(LogicException::class, 'database transaction');
        } finally {
            $pdo->rollBack();
        }

        expect($provider->calls)->toBeEmpty();
        expect(ProjectDocumentUpload::query()->count())->toBe(0);
        expect(ProjectDocumentEntry::query()->count())->toBe(0);
    });

    it('retains a committed intent after writer death following PUT and recovers its exact key', function (): void {
        $project = domain_documents_project();
        domain_documents_provider();
        $database = config()->string('database.connections.sqlite.database');
        $process = new Process([PHP_BINARY, base_path('tests/Fixtures/ProjectDocuments/interrupted_document.php'), $database, (string) $project->id],
            env: ['APP_KEY' => config()->string('app.key')]);
        $process->setTimeout(15);

        expect(fn () => $process->run())->toThrow(ProcessSignaledException::class);
        expect($process->getTermSignal())->toBe(9);
        $remote = json_decode(file_get_contents(dirname($database).'/remote-object.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($remote['transaction_level'])->toBe(0);
        expect($remote['intent_state_before_put'])->toBe('active');
        expect($remote['tracked_key_before_put'])->toBe($remote['key']);
        expect($remote['body'])->toBe('interrupted bytes');
        expect(ProjectDocumentEntry::query()->count())->toBe(0);
        expect(ProjectDocumentVersion::query()->count())->toBe(0);
        $intent = ProjectDocumentUpload::query()->firstOrFail();
        expect($intent->storage_key)->toBe($remote['key']);
        expect($intent->state)->toBe('active');
        $this->travel(61)->minutes();
        expect(app(RecoverDocumentUploadsAction::class)->handle())->toBe(1);
        expect($intent->refresh()->state)->toBe('abandoned');
        expect(ProjectDocumentCleanup::query()->first()->storage_key)->toBe($remote['key']);
    });
    it('stores opaque objects without an object ACL and immutable history with author and current linkage', function (): void {
        $project = domain_documents_project();
        $provider = domain_documents_provider();
        $author = Node::query()->create(['name' => 'author', 'public_ssh_host' => '192.0.2.10', 'wireguard_ip' => '10.44.0.10']);
        $writer = app(WriteDocumentAction::class);
        $file = $writer->create($project->id, 'dangerous.html', null, new DocumentBody('<script>code</script>', 'text/html'), $author->id);
        $first = $writer->version($file);
        $updated = $writer->write($project->id, $file->id, 1, DocumentBody::text('second'), $author->id);

        expect($updated->revision)->toBe(2);
        expect($writer->version($updated)->number)->toBe(2);
        expect($writer->read($project->id, $file->id, $first->id)->bytes)->toBe('<script>code</script>');
        expect($first->size_bytes)->toBe(21);
        expect($first->created_by_node_id)->toBe($author->id);
        expect($first->storage_key)->not->toContain('dangerous.html');
        expect($provider->calls[0])->not->toHaveKey('ACL');
        expect($provider->requests[0]->hasHeader('x-amz-acl'))->toBeFalse();
        expect($provider->calls[0]['ContentType'])->toBe('application/octet-stream');
        expect($provider->calls[0]['ContentDisposition'])->toBe('attachment');
        expect($first->toArray())->not->toHaveKeys(['storage_key', 'upload_id']);
        expect(fn () => $first->update(['sha256' => str_repeat('0', 64)]))->toThrow(LogicException::class);
        expect(fn () => $first->delete())->toThrow(LogicException::class);
        $author->delete();
        expect($first->refresh()->created_by_node_id)->toBeNull();
    });

    it('stores and verifies empty files without replacing missing bodies with empty bytes', function (): void {
        $project = domain_documents_project();
        $provider = domain_documents_provider();
        $writer = app(WriteDocumentAction::class);
        $file = $writer->create($project->id, 'empty', null, DocumentBody::text(''), null);

        expect($writer->read($project->id, $file->id)->bytes)->toBe('');
        expect($writer->version($file)->size_bytes)->toBe(0);
        expect($writer->version($file)->sha256)->toBe('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855');
        expect(count($provider->objects))->toBe(1);
    });

    it('refuses a create when a sibling reserves its name during the upload', function (): void {
        $project = domain_documents_project();
        $provider = domain_documents_provider();
        $provider->afterPut = function () use ($project): void {
            app(DocumentTreeAction::class)->folder($project->id, 'note.txt');
        };

        expect(fn () => domain_documents_file($project))->toThrow(ResourceOperationException::class, 'sibling');
        expect(ProjectDocumentEntry::query()->count())->toBe(1);
        expect(ProjectDocumentEntry::query()->first()->kind)->toBe('folder');
        expect(ProjectDocumentVersion::query()->count())->toBe(0);
        expect(ProjectDocumentCleanup::query()->count())->toBe(1);
    });

    it('makes identical writes no ops and restores old bytes as a fresh version', function (): void {
        $project = domain_documents_project();
        $provider = domain_documents_provider();
        $writer = app(WriteDocumentAction::class);
        $file = domain_documents_file($project);
        $first = $writer->version($file);
        $writer->write($project->id, $file->id, 1, DocumentBody::text('first'), null);
        expect(count($provider->calls))->toBe(2);
        expect(ProjectDocumentUpload::query()->count())->toBe(1);
        $writer->write($project->id, $file->id, 1, DocumentBody::text('changed'), null);
        $restored = $writer->restoreVersion($project->id, $file->id, 2, $first->id, null);

        expect($writer->version($restored)->number)->toBe(3);
        expect($writer->read($project->id, $file->id)->bytes)->toBe('first');
        expect(ProjectDocumentVersion::query()->count())->toBe(3);
        expect($writer->version($restored)->storage_key)->not->toBe($first->storage_key);
    });

    it('rejects stale revisions before uploading and rejects cross file version IDs', function (): void {
        $project = domain_documents_project();
        $provider = domain_documents_provider();
        $writer = app(WriteDocumentAction::class);
        $file = domain_documents_file($project);
        $other = domain_documents_file($project, 'other');
        app(DocumentTreeAction::class)->update($project->id, $file->id, 1, ['name' => 'renamed']);

        expect(fn () => $writer->write($project->id, $file->id, 1, DocumentBody::text('stale'), null))->toThrow(ResourceOperationException::class, 'changed');
        expect(fn () => $writer->read($project->id, $file->id, $other->current_version_id))->toThrow(ResourceOperationException::class, 'not found');
        expect(count($provider->calls))->toBe(4);
        expect(ProjectDocumentVersion::query()->count())->toBe(2);
    });

    it('abandons a losing concurrent write after PUT and preserves the winning revision', function (): void {
        $project = domain_documents_project();
        $provider = domain_documents_provider();
        $file = domain_documents_file($project);
        $provider->afterPut = function () use ($project, $file): void {
            app(DocumentTreeAction::class)->update($project->id, $file->id, 1, ['name' => 'winner']);
        };

        expect(fn () => app(WriteDocumentAction::class)->write($project->id, $file->id, 1, DocumentBody::text('loser'), null))
            ->toThrow(ResourceOperationException::class, 'changed');
        expect($file->refresh()->name)->toBe('winner');
        expect($file->revision)->toBe(2);
        expect(ProjectDocumentVersion::query()->count())->toBe(1);
        expect(ProjectDocumentUpload::query()->latest('id')->first()->state)->toBe('abandoned');
        expect(ProjectDocumentCleanup::query()->first()->retained_fence)->toBeTrue();
        expect(count($provider->objects))->toBe(2);
    });

    it('publishes only the winning overlapping content writer', function (): void {
        $project = domain_documents_project();
        $provider = domain_documents_provider();
        $file = domain_documents_file($project);
        $provider->afterPut = function () use ($project, $file, $provider): void {
            $provider->afterPut = null;
            app(WriteDocumentAction::class)->write($project->id, $file->id, 1, DocumentBody::text('winner'), null);
        };

        expect(fn () => app(WriteDocumentAction::class)->write($project->id, $file->id, 1, DocumentBody::text('loser'), null))
            ->toThrow(ResourceOperationException::class, 'changed');
        expect(app(WriteDocumentAction::class)->read($project->id, $file->id)->bytes)->toBe('winner');
        expect(ProjectDocumentVersion::query()->count())->toBe(2);
        expect(ProjectDocumentUpload::query()->where('state', 'abandoned')->count())->toBe(1);
        expect(ProjectDocumentCleanup::query()->count())->toBe(1);
    });

    it('rolls back failed publication and retains cleanup without SQL diagnostics', function (): void {
        $project = domain_documents_project();
        $provider = domain_documents_provider();

        expect(fn () => app(WriteDocumentAction::class)->create($project->id, 'file', null, DocumentBody::text('bytes'), 999999))
            ->toThrow(ResourceOperationException::class, 'publication is unavailable');
        expect(ProjectDocumentEntry::query()->count())->toBe(0);
        expect(ProjectDocumentVersion::query()->count())->toBe(0);
        expect(ProjectDocumentUpload::query()->first()->state)->toBe('abandoned');
        expect(ProjectDocumentCleanup::query()->first()->storage_key)->toBe(array_key_first($provider->objects));
    });

    it('retains recovery after upload failure without exposing provider diagnostics or creating a bodyless file', function (): void {
        $project = domain_documents_project();
        $provider = domain_documents_provider();
        $provider->fail = true;

        expect(fn () => domain_documents_file($project))->toThrow(ResourceOperationException::class, 'storage is unavailable');
        expect(ProjectDocumentEntry::query()->count())->toBe(0);
        expect(ProjectDocumentVersion::query()->count())->toBe(0);
        expect(ProjectDocumentUpload::query()->first()->state)->toBe('abandoned');
        expect(ProjectDocumentCleanup::query()->first()->pending)->toBeTrue();
        $status = DocumentStorageData::fromModel(ProjectDocumentStorage::query()->findOrFail(1))->toArray();
        expect($status['pending_cleanup_count'])->toBe(1);
        expect($status['oldest_pending_cleanup_at'])->not->toBeNull();
        expect(json_encode($status))->not->toContain('private-secret', 'storage_key');
    });

    it('refuses publication when cleanup fences a paused writer and retains the late PUT key', function (): void {
        $this->freezeTime();
        $project = domain_documents_project();
        $provider = domain_documents_provider();
        $provider->afterPut = function (): void {
            test()->travel(61)->minutes();
            expect(app(RecoverDocumentUploadsAction::class)->handle())->toBe(1);
        };

        expect(fn () => domain_documents_file($project))->toThrow(ResourceOperationException::class, 'abandoned');
        expect(ProjectDocumentVersion::query()->count())->toBe(0);
        $intent = ProjectDocumentUpload::query()->first();
        expect(array_keys($provider->objects))->toBe([$intent->storage_key]);
        expect(ProjectDocumentCleanup::query()->first()->storage_key)->toBe($intent->storage_key);
    });

    it('preserves metadata when committed bytes are missing or corrupt', function (bool $missing): void {
        $project = domain_documents_project();
        $provider = domain_documents_provider();
        $file = domain_documents_file($project);
        if ($missing) {
            $provider->objects = [];
        } else {
            $provider->corrupt = true;
        }

        expect(fn () => app(WriteDocumentAction::class)->read($project->id, $file->id))->toThrow(ResourceOperationException::class, 'missing or corrupt');
        expect(ProjectDocumentVersion::query()->count())->toBe(1);
        $this->assertModelExists($file);
    })->with([true, false]);

    it('does not publish an object whose verification fails', function (): void {
        $project = domain_documents_project();
        $provider = domain_documents_provider();
        $provider->corrupt = true;

        expect(fn () => domain_documents_file($project))->toThrow(ResourceOperationException::class, 'missing or corrupt');
        expect(ProjectDocumentEntry::query()->count())->toBe(0);
        expect(ProjectDocumentCleanup::query()->count())->toBe(1);
    });

    it('keeps a retained absent fence destination reserved even with zero pending cleanup', function (): void {
        $project = domain_documents_project();
        $provider = domain_documents_provider();
        $provider->fail = true;
        try {
            domain_documents_file($project);
        } catch (ResourceOperationException) {
        }
        ProjectDocumentCleanup::query()->update(['pending' => false]);
        $data = new UpdateDocumentStorageData('https://elsewhere.example.test', null, null, null, null);

        expect(fn () => app(UpdateDocumentStorageAction::class)->handle($data))->toThrow(ResourceOperationException::class, 'in use');
        expect(ProjectDocumentCleanup::query()->where('pending', true)->count())->toBe(0);
        expect(ProjectDocumentUpload::query()->first()->state)->toBe('abandoned');
        expect($provider->objects)->toBeEmpty();
    });
});

describe('removal and recovery', function (): void {
    it('requires recursive consent and retains every version key through removal during an outage', function (): void {
        $project = domain_documents_project();
        $provider = domain_documents_provider();
        $tree = app(DocumentTreeAction::class);
        $folder = $tree->folder($project->id, 'folder');
        $file = domain_documents_file($project, 'file', $folder->id);
        app(WriteDocumentAction::class)->write($project->id, $file->id, 1, DocumentBody::text('second'), null);
        $tree->archive($project->id, $file->id, 2, true);
        $provider->fail = true;
        expect(fn () => $tree->remove($project->id, $folder->id, 1))->toThrow(ResourceOperationException::class, 'not empty');
        $tree->remove($project->id, $folder->id, 1, true);

        expect(ProjectDocumentEntry::query()->count())->toBe(0);
        expect(ProjectDocumentVersion::query()->count())->toBe(0);
        expect(ProjectDocumentCleanup::query()->pluck('storage_key')->all())->toEqualCanonicalizing(array_keys($provider->objects));
        expect(count($provider->calls))->toBe(4);
    });

    it('removes a Project and records body cleanup atomically without contacting S3', function (): void {
        $project = domain_documents_project();
        $provider = domain_documents_provider();
        domain_documents_file($project);
        $provider->fail = true;

        app(RemoveProjectAction::class)->execute($project);

        $this->assertModelMissing($project);
        expect(ProjectDocumentEntry::query()->count())->toBe(0);
        expect(ProjectDocumentVersion::query()->count())->toBe(0);
        expect(ProjectDocumentCleanup::query()->count())->toBe(1);
        expect(count($provider->calls))->toBe(2);
    });

    it('fences a writer when its Project is removed during the upload', function (): void {
        $project = domain_documents_project();
        $provider = domain_documents_provider();
        $provider->afterPut = function () use ($project): void {
            app(RemoveProjectAction::class)->execute($project);
        };

        expect(fn () => domain_documents_file($project))->toThrow(ResourceOperationException::class, 'abandoned');
        $this->assertModelMissing($project);
        expect(ProjectDocumentEntry::query()->count())->toBe(0);
        expect(ProjectDocumentVersion::query()->count())->toBe(0);
        expect(ProjectDocumentCleanup::query()->first()->storage_key)->toBe(array_key_first($provider->objects));
    });

    it('rolls back removal and tombstones together when the outer transaction fails', function (): void {
        $project = domain_documents_project();
        domain_documents_provider();
        $file = domain_documents_file($project);

        expect(fn () => DB::transaction(function () use ($project): void {
            app(DocumentTreeAction::class)->removeProject($project->id);
            throw new RuntimeException('rollback');
        }))->toThrow(RuntimeException::class, 'rollback');

        $this->assertModelExists($file);
        expect(ProjectDocumentVersion::query()->count())->toBe(1);
        expect(ProjectDocumentCleanup::query()->count())->toBe(0);
    });

    it('expires crash leftovers but never claims published objects', function (): void {
        $this->freezeTime();
        $project = domain_documents_project();
        domain_documents_provider();
        domain_documents_file($project);
        $intent = ProjectDocumentUpload::query()->create(['project_id' => $project->id, 'storage_key' => 'orbit-documents/crash']);
        $this->travel(61)->minutes();

        expect(app(RecoverDocumentUploadsAction::class)->handle())->toBe(1);
        expect($intent->refresh()->state)->toBe('abandoned');
        expect(ProjectDocumentUpload::query()->where('state', 'published')->count())->toBe(1);
        expect(ProjectDocumentCleanup::query()->pluck('storage_key')->all())->toBe([$intent->storage_key]);
        expect(app(RecoverDocumentUploadsAction::class)->handle())->toBe(0);
    });
});

describe('bounded content', function (): void {
    it('rejects noncanonical base64', function (string $encoded): void {
        expect(fn () => DocumentBody::base64($encoded))->toThrow(ValidationException::class);
    })->with(['Zg', 'Zh==', "Zg==\n", '!!!!']);

    it('keeps exact binary bytes and rejects executable inline media', function (): void {
        expect(DocumentBody::base64('AAENCg==')->bytes)->toBe("\0\1\r\n");
        expect(fn () => DocumentBody::text('<svg/>', 'image/svg+xml'))->toThrow(ResourceOperationException::class);
        expect(fn () => DocumentBody::text("a\0b"))->toThrow(ResourceOperationException::class);
        expect(fn () => DocumentBody::text(str_repeat('x', DocumentBody::INLINE_BYTES + 1)))->toThrow(ResourceOperationException::class);
        expect(fn () => new DocumentBody(str_repeat('x', DocumentBody::MAX_BYTES + 1), 'text/plain'))->toThrow(ResourceOperationException::class);
    });
});
