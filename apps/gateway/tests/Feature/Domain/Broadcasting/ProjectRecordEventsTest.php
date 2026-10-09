<?php

declare(strict_types=1);

use App\Actions\Projects\CreateProjectAction;
use App\Actions\Projects\RemoveProjectAction;
use App\Actions\Projects\UpdateProjectAction;
use App\Data\Projects\CreateProjectData;
use App\Data\Projects\UpdateProjectData;
use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Projects\ProjectType;
use App\Models\Project;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->fakeRepositoryBranches();
});

describe('Project record events', function (): void {
    it('broadcasts project.created for a genuinely new Project', function (): void {
        Event::fake([RecordBroadcast::class]);

        $action = app(CreateProjectAction::class);
        $data = new CreateProjectData(
            slug: 'acme',
            name: 'Acme',
            type: ProjectType::LaravelApp,
            repositoryUrl: 'git@github.com:acme/site.git',
            defaultBranch: 'main',
            apps: fixture_apps('public'),
        );

        $result = $action->execute($data);
        expect($result['created'])->toBeTrue();

        Event::assertDispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => $event->type === RecordEventType::ProjectCreated
                && $event->id === $result['project']->id
                && $event->data['slug'] === 'acme',
        );
    });

    it('does not broadcast when creating a Project that already exists with identical identity', function (): void {
        $action = app(CreateProjectAction::class);
        $data = new CreateProjectData(
            slug: 'acme',
            name: 'Acme',
            type: ProjectType::LaravelApp,
            repositoryUrl: 'git@github.com:acme/site.git',
            defaultBranch: 'main',
            apps: fixture_apps('public'),
        );
        $action->execute($data);

        Event::fake([RecordBroadcast::class]);
        $result = $action->execute($data);

        expect($result['created'])->toBeFalse();
        Event::assertNotDispatched(RecordBroadcast::class);
    });

    it('broadcasts project.updated when a Project field changes', function (): void {
        $project = Project::query()->create([
            'slug' => 'acme',
            'name' => 'Acme',
            'repository_url' => 'git@github.com:acme/site.git',
            'default_branch' => 'main',
            'apps' => fixture_apps('public'),
        ]);

        Event::fake([RecordBroadcast::class]);

        $action = app(UpdateProjectAction::class);
        $result = $action->execute($project, new UpdateProjectData(
            typeProvided: false,
            type: null,
            slugProvided: true,
            slug: 'acme-renamed',
            repositoryUrlProvided: false,
            repositoryUrl: null,
            defaultBranchProvided: false,
            defaultBranch: null,
        ));

        Event::assertDispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => $event->type === RecordEventType::ProjectUpdated
                && $event->id === $result->id
                && $event->data['slug'] === 'acme-renamed',
        );
    });

    it('broadcasts project.deleted with a minimal snapshot when a Project is removed', function (): void {
        $project = Project::query()->create([
            'slug' => 'acme',
            'name' => 'Acme',
            'repository_url' => 'git@github.com:acme/site.git',
            'default_branch' => 'main',
            'apps' => fixture_apps('public'),
        ]);

        Event::fake([RecordBroadcast::class]);

        app(RemoveProjectAction::class)->execute($project);

        Event::assertDispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => $event->type === RecordEventType::ProjectDeleted
                && $event->id === $project->id
                && $event->data === ['id' => $project->id, 'name' => 'Acme', 'slug' => 'acme'],
        );
    });
});
