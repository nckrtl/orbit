<?php

declare(strict_types=1);

namespace App\Http\Authorization;

enum ServingNode
{
    case Gateway;
    case Target;
    case AppOwning;
    case InstanceOwning;
    case CandidateClone;
    case InstanceTransfer;
    case EnvironmentInstanceOwning;
    case ProcessOwning;
    case HerdrSessionOwning;
    case ScheduleOwning;
    case ScheduleHost;
    case AppInstanceHost;
    case ToolOwning;
    case ClusterOwning;
    case RouteOwning;
    case RoleMutation;
    case Collection;
    case Caller;
}
