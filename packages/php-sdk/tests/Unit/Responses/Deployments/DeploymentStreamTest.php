<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Responses\Deployments\DeploymentOutputEvent;
use Orbit\Sdk\Responses\Deployments\DeploymentPhaseEvent;
use Orbit\Sdk\Responses\Deployments\DeploymentResultEvent;
use Orbit\Sdk\Responses\Deployments\DeploymentStream;

describe(DeploymentStream::class, function (): void {
    it('yields typed events incrementally across arbitrary chunks', function (): void {
        $requestId = deployment_stream_request_id();
        $output = "binary\0\xffoutput";
        $body = deployment_stream_line([
            'type' => 'phase',
            'sequence' => 1,
            'request_id' => $requestId,
            'phase' => 'before_activation',
            'step_name' => 'migrate',
        ], leadingWhitespace: 3)
            .deployment_stream_line([
                'type' => 'output',
                'sequence' => 2,
                'request_id' => $requestId,
                'stream' => 'stdout',
                'data_base64' => base64_encode($output),
            ])
            .deployment_stream_line(deployment_stream_result(3));
        $closed = false;
        $stream = deployment_chunked_stream($body, [1, 2, 7, 3, 13], $closed);

        $events = iterator_to_array($stream);

        expect($events)->toHaveCount(3)
            ->and($events[0])->toBeInstanceOf(DeploymentPhaseEvent::class)
            ->and($events[0]->phase)->toBe('before_activation')
            ->and($events[0]->stepName)->toBe('migrate')
            ->and($events[1])->toBeInstanceOf(DeploymentOutputEvent::class)
            ->and($events[1]->stream)->toBe('stdout')
            ->and($events[1]->data)->toBe($output)
            ->and($events[2])->toBeInstanceOf(DeploymentResultEvent::class)
            ->and($events[2]->succeeded())->toBeTrue()
            ->and($events[2]->selectedRelease)->toBe('release-a')
            ->and(array_map(static fn ($event): int => $event->sequence, $events))->toBe([1, 2, 3])
            ->and($closed)->toBeTrue();
    });

    it('yields a typed terminal failure without reporting success', function (): void {
        $body = deployment_stream_line([
            ...deployment_stream_result(1),
            'status' => 'failed',
            'failed_step' => 'before_activation',
            'error_code' => 'deployment.step_failed',
            'selected_release' => null,
        ]);
        $closed = false;
        $events = iterator_to_array(deployment_chunked_stream($body, [2], $closed));

        expect($events)->toHaveCount(1)
            ->and($events[0])->toBeInstanceOf(DeploymentResultEvent::class)
            ->and($events[0]->succeeded())->toBeFalse()
            ->and($events[0]->failedStep)->toBe('before_activation')
            ->and($events[0]->errorCode)->toBe('deployment.step_failed')
            ->and($closed)->toBeTrue();
    });

    it('rejects malformed invalid over-limit misordered and truncated streams', function (string $body): void {
        $closed = false;
        $stream = deployment_chunked_stream($body, [5, 1, 8], $closed);

        expect(fn (): array => iterator_to_array($stream))
            ->toThrow(GatewayApiException::class, 'Gateway deployment stream is invalid.')
            ->and($closed)->toBeTrue();
    })->with([
        'malformed JSON' => ["{not-json}\n"],
        'unknown event member' => [deployment_stream_line([
            ...deployment_stream_result(1),
            'unexpected' => true,
        ])],
        'line over 32 KiB' => [str_repeat(' ', 32 * 1024)."{}\n"],
        'sequence does not start at one' => [deployment_stream_line(deployment_stream_result(2))],
        'sequence is not continuous' => [deployment_stream_line([
            'type' => 'phase',
            'sequence' => 1,
            'request_id' => deployment_stream_request_id(),
            'phase' => 'activation',
        ]).deployment_stream_line(deployment_stream_result(3))],
        'request identity mismatch' => [deployment_stream_line([
            ...deployment_stream_result(1),
            'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67ffff',
        ])],
        'invalid request identity' => [deployment_stream_line([
            ...deployment_stream_result(1),
            'request_id' => 'invalid-request-id',
        ])],
        'named phase without step name' => [deployment_stream_line([
            'type' => 'phase',
            'sequence' => 1,
            'request_id' => deployment_stream_request_id(),
            'phase' => 'before_activation',
        ])],
        'unnamed phase with step name' => [deployment_stream_line([
            'type' => 'phase',
            'sequence' => 1,
            'request_id' => deployment_stream_request_id(),
            'phase' => 'activation',
            'step_name' => 'unexpected',
        ])],
        'unsupported phase' => [deployment_stream_line([
            'type' => 'phase',
            'sequence' => 1,
            'request_id' => deployment_stream_request_id(),
            'phase' => 'database_migration',
        ])],
        'invalid step name' => [deployment_stream_line([
            'type' => 'phase',
            'sequence' => 1,
            'request_id' => deployment_stream_request_id(),
            'phase' => 'after_activation',
            'step_name' => 'Invalid Step',
        ])],
        'unsupported output stream' => [deployment_stream_line([
            'type' => 'output',
            'sequence' => 1,
            'request_id' => deployment_stream_request_id(),
            'stream' => 'combined',
            'data_base64' => '',
        ])],
        'invalid base64 output' => [deployment_stream_line([
            'type' => 'output',
            'sequence' => 1,
            'request_id' => deployment_stream_request_id(),
            'stream' => 'stdout',
            'data_base64' => 'not+canonical===',
        ]).deployment_stream_line(deployment_stream_result(2))],
        'decoded output over 16 KiB' => [deployment_stream_line([
            'type' => 'output',
            'sequence' => 1,
            'request_id' => deployment_stream_request_id(),
            'stream' => 'stdout',
            'data_base64' => base64_encode(str_repeat('x', (16 * 1024) + 1)),
        ]).deployment_stream_line(deployment_stream_result(2))],
        'result before another event' => [deployment_stream_line(deployment_stream_result(1)).deployment_stream_line([
            'type' => 'phase',
            'sequence' => 2,
            'request_id' => deployment_stream_request_id(),
            'phase' => 'activation',
        ])],
        'successful result without release' => [deployment_stream_line([
            ...deployment_stream_result(1),
            'selected_release' => null,
        ])],
        'failed result without failure boundary' => [deployment_stream_line([
            ...deployment_stream_result(1),
            'status' => 'failed',
            'selected_release' => null,
        ])],
        'failed result with invalid error code' => [deployment_stream_line([
            ...deployment_stream_result(1),
            'status' => 'failed',
            'failed_step' => 'operation',
            'error_code' => 'Invalid Error',
            'selected_release' => null,
        ])],
        'truncated line' => [rtrim(deployment_stream_line(deployment_stream_result(1)), "\n")],
        'end before result' => [deployment_stream_line([
            'type' => 'phase',
            'sequence' => 1,
            'request_id' => deployment_stream_request_id(),
            'phase' => 'activation',
        ])],
    ]);

    it('closes the response when the consumer cancels and never replays events', function (): void {
        $body = deployment_stream_line([
            'type' => 'phase',
            'sequence' => 1,
            'request_id' => deployment_stream_request_id(),
            'phase' => 'activation',
        ]).deployment_stream_line(deployment_stream_result(2));
        $closed = false;
        $stream = deployment_chunked_stream($body, [4], $closed);
        $iterator = $stream->getIterator();

        $iterator->rewind();
        expect($iterator->current())->toBeInstanceOf(DeploymentPhaseEvent::class)
            ->and($closed)->toBeFalse();

        $stream->close();
        expect($closed)->toBeTrue()
            ->and(fn (): array => iterator_to_array($stream))
            ->toThrow(LogicException::class, 'Deployment streams cannot be replayed.');
    });

    it('rejects an empty transport read before terminal EOF', function (): void {
        $inner = Utils::streamFor(deployment_stream_line(deployment_stream_result(1)));
        $body = FnStream::decorate($inner, [
            'eof' => static fn (): bool => false,
        ]);
        $closed = false;
        $stream = new DeploymentStream(
            $body,
            static function () use (&$closed, $body): void {
                $closed = true;
                $body->close();
            },
            deployment_stream_request_id(),
        );

        expect(fn (): array => iterator_to_array($stream))
            ->toThrow(GatewayApiException::class, 'Gateway deployment stream is invalid.')
            ->and($closed)->toBeTrue();
    });

    it('keeps application output out of generic diagnostics', function (): void {
        $sentinel = 'application-output-sentinel-71af';
        $body = deployment_stream_line([
            'type' => 'output',
            'sequence' => 1,
            'request_id' => deployment_stream_request_id(),
            'stream' => 'stderr',
            'data_base64' => base64_encode($sentinel),
        ]).deployment_stream_line(deployment_stream_result(2));
        $closed = false;
        $events = iterator_to_array(deployment_chunked_stream($body, [11], $closed));
        $event = $events[0];

        expect($event)->toBeInstanceOf(DeploymentOutputEvent::class)
            ->and($event->data)->toBe($sentinel)
            ->and(implode("\n", [
                print_r($event, return: true),
                (string) json_encode($event, JSON_THROW_ON_ERROR),
            ]))->not->toContain($sentinel);
    });
});

