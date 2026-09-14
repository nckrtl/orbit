<?php

declare(strict_types=1);

namespace App\Domain\Hibernation;

use App\Models\AppInstance;

interface AppInstanceCheckoutInspector
{
    public function inspect(AppInstance $instance): RuntimeDependencyState;

    public function prune(AppInstance $instance, RuntimeDependencyState $state): void;

    public function restore(AppInstance $instance, RuntimeDependencyState $state): void;
}
