<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayReleaseWebBuild;
use App\Domain\GitHub\GitHubRepository;
use App\Infrastructure\GitHub\GitHubActionsReader;
use App\Infrastructure\GitHub\GitHubActionsUnavailable;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use ZipArchive;

/**
 * Installs the web app build that CI published for a commit, the artifact `web-dist-<sha>`, into
 * `<web>/releases/<id>`, in the layout and with the permissions of `bin/web-deploy`. Publish and
 * restore move `<web>/current` with one rename.
 *
 * CI's `Required checks` job needs the Web job, and the Web job uploads the artifact before it
 * succeeds. A commit whose checks passed therefore has the artifact, so a missing or expired one
 * fails the commit instead of waiting for it. GitHub that cannot be read is retried later.
 */
final class GitHubArtifactWebBuild implements GatewayReleaseWebBuild
{
    /** The largest artifact archive the Gateway downloads. */
    public const int MaxArchiveBytes = 128 * 1024 * 1024;

    /** The largest total size of the files in one build. */
    public const int MaxExpandedBytes = 512 * 1024 * 1024;

    /** The most entries one build archive may hold. */
    public const int MaxEntries = 20_000;

    private const int UnixFileType = 0170000;

    private const int UnixRegularFile = 0100000;

    private const int UnixDirectory = 0040000;

    /** Whether this attempt switched `current`, and the link target it replaced. */
    private bool $published = false;

    private ?string $publishedFrom = null;

    public function __construct(
        private readonly GatewayReleaseLayout $layout,
        private readonly ProcessRunner $processes,
        private readonly GitHubActionsReader $actions,
        private readonly string $webRoot,
        private readonly string $group = 'caddy',
    ) {}

    public static function artifactName(string $sha): string
    {
        return 'web-dist-'.$sha;
    }

    public function install(string $id, string $sha): bool
    {
        $id = GatewayReleaseCommit::assertId($id);

        if (strlen($sha) !== 40 || ! GatewayReleaseCommit::isSha($sha) || ! str_starts_with($sha, $id)) {
            throw $this->invalid("Web build [{$id}] needs the full commit, not [{$sha}].");
        }

        if ($this->installed($id)) {
            return true;
        }

        $this->assertDirectory();
        $repository = $this->repository();
        $archive = @tempnam(sys_get_temp_dir(), 'orbit-web-build-');

        if ($archive === false) {
            throw $this->installFailed('The web build archive cannot be created in the temporary directory.');
        }

        $staging = $this->releasesPath().'/.'.$id.'.partial';

        try {
            @chmod($archive, 0600);
            $this->downloadArtifact($repository, $sha, $archive);
            $this->removePath($staging);
            $this->extract($archive, $staging);

            if (! is_file($staging.'/index.html') || is_link($staging.'/index.html')) {
                throw $this->invalid('The web build of ['.$sha.'] has no index.html.');
            }

            $this->grantCaddy($staging);
            $this->moveIntoPlace($staging, $this->releasePath($id));
        } finally {
            @unlink($archive);
            $this->removePath($staging);
        }

        return false;
    }

    public function publish(string $id): void
    {
        $id = GatewayReleaseCommit::assertId($id);

        if (! $this->installed($id)) {
            throw new GatewayReleaseException(
                step: 'web',
                errorCode: 'gateway.release_web_build_missing',
                message: "The web build [{$id}] is not installed in [{$this->releasesPath()}].",
                status: 422,
            );
        }

        $from = $this->currentTarget();
        $this->switchTo('releases/'.$id);
        $this->published = true;
        $this->publishedFrom = $from;
    }

    public function restore(string $id): void
    {
        if (! $this->published) {
            return;
        }

        $id = GatewayReleaseCommit::assertId($id);
        $target = $this->publishedFrom ?? ($this->installed($id) ? 'releases/'.$id : null);

        if ($target !== null) {
            $this->switchTo($target);
        }

        $this->published = false;
        $this->publishedFrom = null;
    }

    public function remove(string $id): void
    {
        $id = GatewayReleaseCommit::assertId($id);

        if ($this->currentTarget() === 'releases/'.$id || $this->currentTarget() === $this->releasePath($id)) {
            return;
        }

        $this->removePath($this->releasePath($id));
    }

