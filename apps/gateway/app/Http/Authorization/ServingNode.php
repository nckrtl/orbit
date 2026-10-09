<?php

declare(strict_types=1);

namespace App\Http\Authorization;

enum ServingNode
{
    case Gateway;
    case Target;
    case ProjectOwning;
    case InstanceOwning;
    case InstanceCreation;
    case DeploymentOwning;
    case CandidateClone;
    case InstanceTransfer;
    case EnvironmentInstanceOwning;
    case ProcessOwning;
    case ScheduleOwning;
    case ScheduleHost;
    case InstanceHost;
    case ToolOwning;
    case ClusterOwning;
    case RouteOwning;
    case TaskGroupOwning;
    case RoleMutation;
    case Collection;
    case Caller;
}
