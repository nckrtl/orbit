<?php

declare(strict_types=1);

use App\Domain\Doctor\DoctorFamily;
use App\Models\Activity;
use App\Models\AgentThread;
use App\Models\AgentThreadSendLease;
use App\Models\Annotation;
use App\Models\Cluster;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\DatabaseServer;
use App\Models\DatabaseUser;
use App\Models\DependencyPackage;
use App\Models\FirewallRule;
use App\Models\Instance;
use App\Models\InstanceDependencyEdge;
use App\Models\InstanceDependencyObservation;
use App\Models\InstanceDependencyResolution;
use App\Models\InstanceDependencyScanAttempt;
use App\Models\InstanceDeployment;
use App\Models\InstanceDeployStep;
use App\Models\InstanceEnvironmentValue;
use App\Models\InstanceRemoval;
use App\Models\InstanceRemovalMember;
use App\Models\InstanceTransfer;
use App\Models\JevDecision;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRole;
use App\Models\ProblemCollectorState;
use App\Models\ProblemFingerprint;
use App\Models\Process;
use App\Models\ProcessDefinition;
use App\Models\Project;
use App\Models\ProjectDevelopmentDeployStep;
use App\Models\ProjectDocumentStorage;
use App\Models\ProjectLifecycleStep;
use App\Models\ProjectNodeExclusion;
use App\Models\ProjectUpdate;
use App\Models\Route;
use App\Models\RouteAnalyticsTracking;
use App\Models\RouteCustomProxy;
use App\Models\RouteTarget;
use App\Models\Schedule;
use App\Models\ScheduleDefinition;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use App\Models\TaskDefinition;
use App\Models\TaskQuestion;
use App\Models\Tool;
use App\Models\ToolManagerRecord;

it('partitions every persisted model across doctor dispositions', function (): void {
    $familyModels = [
        Node::class => DoctorFamily::Node,
        NodeRole::class => DoctorFamily::Role,
        Project::class => DoctorFamily::Project,
        Instance::class => DoctorFamily::Instance,
        Schedule::class => DoctorFamily::Schedule,
        Tool::class => DoctorFamily::Tool,
        Process::class => DoctorFamily::Process,
        FirewallRule::class => DoctorFamily::Firewall,
        DatabaseConnection::class => DoctorFamily::DatabaseConnection,
        RouteCustomProxy::class => DoctorFamily::Route,
    ];
    $ownerInputs = [
        InstanceEnvironmentValue::class,
        ToolManagerRecord::class,
        Setting::class,
        ProjectDocumentStorage::class,
        Cluster::class,
        Route::class,
        RouteTarget::class,
        RouteAnalyticsTracking::class,
        ProcessDefinition::class,
        ScheduleDefinition::class,
        DatabaseConnectionTarget::class,
    ];
    $excluded = [
        Annotation::class,
        DependencyPackage::class,
        InstanceDependencyObservation::class,
        InstanceDependencyResolution::class,
        InstanceDependencyEdge::class,
        InstanceDependencyScanAttempt::class,
        NodeAccess::class,
        Activity::class,
        InstanceDeployment::class,
        InstanceDeployStep::class,
        ProjectLifecycleStep::class,
        ProjectDevelopmentDeployStep::class,
        ProjectNodeExclusion::class,
        InstanceRemoval::class,
        InstanceRemovalMember::class,
        InstanceTransfer::class,
        JevDecision::class,
        ProjectUpdate::class,
        ProblemFingerprint::class,
        ProblemCollectorState::class,
        DatabaseUser::class,
        DatabaseServer::class,
        Task::class,
        TaskDefinition::class,
        AgentThread::class,
        AgentThreadSendLease::class,
        Task::class,
        TaskComment::class,
        TaskCheck::class,
        TaskQuestion::class,
    ];
    $modelsDirectory = new ReflectionClass(Node::class)->getFileName();
    if (! is_string($modelsDirectory)) {
        throw new RuntimeException('Unable to locate model directory.');
    }
    $modelFiles = array_values(array_filter(
        iterator_to_array(new FilesystemIterator(dirname($modelsDirectory))),
        static function (mixed $file): bool {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                return false;
            }

            $contents = file_get_contents($file->getPathname());

            return is_string($contents)
                && preg_match('/\b(?:class|enum)\s+'.preg_quote($file->getBasename('.php'), '/').'\b/', $contents) === 1;
        },
    ));
    $models = array_map(
        static fn (SplFileInfo $file): string => 'App\\Models\\'.$file->getBasename('.php'),
        $modelFiles,
    );

    expect(array_diff($models, array_merge(array_keys($familyModels), $ownerInputs, $excluded)))
        ->toBeEmpty()
        ->and(array_diff(array_merge(array_keys($familyModels), $ownerInputs, $excluded), $models))
        ->toBeEmpty()
        ->and(array_unique(array_map(
            static fn (DoctorFamily $family): string => $family->value,
            array_values($familyModels),
        )))
        ->toHaveCount(count(DoctorFamily::cases()))
        ->and(array_values($familyModels))
        ->toHaveCount(count(DoctorFamily::cases()))
        ->and(array_intersect(array_keys($familyModels), $ownerInputs))
        ->toBeEmpty()
        ->and(array_intersect(array_keys($familyModels), $excluded))
        ->toBeEmpty()
        ->and(array_intersect($ownerInputs, $excluded))
        ->toBeEmpty();
});
