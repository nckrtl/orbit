<?php

declare(strict_types=1);

namespace App\Infrastructure\ProjectDocuments;

use App\Data\ProjectDocuments\DocumentBody;
use App\Domain\Shared\ResourceOperationException;
use App\Models\ProjectDocumentStorage;
use Aws\Exception\AwsException;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\DB;
use LogicException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use SensitiveParameter;
use Throwable;

final readonly class DocumentBodies
{
    public function __construct(private DocumentsFilesystem $filesystems) {}

    public function assertOutsideTransaction(): void
    {
        if (DB::transactionLevel() !== 0 || DB::getPdo()->inTransaction()) {
            throw new LogicException('Document body operations cannot run inside a database transaction.');
        }
    }

    public function put(string $key, #[SensitiveParameter] DocumentBody $body): void
    {
        $this->assertOutsideTransaction();
        $disk = $this->configured();
        try {
            $disk->getClient()->putObject([
                'Bucket' => $disk->getConfig()['bucket'], 'Key' => $key, 'Body' => $body->bytes,
                'ACL' => 'private', 'ContentType' => 'application/octet-stream',
                'ContentDisposition' => 'attachment',
                '@http' => ['timeout' => 30, 'connect_timeout' => 2, 'allow_redirects' => false, 'sink' => new ProbeResponseBuffer(65536)],
            ]);
        } catch (Throwable) {
            throw new ResourceOperationException('project_documents.storage_unavailable', 'Document storage is unavailable.', 503);
        }
        $this->get($key, $body->sizeBytes, $body->sha256);
    }

    public function get(string $key, int $size, string $sha256): string
    {
        $this->assertOutsideTransaction();
        $disk = $this->configured();
        $buffer = new DocumentResponseBuffer($size + 1);
        try {
            $deadline = microtime(true) + 30;
            $result = $disk->getClient()->getObject([
                'Bucket' => $disk->getConfig()['bucket'], 'Key' => $key,
                '@http' => ['timeout' => 30, 'connect_timeout' => 2, 'allow_redirects' => false,
                    'sink' => $buffer, 'on_headers' => $buffer->onHeaders(...)],
            ]);
            $stream = $result['Body'];
            if (! $stream instanceof StreamInterface) {
                throw new DocumentBodyUnavailable('Invalid body stream.');
            }
            $bytes = '';
            try {
                while (true) {
                    if (microtime(true) >= $deadline) {
                        throw new RuntimeException('Document read timed out.');
                    }
                    $chunk = $stream->read(min(8192, $size + 1 - strlen($bytes)));
                    $bytes .= $chunk;
                    if (strlen($bytes) > $size) {
                        throw new DocumentBodyUnavailable('Invalid document body.');
                    }
                    if ($stream->eof()) {
                        break;
                    }
                    if ($chunk === '') {
                        throw new DocumentBodyUnavailable('Invalid document body.');
                    }
                }
            } finally {
                $stream->close();
            }
            if (strlen($bytes) !== $size || ! hash_equals($sha256, hash('sha256', $bytes))) {
                throw new DocumentBodyUnavailable('Document digest mismatch.');
            }

            return $bytes;
        } catch (Throwable $exception) {
            $status = $buffer->responseStatus ?? ($exception instanceof AwsException ? $exception->getStatusCode() : null);
            $missing = $exception instanceof AwsException && $status === 404
                && in_array($exception->getAwsErrorCode(), ['NoSuchKey', 'NotFound'], true);
            $successfulResponse = $status !== null && $status >= 200 && $status < 300;
            $corrupt = $exception instanceof DocumentBodyUnavailable && ($status === null || $successfulResponse);
            if ($successfulResponse) {
                for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
                    if ($cause instanceof DocumentBodyUnavailable) {
                        $corrupt = true;
                        break;
                    }
                }
            }
            if ($missing || $corrupt) {
                throw new ResourceOperationException('project_documents.body_unavailable', 'Document body is missing or corrupt.', 502);
            }
            throw new ResourceOperationException('project_documents.storage_unavailable', 'Document storage is unavailable.', 503);
        }
    }

    /** Exact durable keys only; the worker owns authorization and holds the execution lock. */
    public function delete(string $key): void
    {
        $this->assertOutsideTransaction();
        $disk = $this->configured();
        try {
            $result = $disk->getClient()->deleteObject([
                'Bucket' => $disk->getConfig()['bucket'], 'Key' => $key,
                '@http' => ['timeout' => 30, 'connect_timeout' => 2, 'allow_redirects' => false,
                    'sink' => new ProbeResponseBuffer(65536)],
            ]);
            $metadata = $result['@metadata'];
            $status = is_array($metadata) ? ($metadata['statusCode'] ?? null) : null;
            if (! is_int($status) || $status < 200 || $status >= 300) {
                throw new RuntimeException('Document deletion did not succeed.');
            }
        } catch (Throwable) {
            throw new ResourceOperationException('project_documents.storage_unavailable', 'Document storage is unavailable.', 503);
        }
    }

    private function configured(): AwsS3V3Adapter
    {
        $storage = ProjectDocumentStorage::query()->find(1);
        if ($storage === null || $storage->endpoint === null) {
            throw new ResourceOperationException('project_documents.storage_not_configured', 'Document storage is not configured.', 409);
        }

        return $this->filesystems->forConfiguration($storage);
    }
}
