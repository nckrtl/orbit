<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

use Illuminate\Support\Facades\Config;

/**
 * The paths of the Gateway release layout, derived from the stable Gateway checkout path
 * (`orbit.gateway_checkout`, `/home/orbit/orbit/apps/gateway`):
 *
 * - `/home/orbit/orbit` links to the current release, so every path below it stays stable.
 * - `/home/orbit/releases/<id>` holds one immutable release, a linked worktree of one commit.
 * - `/home/orbit/shared` holds what every release shares: the Git repository, the Gateway env
 *   file, and the Gateway storage directory.
 */
final readonly class GatewayReleaseLayout
{
    private const string ApplicationSuffix = '/apps/gateway';

    public function __construct(public string $applicationPath)
    {
        if (
            ! str_ends_with($applicationPath, self::ApplicationSuffix)
            || ! str_starts_with($applicationPath, '/')
            || str_contains($applicationPath, "\0")
            || str_contains($applicationPath, "\n")
            || in_array('..', explode('/', $applicationPath), true)
            || dirname($applicationPath, 3) === '/'
        ) {
            throw new GatewayReleaseException(
                step: 'layout',
                errorCode: 'gateway.release_layout_invalid',
                message: "The Gateway checkout [{$applicationPath}] must be an absolute <base>/<checkout>/apps/gateway path.",
            );
        }
    }

    public static function fromConfig(): self
    {
        return new self(rtrim(Config::string('orbit.gateway_checkout'), '/'));
    }

    /** The stable path that links to the current release, `/home/orbit/orbit`. */
    public function currentPath(): string
    {
        return dirname($this->applicationPath, 2);
    }

    public function basePath(): string
    {
        return dirname($this->currentPath());
    }

    public function releasesPath(): string
    {
        return $this->basePath().'/releases';
    }

    public function releasePath(string $id): string
    {
        return $this->releasesPath().'/'.GatewayReleaseCommit::assertId($id);
    }

    public function releaseApplicationPath(string $id): string
    {
        return $this->releasePath($id).self::ApplicationSuffix;
    }

    public function sharedPath(): string
    {
        return $this->basePath().'/shared';
    }

    /** The bare repository every release is a linked worktree of. */
    public function repositoryPath(): string
    {
        return $this->sharedPath().'/orbit.git';
    }

    /** The one Gateway env file that `apps/gateway/.env` links to in every release. */
    public function environmentPath(): string
    {
        return $this->sharedPath().'/gateway.env';
    }

    /** The Gateway storage directory, shared so caches, locks, and logs survive a release switch. */
    public function storagePath(): string
    {
        return $this->sharedPath().'/gateway-storage';
    }

    /** The relative link target that names one release from the current path's directory. */
    public function linkTarget(string $id): string
    {
        return 'releases/'.GatewayReleaseCommit::assertId($id);
    }

    /**
     * The id of the release the current path links to, or null when the current path is not a
     * release link (an in-place checkout, or nothing).
     */
    public function currentReleaseId(): ?string
    {
        $current = $this->currentPath();

        if (! is_link($current)) {
            return null;
        }

        $target = readlink($current);

        if (! is_string($target)) {
            return null;
        }

        foreach ([$this->releasesPath().'/', 'releases/'] as $prefix) {
            if (str_starts_with($target, $prefix)) {
                $id = substr($target, strlen($prefix));

                return GatewayReleaseCommit::isId($id) ? $id : null;
            }
        }

        return null;
    }

    public function isAdopted(): bool
    {
        return $this->currentReleaseId() !== null;
    }

    /**
     * The full commit a prepared release was built from, or null when the release is missing or its
     * preparation never finished. `REVISION` is written last, so it marks a complete release.
     */
    public function preparedCommit(string $id): ?string
    {
        $revision = $this->releasePath($id).'/REVISION';

        if (! is_file($revision) || is_link($revision)) {
            return null;
        }

        $sha = trim((string) file_get_contents($revision));

        return GatewayReleaseCommit::isSha($sha) && str_starts_with($sha, $id) ? $sha : null;
    }

    /**
     * The ids of release directories without `REVISION`: prepares that stopped or are still running. A directory
     * whose `REVISION` names another commit is not listed, because prepare refuses to replace it.
     *
     * @return list<string>
     */
    public function incompleteReleaseIds(): array
    {
        $entries = @scandir($this->releasesPath());

        if ($entries === false) {
            return [];
        }

        $releases = [];

        foreach ($entries as $entry) {
            $path = $this->releasesPath().'/'.$entry;

            if (GatewayReleaseCommit::isId($entry) && is_dir($path) && ! is_link($path) && ! file_exists($path.'/REVISION') && ! is_link($path.'/REVISION')) {
                $releases[] = $entry;
            }
        }

        return $releases;
    }

    /**
     * Retained release ids, newest first by preparation time. Partial releases are excluded.
     *
     * @return list<string>
     */
    public function retainedReleaseIds(): array
    {
        $entries = @scandir($this->releasesPath());

        if ($entries === false) {
            return [];
        }

        $releases = [];

        foreach ($entries as $entry) {
            if (! GatewayReleaseCommit::isId($entry) || $this->preparedCommit($entry) === null) {
                continue;
            }

            $releases[$entry] = (int) filemtime($this->releasePath($entry).'/REVISION');
        }

        uksort($releases, static fn (string $left, string $right): int => [$releases[$right], $right] <=> [$releases[$left], $left]);

        return array_map(strval(...), array_keys($releases));
    }
}
