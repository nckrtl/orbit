<?php

declare(strict_types=1);

namespace App\Infrastructure\ProjectDocuments;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use SensitiveParameter;

/** One bounded page of at most 1,000 S3 keys and markers, including XML escaping. */
final class RecoveryListBuffer implements StreamInterface
{
    use StreamDecoratorTrait;

    private StreamInterface $stream;

    public function __construct()
    {
        $this->stream = Utils::streamFor('');
    }

    public function write(#[SensitiveParameter] string $string): int
    {
        if (strlen($string) + ($this->stream->getSize() ?? 0) > 8 * 1024 * 1024) {
            throw new RuntimeException('Recovery listing exceeded its limit.');
        }

        return $this->stream->write($string);
    }
}
