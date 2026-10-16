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
use App\Models\FleetRollout;
use App\Models\FleetRolloutNode;
use App\Models\GatewayRelease;
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
use App\Models\NodeFootprint;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\OperationStep;
use App\Models\PackageCacheEntry;
use App\Models\PackageCacheSnapshot;
use App\Models\ProblemCollectorState;
use App\Models\ProblemFingerprint;
use App\Models\Process;
use App\Models\ProcessDefinition;
use App\Models\Project;
use App\Models\ProjectDevelopmentDeployStep;
use App\Models\ProjectLifecycleStep;
use App\Models\ProjectNodeExclusion;
use App\Models\ProjectUpdate;
use App\Models\Route;
use App\Models\RouteAnalyticsTracking;
use App\Models\RouteCustomProxy;
use App\Models\RouteRemovalResidue;
use App\Models\RouteTarget;
use App\Models\Schedule;
use App\Models\ScheduleDefinition;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use App\Models\TaskQuestion;
use App\Models\TaskReviewedCommit;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use App\Models\UpCloudTaskVmImageBuild;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;
use Orbit\Extensions\Conn\Models\ConnProfile;
use Orbit\Extensions\Conn\Models\ConnProfileBinding;
use Orbit\Extensions\Documents\Models\ProjectDocumentCleanup;
use Orbit\Extensions\Documents\Models\ProjectDocumentEntry;
use Orbit\Extensions\Documents\Models\ProjectDocumentStorage;
use Orbit\Extensions\Documents\Models\ProjectDocumentUpload;
use Orbit\Extensions\Documents\Models\ProjectDocumentVersion;
use Orbit\Extensions\Vault\Models\VaultItem;

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
        DatabaseServer::class => DoctorFamily::DatabaseServer,
        RouteCustomProxy::class => DoctorFamily::Route,
    ];
    $ownerInputs = [
        InstanceEnvironmentValue::class,
        ToolManagerRecord::class,
        Setting::class,
        ProjectDocumentStorage::class,
        ProjectDocumentEntry::class,
        ProjectDocumentVersion::class,
        ProjectDocumentUpload::class,
        ProjectDocumentCleanup::class,
        Cluster::class,
        Route::class,
        RouteTarget::class,
        RouteAnalyticsTracking::class,
        RouteRemovalResidue::class,
        ProcessDefinition::class,
        ScheduleDefinition::class,
        DatabaseConnectionTarget::class,
    ];
    $excluded = [
        TaskVm::class,
        TaskVmImageBuild::class, // Build history, not a resource that doctor converges.
        UpCloudTaskVmImageBuild::class, // Attempt ledger, not a converged resource; RecipeImageBuilds alerts on fenced recipes.
        PackageCacheSnapshot::class, // Desired dependency inventory, not a converged fleet resource.
        PackageCacheEntry::class, // Verified cache inventory, not a converged fleet resource.
        GatewayRelease::class,
        FleetRollout::class,
        FleetRolloutNode::class,
        NodeFootprint::class,
        Annotation::class,
        DependencyPackage::class,
        InstanceDependencyObservation::class,
        InstanceDependencyResolution::class,
        InstanceDependencyEdge::class,
        InstanceDependencyScanAttempt::class,
        NodeAccess::class,
        Activity::class,
        InstanceDeployment::class,
        Operation::class, // Execution record of a long operation, not a converged fleet resource.
        OperationStep::class,
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
        VaultItem::class,
        DatabaseUser::class,
        Task::class,
        AgentThread::class,
        AgentThreadSendLease::class,
        Task::class,
        TaskComment::class,
        TaskCheck::class,
        TaskQuestion::class,
        TaskReviewedCommit::class,
        ConnProfile::class,
        ConnProfileBinding::class,
        ConnEnvironment::class,
        ConnPairing::class,
    ];
    $modelsDirectory = new ReflectionClass(Node::class)->getFileName();
    if (! is_string($modelsDirectory)) {
        throw new RuntimeException('Unable to locate model directory.');
    }
    // Core models, and each extension's models under extensions/<name>/src/Models.
    $directories = ['App\\Models\\' => dirname($modelsDirectory)];
    foreach (glob(dirname(__DIR__, 3).'/extensions/*/src/Models', GLOB_ONLYDIR) ?: [] as $directory) {
        $directories['Orbit\\Extensions\\'.ucfirst(basename(dirname($directory, 2))).'\\Models\\'] = $directory;
    }
    $models = [];
    foreach ($directories as $namespace => $directory) {
        foreach (new FilesystemIterator($directory) as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if (is_string($contents)
                && preg_match('/\b(?:class|enum)\s+'.preg_quote($file->getBasename('.php'), '/').'\b/', $contents) === 1) {
                $models[] = $namespace.$file->getBasename('.php');
            }
        }
    }

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
