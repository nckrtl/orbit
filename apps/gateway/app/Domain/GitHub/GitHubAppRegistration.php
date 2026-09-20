<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

use SensitiveParameter;

/**
 * One pending App registration. GitHub returns `state` with its redirect, and the one-time code it
 * sends is valid for one hour, so the registration expires with it.
 */
final readonly class GitHubAppRegistration
{
    public const int LIFETIME_SECONDS = 3600;

    public function __construct(
        #[SensitiveParameter]
        public string $state,
        public string $name,
        public ?string $owner,
        public string $gatewayUrl,
        public int $expiresAt,
    ) {}

    public function matches(#[SensitiveParameter] string $state, int $now): bool
    {
        return $now < $this->expiresAt && hash_equals($this->state, $state);
    }

    /** @return array{state: string, name: string, owner: string|null, gateway_url: string, expires_at: int} */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'name' => $this->name,
            'owner' => $this->owner,
            'gateway_url' => $this->gatewayUrl,
            'expires_at' => $this->expiresAt,
        ];
    }

    /** @param array<array-key, mixed> $stored */
    public static function fromArray(array $stored): ?self
    {
        $state = $stored['state'] ?? null;
        $name = $stored['name'] ?? null;
        $owner = $stored['owner'] ?? null;
        $gatewayUrl = $stored['gateway_url'] ?? null;
        $expiresAt = $stored['expires_at'] ?? null;

        if (
            ! is_string($state)
            || ! is_string($name)
            || ! (is_string($owner) || $owner === null)
            || ! is_string($gatewayUrl)
            || ! is_int($expiresAt)
        ) {
            return null;
        }

        return new self($state, $name, $owner, $gatewayUrl, $expiresAt);
    }
}
