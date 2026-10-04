<?php

declare(strict_types=1);

namespace App\Data\ProjectDocuments;

use SensitiveParameter;

final readonly class UpdateDocumentStorageData
{
    public function __construct(
        public ?string $endpoint,
        public ?string $region,
        public ?string $bucket,
        #[SensitiveParameter] public ?string $accessKeyId,
        #[SensitiveParameter] public ?string $secretAccessKey,
    ) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['endpoint' => $this->endpoint, 'region' => $this->region, 'bucket' => $this->bucket];
    }
}
