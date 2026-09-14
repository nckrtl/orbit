<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Hibernation\ScheduleAppInstanceRuntimeWakeAction;
use App\Domain\Hibernation\AppDevHibernationPolicy;
use App\Domain\Hibernation\HibernationWakeFailureStore;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Responses\RuntimeActivationPage;
use App\Models\AppInstance;
use App\Models\Node;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use SensitiveParameter;

#[RequiresNodeAccess(ServingNode::AppInstanceHost)]
final class RuntimeActivationsController extends Controller
{
    public function show(
        Request $request,
        #[SensitiveParameter]
        AppInstance $instance,
        AppDevHibernationPolicy $policy,
        HibernationWakeFailureStore $failures,
        ScheduleAppInstanceRuntimeWakeAction $schedule,
        RuntimeActivationPage $pages,
    ): Response {
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $pages->failed('Active WireGuard peer identity required.');
        }

        $instance->loadMissing('node');

        if (! $policy->appliesToInstance($instance)) {
            return $pages->failed("AppInstance [{$instance->name}] is not an app-dev development target.");
        }

        $failure = $failures->pull((int) $instance->getKey());
        $schedule->afterResponse($instance);

        return $failure === null ? $pages->progress() : $pages->failed($failure);
    }
}
