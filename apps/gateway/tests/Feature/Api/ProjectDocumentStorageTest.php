<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\ProjectDocuments\CleanupGate;
use App\Infrastructure\ProjectDocuments\DocumentsFilesystem;
use App\Infrastructure\ProjectDocuments\ProbeResponseBuffer;
use App\Models\Activity;
use App\Models\Node;
use App\Models\ProjectDocumentStorage;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\Result;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;

function document_storage_gateway(): Node
{
    $home = sys_get_temp_dir().'/orbit-probe-api-'.Str::uuid();
    config(['orbit.home' => $home]);
    test()->beforeApplicationDestroyed(static function () use ($home): void {
        File::deleteDirectory($home);
    });
    $gateway = Node::query()->create([
        'name' => 'document-gateway', 'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1',
    ]);
    test()->markAsGateway($gateway);
    test()->withServerVariables(['REMOTE_ADDR' => '10.44.0.1']);

    return $gateway;
}

/** @return array<string, string> */
function document_storage_input(): array
{
    return [
        'endpoint' => 'https://s3.example.test', 'region' => 'example-1',
        'bucket' => 'project-documents-example', 'access_key_id' => 'sentinel-access-key',
        'secret_access_key' => 'sentinel-secret-key',
    ];
}

/**
 * The SDK's command handler fakes only the remote service; signing, client construction,
 * Flysystem, configuration, validation, persistence, and redaction remain real.
 *
 * @return object{calls: array, body: string, failure: string|null}
 */
function document_storage_provider(?string $failure = null): object
{
    $provider = (object) ['calls' => [], 'body' => '', 'failure' => $failure, 'failOn' => 'PutObject', 'corrupt' => false, 'chunkSize' => 8192, 'stall' => false];
    config(['filesystems.disks.documents.handler' => function (CommandInterface $command, RequestInterface $request) use ($provider): PromiseInterface {
        $provider->calls[] = ['operation' => $command->getName(), 'command' => $command->toArray(), 'request' => $request];
        if ($provider->failure === 'ConnectionFailure' && $command->getName() === $provider->failOn) {
            return Create::rejectionFor(new ConnectException('Connection failed sentinel-access-key sentinel-secret-key', $request));
        }
        if ($provider->failure !== null && $command->getName() === $provider->failOn) {
            return Create::rejectionFor(new AwsException(
                'Provider diagnostic containing sentinel-access-key sentinel-secret-key',
                $command,
                ['code' => $provider->failure, 'request' => $request],
            ));
        }
        if ($command->getName() === 'PutObject') {
            $provider->body = (string) $command['Body'];
        }

        $body = new class(Utils::streamFor($provider->body.($provider->corrupt ? 'extra bytes' : '')), $provider) implements StreamInterface
        {
            use StreamDecoratorTrait;

            private StreamInterface $stream;

            public function __construct(StreamInterface $stream, private object $provider)
            {
                $this->stream = $stream;
            }

            public function read(int $length): string
            {
                return $this->provider->stall ? '' : $this->stream->read(min($length, $this->provider->chunkSize));
            }
        };

        return Create::promiseFor(new Result($command->getName() === 'GetObject' ? ['Body' => $body] : []));
    }]);

    return $provider;
}

