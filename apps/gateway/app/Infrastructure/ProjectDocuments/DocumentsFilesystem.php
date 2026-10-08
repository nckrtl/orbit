<?php

declare(strict_types=1);

namespace App\Infrastructure\ProjectDocuments;

use App\Domain\Shared\ResourceOperationException;
use App\Models\ProjectDocumentStorage;
use Aws\CommandInterface;
use Aws\Handler\Guzzle\GuzzleHandler;
use Aws\Middleware as AwsMiddleware;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemManager;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use SensitiveParameter;
use Throwable;

final readonly class DocumentsFilesystem
{
    public function __construct(private FilesystemManager $filesystems) {}

    public function current(): AwsS3V3Adapter
    {
        return $this->forConfiguration(ProjectDocumentStorage::query()->findOrFail(1));
    }

    public function forConfiguration(#[SensitiveParameter] ProjectDocumentStorage $storage): AwsS3V3Adapter
    {
        try {
            if ($storage->endpoint === null || $storage->region === null || $storage->bucket === null
                || $storage->access_key_id === null || $storage->secret_access_key === null) {
                throw new ResourceOperationException('project_documents.storage_not_configured', 'Document storage is not configured.', 409);
            }

            $options = config()->array('filesystems.disks.documents');
            if (! isset($options['handler']) && ! isset($options['http_handler'])) {
                $handler = HandlerStack::create(new CurlHandler);
                $handler->unshift(Middleware::mapResponse(static function (#[SensitiveParameter] ResponseInterface $response): ResponseInterface {
                    if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                        throw new RuntimeException('Document storage returned an unsuccessful response.');
                    }

                    return $response;
                }), 'documents_success_status');
                $options['http_handler'] = new GuzzleHandler(new Client(['handler' => $handler]));
            }
            $disk = $this->filesystems->build([
                ...$options,
                'driver' => 's3',
                'endpoint' => $storage->endpoint,
                'region' => $storage->region,
                'bucket' => $storage->bucket,
                'key' => $storage->access_key_id,
                'secret' => $storage->secret_access_key,
                'credentials' => ['key' => $storage->access_key_id, 'secret' => $storage->secret_access_key],
            ]);

            if (! $disk instanceof AwsS3V3Adapter) {
                throw new ResourceOperationException('project_documents.storage_unavailable', 'Document storage is unavailable.', 503);
            }

            // Flysystem defaults uploads to ACL=private even without visibility config.
            // Document privacy comes from the private bucket and IAM, not object ACLs.
            $disk->getClient()->getHandlerList()->appendInit(AwsMiddleware::mapCommand(static function (CommandInterface $command): CommandInterface {
                if (in_array($command->getName(), ['PutObject', 'CreateMultipartUpload'], true)) {
                    unset($command['ACL']);
                }

                return $command;
            }), 'documents_no_object_acl');

            return $disk;
        } catch (ResourceOperationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ResourceOperationException('project_documents.storage_unavailable', 'Document storage is unavailable.', 503);
        }
    }
}
