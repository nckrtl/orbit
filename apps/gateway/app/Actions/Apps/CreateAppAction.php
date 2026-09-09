<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Data\Apps\CreateAppData;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Domain\SourceControl\RelativeWebRoot;
use App\Domain\SourceControl\RepositoryDefaultBranchResolver;
use App\Models\App as OrbitApp;
use Illuminate\Database\UniqueConstraintViolationException;

final readonly class CreateAppAction
{
    public function __construct(
        private RepositoryDefaultBranchResolver $branches,
    ) {}

    /** @return array{app: OrbitApp, created: bool} */
    public function execute(CreateAppData $data): array
    {
        $repositoryUrl = GitRepositoryOrigin::validate($data->repositoryUrl);
        $repositoryIdentity = GitRepositoryIdentity::derive($repositoryUrl);
        $defaultBranch = $data->defaultBranch === null ? null : GitBranchName::validate($data->defaultBranch);
        $root = RelativeWebRoot::validate($data->root);
        $app = OrbitApp::query()->where('slug', $data->slug)->first();

        if ($app instanceof OrbitApp) {
            $this->assertIdentityMatches($app, $data, $repositoryUrl, $defaultBranch, $root);

            return ['app' => $app, 'created' => false];
        }

        $this->assertRepositoryIdentityAvailable($repositoryIdentity);

        $requestedDefaultBranch = $defaultBranch;

        if ($defaultBranch === null) {
            $defaultBranch = GitBranchName::validate($this->branches->resolve($repositoryUrl));
        } else {
            $this->branches->verify($repositoryUrl, $defaultBranch);
        }

        try {
            $app = OrbitApp::query()->create([
                'slug' => $data->slug,
                'name' => $data->name,
                'repository_url' => $repositoryUrl,
                'default_branch' => $defaultBranch,
                'root' => $root,
                'defaults' => $data->defaults,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            $app = OrbitApp::query()->where('slug', $data->slug)->first();

            if ($app instanceof OrbitApp) {
                $this->assertIdentityMatches(
                    $app,
                    $data,
                    $repositoryUrl,
                    $requestedDefaultBranch,
                    $root,
                );

                return ['app' => $app, 'created' => false];
            }

            if (OrbitApp::query()->where('repository_identity', $repositoryIdentity)->exists()) {
                throw $this->repositoryIdentityConflict($exception);
            }

            throw $exception;
        }

        return ['app' => $app->refresh(), 'created' => true];
    }

    private function assertRepositoryIdentityAvailable(string $repositoryIdentity): void
    {
        if (OrbitApp::query()->where('repository_identity', $repositoryIdentity)->exists()) {
            throw $this->repositoryIdentityConflict();
        }
    }

    private function repositoryIdentityConflict(
        ?UniqueConstraintViolationException $previous = null,
    ): ResourceOperationException {
        return new ResourceOperationException(
            errorCode: 'app.repository_identity_conflict',
            message: 'The repository is already owned by another App.',
            status: 409,
            previous: $previous,
        );
    }

    private function assertIdentityMatches(
        OrbitApp $app,
        CreateAppData $data,
        string $repositoryUrl,
        ?string $defaultBranch,
        string $root,
    ): void {
        if (
            $app->name === $data->name
            && $app->repository_url === $repositoryUrl
            && ($defaultBranch === null
            || $app->default_branch === $defaultBranch)
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
