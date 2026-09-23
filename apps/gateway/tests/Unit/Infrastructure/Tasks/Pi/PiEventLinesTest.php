<?php

declare(strict_types=1);

use App\Infrastructure\Tasks\Pi\PiEventLines;
use GuzzleHttp\Psr7\Exception\TimeoutException;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/** A stream that returns scripted reads; a TimeoutException entry throws like an idle socket. */
/** @param list<string|TimeoutException> $reads */
function scripted_stream(array $reads): StreamInterface
{
    return new class($reads) extends Stream
    {
        /** @param list<string|TimeoutException> $reads */
        public function __construct(private array $reads)
        {
            parent::__construct(Utils::tryFopen('php://memory', 'r'));
        }

        public function eof(): bool
        {
            return $this->reads === [];
        }

        public function read($length): string
        {
            $next = array_shift($this->reads);
            if ($next instanceof TimeoutException) {
                throw $next;
            }

            return (string) $next;
        }
    };
}

it('treats a read timeout as an idle heartbeat and keeps reading', function (): void {
    $stream = scripted_stream([
        "{\"kind\":\"snapshot\",\"sequence\":1}\n",
        new TimeoutException('Unable to read from stream: timed out'),
        "{\"kind\":\"state\",\"sequence\":2}\n",
    ]);

    $events = iterator_to_array(new PiEventLines(5)->read($stream), false);

    expect(array_column($events, 'kind'))->toBe(['snapshot', 'heartbeat', 'state']);
});

it('joins events split across reads and skips malformed lines', function (): void {
    $stream = scripted_stream(['{"kind":"ent', "ry\",\"sequence\":3}\nnot json\n{\"no\":\"kind\"}\n"]);

    expect(iterator_to_array(new PiEventLines(5)->read($stream), false))->toBe([['kind' => 'entry', 'sequence' => 3]]);
});
