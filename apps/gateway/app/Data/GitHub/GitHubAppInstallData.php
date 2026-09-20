<?php

declare(strict_types=1);

namespace App\Data\GitHub;

/**
 * The browser step that continues `github:app:install`. `register` opens the Gateway page that sends
 * the App manifest to GitHub; `install` opens the App's install page on GitHub. `accounts` lists the
 * accounts that had installed the App when the step was issued, so a caller can tell a new
 * installation apart.
 */
final readonly class GitHubAppInstallData
{
    /** @param list<string> $accounts */
    public function __construct(
        public string $step,
        public string $url,
        public array $accounts,
    ) {}

    /** @return array{step: string, url: string, accounts: list<string>} */
    public function toArray(): array
    {
        return [
            'step' => $this->step,
            'url' => $this->url,
            'accounts' => $this->accounts,
        ];
    }
}