/** @param array<string, mixed> $event */
function deployment_stream_line(array $event, int $leadingWhitespace = 0): string
{
    return str_repeat(' ', $leadingWhitespace).json_encode($event, JSON_THROW_ON_ERROR)."\n";
}

/** @return array<string, mixed> */
function deployment_stream_result(int $sequence): array
{
    return [
        'type' => 'result',
        'sequence' => $sequence,
        'request_id' => deployment_stream_request_id(),
        'status' => 'succeeded',
        'failed_step' => null,
        'error_code' => null,
        'selected_release' => 'release-a',
    ];
}

/** @param list<int> $chunkSizes */
function deployment_chunked_stream(string $body, array $chunkSizes, bool &$closed): DeploymentStream
{
    $inner = Utils::streamFor($body);
    $index = 0;
    $chunked = FnStream::decorate($inner, [
        'read' => static function (int $length) use ($inner, $chunkSizes, &$index): string {
            $size = $chunkSizes[$index % count($chunkSizes)];
            $index++;

            return $inner->read(min($length, $size));
        },
    ]);

    return new DeploymentStream(
        $chunked,
        static function () use (&$closed, $chunked): void {
            $closed = true;
            $chunked->close();
        },
        deployment_stream_request_id(),
    );
}

function deployment_stream_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a846';
}
