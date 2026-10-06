<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Tasks\ProbeTaskDeliverableRequest;
use Orbit\Sdk\Requests\Tasks\ShowTaskCheckRequest;
use Orbit\Sdk\Responses\Tasks\TaskCheckResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe('task check transport', function (): void {
    it('addresses a probe with only its base flag and a bodyless check read', function (bool $base): void {
        $probe = new ProbeTaskDeliverableRequest(13, 57, 'test', $base);
        $show = new ShowTaskCheckRequest(13, 57, 8);
        expect($probe->getMethod())->toBe(Method::POST)
            ->and($probe->resolveEndpoint())->toBe('/api/v1/task-groups/13/tasks/57/deliverables/test/probe')
            ->and($probe->body()->all())->toBe(json_encode(['base' => $base], JSON_THROW_ON_ERROR))
            ->and($probe->headers()->get('Content-Type'))->toBe('application/json')
            ->and($show->getMethod())->toBe(Method::GET)
            ->and($show->resolveEndpoint())->toBe('/api/v1/task-groups/13/tasks/57/checks/8');
    })->with([false, true]);

    it('decodes check ids and receipts without losing exit zero or evidence', function (): void {
        $data = [
            'id' => 8, 'kind' => 'probe', 'status' => 'passed', 'started_at' => '2026-10-06T12:00:00Z',
            'finished_at' => '2026-10-06T12:00:01Z', 'exit_code' => 0, 'changed_paths' => [], 'failed_step' => null,
            'output' => 'passed', 'output_tail' => 'passed', 'deliverable_evidence' => ['commands' => ['test' => ['exit_code' => 0]]],
            'receipt' => ['check_id' => 8, 'kind' => 'probe', 'deliverable' => 'test', 'exit_code' => 0, 'uid' => 1001],
        ];
        $id = '4f20d3c2-b6d5-42ce-823a-b784dcbd93fb';
        $connector = new GatewayConnector('https://gateway.test', '/tmp/ca.pem');
        $connector->withMockClient(new MockClient([
            ShowTaskCheckRequest::class => MockResponse::make(['data' => $data, 'meta' => ['request_id' => $id]]),
            ProbeTaskDeliverableRequest::class => MockResponse::make(['data' => $data, 'meta' => ['request_id' => $id]], 201),
        ]));
        foreach ([new ShowTaskCheckRequest(13, 57, 8), new ProbeTaskDeliverableRequest(13, 57, 'test')] as $request) {
            $dto = $connector->send($request)->dtoOrFail();
            expect($dto)->toBeInstanceOf(TaskCheckResponse::class)->and($dto->toArray())->toEqual($data + ['request_id' => $id]);
        }
    });

    it('preserves the reserved check id and correlation on a pending-start failure', function (): void {
        $fixture = json_decode((string) file_get_contents(dirname(__DIR__, 4).'/fixtures/tasks/tasks-deliverable-probe/start-pending.json'), true, flags: JSON_THROW_ON_ERROR);
        $connector = new GatewayConnector('https://gateway.test', '/tmp/ca.pem');
        $connector->withMockClient(new MockClient([
            ProbeTaskDeliverableRequest::class => MockResponse::make($fixture['body'], $fixture['status'], $fixture['headers']),
        ]));
        try {
            $connector->send(new ProbeTaskDeliverableRequest(1, 2, 'test'))->dtoOrFail();
            test()->fail('Expected a pending-start failure.');
        } catch (GatewayApiException $exception) {
            expect($exception->errorCode())->toBe('tasks.probe_start_pending')
                ->and($exception->details())->toBe(['check_id' => 1])
                ->and($exception->requestId())->toBe($fixture['headers']['X-Orbit-Request-Id']);
        }
    });

    it('refuses a malformed check identity', function (): void {
        TaskCheckResponse::fromGatewayData(['id' => 0], 'request-id');
    })->throws(GatewayApiException::class);
});
