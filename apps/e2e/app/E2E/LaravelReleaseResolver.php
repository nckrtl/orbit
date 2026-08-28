<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\LaravelRelease;
use Composer\Semver\Semver;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;

final readonly class LaravelReleaseResolver
{
    public function __construct(
        private string $repository = 'https://github.com/laravel/laravel.git',
    ) {}

    public function resolve(string $constraint): LaravelRelease
    {
        $result = Process::run(['git', 'ls-remote', '--tags', '--refs', $this->repository]);

        if ($result->failed()) {
            throw new InvalidArgumentException('Unable to resolve Laravel releases.');
        }

        $releases = $this->parseReleases($result->output());
        $matches = Semver::satisfiedBy(array_keys($releases), $constraint);

        if ($matches === []) {
            throw new InvalidArgumentException('No stable Laravel release matches the constraint.');
        }

        return $releases[Semver::rsort($matches)[0]];
    }

    public function forCommit(string $tag, ?string $commit = null): LaravelRelease
    {
        if ($commit === null) {
            $commit = $tag;
            $tag = '';
        }

        if ($tag !== '' && preg_match('/\Av\d+\.\d+\.\d+\z/D', $tag) !== 1) {
            throw new InvalidArgumentException('The Laravel release tag is invalid.');
        }

        if (preg_match('/\A[0-9a-f]{40}\z/D', $commit) !== 1) {
            throw new InvalidArgumentException('The Laravel commit must be a lowercase full SHA.');
        }

        return new LaravelRelease($tag, $commit);
    }

    /** @return array<string, LaravelRelease> */
    private function parseReleases(string $output): array
    {
        $lines = preg_split('/\R/', rtrim($output, "\r\n"));

        if ($lines === false || $lines === [] || $lines === ['']) {
            throw new InvalidArgumentException('Laravel returned no release tags.');
        }

        $releases = [];

        foreach ($lines as $line) {
            $matches = [];

            if (preg_match('/\A([0-9a-f]{40})\trefs\/tags\/(v(\d+\.\d+\.\d+))\z/D', $line, $matches) !== 1) {
                if (preg_match('/\A[0-9a-f]{40}\trefs\/tags\/v?\d/', $line) === 1) {
                    continue;
                }

                throw new InvalidArgumentException('Laravel returned a malformed release reference.');
            }

            [$reference, $tag, $version] = [$matches[1], $matches[2], $matches[3]];

            if (isset($releases[$version]) && $releases[$version]->commit !== $reference) {
                throw new InvalidArgumentException("Laravel release [{$tag}] moved during resolution.");
            }

            $releases[$version] = new LaravelRelease($tag, $reference);
        }

        if ($releases === []) {
            throw new InvalidArgumentException('Laravel returned no stable commit tags.');
        }

        return $releases;
    }
}
