<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\GitHub\GitHubRepository;

/** Operator-owned repair authority. Display names and repository roles are never consulted. */
final readonly class TaskReviewTrust
{
    /** @param list<int> $accountIds */
    private function __construct(public bool $valid, public array $accountIds, public string $revision) {}

    /**
     * Reads the operator's env value `owner/repo:id,id;owner/repo:id` into the config shape. A token that is not a
     * positive integer stays a string, so fromConfig() disables that repository instead of trusting it.
     *
     * @return array<string, list<int|string>>
     */
    public static function parseEnv(?string $value): array
    {
        $config = [];
        foreach (explode(';', (string) $value) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            [$repository, $ids] = array_pad(explode(':', $entry, 2), 2, '');
            $config[strtolower(trim($repository))] = array_map(
                static fn (string $id): int|string => preg_match('/\A[1-9][0-9]{0,18}\z/', $id) === 1 ? (int) $id : $id,
                array_map(trim(...), explode(',', $ids)),
            );
        }

        return $config;
    }

    public static function fromConfig(GitHubRepository $repository, mixed $config): self
    {
        $key = strtolower($repository->owner.'/'.$repository->name);
        if (! is_array($config)) {
            return self::invalid($key);
        }
        // Invalid aliases and wildcard scopes disable only repositories they could name.
        // Unrelated malformed keys cannot revoke a valid repository's operator-owned trust.
        foreach (array_keys($config) as $scope) {
            if (! is_string($scope) || $scope === $key) {
                continue;
            }
            $alias = GitHubRepository::fromOrigin($scope)
                ?? GitHubRepository::fromOrigin('https://github.com/'.$scope);
            $aliasKey = $alias === null ? null : strtolower($alias->owner.'/'.$alias->name);
            if ($aliasKey === $key || fnmatch(strtolower($scope), $key)) {
                return self::invalid($key);
            }
        }
        $ids = array_key_exists($key, $config) ? $config[$key] : [];
        if (! is_array($ids) || ! array_is_list($ids)) {
            return self::invalid($key);
        }
        foreach ($ids as $id) {
            if (! is_int($id) || $id < 1) {
                return self::invalid($key);
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);

        return new self(true, $ids, hash('sha256', serialize([$key, $ids])));
    }

    private static function invalid(string $key): self
    {
        return new self(false, [], hash('sha256', $key.':invalid'));
    }
}
