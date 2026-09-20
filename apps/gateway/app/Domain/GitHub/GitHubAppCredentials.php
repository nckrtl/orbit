<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

use LogicException;
use SensitiveParameter;

final readonly class GitHubAppCredentials
{
    public function __construct(
        public int $appId,
        public string $slug,
        public string $name,
        public string $owner,
        public string $ownerType,
        public string $url,
        #[SensitiveParameter]
        public string $privateKey,
    ) {}

    /** The GitHub page where the owner deletes the App registration. */
    public function settingsUrl(): string
    {
        return $this->ownerType === 'organization'
            ? 'https://github.com/organizations/'.rawurlencode($this->owner).'/settings/apps/'.rawurlencode($this->slug)
            : 'https://github.com/settings/apps/'.rawurlencode($this->slug);
    }

    public function installUrl(): string
    {
        return 'https://github.com/apps/'.rawurlencode($this->slug).'/installations/new';
    }

    /** @return array{type: class-string} */
    public function __debugInfo(): array
    {
        return ['type' => self::class];
    }

    public function __serialize(): array
    {
        throw new LogicException('GitHub App credentials cannot be serialized.');
    }
}
