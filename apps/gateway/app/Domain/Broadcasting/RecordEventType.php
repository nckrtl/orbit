<?php

declare(strict_types=1);

namespace App\Domain\Broadcasting;

/**
 * Every realtime record event the Gateway can broadcast on the `orbit`
 * channel. The backing string is both the broadcast name and the `type`
 * field of the event envelope. See `docs/reference/events.mdx` for the
 * catalogue this enum must stay in sync with.
 */
enum RecordEventType: string
{
    case AnnotationUpdated = 'annotation.updated';

    case NodeCreated = 'node.created';
    case NodeUpdated = 'node.updated';
    case NodeDeleted = 'node.deleted';

    case AppCreated = 'app.created';
    case AppUpdated = 'app.updated';
    case AppDeleted = 'app.deleted';

    case InstanceCreated = 'instance.created';
    case InstanceUpdated = 'instance.updated';
    case InstanceDeleted = 'instance.deleted';

    case ProcessCreated = 'process.created';
    case ProcessStatus = 'process.status';
    case ProcessDeleted = 'process.deleted';

    case ScheduleCreated = 'schedule.created';
    case ScheduleUpdated = 'schedule.updated';
    case ScheduleDeleted = 'schedule.deleted';

    case DatabaseCreated = 'database.created';
    case DatabaseUpdated = 'database.updated';
    case DatabaseDeleted = 'database.deleted';

    case FirewallCreated = 'firewall.created';
    case FirewallDeleted = 'firewall.deleted';

    case RouteCreated = 'route.created';
    case RouteUpdated = 'route.updated';
    case RouteDeleted = 'route.deleted';

    case DeployStepCreated = 'deploy_step.created';
    case DeployStepUpdated = 'deploy_step.updated';
    case DeployStepDeleted = 'deploy_step.deleted';

    case DeploymentCreated = 'deployment.created';
    case DeploymentUpdated = 'deployment.updated';

    case ProcessUsage = 'process.usage';

    case TaskGroupCreated = 'task_group.created';
    case TaskGroupUpdated = 'task_group.updated';
    case TaskCommentCreated = 'task_comment.created';
    case AgentThreadUpdated = 'agent_thread.updated';
    case TasksUpdated = 'tasks.updated';

    case ActivityCreated = 'activity.created';
    case ActivityUpdated = 'activity.updated';
}
