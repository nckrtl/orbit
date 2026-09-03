<?php

declare(strict_types=1);

use App\Domain\Doctor\DoctorFamily;
use App\Models\Activity;
use App\Models\App as AppModel;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\FirewallRule;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRole;
use App\Models\Process;
use App\Models\Setting;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use App\Models\Workspace;

it('partitions every persisted model across doctor dispositions', function (): void {
    $familyModels = [
        Node::class => DoctorFamily::Node,
        NodeRole::class => DoctorFamily::Role,
        AppModel::class => DoctorFamily::App,
        AppInstance::class => DoctorFamily::Instance,
        Workspace::class => DoctorFamily::Workspace,
        Tool::class => DoctorFamily::Tool,
        Process::class => DoctorFamily::Process,
        FirewallRule::class => DoctorFamily::Firewall,
    ];
    $ownerInputs = [ToolManagerRecord::class, Setting::class, Cluster::class, Instance::class];
    $excluded = [NodeAccess::class, Activity::class];
    $modelsDirectory = new ReflectionClass(Node::class)->getFileName();
    if (! is_string($modelsDirectory)) {
        throw new RuntimeException('Unable to locate model directory.');
    }
    $modelFiles = array_values(array_filter(
        iterator_to_array(new FilesystemIterator(dirname($modelsDirectory))),
        static fn (mixed $file): bool => $file instanceof SplFileInfo,
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
