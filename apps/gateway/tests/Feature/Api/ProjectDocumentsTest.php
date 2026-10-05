<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Project;
use App\Models\ProjectDocumentStorage;
use Aws\CommandInterface;
use Aws\Result;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function (): void {
    DB::rollBack();
    $home = sys_get_temp_dir().'/orbit-document-api-'.Str::uuid();
    mkdir($home, 0700, true);
    config(['orbit.document_cleanup_runtime' => $home.'/cleanup-runtime']);
    touch($home.'/database.sqlite');
    config(['database.connections.sqlite.database' => $home.'/database.sqlite']);
    DB::purge('sqlite');
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    $this->beforeApplicationDestroyed(static function () use ($home): void {
        DB::disconnect('sqlite');
        File::deleteDirectory($home);
    });
    $gateway = $this->markAsGateway(Node::query()->create(['name' => 'gateway', 'status' => LifecycleStatus::Active, 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']));
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    $this->documentProject = Project::query()->create(['name' => 'Documents', 'slug' => 'documents', 'repository_url' => 'https://example.test/documents.git']);
    $this->documentBase = '/api/v1/projects/'.$this->documentProject->id.'/documents';
});

function interface_document_provider(): object
{
    ProjectDocumentStorage::query()->findOrFail(1)->update(['endpoint' => 'https://documents.example.test', 'region' => 'test-1', 'bucket' => 'document-tests', 'access_key_id' => 'private-access', 'secret_access_key' => 'private-secret']);
    $provider = (object) ['objects' => [], 'calls' => [], 'fail' => false];
    config(['filesystems.disks.documents.handler' => function (CommandInterface $command, RequestInterface $request) use ($provider): PromiseInterface {
        $provider->calls[] = $command->getName();
        if ($provider->fail) {
            return Create::rejectionFor(new RuntimeException('private-secret provider diagnostics'));
        }
        if ($command->getName() === 'PutObject') {
            $provider->objects[$command['Key']] = (string) $command['Body'];
        }

        return Create::promiseFor($command->getName() === 'GetObject' ? new Result(['Body' => Utils::streamFor($provider->objects[$command['Key']] ?? '')]) : new Result);
    }]);

    return $provider;
}

it('records Project Document interface Fixtures from the real Gateway', function (): void {
    $this->travelTo(new Carbon('2026-01-01T00:00:00Z'));
    interface_document_provider();
    $this->withHeader('X-Orbit-Request-Id', fixture_request_id());
    ProjectDocumentStorage::query()->findOrFail(1)->update(['updated_at' => now()]);
    record_fixture($this->getJson('/api/v1/project-document-storage')->assertOk(), 'project-documents/storage-show/default', 'Orbit\\Sdk\\Requests\\ProjectDocuments\\ShowProjectDocumentStorageRequest', 'GET /api/v1/project-document-storage');
    $base = $this->documentBase;
    $route = '/api/v1/projects/{project}/documents';
    $capture = function (TestResponse $response, string $operation, string $request, string $method, string $suffix = '') use ($route): void {
        record_fixture($response, 'project-documents/'.$operation.'/default', 'Orbit\\Sdk\\Requests\\ProjectDocuments\\'.$request.'Request', $method.' '.$route.$suffix);
    };
    $created = $this->postJson($base, ['kind' => 'file', 'name' => 'note.txt', 'content_text' => "first\n"])->assertCreated();
    $capture($created, 'create', 'CreateProjectDocument', 'POST');
    $id = $created->json('data.id');
    $version = $created->json('data.current_version.id');
    $url = $base.'/'.$id;
    $capture($this->getJson($base)->assertOk(), 'list', 'ListProjectDocuments', 'GET');
    $capture($this->getJson($base.'/search?q=note')->assertOk(), 'search', 'SearchProjectDocuments', 'GET', '/search');
    $capture($this->getJson($url)->assertOk(), 'show', 'ShowProjectDocument', 'GET', '/{entry}');
    $capture($this->getJson($url.'/content')->assertOk(), 'read', 'ReadProjectDocument', 'GET', '/{entry}/content');
    $capture($this->getJson($url.'/download')->assertOk(), 'download', 'DownloadProjectDocument', 'GET', '/{entry}/download');
    $capture($this->patchJson($url, ['expected_revision' => 1, 'name' => 'renamed.txt'])->assertOk(), 'update', 'UpdateProjectDocument', 'PATCH', '/{entry}');
    $capture($this->putJson($url.'/content', ['expected_revision' => 2, 'content_text' => 'second'])->assertOk(), 'write', 'WriteProjectDocument', 'PUT', '/{entry}/content');
    $capture($this->putJson($url.'/content', ['expected_revision' => 2, 'content_text' => 'draft'])->assertConflict(), 'conflict', 'WriteProjectDocument', 'PUT', '/{entry}/content');
    $capture($this->getJson($url.'/versions')->assertOk(), 'versions', 'ListProjectDocumentVersions', 'GET', '/{entry}/versions');
    $capture($this->postJson($url.'/restore-version', ['expected_revision' => 3, 'version_id' => $version])->assertOk(), 'restore-version', 'RestoreProjectDocumentVersion', 'POST', '/{entry}/restore-version');
    $capture($this->postJson($url.'/archive', ['expected_revision' => 4])->assertOk(), 'archive', 'ArchiveProjectDocument', 'POST', '/{entry}/archive');
    $capture($this->postJson($url.'/restore', ['expected_revision' => 5])->assertOk(), 'restore', 'RestoreProjectDocument', 'POST', '/{entry}/restore');
    $capture($this->deleteJson($url, ['expected_revision' => 6])->assertOk(), 'remove', 'DestroyProjectDocument', 'DELETE', '/{entry}');
});

describe('Project Documents HTTP and generated MCP', function (): void {
    it('admits the full decoded upload limit above PHP form limits and keeps exact empty text', function (): void {
        interface_document_provider();
        $bytes = str_repeat('x', 10485760);
        $input = ['kind' => 'file', 'name' => 'maximum.bin', 'content_base64' => base64_encode($bytes)];
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.1', 'CONTENT_LENGTH' => strlen(json_encode($input))]);
        $created = $this->postJson($this->documentBase, $input)->assertCreated()->assertJsonPath('data.current_version.size_bytes', 10485760);
        $this->getJson($this->documentBase.'/'.$created->json('data.id').'/download')->assertOk()->assertJsonPath('data.version.sha256', hash('sha256', $bytes));
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.1', 'CONTENT_LENGTH' => 0]);
        $this->postJson($this->documentBase, ['kind' => 'file', 'name' => 'empty.txt', 'content_text' => ''])->assertCreated()->assertJsonPath('data.current_version.size_bytes', 0);
    });

    it('returns correlated JSON for the HTTP size cap but authorizes before oversized body parsing', function (): void {
        $provider = interface_document_provider();
        $body = '{"kind":"folder","name":"oversized"}'.str_repeat(' ', 15728640);
        $this->call('POST', $this->documentBase, server: ['CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => strlen($body)], content: $body)
            ->assertStatus(413)->assertJsonPath('error.code', 'project_documents.content_too_large');
        Node::query()->create(['name' => 'outsider', 'status' => LifecycleStatus::Active, 'public_ssh_host' => '192.0.2.4', 'wireguard_ip' => '10.44.0.4']);
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.4']);
        $this->call('POST', $this->documentBase, server: ['CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => strlen($body)], content: $body)
            ->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
        expect($provider->calls)->toBe([]);
    });

    it('never takes recursive removal consent from mutation query fields', function (): void {
        $folder = $this->postJson($this->documentBase, ['kind' => 'folder', 'name' => 'parent'])->assertCreated()->json('data.id');
        $child = $this->postJson($this->documentBase, ['kind' => 'folder', 'name' => 'child', 'parent_id' => $folder])->assertCreated()->json('data.id');
        $url = $this->documentBase.'/'.$folder;
        $this->deleteJson($url.'?recursive=yes', ['expected_revision' => 1])->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
        $this->deleteJson($url, ['expected_revision' => 1])->assertConflict()->assertJsonPath('error.code', 'project_documents.folder_not_empty');
        $this->deleteJson($url.'?unsupported=1', ['expected_revision' => 1, 'recursive' => true])->assertUnprocessable();
        $this->getJson($url)->assertOk()->assertJsonPath('data.revision', 1);
        $this->getJson($this->documentBase.'/'.$child)->assertOk()->assertJsonPath('data.parent_id', $folder);
        $this->deleteJson($url, ['expected_revision' => 1, 'recursive' => true])->assertOk();
        $this->getJson($this->documentBase.'/'.$child)->assertNotFound();
    });

    it('applies exact-content and authorization exemptions to padded numeric Project IDs', function (): void {
        $provider = interface_document_provider();
        $base = '/api/v1/projects/0'.$this->documentProject->id.'/documents';
        $input = ['kind' => 'file', 'name' => 'padded.bin', 'content_base64' => base64_encode(str_repeat('x', 10485760))];
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.1', 'CONTENT_LENGTH' => strlen(json_encode($input))]);
        $this->postJson($base, $input)->assertCreated()->assertJsonPath('data.current_version.size_bytes', 10485760);
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.1', 'CONTENT_LENGTH' => 0]);
        $this->postJson($base, ['kind' => 'file', 'name' => 'empty.txt', 'content_text' => ''])->assertCreated()->assertJsonPath('data.current_version.size_bytes', 0);
        $this->postJson($base, ['kind' => 'folder', 'name' => ' surrounding '])->assertUnprocessable();
        Node::query()->create(['name' => 'padded-outsider', 'status' => LifecycleStatus::Active, 'public_ssh_host' => '192.0.2.5', 'wireguard_ip' => '10.44.0.5']);
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.5', 'CONTENT_LENGTH' => 15728641]);
        $calls = $provider->calls;
        $this->call('POST', $base, server: ['CONTENT_TYPE' => 'application/json'], content: '{malformed oversized body')->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
        expect($provider->calls)->toBe($calls);
    });

    it('admits maximum-size MCP create and write through both bounded envelopes', function (string $endpoint): void {
        interface_document_provider();
        $call = function (string $verb, array $arguments) use ($endpoint): array {
            $tool = ['name' => 'project-document-'.$verb, 'arguments' => $arguments];
            $params = $endpoint === '/mcp' ? $tool : ['name' => 'execute_tools', 'arguments' => ['calls' => [$tool]]];
            $input = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => $params];
            $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.1', 'CONTENT_LENGTH' => strlen(json_encode($input))]);
            $response = $this->postJson($endpoint, $input)->assertOk();
            if ($response->baseResponse instanceof StreamedResponse) {
                preg_match_all('/^data: (.+)$/m', $response->streamedContent(), $messages);
                $message = json_decode((string) end($messages[1]), true, flags: JSON_THROW_ON_ERROR);
            } else {
                $message = $response->json();
            }
            expect($message['result']['isError'])->toBeFalse();
            $result = json_decode($message['result']['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);

            return $endpoint === '/mcp' ? $result : json_decode($result['results'][0]['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
        };
        $bytes = str_repeat('x', 10485760);
        $created = $call('create', ['project' => $this->documentProject->id, 'kind' => 'file', 'name' => 'maximum.bin', 'content_base64' => base64_encode($bytes)]);
        expect($created['data']['current_version']['size_bytes'])->toBe(10485760);
        $bytes = str_repeat('y', 10485760);
        $written = $call('write', ['project' => $this->documentProject->id, 'entry' => $created['data']['id'], 'expected_revision' => 1, 'content_base64' => base64_encode($bytes)]);
        expect($written['data']['current_version']['size_bytes'])->toBe(10485760)
            ->and($written['data']['current_version']['sha256'])->toBe(hash('sha256', $bytes));
    })->with(['/mcp', '/mcp/search']);

    it('bounds MCP envelopes by declared and actual size after peer authorization', function (string $endpoint, bool $declared): void {
        $provider = interface_document_provider();
        $body = $declared ? '{malformed' : str_repeat(' ', 15728641);
        $server = ['CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => $declared ? 15728641 : 0];
        $this->call('POST', $endpoint, server: $server, content: $body)->assertStatus(413);
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.99']);
        $this->call('POST', $endpoint, server: $server, content: $body)->assertForbidden()->assertJsonPath('error.code', 'peer.identity_unknown');
        expect($provider->calls)->toBe([]);
    })->with(['/mcp', '/mcp/search'])->with([true, false]);

    it('refuses an unauthorized maximum-size MCP document operation before validation or storage', function (): void {
        $provider = interface_document_provider();
        Node::query()->create(['name' => 'large-agent', 'status' => LifecycleStatus::Active, 'public_ssh_host' => '192.0.2.6', 'wireguard_ip' => '10.44.0.6']);
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.6']);
        $input = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'project-document-create', 'arguments' => ['project' => $this->documentProject->id, 'kind' => 'file', 'name' => '../invalid', 'content_base64' => base64_encode(str_repeat('x', 10485760))]]];
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.6', 'CONTENT_LENGTH' => strlen(json_encode($input))]);
        $response = $this->postJson('/mcp', $input)->assertOk()->assertJsonPath('result.isError', true);
        expect(json_decode($response->json('result.content.0.text'), true)['error']['code'])->toBe('node_access.required');
        expect($provider->calls)->toBe([]);
    });

    it('runs the versioned file lifecycle with exact bytes, revision conflicts and redacted Activity', function (): void {
        $provider = interface_document_provider();
        $created = $this->postJson($this->documentBase, ['kind' => 'file', 'name' => 'note.md', 'content_text' => "private draft\n", 'media_type' => 'text/markdown'])->assertCreated();
        $id = $created->json('data.id');
        $version = $created->json('data.current_version.id');
        expect($created->json('data.current_version.sha256'))->toBe(hash('sha256', "private draft\n"));
        expect($created->json('meta.request_id'))->toBe($created->headers->get('X-Orbit-Request-Id'));
        expect($created->getContent())->not->toContain('storage_key', 'private-secret', 'orbit-documents/');
        $url = $this->documentBase.'/'.$id;
        $this->getJson($url.'/content')->assertOk()->assertJsonPath('data.content_text', "private draft\n");
        $this->putJson($url.'/content', ['expected_revision' => 1, 'content_text' => 'second'])->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.current_version.number', 2);
        $this->putJson($url.'/content', ['expected_revision' => 1, 'content_text' => 'lost draft'])->assertConflict()->assertJsonPath('error.code', 'project_documents.revision_conflict')->assertJsonPath('error.details.current_revision', 2);
        $this->getJson($url.'/download?version='.$version)->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.content_base64', base64_encode("private draft\n"));
        $this->getJson($url.'/versions?limit=1')->assertOk()->assertJsonPath('data.0.number', 2);
        $this->postJson($url.'/restore-version', ['expected_revision' => 2, 'version_id' => $version])->assertOk()->assertJsonPath('data.current_version.number', 3);
        $this->postJson($url.'/archive', ['expected_revision' => 3])->assertOk()->assertJsonPath('data.is_archived', true);
        $this->getJson($url.'/content')->assertOk();
        $this->putJson($url.'/content', ['expected_revision' => 4, 'content_text' => 'no'])->assertConflict()->assertJsonPath('error.code', 'project_documents.archived');
        $this->postJson($url.'/restore', ['expected_revision' => 4])->assertOk()->assertJsonPath('data.revision', 5);
        $this->deleteJson($url, ['expected_revision' => 5])->assertOk()->assertJsonPath('data.cleanup_pending', true);
        $this->getJson($url)->assertNotFound()->assertJsonPath('error.code', 'project_documents.not_found');
        expect(json_encode(Activity::query()->get()->toArray()))->not->toContain('private draft', 'lost draft', 'private-secret');
        expect($provider->calls)->toContain('PutObject', 'GetObject');
    });

    it('returns the documented unconfigured-storage conflict for read and download while keeping metadata available', function (): void {
        $provider = interface_document_provider();
        $created = $this->postJson($this->documentBase, ['kind' => 'file', 'name' => 'note.txt', 'content_text' => 'body'])->assertCreated();
        $url = $this->documentBase.'/'.$created->json('data.id');
        ProjectDocumentStorage::query()->findOrFail(1)->update(['access_key_id' => null, 'secret_access_key' => null]);
        $calls = $provider->calls;
        foreach (['content', 'download'] as $suffix) {
            $this->getJson($url.'/'.$suffix)->assertConflict()->assertJsonPath('error.code', 'project_documents.storage_not_configured');
        }
        $this->getJson($url)->assertOk()->assertJsonPath('data.revision', 1);
        expect($provider->calls)->toBe($calls);
    });

    it('browses effective archive state, literal search, moves and filter-bound cursors without storage', function (): void {
        $folder = $this->postJson($this->documentBase, ['kind' => 'folder', 'name' => 'parent'])->assertCreated()->json('data.id');
        $child = $this->postJson($this->documentBase, ['kind' => 'folder', 'name' => '100%_notes', 'parent_id' => $folder])->assertCreated()->json('data.id');
        $this->postJson($this->documentBase, ['kind' => 'folder', 'name' => 'other'])->assertCreated();
        $page = $this->getJson($this->documentBase.'?limit=1')->assertOk();
        $cursor = $page->json('meta.next_cursor');
        $this->getJson($this->documentBase.'?limit=1&cursor='.urlencode($cursor))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->documentBase.'?state=all&cursor='.urlencode($cursor))->assertUnprocessable();
        $this->getJson($this->documentBase.'/search?q='.urlencode('%_'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $child);
        $this->postJson($this->documentBase.'/'.$folder.'/archive', ['expected_revision' => 1])->assertOk();
        $this->getJson($this->documentBase.'/search?q=notes')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->documentBase.'/search?q=notes&state=archived')->assertOk()->assertJsonPath('data.0.is_archived', true);
        $this->postJson($this->documentBase.'/'.$child.'/restore', ['expected_revision' => 1])->assertConflict();
        $this->postJson($this->documentBase.'/'.$folder.'/restore', ['expected_revision' => 2])->assertOk();
        $this->patchJson($this->documentBase.'/'.$child, ['expected_revision' => 1, 'name' => 'moved', 'parent_id' => null])->assertOk()->assertJsonPath('data.path', 'moved')->assertJsonPath('data.id', $child);
        $this->deleteJson($this->documentBase.'/'.$folder, ['expected_revision' => 3])->assertOk()->assertJsonPath('data.cleanup_pending', false);
    });

    it('rejects cross-Project entries and cross-file versions without reading bodies', function (): void {
        $provider = interface_document_provider();
        $one = $this->postJson($this->documentBase, ['kind' => 'file', 'name' => 'one', 'content_text' => 'one'])->assertCreated();
        $two = $this->postJson($this->documentBase, ['kind' => 'file', 'name' => 'two', 'content_text' => 'two'])->assertCreated();
        $other = Project::query()->create(['name' => 'Other', 'slug' => 'other', 'repository_url' => 'https://example.test/other.git']);
        $before = count($provider->calls);
        $this->getJson('/api/v1/projects/'.$other->id.'/documents/'.$one->json('data.id'))->assertNotFound();
        $this->getJson($this->documentBase.'/'.$one->json('data.id').'/download?version='.$two->json('data.current_version.id'))->assertNotFound();
        expect(count($provider->calls))->toBe($before);
    });

    it('refuses every unauthorized operation before validation or object access', function (string $method, string $suffix): void {
        $provider = interface_document_provider();
        Node::query()->create(['name' => 'stranger', 'status' => LifecycleStatus::Active, 'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2']);
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
        $this->json($method, $this->documentBase.$suffix, ['unexpected' => 'secret'])->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
        expect($provider->calls)->toBe([]);
    })->with([
        ['GET', ''], ['GET', '/search'], ['POST', ''], ['GET', '/999'], ['PATCH', '/999'], ['PUT', '/999/content'], ['GET', '/999/content'], ['GET', '/999/download'], ['GET', '/999/versions'], ['POST', '/999/restore-version'], ['POST', '/999/archive'], ['POST', '/999/restore'], ['DELETE', '/999'],
    ]);

    it('validates exact content presence and unknown fields before publication', function (array $input): void {
        $provider = interface_document_provider();
        $this->postJson($this->documentBase, $input)->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
        expect($provider->calls)->toBe([]);
    })->with([
        [['kind' => 'file', 'name' => 'missing']],
        [['kind' => 'file', 'name' => 'both', 'content_text' => '', 'content_base64' => '']],
        [['kind' => 'folder', 'name' => 'folder', 'content_text' => '']],
        [['kind' => 'file', 'name' => 'bad', 'content_base64' => 'YQ']],
        [['kind' => 'folder', 'name' => 'unknown', 'storage_key' => 'unsafe']],
    ]);

    it('executes generated tools with the same archive, revision and authorization contract', function (): void {
        $call = function (string $verb, array $arguments): array {
            $response = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'project-document-'.$verb, 'arguments' => $arguments]])->assertOk();

            return json_decode($response->json('result.content.0.text'), true);
        };
        $folder = $call('create', ['project' => $this->documentProject->id, 'kind' => 'folder', 'name' => 'agent']);
        $id = $folder['data']['id'];
        expect($call('list', ['project' => $this->documentProject->id])['data'][0]['id'])->toBe($id);
        expect($call('archive', ['project' => $this->documentProject->id, 'entry' => $id, 'expected_revision' => 1])['data']['revision'])->toBe(2);
        expect($call('restore', ['project' => $this->documentProject->id, 'entry' => $id, 'expected_revision' => 1])['error']['code'])->toBe('project_documents.revision_conflict');
        interface_document_provider();
        $file = $call('create', ['project' => $this->documentProject->id, 'kind' => 'file', 'name' => 'attachment', 'content_base64' => base64_encode("\0blob")]);
        $fileId = $file['data']['id'];
        $original = $file['data']['current_version']['id'];
        expect($call('download', ['project' => $this->documentProject->id, 'entry' => $fileId])['data']['content_base64'])->toBe(base64_encode("\0blob"));
        expect($call('read', ['project' => $this->documentProject->id, 'entry' => $fileId])['error']['code'])->toBe('project_documents.not_editable');
        expect($call('write', ['project' => $this->documentProject->id, 'entry' => $fileId, 'expected_revision' => 1, 'content_text' => 'agent text', 'media_type' => 'text/plain'])['data']['current_version']['number'])->toBe(2);
        expect($call('read', ['project' => $this->documentProject->id, 'entry' => $fileId])['data']['content_text'])->toBe('agent text');
        expect($call('version-list', ['project' => $this->documentProject->id, 'entry' => $fileId])['data'][0]['number'])->toBe(2);
        expect($call('restore-version', ['project' => $this->documentProject->id, 'entry' => $fileId, 'expected_revision' => 2, 'version_id' => $original])['data']['current_version']['number'])->toBe(3);
        expect($call('destroy', ['project' => $this->documentProject->id, 'entry' => $fileId, 'expected_revision' => 3])['data']['removed'])->toBeTrue();
        Node::query()->create(['name' => 'agent', 'status' => LifecycleStatus::Active, 'public_ssh_host' => '192.0.2.3', 'wireguard_ip' => '10.44.0.3']);
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.3']);
        expect($call('show', ['project' => $this->documentProject->id, 'entry' => $id])['error']['code'])->toBe('node_access.required');
    });
});
