<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks\Pi;

use GuzzleHttp\Psr7\Exception\TimeoutException;
use Psr\Http\Message\StreamInterface;

/**
 * Reads newline-delimited JSON events from a Pi server stream until a deadline. A read that
 * times out without data yields a heartbeat: the connection is idle, not broken.
 */
final readonly class PiEventLines
{
    public function __construct(private float $seconds) {}

    /** @return iterable<array<string, mixed>> */
    public function read(StreamInterface $body): iterable
    {
        $deadline = microtime(true) + $this->seconds;
        $buffer = '';
        while (microtime(true) < $deadline && ! $body->eof() && ! connection_aborted()) {
            try {
                $chunk = $body->read(8192);
            } catch (TimeoutException) {
                $chunk = '';
            }
            if ($chunk === '') {
                yield ['kind' => 'heartbeat'];

                continue;
            }
            $buffer .= $chunk;
            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $newline);
                $buffer = substr($buffer, $newline + 1);
                $event = json_decode($line, true);
                if (is_array($event) && is_string($event['kind'] ?? null)) {
                    /** @var array<string, mixed> $event */
                    yield $event;
                }
            }
        }
    }
}
