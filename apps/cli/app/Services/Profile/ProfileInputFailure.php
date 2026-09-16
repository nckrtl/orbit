<?php

declare(strict_types=1);

namespace App\Services\Profile;

final readonly class ProfileInputFailure
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $code,
        public string $message,
        public array $meta,
    ) {}

    public function isUrlResolutionFailure(): bool
    {
        return $this->code === 'profile.validation_failed'
            && ($this->meta['field'] ?? null) === 'url'
            && in_array($this->meta['reason'] ?? null, ['missing_required_input', 'invalid_url'], true);
    }
}
