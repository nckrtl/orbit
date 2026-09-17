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
use RuntimeException;
use SensitiveParameter;
use Traversable;

/** @implements IteratorAggregate<int, DeploymentEvent> */
final class DeploymentStream implements IteratorAggregate
{
    private const int MAXIMUM_LINE_BYTES = 32 * 1024;

    private const int MAXIMUM_OUTPUT_BYTES = 16 * 1024;

    private bool $closed = false;

    private bool $consumed = false;

    /** @var Closure(): void|null */
    private ?Closure $onIdle = null;

    /** @param Closure(): void $close */
    public function __construct(
        #[SensitiveParameter]
        private readonly StreamInterface $body,
        #[SensitiveParameter]
        private readonly Closure $close,
        private readonly string $requestId,
    ) {}

    /**
     * Called once per empty read_timeout poll (see DeploymentStreamRequest), between reads,
     * never from inside one. A caller that wants to abort a silent wait — for example on a
     * terminal interrupt — can throw from here; the SDK itself never inspects or depends on
     * why the callback throws.
     *
     * @param  Closure(): void  $callback
     */
    public function onIdle(Closure $callback): void
    {
        $this->onIdle = $callback;
    }

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

                // Checking eof() up front is unsafe here: PHP's SSL stream wrapper can report
                // eof() as true right after a read_timeout even though the connection is still
                // open (a longstanding PHP quirk). Let the read itself, below, be the only
                // source of truth: a genuinely closed stream still reads empty with no timeout.
                $chunk = $this->readIncrementalChunk(strlen($buffer));

                if ($chunk === '') {
                    // A configured read_timeout (see DeploymentStreamRequest) bounds the read
                    // above, not the whole operation; this is an empty poll, not end of stream.
                    continue;
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
            $trailing = $this->readWithTimeoutTolerance(8 * 1024);

            if ($trailing === null) {
                continue;
            }

            if ($trailing !== '' || ! $this->bodyAtEof()) {
                throw $this->invalid();
            }
        }
    }

    private function readIncrementalChunk(int $bufferBytes): string
    {
        // PHP's HTTP dechunk filter can wait for the requested length. Block for
        // one byte, then drain only bytes that the stream reports as buffered.
        $chunk = $this->readWithTimeoutTolerance(1);

        if ($chunk === null) {
            return '';
        }

        if ($chunk === '') {
            throw $this->invalid();
        }

        $availableBytes = $this->body->getMetadata('unread_bytes');

        if (! is_int($availableBytes) || $availableBytes < 1) {
            return $chunk;
        }

        $remainingBytes = self::MAXIMUM_LINE_BYTES - $bufferBytes - 1;

        return $chunk.($this->readWithTimeoutTolerance(min($availableBytes, $remainingBytes)) ?? '');
    }

    /**
     * A plain TCP socket reads '' on a read_timeout, no exception; PHP's SSL stream wrapper
     * throws instead. Either way, decide once, immediately, whether an empty result is a
     * timeout (null: the caller should retry, after running onIdle()) or a real empty read; a
     * second, later getMetadata('timed_out') check would race against the flag resetting.
     *
     * Matching the exact RuntimeException class, not instanceof, matters: a caller's own
     * signal handler (installed around this call, e.g. for Ctrl-C) can throw its own
     * RuntimeException subclass from inside this read. That must always propagate, never be
     * swallowed as a timeout retry, or an interrupt silently turns into an endless poll.
     *
     * A signal delivered while fread() is blocked can still record its own intent (a
     * caller's handler may do that as a constructor side effect) without its throw reliably
     * unwinding out of that internal call on every PHP build — a real, observed limitation,
     * not a hypothetical one. onIdle() is the reliable half of that: it always runs from
     * plain userland code between polls, so a callback that raises based on recorded intent
     * is never at risk of being silently lost the way the async-dispatched throw itself is.
     */
    private function readWithTimeoutTolerance(int $length): ?string
    {
        try {
            $chunk = $this->body->read($length);
        } catch (RuntimeException $exception) {
            if ($exception::class !== RuntimeException::class || $this->body->getMetadata('timed_out') !== true) {
                throw $exception;
            }

            $chunk = '';
        }

        if ($chunk !== '' || $this->body->getMetadata('timed_out') !== true) {
            return $chunk;
        }

        if ($this->onIdle !== null) {
            ($this->onIdle)();
        }

        return null;
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
