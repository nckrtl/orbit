<?php

declare(strict_types=1);

namespace App\Infrastructure\ProjectDocuments;

use App\Models\ProjectDocumentStorage;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use SensitiveParameter;

final readonly class ProbeCleanupRecord
{
    public function __construct(
        public string $id,
        public string $endpoint,
        public string $region,
        public string $bucket,
        #[SensitiveParameter] private string $encryptedAccessKey,
        #[SensitiveParameter] private string $encryptedSecretKey,
        public int $failureCount,
    ) {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $id) !== 1) {
            throw new InvalidArgumentException('Invalid document probe record.');
        }
    }

    public function key(): string
    {
        return 'orbit-document-probes/'.$this->id;
    }

    public function configuration(): ProjectDocumentStorage
    {
        return new ProjectDocumentStorage([
            'endpoint' => $this->endpoint, 'region' => $this->region, 'bucket' => $this->bucket,
            'access_key_id' => Crypt::decryptString($this->encryptedAccessKey),
            'secret_access_key' => Crypt::decryptString($this->encryptedSecretKey),
        ]);
    }

    /** @return array<string, string|int> */
    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'endpoint' => $this->endpoint, 'region' => $this->region, 'bucket' => $this->bucket, 'failure_count' => $this->failureCount];
    }
}
