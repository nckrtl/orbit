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
        $mainBranch = $data->mainBranch === null ? null : GitBranchName::validate($data->mainBranch);
        $root = RelativeWebRoot::validate($data->root);
        $app = OrbitApp::query()->where('slug', $data->slug)->first();

        if ($app instanceof OrbitApp) {
            $this->assertIdentityMatches($app, $data, $repositoryUrl, $mainBranch, $root);

            return ['app' => $app, 'created' => false];
        }

        if ($mainBranch === null) {
            $mainBranch = GitBranchName::validate($this->branches->resolve($repositoryUrl));
        } else {
            $this->branches->verify($repositoryUrl, $mainBranch);
        }

        $app = OrbitApp::query()->create([
            'slug' => $data->slug,
            'name' => $data->name,
            'repository_url' => $repositoryUrl,
            'main_branch' => $mainBranch,
            'root' => $root,
            'defaults' => $data->defaults,
        ]);

        return ['app' => $app->refresh(), 'created' => true];
    }

    private function assertIdentityMatches(
        OrbitApp $app,
        CreateAppData $data,
        string $repositoryUrl,
        ?string $mainBranch,
        string $root,
    ): void {
        if (
            $app->name === $data->name
            && $app->repository_url === $repositoryUrl
            && ($mainBranch === null
            || $app->main_branch === $mainBranch)
            && $app->root === $root
            && $app->defaults === $data->defaults
        ) {
            return;
        }

        throw new ResourceOperationException(
            errorCode: 'app.identity_conflict',
            message: "App [{$app->slug}] already exists with different creation identity.",
            status: 409,
        );
    }

}