describe('Project document storage', function (): void {
    it('shows the local cleanup gate without rotating it or contacting storage and fails closed on unreadable state', function (): void {
        document_storage_gateway();
        $provider = document_storage_provider('NoSuchBucket');
        $runtime = sys_get_temp_dir().'/orbit-document-gate-api-'.Str::uuid();
        config(['orbit.document_cleanup_runtime' => $runtime]);
        $this->beforeApplicationDestroyed(static fn (): bool => File::deleteDirectory($runtime));
        $gate = app(CleanupGate::class);
        $generation = $gate->invalidate()->generation;

        $this->getJson('/api/v1/project-document-storage')->assertOk()
            ->assertJsonPath('data.cleanup_state', 'paused')
            ->assertJsonPath('data.cleanup_generation', $generation)
            ->assertJsonPath('data.reconciliation_report_id', null);
        expect($gate->status()->generation)->toBe($generation);
        chmod($runtime.'/generation.json', 0000);
        $this->getJson('/api/v1/project-document-storage')->assertOk()
            ->assertJsonPath('data.cleanup_state', 'paused')
            ->assertJsonPath('data.cleanup_generation', null)
            ->assertJsonPath('data.reconciliation_report_id', null);
        expect($provider->calls)->toBe([]);
    });

    it('returns unconfigured redacted status without contacting S3', function (): void {
        document_storage_gateway();
        $provider = document_storage_provider('NoSuchBucket');

        $this->getJson('/api/v1/project-document-storage')->assertOk()
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.credentials_configured', false)
            ->assertJsonPath('data.endpoint', null)
            ->assertJsonPath('data.region', null)
            ->assertJsonPath('data.bucket', null)
            ->assertJsonPath('data.updated_at', null);
        expect($provider->calls)->toBe([]);
    });

    it('encrypts both credentials and verifies private path-style S3 without changing the default disk', function (): void {
        document_storage_gateway();
        $provider = document_storage_provider();
        $default = config('filesystems.default');

        $response = $this->putJson('/api/v1/project-document-storage', document_storage_input())
            ->assertOk()->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.endpoint', 'https://s3.example.test')
            ->assertJsonPath('data.region', 'example-1')
            ->assertJsonPath('data.bucket', 'project-documents-example');
        $storage = ProjectDocumentStorage::query()->findOrFail(1);
        foreach (['access_key_id', 'secret_access_key'] as $field) {
            $plaintext = document_storage_input()[$field];
            expect($storage->getRawOriginal($field))->not->toContain($plaintext);
            expect(Crypt::decryptString($storage->getRawOriginal($field)))->toBe($plaintext);
            expect($response->getContent())->not->toContain($plaintext);
            expect(json_encode($storage))->not->toContain($plaintext);
            expect(print_r($storage, true))->not->toContain($plaintext);
            expect(Activity::query()->get()->toJson())->not->toContain($plaintext);
        }
        expect(array_column($provider->calls, 'operation'))->toBe(['PutObject', 'GetObject', 'DeleteObject']);
        $key = $provider->calls[0]['command']['Key'];
        expect($key)->toStartWith('orbit-document-probes/');
        foreach ($provider->calls as $call) {
            expect($call['command']['Key'])->toBe($key);
            expect($call['command']['@http'])->toMatchArray(['connect_timeout' => 2, 'timeout' => 5, 'read_timeout' => 5, 'allow_redirects' => false]);
            expect($call['request']->getUri()->getHost())->toBe('s3.example.test');
            expect($call['request']->getUri()->getPath())->toBe('/project-documents-example/'.$key);
            expect($call['request']->getHeaderLine('Authorization'))->toContain('AWS4-HMAC-SHA256', '/example-1/s3/aws4_request');
        }
        expect($provider->calls[1]['command']['@http']['stream'])->toBeFalse();
        expect($provider->calls[1]['command']['@http']['sink'])->toBeInstanceOf(ProbeResponseBuffer::class);
        expect($provider->calls[0]['command'])->not->toHaveKey('ACL');
        expect(config('filesystems.default'))->toBe($default);
        expect(Storage::disk('documents')->getClient()->getRegion())->toBe('example-1');

        $this->getJson('/api/v1/project-document-storage')->assertOk()
            ->assertJsonMissingPath('data.access_key_id')->assertJsonMissingPath('data.secret_access_key');
        expect($provider->calls)->toHaveCount(3);
    });

    it('returns sanitized 503 for invalid credentials, unavailable endpoints, and missing buckets and cleans only the probe', function (string $failure): void {
        document_storage_gateway();
        $provider = document_storage_provider($failure);
        Log::spy();

        $response = $this->putJson('/api/v1/project-document-storage', document_storage_input())
            ->assertStatus(503)->assertJsonPath('error.code', 'project_documents.storage_unavailable');
        expect($response->getContent())->not->toContain('sentinel-access-key', 'sentinel-secret-key', 'Provider diagnostic');
        expect(Activity::query()->get()->toJson())->not->toContain('sentinel-access-key', 'sentinel-secret-key', 'Provider diagnostic');
        expect(ProjectDocumentStorage::query()->findOrFail(1)->endpoint)->toBeNull();
        expect(array_column($provider->calls, 'operation'))->toBe(['PutObject', 'DeleteObject']);
        expect($provider->calls[1]['command']['Key'])->toBe($provider->calls[0]['command']['Key']);
        Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context): bool {
            expect($message.print_r($context, true))->not->toContain('sentinel-access-key', 'sentinel-secret-key', 'Provider diagnostic');

            return true;
        });
    })->with(['invalid credentials' => 'InvalidAccessKeyId', 'endpoint unavailable' => 'ConnectionFailure', 'missing bucket' => 'NoSuchBucket']);

    it('attempts cleanup after a failed probe read or delete and does not save the candidate', function (string $operation): void {
        document_storage_gateway();
        $provider = document_storage_provider('AccessDenied');
        $provider->failOn = $operation;

        $this->putJson('/api/v1/project-document-storage', document_storage_input())
            ->assertStatus(503)->assertJsonPath('error.code', 'project_documents.storage_unavailable');
        $calls = array_column($provider->calls, 'operation');
        expect(end($calls))->toBe('DeleteObject');
        expect(count(array_unique(array_column(array_column($provider->calls, 'command'), 'Key'))))->toBe(1);
        expect(ProjectDocumentStorage::query()->findOrFail(1)->endpoint)->toBeNull();
    })->with(['GetObject', 'DeleteObject']);

    it('rejects a mismatched probe body and deletes the probe even when the provider ignores Range', function (): void {
        document_storage_gateway();
        $provider = document_storage_provider();
        $provider->corrupt = true;

        $this->putJson('/api/v1/project-document-storage', document_storage_input())
            ->assertStatus(503)->assertJsonPath('error.code', 'project_documents.storage_unavailable');
        expect(array_column($provider->calls, 'operation'))->toBe(['PutObject', 'GetObject', 'DeleteObject']);
        expect($provider->calls[1]['command']['Range'])->toBe('bytes=0-32');
        expect(ProjectDocumentStorage::query()->findOrFail(1)->endpoint)->toBeNull();
    });

    it('accepts a correct probe body returned in short reads', function (): void {
        document_storage_gateway();
        $provider = document_storage_provider();
        $provider->chunkSize = 8;

        $this->putJson('/api/v1/project-document-storage', document_storage_input())->assertOk();
        expect(array_column($provider->calls, 'operation'))->toBe(['PutObject', 'GetObject', 'DeleteObject']);
    });

    it('does not conceal trailing bytes after a complete first chunk', function (): void {
        document_storage_gateway();
        $provider = document_storage_provider();
        $provider->chunkSize = 32;
        $provider->corrupt = true;

        $this->putJson('/api/v1/project-document-storage', document_storage_input())->assertStatus(503);
        expect(ProjectDocumentStorage::query()->findOrFail(1)->endpoint)->toBeNull();
        expect(array_column($provider->calls, 'operation'))->toBe(['PutObject', 'GetObject', 'DeleteObject']);
    });

    it('fails without busy-waiting when a probe stream makes no progress', function (): void {
        document_storage_gateway();
        $provider = document_storage_provider();
        $provider->stall = true;
        $started = hrtime(true);

        $this->putJson('/api/v1/project-document-storage', document_storage_input())->assertStatus(503);
        expect((hrtime(true) - $started) / 1_000_000_000)->toBeLessThan(0.5);
        expect(ProjectDocumentStorage::query()->findOrFail(1)->endpoint)->toBeNull();
        expect(array_column($provider->calls, 'operation'))->toBe(['PutObject', 'GetObject', 'DeleteObject']);
    });

    it('preserves credentials when omitted and keeps the prior configuration after a failed rotation', function (): void {
        document_storage_gateway();
        $provider = document_storage_provider();
        $this->putJson('/api/v1/project-document-storage', document_storage_input())->assertOk();
        $this->putJson('/api/v1/project-document-storage', ['region' => 'example-1'])->assertOk();
        $prior = ProjectDocumentStorage::query()->findOrFail(1)->getAttributes();
        $provider->failure = 'SignatureDoesNotMatch';

        $this->putJson('/api/v1/project-document-storage', ['access_key_id' => 'rotated-access', 'secret_access_key' => 'rotated-secret'])
            ->assertStatus(503)->assertJsonPath('error.code', 'project_documents.storage_unavailable');
        expect(ProjectDocumentStorage::query()->findOrFail(1)->getAttributes())->toBe($prior);
        expect(Activity::query()->get()->toJson())->not->toContain('rotated-access', 'rotated-secret');
        expect(app(DocumentsFilesystem::class)->current()->getClient()->getCredentials()->wait()->getAccessKeyId())->toBe('sentinel-access-key');
    });

    it('rotates credentials only after a successful probe and refreshes the documents disk', function (string $access): void {
        document_storage_gateway();
        document_storage_provider();
        $this->putJson('/api/v1/project-document-storage', document_storage_input())->assertOk();
        Storage::disk('documents');

        $this->putJson('/api/v1/project-document-storage', ['access_key_id' => $access, 'secret_access_key' => 'new-secret'])->assertOk();
        expect(Storage::disk('documents')->getClient()->getCredentials()->wait()->getAccessKeyId())->toBe($access);
        expect(ProjectDocumentStorage::query()->findOrFail(1)->secret_access_key)->toBe('new-secret');
    })->with(['normal key' => 'new-access', 'nonempty zero key never falls back to AWS environment credentials' => '0']);

    it('recovers credentials encrypted with a lost key only after a successful replacement probe', function (): void {
        document_storage_gateway();
        $provider = document_storage_provider('InvalidAccessKeyId');
        $oldEncrypter = new Encrypter(str_repeat('x', 32), 'AES-256-CBC');
        $oldAccess = $oldEncrypter->encryptString('lost-key-access');
        $oldSecret = $oldEncrypter->encryptString('lost-key-secret');
        DB::table('project_document_storages')->where('id', 1)->update([
            'endpoint' => 'https://s3.example.test', 'region' => 'example-1',
            'bucket' => 'project-documents-example', 'access_key_id' => $oldAccess, 'secret_access_key' => $oldSecret,
        ]);
        expect(fn () => Crypt::decryptString($oldAccess))->toThrow(DecryptException::class);
        $this->getJson('/api/v1/project-document-storage')->assertOk()->assertJsonPath('data.configured', true);
        $this->putJson('/api/v1/project-document-storage', ['region' => 'example-1'])->assertStatus(503)
            ->assertJsonPath('error.code', 'project_documents.storage_unavailable');
        expect($provider->calls)->toBe([]);
        $replacement = ['access_key_id' => 'replacement-access', 'secret_access_key' => 'replacement-secret'];

        $this->putJson('/api/v1/project-document-storage', $replacement)
            ->assertStatus(503)->assertJsonPath('error.code', 'project_documents.storage_unavailable');
        $this->assertDatabaseHas('project_document_storages', ['id' => 1, 'access_key_id' => $oldAccess, 'secret_access_key' => $oldSecret]);
        $provider->failure = null;
        $response = $this->putJson('/api/v1/project-document-storage', $replacement)->assertOk()->assertJsonPath('data.configured', true);
        $stored = ProjectDocumentStorage::query()->findOrFail(1);
        expect(Crypt::decryptString($stored->getRawOriginal('access_key_id')))->toBe('replacement-access');
        expect(Crypt::decryptString($stored->getRawOriginal('secret_access_key')))->toBe('replacement-secret');
        expect($response->getContent().Activity::query()->get()->toJson())->not->toContain('replacement-access', 'replacement-secret', 'lost-key-access', 'lost-key-secret');
    });

    it('does not send an object ACL when writing through the dedicated disk', function (): void {
        document_storage_gateway();
        $provider = document_storage_provider();
        $this->putJson('/api/v1/project-document-storage', document_storage_input())->assertOk();

        Storage::disk('documents')->put('test-private-document', 'private test body');
        expect($provider->calls[3]['operation'])->toBe('PutObject');
        expect($provider->calls[3]['command'])->not->toHaveKey('ACL');
        expect($provider->calls[3]['request']->hasHeader('x-amz-acl'))->toBeFalse();
        Storage::disk('documents')->delete('test-private-document');
        expect($provider->calls[4]['command']['Key'])->toBe('test-private-document');
    });

    it('rejects invalid configuration with 422 before provider access', function (array $override): void {
        document_storage_gateway();
        $provider = document_storage_provider();

        $this->putJson('/api/v1/project-document-storage', [...document_storage_input(), ...$override])
            ->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');
        expect($provider->calls)->toBe([]);
        expect(ProjectDocumentStorage::query()->findOrFail(1)->endpoint)->toBeNull();
    })->with([
        'http' => [['endpoint' => 'http://example.com']],
        'userinfo' => [['endpoint' => 'https://user:password@example.com']],
        'path' => [['endpoint' => 'https://example.com/']],
        'query' => [['endpoint' => 'https://example.com?token=secret']],
        'fragment' => [['endpoint' => 'https://example.com#fragment']],
        'blank region' => [['region' => '']],
        'long region' => [['region' => str_repeat('a', 64)]],
        'nonascii region' => [['region' => 'éurope-2']],
        'IP bucket' => [['bucket' => '192.0.2.1']],
        'bucket label' => [['bucket' => 'invalid..bucket']],
        'uppercase bucket' => [['bucket' => 'InvalidBucket']],
        'short bucket' => [['bucket' => 'ab']],
        'empty access' => [['access_key_id' => '']],
        'empty secret' => [['secret_access_key' => '']],
        'long secret bytes' => [['secret_access_key' => str_repeat('é', 513)]],
        'unexpected setting' => [['use_path_style_endpoint' => false]],
    ]);

    it('requires both credentials when either is supplied and all fields on the initial update', function (): void {
        document_storage_gateway();
        $provider = document_storage_provider();

        $this->putJson('/api/v1/project-document-storage', [])->assertStatus(422);
        $this->putJson('/api/v1/project-document-storage', ['access_key_id' => ''])->assertStatus(422);
        $input = document_storage_input();
        unset($input['secret_access_key']);
        $this->putJson('/api/v1/project-document-storage', $input)->assertStatus(422);
        unset($input['access_key_id']);
        $input['secret_access_key'] = 'secret-alone';
        $this->putJson('/api/v1/project-document-storage', $input)->assertStatus(422);
        expect($provider->calls)->toBe([]);
    });

    it('requires an active peer and Gateway access before validation or storage access', function (): void {
        $provider = document_storage_provider();
        $this->getJson('/api/v1/project-document-storage')->assertForbidden();
        document_storage_gateway();
        $consumer = Node::query()->create([
            'name' => 'unprivileged', 'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2',
        ]);
        $this->withServerVariables(['REMOTE_ADDR' => $consumer->wireguard_ip]);

        $this->putJson('/api/v1/project-document-storage', ['endpoint' => 'invalid'])
            ->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
        $this->getJson('/api/v1/project-document-storage')->assertForbidden();
        expect($provider->calls)->toBe([]);
    });
});
