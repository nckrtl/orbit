<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Hibernation\ActivateAppInstanceRuntimeAction;
use App\Domain\Hibernation\HibernationException;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Shared\ResourceOperationException;
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
        ActivateAppInstanceRuntimeAction $activate,
        RuntimeActivationPage $pages,
    ): Response {
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $pages->failed('Active WireGuard peer identity required.');
        }

        try {
            $activate->execute($instance);
        } catch (HibernationException $exception) {
            if ($exception->errorCode === 'process.operation_busy' || $exception->errorCode === 'process.runtime_lock_failed') {
                return $pages->progress();
            }

            return $pages->failed($exception->getMessage());
        } catch (ProcessOperationException|ResourceOperationException $exception) {
            if (in_array($exception->errorCode, ['process.operation_busy', 'process.runtime_lock_failed'], true)) {
                return $pages->progress();
            }

            return $pages->failed($exception->getMessage());
        }

        return $pages->ready();
    }
}
