<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

final readonly class GitHubInstallation
{
    public function __construct(
        public int $id,
        public string $account,
        public string $type,
        public string $repositories,
        public bool $suspended,
    ) {}

    /** @return array{id: int, account: string, type: string, repositories: string, suspended: bool} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'account' => $this->account,
            'type' => $this->type,
            'repositories' => $this->repositories,
            'suspended' => $this->suspended,
        ];
    }
}
