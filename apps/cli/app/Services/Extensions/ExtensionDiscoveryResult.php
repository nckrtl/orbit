<?php

declare(strict_types=1);

namespace App\Services\Extensions;

final readonly class ExtensionDiscoveryResult
{
    /**
     * @param  array<string, bool>|null  $enabled
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public ?array $enabled,
        public ?string $errorCode = null,
        public ?string $message = null,
        public ?string $requestId = null,
        public array $details = [],
    ) {}

    public function isKnown(): bool
    {
        return $this->enabled !== null;
    }

    public function isEnabled(string $extension): ?bool
    {
        return $this->enabled[$extension] ?? ($this->enabled === null ? null : false);
    }

    /** @return array{code: string, message: string, request_id: ?string, details?: array<string, mixed>}|null */
    public function stateDetail(): ?array
    {
        if ($this->isKnown()) {
            return null;
        }

        $detail = [
            'code' => $this->errorCode ?? 'gateway.request_failed',
            'message' => $this->message ?? 'Could not determine extension state.',
            'request_id' => $this->requestId,
        ];

        if ($this->details !== []) {
            $detail['details'] = $this->details;
        }

        return $detail;
    }
}
