<?php

declare(strict_types=1);

namespace App\Actions\ProjectDocuments;

use App\Data\ProjectDocuments\UpdateDocumentStorageData;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\ProjectDocuments\VerifyDocumentStorage;
use App\Models\ProjectDocumentStorage;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

final readonly class UpdateDocumentStorageAction
{
    public function __construct(private VerifyDocumentStorage $verify) {}

    public function handle(#[SensitiveParameter] UpdateDocumentStorageData $data): ProjectDocumentStorage
    {
        return DB::transaction(function () use ($data): ProjectDocumentStorage {
            $storage = ProjectDocumentStorage::query()->lockForUpdate()->findOrFail(1);
            $candidate = clone $storage;
            try {
                $candidate->fill([
                    'endpoint' => $data->endpoint ?? $storage->endpoint,
                    'region' => $data->region ?? $storage->region,
                    'bucket' => $data->bucket ?? $storage->bucket,
                    'access_key_id' => $data->accessKeyId ?? $this->existingCredential($storage, 'access_key_id'),
                    'secret_access_key' => $data->secretAccessKey ?? $this->existingCredential($storage, 'secret_access_key'),
                ]);
            } catch (DecryptException) {
                throw new ResourceOperationException('project_documents.storage_unavailable', 'Document storage requires replacement credentials.', 503);
            }

            foreach (['endpoint', 'region', 'bucket', 'access_key_id', 'secret_access_key'] as $field) {
                if ($candidate->getAttribute($field) === null) {
                    throw ValidationException::withMessages([$field => ['This field is required when configuring document storage.']]);
                }
            }

            $this->verify->handle($candidate);
            $candidate->save();
            Storage::forgetDisk('documents');

            return $candidate->refresh();
        });
    }

    /** @throws DecryptException when the saved credential cannot be recovered with the current or previous keys. */
    private function existingCredential(#[SensitiveParameter] ProjectDocumentStorage $storage, string $field): ?string
    {
        $value = $storage->getAttribute($field);

        return is_string($value) ? $value : null;
    }
}
