<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Deployments;

use Closure;
use IteratorAggregate;
use JsonException;
use LogicException;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Support\GatewayErrorCode;
use Orbit\Sdk\Support\GatewayRequestId;
use Psr\Http\Message\StreamInterface;
use SensitiveParameter;
use Traversable;

/** @implements IteratorAggregate<int, DeploymentEvent> */
final class DeploymentStream implements IteratorAggregate
{
    private const int MAXIMUM_LINE_BYTES = 32 * 1024;

    private const int MAXIMUM_OUTPUT_BYTES = 16 * 1024;

    private bool $closed = false;

    private bool $consumed = false;

    /** @param Closure(): void $close */
    public function __construct(
        #[SensitiveParameter]
        private readonly StreamInterface $body,
        #[SensitiveParameter]
        private readonly Closure $close,
        private readonly string $requestId,
    ) {}

    /** @return Traversable<int, DeploymentEvent> */
    public function getIterator(): Traversable
    {
        if ($this->consumed) {
            throw new LogicException('Deployment streams cannot be replayed.');
        }

        $this->consumed = true;
        $buffer = '';
        $expectedSequence = 1;

        try {
            while (true) {
                $newline = strpos($buffer, "\n");

                if ($newline !== false) {
                    if ($newline + 1 > self::MAXIMUM_LINE_BYTES) {
                        throw $this->invalid();
                    }

                    $line = substr($buffer, 0, $newline);
                    $buffer = substr($buffer, $newline + 1);
                    $event = $this->parse($line, $expectedSequence);
                    $expectedSequence++;

                    if ($event instanceof DeploymentResultEvent) {
                        $this->assertTerminal($buffer);
                        yield $event;

                        return;
                    }

                    yield $event;

                    continue;
                }

                if (strlen($buffer) >= self::MAXIMUM_LINE_BYTES) {
                    throw $this->invalid();
                }

                if ($this->body->eof()) {
                    throw $this->invalid();
                }

                $chunk = $this->readIncrementalChunk(strlen($buffer));

                if ($chunk === '') {
                    throw $this->invalid();
                }

                $buffer .= $chunk;
            }
        } finally {
            $this->close();
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        ($this->close)();
    }

    /** @return array{type: class-string<self>} */
    public function __debugInfo(): array
    {
        return ['type' => self::class];
    }

    /** @return array<never, never> */
    public function __serialize(): array
    {
        throw new LogicException('Orbit deployment streams cannot be serialized.');
    }

    /** @param array<array-key, mixed> $data */
    public function __unserialize(#[SensitiveParameter] array $data): void
    {
        throw new LogicException('Orbit deployment streams cannot be unserialized.');
    }

    public function __destruct()
    {
        $this->close();
    }

    private function parse(#[SensitiveParameter] string $line, int $expectedSequence): DeploymentEvent
    {
        try {
            $event = json_decode($line, associative: true, depth: 16, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->invalid();
        }

        if (
            ! is_array($event)
            || array_is_list($event)
            || ! is_string($event['type'] ?? null)
            || ! is_int($event['sequence'] ?? null)
            || $event['sequence'] !== $expectedSequence
            || GatewayRequestId::fromTransport($event['request_id'] ?? null) !== $this->requestId
        ) {
            throw $this->invalid();
        }

        return match ($event['type']) {
            'phase' => $this->phase($event),
            'output' => $this->output($event),
            'result' => $this->result($event),
            default => throw $this->invalid(),
        };
    }

    /** @param array<array-key, mixed> $event */
    private function phase(#[SensitiveParameter] array $event): DeploymentPhaseEvent
    {
        $phase = $event['phase'] ?? null;
        $stepRequired = in_array($phase, ['before_activation', 'after_activation'], strict: true);
        $expected = ['type', 'sequence', 'request_id', 'phase'];

        if ($stepRequired) {
            $expected[] = 'step_name';
        }

        $stepName = $event['step_name'] ?? null;

        if (
            ! $this->hasExactFields($event, $expected)
            || ! is_string($phase)
            || ! in_array($phase, [
                'source_preparation',
                'environment_sync',
                'before_activation',
                'activation',
                'php_refresh',
                'after_activation',
                'rollback',
            ], strict: true)
            || ($stepRequired && (! is_string($stepName) || ! $this->validStepName($stepName)))
        ) {
            throw $this->invalid();
        }

        return new DeploymentPhaseEvent($event['sequence'], $this->requestId, $phase, $stepName);
    }

    /** @param array<array-key, mixed> $event */
    private function output(#[SensitiveParameter] array $event): DeploymentOutputEvent
    {
        if (
            ! $this->hasExactFields($event, [
                'type',
                'sequence',
                'request_id',
                'stream',
                'data_base64',
            ])
            || ! is_string($event['stream'])
            || ! in_array($event['stream'], ['stdout', 'stderr'], strict: true)
            || ! is_string($event['data_base64'])
        ) {
            throw $this->invalid();
        }

        $decoded = base64_decode($event['data_base64'], strict: true);

        if (
            ! is_string($decoded)
            || base64_encode($decoded) !== $event['data_base64']
            || strlen($decoded) > self::MAXIMUM_OUTPUT_BYTES
        ) {
            throw $this->invalid();
        }

        return new DeploymentOutputEvent(
            $event['sequence'],
            $this->requestId,
            $event['stream'],
            $decoded,
        );
    }

    /** @param array<array-key, mixed> $event */
    private function result(#[SensitiveParameter] array $event): DeploymentResultEvent
    {
        if (! $this->hasExactFields($event, [
            'type',
            'sequence',
            'request_id',
            'status',
            'failed_step',
            'error_code',
            'selected_release',
        ])) {
            throw $this->invalid();
        }

        $status = $event['status'];
        $failedStep = $event['failed_step'];
        $errorCode = $event['error_code'];
        $selectedRelease = $event['selected_release'];

        if (
            ! is_string($status)
            || ! in_array($status, ['succeeded', 'failed'], strict: true)
            || ($failedStep !== null && (! is_string($failedStep) || ! in_array($failedStep, [
                'preparation',
                'environment',
                'before_activation',
                'activation',
                'cache_refresh',
                'after_activation',
                'rollback_selection',
                'operation',
            ], strict: true)))
            || ($errorCode !== null && GatewayErrorCode::fromTransport($errorCode) === null)
            || ($selectedRelease !== null && ! $this->validRelease($selectedRelease))
            || ($status === 'succeeded' && ($failedStep !== null || $errorCode !== null || $selectedRelease === null))
            || ($status === 'failed' && ($failedStep === null || $errorCode === null))
        ) {
            throw $this->invalid();
        }

        return new DeploymentResultEvent(
            $event['sequence'],
            $this->requestId,
            $status,
            $failedStep,
            $errorCode,
            $selectedRelease,
        );
    }

    private function assertTerminal(#[SensitiveParameter] string $buffer): void
    {
        if ($buffer !== '') {
            throw $this->invalid();
        }

        while (! $this->bodyAtEof()) {
            if ($this->body->read(8 * 1024) !== '' || ! $this->bodyAtEof()) {
                throw $this->invalid();
            }
        }
    }

    private function readIncrementalChunk(int $bufferBytes): string
    {
        // PHP's HTTP dechunk filter can wait for the requested length. Block for
        // one byte, then drain only bytes that the stream reports as buffered.
        $chunk = $this->body->read(1);

        if ($chunk === '') {
            return '';
        }

        $availableBytes = $this->body->getMetadata('unread_bytes');

        if (! is_int($availableBytes) || $availableBytes < 1) {
            return $chunk;
        }

        $remainingBytes = self::MAXIMUM_LINE_BYTES - $bufferBytes - 1;

        return $chunk.$this->body->read(min($availableBytes, $remainingBytes));
    }

    /** @phpstan-impure */
    private function bodyAtEof(): bool
    {
        return $this->body->eof();
    }

    /**
     * @param  array<array-key, mixed>  $event
     * @param  list<string>  $expected
     */
    private function hasExactFields(#[SensitiveParameter] array $event, array $expected): bool
    {
        return count($event) === count($expected) && array_all(
            array_keys($event),
            static fn (mixed $key): bool => is_string($key) && in_array($key, $expected, strict: true),
        );
    }

    private function validStepName(string $name): bool
    {
        return preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $name) === 1;
    }

    private function validRelease(mixed $release): bool
    {
        return is_string($release)
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/', $release) === 1;
    }

    private function invalid(): GatewayApiException
    {
        return new GatewayApiException(
            'Gateway deployment stream is invalid.',
            requestId: $this->requestId,
        );
    }
}
