<?php

declare(strict_types=1);

namespace App\Infrastructure\ProjectDocuments;

use App\Domain\Shared\ResourceOperationException;
use App\Models\ProjectDocumentStorage;
use Psr\Http\Message\StreamInterface;
use SensitiveParameter;
use Throwable;

final readonly class VerifyDocumentStorage
{
    public function __construct(private DocumentsFilesystem $filesystems, private ProbeJournal $journal) {}

    public function handle(#[SensitiveParameter] ProjectDocumentStorage $storage): void
    {
        if ($storage->bucket === null) {
            throw new ResourceOperationException('project_documents.storage_not_configured', 'Document storage is not configured.', 409);
        }
        $client = $this->filesystems->forConfiguration($storage)->getClient();
        $record = $this->journal->create($storage);
        $key = $record->key();
        $body = random_bytes(32);
        $object = ['Bucket' => $storage->bucket, 'Key' => $key];
        $deleted = false;

        try {
            $client->putObject([...$object, 'Body' => $body, 'ContentType' => 'application/octet-stream', '@http' => ['stream' => false, 'sink' => new ProbeResponseBuffer(65_536)]]);
            $deadline = hrtime(true) + 5_000_000_000;
            $result = $client->getObject([...$object, 'Range' => 'bytes=0-32', '@http' => ['stream' => false, 'sink' => new ProbeResponseBuffer]]);
            $stream = $result['Body'];
            if (! $stream instanceof StreamInterface) {
                throw new ResourceOperationException('project_documents.storage_unavailable', 'Document storage verification failed.', 503);
            }
            try {
                $received = '';
                while (! $this->hasEnded($stream) && strlen($received) < 33) {
                    if (hrtime(true) >= $deadline) {
                        throw new ResourceOperationException('project_documents.storage_unavailable', 'Document storage verification failed.', 503);
                    }
                    $chunk = $stream->read(33 - strlen($received));
                    if ($chunk === '' && ! $this->hasEnded($stream)) {
                        throw new ResourceOperationException('project_documents.storage_unavailable', 'Document storage verification failed.', 503);
                    }
                    $received .= $chunk;
                }
                if (hrtime(true) >= $deadline || $received !== $body || ! $this->hasEnded($stream)) {
                    throw new ResourceOperationException('project_documents.storage_unavailable', 'Document storage verification failed.', 503);
                }
            } finally {
                $stream->close();
            }
            $client->deleteObject([...$object, '@http' => ['stream' => false, 'sink' => new ProbeResponseBuffer(65_536)]]);
            $deleted = true;
        } catch (Throwable) {
            // Never chain provider exceptions: their requests can contain credentials and signed URLs.
            throw new ResourceOperationException('project_documents.storage_unavailable', 'Document storage verification failed.', 503);
        } finally {
            if (! $deleted) {
                try {
                    // PUT can reach the provider even when its response is lost.
                    $client->deleteObject([...$object, '@http' => ['stream' => false, 'sink' => new ProbeResponseBuffer(65_536)]]);
                } catch (Throwable) {
                    // Only this random probe key is eligible for cleanup. Never enumerate or delete other objects.
                }
            }
        }
    }

    /** @phpstan-impure */
    private function hasEnded(StreamInterface $stream): bool
    {
        return $stream->eof();
    }
}
