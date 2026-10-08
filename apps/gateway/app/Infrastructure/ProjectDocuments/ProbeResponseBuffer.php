<?php

declare(strict_types=1);

namespace App\Infrastructure\ProjectDocuments;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use SensitiveParameter;

/** Abort the HTTP transfer before an ignored Range can buffer an unbounded body. */
final class ProbeResponseBuffer implements StreamInterface
{
    use StreamDecoratorTrait;

    private StreamInterface $stream;

    public function __construct(private readonly int $limit = 33)
    {
        if ($limit < 1 || $limit > 65_536) {
            throw new RuntimeException('Invalid document probe response limit.');
        }
        $this->stream = Utils::streamFor('');
    }

    public function write(#[SensitiveParameter] string $string): int
    {
        $remaining = $this->limit - ($this->stream->getSize() ?? 0);
        if (strlen($string) > $remaining) {
            $this->stream->write(substr($string, 0, $remaining));
            throw new RuntimeException('Document storage probe response exceeds its size limit.');
        }

        return $this->stream->write($string);
    }
}
