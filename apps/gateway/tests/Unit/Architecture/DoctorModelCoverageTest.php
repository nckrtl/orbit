<?php

declare(strict_types=1);

use App\Domain\Doctor\DoctorFamily;
use App\Models\Activity;
use App\Models\AgentThread;
use App\Models\App as AppModel;
use App\Models\AppInstance;
use App\Models\AppInstanceDependencyEdge;
use App\Models\AppInstanceDependencyObservation;
use App\Models\AppInstanceDependencyResolution;
use App\Models\AppInstanceDependencyScanAttempt;
use App\Models\AppInstanceDeployment;
use App\Models\AppInstanceDeployStep;
use App\Models\AppInstanceEnvironmentValue;
use App\Models\AppInstanceRemoval;
use App\Models\AppInstanceRemovalMember;
use App\Models\AppInstanceTransfer;
use App\Models\AppUpdate;
use App\Models\Cluster;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\DatabaseUser;
use App\Models\DependencyPackage;
use App\Models\FirewallRule;
use App\Models\HerdrObservationNonce;
use App\Models\HerdrSession;
use App\Models\JwksKey;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRole;
use App\Models\Process;
use App\Models\ProcessDefinition;
use App\Models\Route;
use App\Models\RouteAnalyticsTracking;
use App\Models\RouteCustomProxy;
use App\Models\RouteTarget;
use App\Models\Schedule;
use App\Models\ScheduleDefinition;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskGroup;
use App\Models\TaskVerification;
use App\Models\Tool;
use App\Models\ToolManagerRecord;

it('partitions every persisted model across doctor dispositions', function (): void {
    $familyModels = [
        Node::class => DoctorFamily::Node,
        NodeRole::class => DoctorFamily::Role,
        AppModel::class => DoctorFamily::App,
        AppInstance::class => DoctorFamily::Instance,
        Schedule::class => DoctorFamily::Schedule,
        Tool::class => DoctorFamily::Tool,
        Process::class => DoctorFamily::Process,
        FirewallRule::class => DoctorFamily::Firewall,
        HerdrSession::class => DoctorFamily::Herdr,
        DatabaseConnection::class => DoctorFamily::DatabaseConnection,
        RouteCustomProxy::class => DoctorFamily::Route,
    ];
    $ownerInputs = [
        AppInstanceEnvironmentValue::class,
        ToolManagerRecord::class,
        Setting::class,
        Cluster::class,
        Route::class,
        RouteTarget::class,
        RouteAnalyticsTracking::class,
        ProcessDefinition::class,
        ScheduleDefinition::class,
        JwksKey::class,
        DatabaseConnectionTarget::class,
    ];
    $excluded = [
        DependencyPackage::class,
        AppInstanceDependencyObservation::class,
        AppInstanceDependencyResolution::class,
        AppInstanceDependencyEdge::class,
        AppInstanceDependencyScanAttempt::class,
        NodeAccess::class,
        Activity::class,
        AppInstanceDeployment::class,
        AppInstanceDeployStep::class,
        AppInstanceRemoval::class,
        AppInstanceRemovalMember::class,
        AppInstanceTransfer::class,
        AppUpdate::class,
        DatabaseUser::class,
        HerdrObservationNonce::class,
        Task::class,
        AgentThread::class,
        TaskGroup::class,
        TaskComment::class,
        TaskVerification::class,
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