    private function releasesPath(): string
    {
        return $this->webRoot.'/releases';
    }

    private function releasePath(string $id): string
    {
        return $this->releasesPath().'/'.$id;
    }

    /** A web release is complete once it is in place, because install moves it there in one rename. */
    private function installed(string $id): bool
    {
        $path = $this->releasePath($id);

        return is_dir($path) && ! is_link($path) && is_file($path.'/index.html') && ! is_link($path.'/index.html');
    }

    private function currentTarget(): ?string
    {
        $current = $this->webRoot.'/current';

        if (! is_link($current)) {
            return null;
        }

        $target = readlink($current);

        return is_string($target) ? $target : null;
    }

    private function assertDirectory(): void
    {
        if (! is_dir($this->releasesPath()) || is_link($this->releasesPath()) || is_link($this->webRoot)) {
            throw new GatewayReleaseException(
                step: 'web',
                errorCode: 'gateway.release_web_directory_missing',
                message: "The web directory [{$this->releasesPath()}] is missing. Run php artisan orbit:gateway-web.",
                status: 500,
            );
        }
    }

    private function repository(): GitHubRepository
    {
        $origin = $this->processes->run(new ProcessInvocation(
            ['git', '-C', $this->layout->repositoryPath(), 'remote', 'get-url', 'origin'],
            timeout: 30.0,
        ));
        $repository = $origin->succeeded() ? GitHubRepository::fromOrigin(trim($origin->stdout)) : null;

        if (! $repository instanceof GitHubRepository) {
            throw new GatewayReleaseException(
                step: 'web',
                errorCode: 'gateway.release_web_build_unavailable',
                message: 'The shared release repository has no github.com origin, so its web build cannot be found.',
                status: 500,
            );
        }

        return $repository;
    }

    /**
     * Finds the newest unexpired `web-dist-<sha>` artifact from a push run of this repository for
     * exactly this commit, downloads it, and checks its digest.
     */
    private function downloadArtifact(GitHubRepository $repository, string $sha, string $archive): void
    {
        $name = self::artifactName($sha);
        $path = GitHubActionsReader::repositoryPath($repository);

        try {
            $token = $this->actions->token($repository);
            $listing = $this->actions->json($path.'/actions/artifacts?'.http_build_query(['name' => $name, 'per_page' => 100]), $token);
            $rows = $listing['artifacts'] ?? null;

            if (! is_array($rows) || ! is_int($listing['total_count'] ?? null)) {
                throw new GitHubActionsUnavailable;
            }

            $expired = false;

            foreach ($this->newestFirst($rows) as $artifact) {
                if (($artifact['name'] ?? null) !== $name) {
                    continue;
                }

                if (($artifact['expired'] ?? null) !== false) {
                    $expired = true;

                    continue;
                }

                $id = $artifact['id'] ?? null;
                $size = $artifact['size_in_bytes'] ?? null;
                $run = $artifact['workflow_run'] ?? null;

                if (! is_int($id) || $id < 1 || ! is_int($size) || ! is_array($run)
                    || ($run['head_sha'] ?? null) !== $sha || ! is_int($run['id'] ?? null)
                    || ! is_int($run['repository_id'] ?? null) || ($run['head_repository_id'] ?? null) !== $run['repository_id']
                    || ! $this->pushRun($path, $run['id'], $sha, $run['repository_id'], $token)) {
                    continue;
                }

                if ($size < 1 || $size > self::MaxArchiveBytes) {
                    throw $this->invalid("The web build artifact [{$name}] has {$size} bytes; at most ".self::MaxArchiveBytes.' are accepted.');
                }

                $digest = $this->actions->download($this->actions->archiveLocation($repository, $id, $token), $archive, self::MaxArchiveBytes);
                $expected = $artifact['digest'] ?? null;

                if (is_string($expected) && ! hash_equals(strtolower($expected), 'sha256:'.$digest)) {
                    throw $this->invalid("The downloaded web build [{$name}] does not match its artifact digest.");
                }

                return;
            }
        } catch (GitHubActionsUnavailable) {
            throw new GatewayReleaseException(
                step: 'web',
                errorCode: 'gateway.release_web_build_unavailable',
                message: 'Cannot read the web build from GitHub Actions. Install the Gateway GitHub App on the repository with Actions read permission.',
                status: 500,
                sha: $sha,
            );
        }

        throw new GatewayReleaseException(
            step: 'web',
            errorCode: 'gateway.release_web_build_missing',
            message: $expired
                ? "The CI artifact [{$name}] has expired. Release a newer commit."
                : "CI published no artifact [{$name}] for a push of this commit. Release a commit whose CI run uploaded its web build.",
            status: 422,
            sha: $sha,
        );
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return list<array<array-key, mixed>>
     */
    private function newestFirst(array $rows): array
    {
        $artifacts = array_values(array_filter($rows, is_array(...)));
        usort($artifacts, static fn (array $left, array $right): int => [(string) ($right['created_at'] ?? ''), $right['id'] ?? 0] <=> [(string) ($left['created_at'] ?? ''), $left['id'] ?? 0]);

        return $artifacts;
    }

    /**
     * Only a push run of this repository for this commit counts. A pull request from a fork runs in
     * this repository too, but its run has another head repository or another event.
     */
    private function pushRun(string $path, int $runId, string $sha, int $repositoryId, string $token): bool
    {
        $run = $this->actions->json($path.'/actions/runs/'.$runId, $token);
        $repository = $run['repository'] ?? null;
        $head = $run['head_repository'] ?? null;

        return ($run['id'] ?? null) === $runId
            && ($run['event'] ?? null) === 'push'
            && ($run['head_sha'] ?? null) === $sha
            && is_array($repository) && ($repository['id'] ?? null) === $repositoryId
            && is_array($head) && ($head['id'] ?? null) === $repositoryId;
    }

    /**
     * Extracts the archive into a new directory. Every entry must be a regular file or a directory
     * with a plain relative name. Links, special files, absolute names, `..`, duplicates, encrypted
     * entries, and sizes or checksums that do not match their header are refused, and so are
     * archives above the entry and size limits.
     */
    private function extract(string $archive, string $staging): void
    {
        $zip = new ZipArchive;

        if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
            throw $this->invalid('The web build artifact is not a ZIP archive.');
        }

        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::MaxEntries) {
                throw $this->invalid("The web build artifact has {$zip->numFiles} entries; 1 to ".self::MaxEntries.' are accepted.');
            }

