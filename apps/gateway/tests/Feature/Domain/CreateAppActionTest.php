<?php

declare(strict_types=1);

use App\Actions\Apps\CreateAppAction;
use App\Data\Apps\CreateAppData;
use App\Domain\Projects\ProjectType;
use App\Models\Project;

it('rejects an unsafe repository origin before app persistence', function (): void {
    $sentinel = 'sentinel-action-password';
    $data = new CreateAppData(
        name: 'Acme',
        slug: 'acme',
        type: ProjectType::LaravelApp,
        repositoryUrl: "ssh://git:{$sentinel}@example.test/acme/site.git",
        defaultBranch: 'main',
        root: 'public',
    );

    expect(fn (): array => app(CreateAppAction::class)->execute($data))
        ->toThrow(InvalidArgumentException::class, 'The Git repository origin is invalid.');
    expect(Project::query()->exists())->toBeFalse();
});
