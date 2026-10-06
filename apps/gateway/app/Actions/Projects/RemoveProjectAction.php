<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Actions\ProjectDocuments\DocumentTreeAction;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Routes\RouteRemovalGuard;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

final readonly class RemoveProjectAction
{
    public function __construct(
        private ?RouteRemovalGuard $routes = null,
        private ?RecordEventBroadcaster $broadcaster = null,
    ) {}

    public function execute(Project $project): Project
    {
        DB::transaction(function () use ($project): void {
            app(DocumentTreeAction::class)->lock();
            if ($project->instances()->exists()) {
                throw new ResourceOperationException(
                    errorCode: 'project.has_instances',
                    message: "Project [{$project->slug}] still has Instances.",
                    status: 409,
                );
            }

            ($this->routes ?? app(RouteRemovalGuard::class))->assertAppRemovable($project);

            if ($project->tasks()->exists()) {
                throw new ResourceOperationException(
                    errorCode: 'project.has_task_groups',
                    message: "Project [{$project->slug}] still has task groups.",
                    status: 409,
                );
            }

            app(DocumentTreeAction::class)->removeProject($project->id);
            $project->delete();
        });

        ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
            RecordEventType::ProjectDeleted,
            $project->id,
            ['id' => $project->id, 'name' => $project->name, 'slug' => $project->slug],
        );

        return $project;
    }
}
