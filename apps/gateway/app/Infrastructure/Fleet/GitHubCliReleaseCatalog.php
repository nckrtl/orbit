<?php

declare(strict_types=1);

namespace App\Infrastructure\Fleet;

use App\Data\Fleet\DesiredCliReleaseData;
use App\Data\Fleet\FleetReleaseAssetData;
use App\Domain\Fleet\CliReleaseCatalog;
use App\Domain\Fleet\CliReleaseName;
use App\Domain\Fleet\CliReleaseUnavailableReason;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubRepository;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

/**
 * Reads a published CLI release from GitHub. The tag must point at the commit, the release must be published
 * with every binary and `SHA256SUMS`, and `SHA256SUMS` must name each binary once. It asks GitHub with a
 * read-only token of the Gateway's GitHub App when the App is installed on the repository, and anonymously
 * otherwise: the repository and its releases are public.
 */
final readonly class GitHubCliReleaseCatalog implements CliReleaseCatalog
{
    private const string Api = 'https://api.github.com';

    private const int ChecksumsMaxBytes = 65536;

    private const int JsonMaxBytes = 1024 * 1024;

    public function __construct(
        private GitHubAppStore $apps,
        private GitHubApi $github,
        private string $repositoryOrigin,
    ) {}

    public function find(string $commit, CliReleaseName $release): DesiredCliReleaseData
    {
        $repository = GitHubRepository::fromOrigin($this->repositoryOrigin);

        if (! $repository instanceof GitHubRepository) {
            return DesiredCliReleaseData::unavailable(CliReleaseUnavailableReason::GitHubUnavailable);
        }

        try {
            return $this->read($repository, $commit, $release, $this->token($repository));
        } catch (CliReleaseLookupFailed $failure) {
            return $failure->reason === CliReleaseUnavailableReason::ReleaseMissing
                ? DesiredCliReleaseData::pending($release, $commit)
                : DesiredCliReleaseData::unavailable($failure->reason);
        } catch (Throwable) {
            return DesiredCliReleaseData::unavailable(CliReleaseUnavailableReason::GitHubUnavailable);
        }
    }

    private function read(GitHubRepository $repository, string $commit, CliReleaseName $release, #[SensitiveParameter] ?string $token): DesiredCliReleaseData
    {
        $path = self::Api.'/repos/'.rawurlencode($repository->owner).'/'.rawurlencode($repository->name);

        if ($this->taggedCommit($path, $release->tag(), $token) !== $commit) {
            throw new CliReleaseLookupFailed(CliReleaseUnavailableReason::ReleaseMismatch);
        }

        $published = $this->json($path.'/releases/tags/'.rawurlencode($release->tag()), $token);
        $rows = $published['assets'] ?? null;

        if (($published['draft'] ?? null) !== false || ($published['tag_name'] ?? null) !== $release->tag() || ! is_array($rows)) {
            throw new CliReleaseLookupFailed(CliReleaseUnavailableReason::ReleaseIncomplete);
        }

        $urls = $this->assetUrls($rows, $repository, $release);
        $checksums = $this->checksums($this->download($urls[CliReleaseName::ChecksumsAsset]));
        $assets = [];

        foreach (CliReleaseName::Platforms as $platform) {
            $name = $release->assetName($platform);
            $assets[] = new FleetReleaseAssetData($platform, $name, $urls[$name], $checksums[$name] ?? throw new CliReleaseLookupFailed(CliReleaseUnavailableReason::ReleaseIncomplete));
        }

        return DesiredCliReleaseData::available($release, $commit, $urls[CliReleaseName::ChecksumsAsset], $assets);
    }

    /** The commit a tag points at, through an annotated tag object when the tag has one. */
    private function taggedCommit(string $path, string $tag, #[SensitiveParameter] ?string $token): string
    {
        $reference = $this->json($path.'/git/ref/tags/'.rawurlencode($tag), $token);
        $object = $reference['object'] ?? null;
        $type = is_array($object) ? ($object['type'] ?? null) : null;
        $sha = is_array($object) ? ($object['sha'] ?? null) : null;

        if ($type === 'tag' && is_string($sha) && preg_match('/\A[0-9a-f]{40}\z/D', $sha) === 1) {
            $annotated = $this->json($path.'/git/tags/'.$sha, $token)['object'] ?? null;
            $type = is_array($annotated) ? ($annotated['type'] ?? null) : null;
            $sha = is_array($annotated) ? ($annotated['sha'] ?? null) : null;
        }

        if ($type !== 'commit' || ! is_string($sha) || preg_match('/\A[0-9a-f]{40}\z/D', $sha) !== 1) {
            throw new CliReleaseLookupFailed(CliReleaseUnavailableReason::GitHubUnavailable);
        }

        return $sha;
    }

    /**
     * The download URL of each expected asset. Every binary and `SHA256SUMS` must be uploaded and served from
     * the repository's release download path.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, string>
     */
    private function assetUrls(array $rows, GitHubRepository $repository, CliReleaseName $release): array
    {
        $expected = [CliReleaseName::ChecksumsAsset, ...array_map($release->assetName(...), CliReleaseName::Platforms)];
        $prefix = 'https://github.com/'.$repository->owner.'/'.$repository->name.'/releases/download/'.$release->tag().'/';
        $urls = [];

        foreach ($rows as $row) {
            $name = is_array($row) ? ($row['name'] ?? null) : null;

            if (! is_string($name) || ! in_array($name, $expected, true)) {
                continue;
            }

            $url = $row['browser_download_url'] ?? null;

            if (($row['state'] ?? null) !== 'uploaded' || ! is_string($url) || strcasecmp($url, $prefix.$name) !== 0) {
                throw new CliReleaseLookupFailed(CliReleaseUnavailableReason::ReleaseIncomplete);
            }

            $urls[$name] = $url;
        }

        if (count($urls) !== count($expected)) {
            throw new CliReleaseLookupFailed(CliReleaseUnavailableReason::ReleaseIncomplete);
        }

        return $urls;
    }

    /**
     * Parses `SHA256SUMS` in `sha256sum` format: a lowercase digest, two spaces, and the asset name, one line each.
     *
     * @return array<string, string>
     */
    private function checksums(string $contents): array
    {
        $checksums = [];

        foreach (explode("\n", rtrim($contents, "\n")) as $line) {
            if (preg_match('/\A([0-9a-f]{64})  ([A-Za-z0-9._-]+)\z/D', $line, $matches) !== 1 || isset($checksums[$matches[2]])) {
                throw new CliReleaseLookupFailed(CliReleaseUnavailableReason::ReleaseIncomplete);
            }

            $checksums[$matches[2]] = $matches[1];
        }

        return $checksums;
    }

    /** A read-only token of the Gateway's GitHub App, or null to ask GitHub anonymously. */
    private function token(GitHubRepository $repository): ?string
    {
        $credentials = $this->apps->credentials();

        if ($credentials === null) {
            return null;
        }

        try {
            $installation = $this->github->repositoryInstallation($credentials, $repository);

            return $installation === null ? null : $this->github->repositoryReadToken($credentials, $installation, $repository);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<array-key, mixed> */
    private function json(string $url, #[SensitiveParameter] ?string $token): array
    {
        $response = $this->get($url, $token, ['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28']);

        if ($response->status() === 404) {
            throw new CliReleaseLookupFailed(CliReleaseUnavailableReason::ReleaseMissing);
        }

        $data = $response->successful() && strlen($response->body()) <= self::JsonMaxBytes ? json_decode($response->body(), true) : null;

        if (! is_array($data)) {
            throw new CliReleaseLookupFailed(CliReleaseUnavailableReason::GitHubUnavailable);
        }

        return $data;
    }

    /** Downloads a public release asset without the App token, because GitHub redirects it to another host. */
    private function download(string $url): string
    {
        $response = $this->get($url, null, ['Accept' => 'application/octet-stream']);

        if ($response->status() === 404) {
            throw new CliReleaseLookupFailed(CliReleaseUnavailableReason::ReleaseIncomplete);
        }

        if (! $response->successful()) {
            throw new CliReleaseLookupFailed(CliReleaseUnavailableReason::GitHubUnavailable);
        }

        $body = $response->body();

        if ($body === '' || strlen($body) > self::ChecksumsMaxBytes) {
            throw new CliReleaseLookupFailed(CliReleaseUnavailableReason::ReleaseIncomplete);
        }

        return $body;
    }

    /** @param  array<string, string>  $headers */
    private function get(string $url, #[SensitiveParameter] ?string $token, array $headers): Response
    {
        $request = Http::timeout(10)->connectTimeout(5)->withHeaders($headers);

        if ($token !== null) {
            $request = $request->withToken($token);
        }

        return $request->get($url);
    }
}
