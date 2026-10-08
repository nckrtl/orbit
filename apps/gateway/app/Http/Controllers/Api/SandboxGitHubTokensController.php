<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Compute\IssueSandboxGitHubTokenAction;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresNodeAccess(ServingNode::Caller)]
final class SandboxGitHubTokensController extends Controller
{
    public function store(Request $request, IssueSandboxGitHubTokenAction $action): JsonResponse
    {
        $node = $request->user();
        abort_unless($node instanceof Node, 403);

        return response()->json(['token' => $action->handle($node, $request->bearerToken())])
            ->header('Cache-Control', 'no-store');
    }
}
