<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class GrafanaAccessAuthorizationController extends Controller
{
    public function show(): Response
    {
        return response()->noContent();
    }
}
