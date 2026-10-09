<?php

declare(strict_types=1);

use App\Actions\Projects\CreateProjectAction;
use App\Data\Projects\CreateProjectData;
use App\Domain\Projects\ProjectType;
use App\Models\Project;

it('rejects an unsafe repository origin before app persistence', function (): void {
    $sentinel = 'sentinel-action-password';
    $data = new CreateProjectData(
        name: 'Acme',
        slug: 'acme',
        type: ProjectType::LaravelApp,
        repositoryUrl: "ssh://git:{$sentinel}@example.test/acme/site.git",
        defaultBranch: 'main',
        apps: fixture_apps('public'),
    );

    expect(fn (): array => app(CreateProjectAction::class)->execute($data))
        ->toThrow(InvalidArgumentException::class, 'The Git repository origin is invalid.');
    expect(Project::query()->exists())->toBeFalse();
});
