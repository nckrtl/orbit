<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\ProjectDocuments\ArchiveProjectDocumentRequest;
use Orbit\Sdk\Requests\ProjectDocuments\CreateProjectDocumentRequest;
use Orbit\Sdk\Requests\ProjectDocuments\DestroyProjectDocumentRequest;
use Orbit\Sdk\Requests\ProjectDocuments\DownloadProjectDocumentRequest;
use Orbit\Sdk\Requests\ProjectDocuments\ListProjectDocumentsRequest;
use Orbit\Sdk\Requests\ProjectDocuments\ListProjectDocumentVersionsRequest;
use Orbit\Sdk\Requests\ProjectDocuments\ReadProjectDocumentRequest;
use Orbit\Sdk\Requests\ProjectDocuments\RestoreProjectDocumentRequest;
use Orbit\Sdk\Requests\ProjectDocuments\RestoreProjectDocumentVersionRequest;
use Orbit\Sdk\Requests\ProjectDocuments\SearchProjectDocumentsRequest;
use Orbit\Sdk\Requests\ProjectDocuments\ShowProjectDocumentRequest;
use Orbit\Sdk\Requests\ProjectDocuments\UpdateProjectDocumentRequest;
use Orbit\Sdk\Requests\ProjectDocuments\WriteProjectDocumentRequest;
use Orbit\Sdk\Responses\ProjectDocuments\DownloadProjectDocumentResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

function document_transport_fixture(string $operation): array
{
    return json_decode(file_get_contents(__DIR__.'/../../../../fixtures/project-documents/'.$operation.'/default.json'), true, flags: JSON_THROW_ON_ERROR);
}

describe('Project Documents typed transport', function (): void {
    it('replays Gateway lifecycle envelopes with exact method path fields and request correlation', function (string $operation): void {
        $request = match ($operation) {
            'create' => new CreateProjectDocumentRequest(1, 'file', 'note.txt', contentText: "first\n"),
            'list' => new ListProjectDocumentsRequest(1),
            'search' => new SearchProjectDocumentsRequest(1, 'note'),
            'show' => new ShowProjectDocumentRequest(1, 1),
            'read' => new ReadProjectDocumentRequest(1, 1),
            'download' => new DownloadProjectDocumentRequest(1, 1),
            'update' => new UpdateProjectDocumentRequest(1, 1, 1, name: 'renamed.txt'),
            'write' => new WriteProjectDocumentRequest(1, 1, 2, contentText: 'second'),
            'versions' => new ListProjectDocumentVersionsRequest(1, 1),
            'restore-version' => new RestoreProjectDocumentVersionRequest(1, 1, 3, 1),
            'archive' => new ArchiveProjectDocumentRequest(1, 1, 4),
            'restore' => new RestoreProjectDocumentRequest(1, 1, 5),
            'remove' => new DestroyProjectDocumentRequest(1, 1, 6),
        };
        $fixture = document_transport_fixture($operation);
        $mock = new MockClient([$request::class => MockResponse::make($fixture['body'], $fixture['status'])]);
        $connector = new GatewayConnector('https://gateway.test');
        $connector->withMockClient($mock);
        $dto = $connector->send($request)->dto();
        expect($dto->toArray())->toBe($fixture['body']);
        [$method, $path] = explode(' ', $fixture['route'], 2);
        expect($request->getMethod()->value)->toBe($method)
            ->and($request->resolveEndpoint())->toBe(str_replace(['{project}', '{entry}'], ['1', '1'], $path));
        if ($dto instanceof DownloadProjectDocumentResponse) {
            expect($dto->decodedBytes())->toBe("first\n");
        }
    })->with(['create', 'list', 'search', 'show', 'read', 'download', 'update', 'write', 'versions', 'restore-version', 'archive', 'restore', 'remove']);

    it('keeps omission separate from explicit null, empty content and false', function (): void {
        expect((new UpdateProjectDocumentRequest(1, 2, 3))->body()->all())->toBe(['expected_revision' => 3]);
        expect((new UpdateProjectDocumentRequest(1, 2, 3, parentProvided: true))->body()->all())->toBe(['expected_revision' => 3, 'parent_id' => null]);
        expect((new WriteProjectDocumentRequest(1, 2, 3, contentText: ''))->body()->all())->toBe(['expected_revision' => 3, 'content_text' => '']);
        expect((new DestroyProjectDocumentRequest(1, 2, 3))->body()->all())->toBe(['expected_revision' => 3, 'recursive' => false]);
        expect((new ListProjectDocumentsRequest(1, state: 'all', cursor: 'opaque', limit: 1))->query()->all())->toBe(['state' => 'all', 'cursor' => 'opaque', 'limit' => 1]);
    });

    it('returns the Gateway revision conflict without retrying or losing request correlation', function (): void {
        $fixture = document_transport_fixture('conflict');
        $mock = new MockClient([WriteProjectDocumentRequest::class => MockResponse::make($fixture['body'], 409, ['X-Orbit-Request-Id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'])]);
        $connector = new GatewayConnector('https://gateway.test');
        $connector->withMockClient($mock);
        try {
            $connector->send(new WriteProjectDocumentRequest(1, 1, 2, contentText: 'draft'))->dto();
            test()->fail('Expected revision conflict.');
        } catch (GatewayApiException $exception) {
            expect($exception->errorCode())->toBe('project_documents.revision_conflict')
                ->and($exception->details())->toBe(['entry_id' => 1, 'current_revision' => 3]);
        }
        $mock->assertSentCount(1);
    });

    it('rejects corrupted or oversized downloaded bodies before exposing bytes', function (): void {
        $data = document_transport_fixture('download')['body']['data'];
        $data['content_base64'] = base64_encode('corrupt');
        expect(fn () => DownloadProjectDocumentResponse::fromGatewayData($data, '')->decodedBytes())->toThrow(UnexpectedValueException::class);
        $data['version']['size_bytes'] = 10485761;
        expect(fn () => DownloadProjectDocumentResponse::fromGatewayData($data, '')->decodedBytes())->toThrow(UnexpectedValueException::class);
        expect(fn () => WriteProjectDocumentRequest::encodeBytes(str_repeat('x', 10485761)))->toThrow(InvalidArgumentException::class);
    });
});
