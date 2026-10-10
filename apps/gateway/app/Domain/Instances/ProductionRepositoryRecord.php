<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Domain\SourceControl\GitRepositoryOrigin;
use InvalidArgumentException;

/**
 * The repository URLs that one production home records: the release layout marker,
 * the initial clone marker, and the origin of each retained release
 * ([Projects](/reference/projects#production-instances)).
 */
final readonly class ProductionRepositoryRecord
{
    /** @param array<string, string> $releases */
    public function __construct(
        public ?string $layout,
        public ?string $initial,
        public array $releases,
    ) {}

    /** @return list<string> */
    public function urls(): array
    {
        return array_values(array_unique(array_filter(
            [$this->layout, $this->initial, ...array_values($this->releases)],
            static fn (?string $url): bool => $url !== null,
        )));
    }

    public function boundTo(string $repository): bool
    {
        return array_all($this->urls(), static fn (string $url): bool => $url === $repository);
    }

    /**
     * True when every recorded URL names the same repository as the given URL,
     * in SSH or HTTPS form, with or without `.git`.
     */
    public function sameRepositoryAs(string $repository): bool
    {
        $identity = GitRepositoryIdentity::derive($repository);

        foreach ($this->urls() as $url) {
            if (! GitRepositoryOrigin::isValid($url)) {
                return false;
            }

            try {
                if (GitRepositoryIdentity::derive($url) !== $identity) {
                    return false;
                }
            } catch (InvalidArgumentException) {
                return false;
            }
        }

        return true;
    }

    public function withRepository(string $repository): self
    {
        return new self(
            $this->layout === null ? null : $repository,
            $this->initial === null ? null : $repository,
            array_map(static fn (): string => $repository, $this->releases),
        );
    }

    /** @return array{layout: ?string, initial: ?string, releases: array<string, string>} */
    public function toArray(): array
    {
        return [
            'layout' => $this->layout,
            'initial' => $this->initial,
            'releases' => $this->releases,
        ];
    }

    public static function fromArray(mixed $value): self
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('The production repository record is invalid.');
        }

        $layout = $value['layout'] ?? null;
        $initial = $value['initial'] ?? null;
        $releases = $value['releases'] ?? null;

        if (
            ($layout !== null && ! is_string($layout))
            || ($initial !== null && ! is_string($initial))
            || ! is_array($releases)
        ) {
            throw new InvalidArgumentException('The production repository record is invalid.');
        }

        $names = [];

        foreach ($releases as $name => $url) {
            if (! is_string($url)) {
                throw new InvalidArgumentException('The production repository record is invalid.');
            }

            $names[(string) $name] = $url;
        }

        return new self($layout, $initial, $names);
    }
}
