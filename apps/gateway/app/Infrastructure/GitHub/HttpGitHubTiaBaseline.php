<?php

declare(strict_types=1);

namespace App\Infrastructure\GitHub;

use App\Domain\GitHub\GitHubRepository;
use App\Domain\Projects\TiaBaselineFiles;
use App\Domain\Projects\TiaBaselineSource;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Project;

final readonly class HttpGitHubTiaBaseline implements TiaBaselineSource
{
    public function __construct(private GitHubActionsReader $actions) {}

    public function fetch(Project $project): TiaBaselineFiles
    {
        $repository = GitHubRepository::fromOrigin($project->repository_url);
        if ($repository === null) {
            throw $this->unavailable();
        }

        try {
            return $this->download($repository);
        } catch (GitHubActionsUnavailable) {
            throw $this->unavailable();
        }
    }

    private function download(GitHubRepository $repository): TiaBaselineFiles
    {
        $path = GitHubActionsReader::repositoryPath($repository);
        $token = $this->actions->token($repository);
        $metadata = $this->actions->json($path, $token);
        $branch = $metadata['default_branch'] ?? null;
        if (! is_string($branch) || $branch === '' || strlen($branch) > 255) {
            throw $this->unavailable();
        }
        $runs = $this->actions->json($path.'/actions/workflows/tia-baseline.yml/runs?'.http_build_query([
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
            $artifacts = $this->actions->json($path.'/actions/runs/'.$run['id'].'/artifacts?per_page=100', $token);
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
                $location = $this->actions->archiveLocation($repository, $artifact['id'], $token);

                return TiaBaselineFiles::fromArchive($this->actions->body($location, null, TiaBaselineFiles::MaxBytes), $branch, $run['head_sha']);
            }
        }
        throw new ResourceOperationException('instance.tia_baseline_missing', 'No successful default-branch TIA baseline is available.', 422);
    }

    private function unavailable(): ResourceOperationException
    {
        return new ResourceOperationException('instance.tia_baseline_unavailable', 'Cannot read the TIA baseline. Install the Gateway GitHub App on this repository and accept Actions read permission.', 422);
    }
}
