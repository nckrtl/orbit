<?php

declare(strict_types=1);

namespace App\Infrastructure\GitHub;

use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Projects\TiaBaselineFiles;
use App\Domain\Projects\TiaBaselineSource;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Project;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

final readonly class HttpGitHubTiaBaseline implements TiaBaselineSource
{
    private const string Api = 'https://api.github.com';

    public function __construct(private GitHubAppStore $apps, private CommandDeadline $deadline) {}

    public function fetch(Project $project): TiaBaselineFiles
    {
        $repository = GitHubRepository::fromOrigin($project->repository_url);
        $credentials = $this->apps->credentials();
        if ($repository === null || $credentials === null) {
            throw $this->unavailable();
        }
        $path = '/repos/'.rawurlencode($repository->owner).'/'.rawurlencode($repository->name);
        try {
            $jwt = GitHubAppJwt::sign($credentials, now()->getTimestamp());
        } catch (GitHubApiException) {
            throw $this->unavailable();
        }
        $installation = $this->json(self::Api.$path.'/installation', $jwt);
        $id = $installation['id'] ?? null;
        if (! is_int($id) || $id < 1) {
            throw $this->unavailable();
        }
        $access = $this->json(self::Api.'/app/installations/'.$id.'/access_tokens', $jwt, [
            'repositories' => [$repository->name], 'permissions' => ['actions' => 'read'],
        ]);
        $token = $access['token'] ?? null;
        $permissions = $access['permissions'] ?? null;
        if (! is_string($token) || $token === '' || ! is_array($permissions) || ($permissions['actions'] ?? null) !== 'read') {
            throw $this->unavailable();
        }
        $metadata = $this->json(self::Api.$path, $token);
        $branch = $metadata['default_branch'] ?? null;
        if (! is_string($branch) || $branch === '' || strlen($branch) > 255) {
            throw $this->unavailable();
        }
        $runs = $this->json(self::Api.$path.'/actions/workflows/tia-baseline.yml/runs?'.http_build_query([
            'branch' => $branch, 'status' => 'success', 'per_page' => 100,
        ]), $token)['workflow_runs'] ?? null;
        if (! is_array($runs) || count($runs) > 100) {
            throw $this->unavailable();
        }
        foreach ($runs as $run) {
            if (! is_array($run) || ($run['head_branch'] ?? null) !== $branch
                || ($run['status'] ?? null) !== 'completed' || ($run['conclusion'] ?? null) !== 'success'
                || ! in_array($run['event'] ?? null, ['push', 'workflow_dispatch'], true)
                || ! is_int($run['id'] ?? null) || $run['id'] < 1
                || ! is_string($run['head_sha'] ?? null) || preg_match('/\A[0-9a-f]{40}\z/D', $run['head_sha']) !== 1) {
                continue;
            }
            $artifacts = $this->json(self::Api.$path.'/actions/runs/'.$run['id'].'/artifacts?per_page=100', $token);
            $rows = $artifacts['artifacts'] ?? null;
            if (! is_array($rows) || ! is_int($artifacts['total_count'] ?? null) || $artifacts['total_count'] > 100) {
                throw $this->unavailable();
            }
            foreach ($rows as $artifact) {
                if (! is_array($artifact) || ($artifact['name'] ?? null) !== 'pest-tia-baseline'
                    || ($artifact['expired'] ?? null) !== false) {
                    continue;
                }
                $provenance = $artifact['workflow_run'] ?? null;
                if (! is_array($provenance) || ! is_int($artifact['id'] ?? null) || $artifact['id'] < 1
                    || ! is_int($artifact['size_in_bytes'] ?? null) || $artifact['size_in_bytes'] < 1
                    || $artifact['size_in_bytes'] > TiaBaselineFiles::MaxBytes
                    || ($provenance['id'] ?? null) !== $run['id']
                    || ($provenance['head_sha'] ?? null) !== $run['head_sha']) {
                    throw $this->unavailable();
                }
                $location = $this->redirect(self::Api.$path.'/actions/artifacts/'.$artifact['id'].'/zip', $token);

                return TiaBaselineFiles::fromArchive($this->body($location, null, TiaBaselineFiles::MaxBytes), $branch, $run['head_sha']);
            }
        }
        throw new ResourceOperationException('instance.tia_baseline_missing', 'No successful default-branch TIA baseline is available.', 422);
    }

    /** @param array<string, mixed>|null $payload
     * @return array<array-key, mixed>
     */
    private function json(string $url, #[SensitiveParameter] string $token, ?array $payload = null): array
    {
        $data = json_decode($this->body($url, $token, 1024 * 1024, $payload), true);
        if (! is_array($data)) {
            throw $this->unavailable();
        }

        return $data;
    }

    private function redirect(string $url, #[SensitiveParameter] string $token): string
    {
        try {
            $response = Http::withToken($token)->withoutRedirecting()->timeout($this->deadline->cap(10))
                ->connectTimeout(5)->withOptions(['stream' => true])->get($url);
            $location = $response->header('Location');
            $response->toPsrResponse()->getBody()->close();
        } catch (ResourceOperationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw $this->unavailable();
        }
        $parts = parse_url($location);
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        if ($response->status() !== 302 || ! is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])
            || preg_match('/\A[a-z0-9-]+(?:\.[a-z0-9-]+)*\.(?:blob\.core\.windows\.net|githubusercontent\.com)\z/D', $host) !== 1) {
            throw $this->unavailable();
        }

        return $location;
    }

    /** @param array<string, mixed>|null $payload */
    private function body(string $url, #[SensitiveParameter] ?string $token, int $limit, ?array $payload = null): string
    {
        $stream = null;
        try {
            $request = Http::withoutRedirecting()->timeout($this->deadline->cap(10))->connectTimeout(5)
                ->withOptions(['stream' => true])->withHeaders(['Accept' => 'application/vnd.github+json']);
            if ($token !== null) {
                $request = $request->withToken($token);
            }
            $response = $payload === null ? $request->get($url) : $request->post($url, $payload);
            $stream = $response->toPsrResponse()->getBody();
            if (! $response->successful()) {
                throw $this->unavailable();
            }
            $body = '';
            while (! $stream->eof()) {
                $this->deadline->cap(10);
                $body .= $stream->read(min(65536, $limit - strlen($body) + 1));
                if (strlen($body) > $limit) {
                    throw $this->unavailable();
                }
            }

            return $body;
        } catch (ResourceOperationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw $this->unavailable();
        } finally {
            $stream?->close();
        }
    }

    private function unavailable(): ResourceOperationException
    {
        return new ResourceOperationException('instance.tia_baseline_unavailable', 'Cannot read the TIA baseline. Install the Gateway GitHub App on this repository and accept Actions read permission.', 422);
    }
}
