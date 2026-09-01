<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Data\Apps\CreateAppData;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Domain\SourceControl\RelativeWebRoot;
use App\Domain\SourceControl\RepositoryDefaultBranchResolver;
use App\Models\App as OrbitApp;

final readonly class CreateAppAction
{
    public function __construct(
        private RepositoryDefaultBranchResolver $branches,
    ) {}

    /** @return array{app: OrbitApp, created: bool} */
    public function execute(CreateAppData $data): array
    {
        $repositoryUrl = GitRepositoryOrigin::validate($data->repositoryUrl);
        $root = RelativeWebRoot::validate($data->root);
        $app = OrbitApp::query()->firstOrNew(['slug' => $data->slug]);
        $created = ! $app->exists;

        if ($app->exists && $app->repository_url !== $repositoryUrl) {
            throw new ResourceOperationException(
                errorCode: 'app.repository_change_unsupported',
                message: "App [{$app->slug}] cannot change its repository.",
                status: 409,
            );
        }

        if ($app->exists) {
            $this->assertSourceIdentity($app, $data, $root);
        }

        $mainBranch = $app->exists
            ? $app->main_branch
            : GitBranchName::validate($data->mainBranch ?? $this->branches->resolve($repositoryUrl));

        $app->fill([
            'name' => $data->name,
            'repository_url' => $repositoryUrl,
            'main_branch' => $mainBranch,
            'root' => $app->exists ? $app->root : $root,
            'defaults' => $data->defaults,
        ])->save();

        return ['app' => $app->refresh(), 'created' => $created];
    }

    private function assertSourceIdentity(OrbitApp $app, CreateAppData $data, string $root): void
    {
        if ($app->main_branch === null || $app->root === null) {
            return;
        }

        if ($data->mainBranch !== null && $app->main_branch !== GitBranchName::validate($data->mainBranch)) {
            throw $this->sourceChangeUnsupported($app);
        }

        if ($app->root !== $root) {
            throw $this->sourceChangeUnsupported($app);
        }
    }

    private function sourceChangeUnsupported(OrbitApp $app): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'app.source_defaults_change_unsupported',
            message: "App [{$app->slug}] cannot change its source defaults.",
            status: 409,
        );
    }
}
