<?php

declare(strict_types=1);

namespace App\Infrastructure\GitHub;

use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandDeadline;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

/**
 * Reads GitHub Actions runs and artifacts of one repository with an installation token of the
 * Gateway GitHub App that holds only Actions read. Artifact archives are downloaded from the
 * storage URL GitHub redirects to, without the token. Every failure is a
 * {@see GitHubActionsUnavailable} without detail, so no token or signed URL reaches an error.
 */
final readonly class GitHubActionsReader
{
    public const string Api = 'https://api.github.com';

    /** Responses of the JSON endpoints are read up to this size. */
    private const int JsonBytes = 1024 * 1024;

    public function __construct(private GitHubAppStore $apps, private CommandDeadline $deadline) {}

    /** The API path of a repository, `/repos/<owner>/<name>`. */
    public static function repositoryPath(GitHubRepository $repository): string
    {
        return '/repos/'.rawurlencode($repository->owner).'/'.rawurlencode($repository->name);
    }

    /**
     * A token for this one repository that GitHub confirms holds Actions read.
     *
     * @throws GitHubActionsUnavailable
     */
    public function token(GitHubRepository $repository): string
    {
        $credentials = $this->apps->credentials();

        if ($credentials === null) {
            throw new GitHubActionsUnavailable;
        }

        try {
            $jwt = GitHubAppJwt::sign($credentials, now()->getTimestamp());
        } catch (GitHubApiException) {
            throw new GitHubActionsUnavailable;
        }

        $installation = $this->json(self::repositoryPath($repository).'/installation', $jwt);
        $id = $installation['id'] ?? null;

        if (! is_int($id) || $id < 1) {
            throw new GitHubActionsUnavailable;
        }

        $access = $this->json('/app/installations/'.$id.'/access_tokens', $jwt, [
            'repositories' => [$repository->name], 'permissions' => ['actions' => 'read'],
        ]);
        $token = $access['token'] ?? null;
        $permissions = $access['permissions'] ?? null;

        if (! is_string($token) || $token === '' || ! is_array($permissions) || ($permissions['actions'] ?? null) !== 'read') {
            throw new GitHubActionsUnavailable;
        }

        return $token;
    }

    /**
     * One JSON object from an API path such as `/repos/acme/shop/actions/runs/9`.
     *
     * @param  array<string, mixed>|null  $payload  Sent as a POST body when given.
     * @return array<array-key, mixed>
     *
     * @throws GitHubActionsUnavailable
     */
    public function json(string $path, #[SensitiveParameter] string $token, ?array $payload = null): array
    {
        $data = json_decode($this->body(self::Api.$path, $token, self::JsonBytes, $payload), true);

        if (! is_array($data)) {
            throw new GitHubActionsUnavailable;
        }

        return $data;
    }

    /**
     * The storage URL an artifact archive redirects to. Only an HTTPS URL on GitHub's artifact
     * storage hosts, without credentials or a port, is accepted.
     *
     * @throws GitHubActionsUnavailable
     */
    public function archiveLocation(GitHubRepository $repository, int $artifactId, #[SensitiveParameter] string $token): string
    {
        $url = self::Api.self::repositoryPath($repository).'/actions/artifacts/'.$artifactId.'/zip';

        try {
            $response = Http::withToken($token)->withoutRedirecting()->timeout($this->deadline->cap(10))
                ->connectTimeout(5)->withOptions(['stream' => true])->get($url);
            $location = $response->header('Location');
            $response->toPsrResponse()->getBody()->close();
        } catch (ResourceOperationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new GitHubActionsUnavailable;
        }

        $parts = parse_url($location);
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';

        if ($response->status() !== 302 || ! is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])
            || preg_match('/\A[a-z0-9-]+(?:\.[a-z0-9-]+)*\.(?:blob\.core\.windows\.net|githubusercontent\.com)\z/D', $host) !== 1) {
            throw new GitHubActionsUnavailable;
        }

        return $location;
    }

    /**
     * Reads a response body up to `$limit` bytes. The token is sent only when given, so an artifact
     * storage URL never receives it.
     *
     * @param  array<string, mixed>|null  $payload
     *
     * @throws GitHubActionsUnavailable
     */
    public function body(
        string $url,
        #[SensitiveParameter] ?string $token,
        int $limit,
        ?array $payload = null,
        float $timeout = 10.0,
    ): string {
        $body = '';
        $this->stream($url, $token, $limit, $payload, $timeout, static function (string $chunk) use (&$body): void {
            $body .= $chunk;
        });

        return $body;
    }

    /**
     * Downloads a response body into a file that the caller created, up to `$limit` bytes.
     *
     * @return string the SHA-256 of the written bytes, in hex
     *
     * @throws GitHubActionsUnavailable
     */
    public function download(string $url, string $file, int $limit, float $timeout = 120.0): string
    {
        $handle = @fopen($file, 'wb');

        if ($handle === false) {
            throw new GitHubActionsUnavailable;
        }

        $hash = hash_init('sha256');

        try {
            $this->stream($url, null, $limit, null, $timeout, static function (string $chunk) use ($handle, $hash): void {
                hash_update($hash, $chunk);

                if (@fwrite($handle, $chunk) !== strlen($chunk)) {
                    throw new GitHubActionsUnavailable;
                }
            });
        } finally {
            fclose($handle);
        }

        return hash_final($hash);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @param  callable(string): void  $chunk
     */
    private function stream(
        string $url,
        #[SensitiveParameter] ?string $token,
        int $limit,
        ?array $payload,
        float $timeout,
        callable $chunk,
    ): void {
        $stream = null;

        try {
            $request = Http::withoutRedirecting()->timeout($this->deadline->cap($timeout))->connectTimeout(5)
                ->withOptions(['stream' => true])->withHeaders(['Accept' => 'application/vnd.github+json']);

            if ($token !== null) {
                $request = $request->withToken($token);
            }

            $response = $payload === null ? $request->get($url) : $request->post($url, $payload);
            $stream = $response->toPsrResponse()->getBody();

            if (! $response->successful()) {
                throw new GitHubActionsUnavailable;
            }

            $read = 0;

            while (! $stream->eof()) {
                $this->deadline->cap($timeout);
                $data = $stream->read(min(65536, $limit - $read + 1));
                $read += strlen($data);

                if ($read > $limit) {
                    throw new GitHubActionsUnavailable;
                }

                $chunk($data);
            }
        } catch (ResourceOperationException|GitHubActionsUnavailable $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new GitHubActionsUnavailable;
        } finally {
            $stream?->close();
        }
    }
}
