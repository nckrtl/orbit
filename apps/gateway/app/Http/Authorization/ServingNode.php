<?php

declare(strict_types=1);

namespace App\Http\Authorization;

enum ServingNode
{
    case Gateway;
    case Caller;
    case Target;
    case AppOwning;
    case InstanceOwning;
    case CallerInstanceOwning;
    case WorkspaceOwning;
    case ProcessOwning;
    case ToolOwning;
    case ClusterOwning;
    case RoleMutation;
    case Collection;
}