            if (! @mkdir($staging, 0700)) {
                throw $this->installFailed("The web build cannot be staged in [{$staging}].");
            }

            $seen = [];
            $expanded = 0;

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);

                if ($stat === false) {
                    throw $this->invalid('The web build artifact has an unreadable entry.');
                }

                $name = $stat['name'];
                $directory = str_ends_with($name, '/');
                $relative = $directory ? substr($name, 0, -1) : $name;
                $this->assertEntry($zip, $index, $relative, $directory, $stat['encryption_method']);

                if (isset($seen[$relative])) {
                    throw $this->invalid("The web build artifact holds [{$relative}] twice.");
                }

                $seen[$relative] = true;
                $expanded += $stat['size'];

                if ($expanded > self::MaxExpandedBytes) {
                    throw $this->invalid('The web build expands to more than '.self::MaxExpandedBytes.' bytes.');
                }

                $target = $staging.'/'.$relative;

                if ($directory) {
                    $this->directory($target);

                    continue;
                }

                $this->directory(dirname($target));
                $this->file($zip, $index, $target, $stat['size'], $stat['crc']);
            }
        } finally {
            $zip->close();
        }
    }

    private function assertEntry(ZipArchive $zip, int $index, string $relative, bool $directory, int $encryption): void
    {
        $segments = explode('/', $relative);

        if ($relative === '' || strlen($relative) > 1024 || str_starts_with($relative, '/') || str_contains($relative, '\\')
            || preg_match('/[\x00-\x1f\x7f]/', $relative) === 1
            || array_filter($segments, static fn (string $segment): bool => in_array($segment, ['', '.', '..'], true) || strlen($segment) > 255) !== []) {
            throw $this->invalid('The web build artifact has an unsafe entry name.');
        }

        $system = 0;
        $attributes = 0;

        if (! $zip->getExternalAttributesIndex($index, $system, $attributes)) {
            throw $this->invalid("The web build entry [{$relative}] has unreadable attributes.");
        }

        $type = $system === ZipArchive::OPSYS_UNIX ? ($attributes >> 16) & self::UnixFileType : 0;
        $expected = $directory ? self::UnixDirectory : self::UnixRegularFile;

        if (($type !== 0 && $type !== $expected) || $encryption !== ZipArchive::EM_NONE) {
            throw $this->invalid("The web build entry [{$relative}] is not a plain file or directory.");
        }
    }

    private function directory(string $path): void
    {
        if (is_dir($path) && ! is_link($path)) {
            return;
        }

        if (! @mkdir($path, 0700, true) && ! (is_dir($path) && ! is_link($path))) {
            throw $this->invalid('The web build artifact puts a directory where a file is.');
        }
    }

    private function file(ZipArchive $zip, int $index, string $target, int $size, int $crc): void
    {
        $source = $zip->getStreamIndex($index);
        $handle = @fopen($target, 'xb');

        if ($source === false || $handle === false) {
            if (is_resource($source)) {
                fclose($source);
            }

            throw $this->invalid('The web build artifact holds an entry twice or cannot be read.');
        }

        $hash = hash_init('crc32b');
        $written = 0;

        try {
            while (! feof($source)) {
                $chunk = fread($source, 65536);

                if ($chunk === false) {
                    throw $this->invalid('The web build artifact cannot be read.');
                }

                $written += strlen($chunk);

                if ($written > $size) {
                    throw $this->invalid('A web build entry is larger than its header says.');
                }

                hash_update($hash, $chunk);

                if (fwrite($handle, $chunk) !== strlen($chunk)) {
                    throw $this->installFailed("The web build file [{$target}] cannot be written.");
                }
            }
        } finally {
            fclose($source);
            fclose($handle);
        }

        if ($written !== $size || hexdec(hash_final($hash)) !== $crc) {
            throw $this->invalid('A web build entry does not match its size or checksum.');
        }
    }

    /**
     * Gives the Caddy group read access, as `bin/web-deploy` does: directories `0750`, files `0640`,
     * group `caddy`. Every path was created by extraction, so none is a link.
     */
    private function grantCaddy(string $staging): void
    {
        $paths = [$staging];
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($staging, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($entries as $entry) {
            if ($entry instanceof \SplFileInfo) {
                $paths[] = $entry->getPathname();
            }
        }

        foreach ($paths as $path) {
            if (is_link($path) || ! @chgrp($path, $this->group) || ! @chmod($path, is_dir($path) ? 0750 : 0640)) {
                throw $this->installFailed("The web build path [{$path}] cannot be given to group [{$this->group}].");
            }
        }
    }

    private function moveIntoPlace(string $staging, string $target): void
    {
        $this->removePath($target);

        if (! @rename($staging, $target)) {
            throw $this->installFailed("The web build cannot be moved to [{$target}].");
        }

        @touch($target);
    }

    private function switchTo(string $target): void
    {
        $current = $this->webRoot.'/current';
        $next = $current.'.next';

        if (file_exists($next) || is_link($next)) {
            @unlink($next);
        }

        if (! @symlink($target, $next)) {
            throw $this->publishFailed("The next web link [{$next}] cannot be created.");
        }

        $result = $this->processes->run(new ProcessInvocation(['mv', '-Tf', '--', $next, $current], timeout: 30.0));

        if (! $result->succeeded()) {
            @unlink($next);

            throw $this->publishFailed("The web app link [{$current}] cannot be switched to [{$target}].");
        }
    }

    private function removePath(string $path): void
    {
        if (file_exists($path) || is_link($path)) {
            $this->processes->run(new ProcessInvocation(['rm', '-rf', '--', $path], timeout: 120.0));
        }
    }

    private function invalid(string $message): GatewayReleaseException
    {
        return new GatewayReleaseException(step: 'web', errorCode: 'gateway.release_web_build_invalid', message: $message, status: 422);
    }

    private function installFailed(string $message): GatewayReleaseException
    {
        return new GatewayReleaseException(step: 'web', errorCode: 'gateway.release_web_install_failed', message: $message, status: 500);
    }

    private function publishFailed(string $message): GatewayReleaseException
    {
        return new GatewayReleaseException(step: 'web', errorCode: 'gateway.release_web_publish_failed', message: $message, status: 500);
    }
}
