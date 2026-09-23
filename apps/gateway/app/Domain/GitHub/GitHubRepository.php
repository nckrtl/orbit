<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

use SensitiveParameter;

/**
 * The owner and name of a `github.com` repository, read from an App repository origin in its HTTPS,
 * `ssh://`, or `git@github.com:` form.
 */
final readonly class GitHubRepository
{
    private function __construct(
        public string $owner,
        public string $name,
    ) {}

    public static function fromOrigin(#[SensitiveParameter] string $origin): ?self
    {
        $pattern = '/\A(?:https:\/\/github\.com\/|ssh:\/\/git@github\.com\/|git@github\.com:)'
            .'([A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?)\/([A-Za-z0-9._-]{1,100}?)(?:\.git)?\/?\z/D';

        if (preg_match($pattern, $origin, $matches) !== 1) {
            return null;
        }

        if ($matches[2] === '.' || $matches[2] === '..') {
            return null;
        }

        return new self($matches[1], $matches[2]);
    }

    /**
     * The number of a pull request in this repository, from its web URL.
     */
    public function pullRequestNumber(string $url): ?int
    {
        $prefix = preg_quote('https://github.com/'.$this->owner.'/'.$this->name.'/pull/', '#');

        return preg_match('#\A'.$prefix.'([1-9][0-9]{0,9})\z#D', $url, $matches) === 1 ? (int) $matches[1] : null;
    }
}
