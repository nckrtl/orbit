<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Routes\RouteRemovalGuard;
use App\Domain\Shared\ResourceOperationException;
use App\Models\App as OrbitApp;

final readonly class RemoveAppAction
{
    public function __construct(
        private ?RouteRemovalGuard $routes = null,
        private ?RecordEventBroadcaster $broadcaster = null,
    ) {}

    public function execute(OrbitApp $app): OrbitApp
    {
        if ($app->appInstances()->exists()) {
            throw new ResourceOperationException(
                errorCode: 'app.has_app_instances',
                message: "App [{$app->slug}] still has AppInstances.",
                status: 409,
            );
        }

        ($this->routes ?? app(RouteRemovalGuard::class))->assertAppRemovable($app);

        $app->delete();

        ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
            RecordEventType::AppDeleted,
            $app->id,
            ['id' => $app->id, 'name' => $app->name, 'slug' => $app->slug],
        );

        return $app;
    }
}
