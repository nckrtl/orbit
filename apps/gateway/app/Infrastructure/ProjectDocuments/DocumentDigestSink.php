<?php

declare(strict_types=1);

namespace App\Infrastructure\ProjectDocuments;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use HashContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use SensitiveParameter;

/** Hash successful bodies without retaining bytes; keep only bounded error XML for the SDK. */
final class DocumentDigestSink implements StreamInterface
{
    use StreamDecoratorTrait;

    private StreamInterface $stream;

    private HashContext $hash;

    public ?int $responseStatus = null;

    public int $size = 0;

    public function __construct(private readonly int $expectedSize)
    {
        $this->stream = Utils::streamFor('');
        $this->hash = hash_init('sha256');
    }

    public function onHeaders(#[SensitiveParameter] ResponseInterface $response): void
    {
        $this->responseStatus = $response->getStatusCode();
    }

    public function write(#[SensitiveParameter] string $string): int
    {
        if ($this->responseStatus === null || $this->responseStatus < 200 || $this->responseStatus >= 300) {
            if (strlen($string) + ($this->stream->getSize() ?? 0) > 65536) {
                throw new \RuntimeException('Recovery response exceeded its limit.');
            }

            return $this->stream->write($string);
        }
        $this->size += strlen($string);
        if ($this->size > $this->expectedSize) {
            throw new DocumentBodyUnavailable('Recovery body size mismatch.');
        }
        hash_update($this->hash, $string);

        return strlen($string);
    }

    public function digest(): string
    {
        return hash_final(hash_copy($this->hash));
    }
}
