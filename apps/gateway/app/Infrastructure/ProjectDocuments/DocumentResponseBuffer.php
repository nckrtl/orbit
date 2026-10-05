<?php

declare(strict_types=1);

namespace App\Infrastructure\ProjectDocuments;

use App\Data\ProjectDocuments\DocumentBody;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use SensitiveParameter;

final class DocumentResponseBuffer implements StreamInterface
{
    use StreamDecoratorTrait;

    private StreamInterface $stream;

    public ?int $responseStatus = null;

    public function __construct(private readonly int $limit)
    {
        if ($limit < 1 || $limit > DocumentBody::MAX_BYTES + 1) {
            throw new RuntimeException('Invalid document response limit.');
        }
        $this->stream = Utils::streamFor('');
    }

    public function onHeaders(#[SensitiveParameter] ResponseInterface $response): void
    {
        $this->responseStatus = $response->getStatusCode();
    }

    public function write(#[SensitiveParameter] string $string): int
    {
        // Error XML needs its own bounded allowance even when the committed file is empty.
        $limit = $this->responseStatus !== null && $this->responseStatus >= 300 ? 65536 : $this->limit;
        if (strlen($string) + ($this->stream->getSize() ?? 0) > $limit) {
            throw new DocumentBodyUnavailable('Document response exceeded its size limit.');
        }

        return $this->stream->write($string);
    }
}
