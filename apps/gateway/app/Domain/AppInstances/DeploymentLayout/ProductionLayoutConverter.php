<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\DeploymentLayout;

use App\Models\AppInstance;
use App\Models\Route;

interface ProductionLayoutConverter
{
    public function preflight(
        AppInstance $appInstance,
        Route $route,
        #[\SensitiveParameter]
        string $expectedEnvironment,
        ?string $sqliteSourcePath,
    ): DeploymentLayoutInventory;

    public function moveSource(AppInstance $appInstance, DeploymentLayoutInventory $inventory): void;

    public function assertSqliteQuiescent(AppInstance $appInstance, DeploymentLayoutInventory $inventory): void;

    public function placePersistentState(AppInstance $appInstance, DeploymentLayoutInventory $inventory): void;

    public function runtimeTuning(AppInstance $appInstance, DeploymentLayoutInventory $inventory): string;

    public function validateServingAssociation(AppInstance $appInstance, DeploymentLayoutInventory $inventory): void;

    public function validatePlacedLayout(AppInstance $appInstance, DeploymentLayoutInventory $inventory): void;
}
