<?php

declare(strict_types=1);

use App\Actions\Apps\CreateAppAction;
use App\Actions\Apps\RemoveAppAction;
use App\Actions\Apps\UpdateAppAction;
use App\Data\Apps\CreateAppData;
use App\Data\Apps\UpdateAppData;
use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use App\Models\App as OrbitApp;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->fakeRepositoryBranches();
});

describe('App record events', function (): void {
    it('broadcasts app.created for a genuinely new App', function (): void {
        Event::fake([RecordBroadcast::class]);

        $action = app(CreateAppAction::class);
        $data = new CreateAppData(
            slug: 'acme',
            name: 'Acme',
            repositoryUrl: 'git@github.com:acme/site.git',
            defaultBranch: 'main',
            root: 'public',
            defaults: null,
        );

        $result = $action->execute($data);
        expect($result['created'])->toBeTrue();

        Event::assertDispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => $event->type === RecordEventType::AppCreated
                && $event->id === $result['app']->id
                && $event->data['slug'] === 'acme',
        );
    });

    it('does not broadcast when creating an App that already exists with identical identity', function (): void {
        $action = app(CreateAppAction::class);
        $data = new CreateAppData(
            slug: 'acme',
            name: 'Acme',
            repositoryUrl: 'git@github.com:acme/site.git',
            defaultBranch: 'main',
            root: 'public',
            defaults: null,
        );
        $action->execute($data);

        Event::fake([RecordBroadcast::class]);
        $result = $action->execute($data);

        expect($result['created'])->toBeFalse();
        Event::assertNotDispatched(RecordBroadcast::class);
    });

    it('broadcasts app.updated when an App field changes', function (): void {
        $app = OrbitApp::query()->create([
            'slug' => 'acme',
            'name' => 'Acme',
            'repository_url' => 'git@github.com:acme/site.git',
            'default_branch' => 'main',
            'root' => 'public',
        ]);

        Event::fake([RecordBroadcast::class]);

        $action = app(UpdateAppAction::class);
        $result = $action->execute($app, new UpdateAppData(
            slugProvided: true,
            slug: 'acme-renamed',
            repositoryUrlProvided: false,
            repositoryUrl: null,
            defaultBranchProvided: false,
            defaultBranch: null,
            rootProvided: false,
            root: null,
        ));

        Event::assertDispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => $event->type === RecordEventType::AppUpdated
                && $event->id === $result->id
                && $event->data['slug'] === 'acme-renamed',
        );
    });

    it('broadcasts app.deleted with a minimal snapshot when an App is removed', function (): void {
        $app = OrbitApp::query()->create([
            'slug' => 'acme',
            'name' => 'Acme',
            'repository_url' => 'git@github.com:acme/site.git',
            'default_branch' => 'main',
            'root' => 'public',
        ]);

        Event::fake([RecordBroadcast::class]);

        app(RemoveAppAction::class)->execute($app);

        Event::assertDispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => $event->type === RecordEventType::AppDeleted
                && $event->id === $app->id
                && $event->data === ['id' => $app->id, 'name' => 'Acme', 'slug' => 'acme'],
        );
    });
});
