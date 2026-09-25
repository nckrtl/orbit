<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckReading;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskExecutionMode;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\ProjectLifecycleStep;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskGroup;
use Tests\Support\FakeTaskCheckRunner;

it('baseline installs vendor before check on a fresh workspace', function (): void {
    $app = OrbitApp::query()->create([
        'name' => 'baseline vendor install',
        'slug' => 'baseline-vendor-install',
        'repository_url' => 'git@example.test:baseline-vendor-install.git',
        'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => 'baseline-vendor-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.191',
        'wireguard_ip' => '10.44.0.191',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'baseline-vendor',
        'checkout_path' => '/tmp/tasks-baseline-vendor-install',
        'status' => 'reserved',
    ]);
    $group = TaskGroup::query()->create([
        'app_id' => $app->id,
        'title' => 'Baseline vendor install',
        'brief' => 'Install missing dependencies before check.',
        'status' => TaskGroupStatus::Running,
        'execution_mode' => TaskExecutionMode::Managed,
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    Task::query()->create([
        'task_group_id' => $group->id,
        'position' => 1,
        'title' => 'First task',
        'brief' => 'First task brief',
        'status' => TaskStatus::Running,
    ]);
    ProjectLifecycleStep::query()->create([
        'app_id' => $app->id,
        'phase' => 'setup',
        'name' => 'Project setup',
        'command' => 'echo project setup',
        'timeout_seconds' => 600,
        'position' => 1,
    ]);
    $checks = new FakeTaskCheckRunner;
    app()->instance(TaskCheckRunner::class, $checks);
    app(TaskExtensionState::class)->enable();

    app(TaskScheduler::class)->tick();

    expect($checks->setups[0])->toBe([
        [
            'name' => 'Project setup',
            'command' => 'echo project setup',
            'timeout_seconds' => 600,
        ],
        [
            'name' => '[Orbit internal] Install Composer dependencies',
            'command' => 'while IFS= read -r -d "" manifest; do project="${manifest%/composer.json}"; [ "$project" = "$manifest" ] && project="."; if [ -f "$project/composer.lock" ] && [ ! -f "$project/vendor/autoload.php" ]; then (cd "$project" && composer install --no-interaction --prefer-dist) || exit $?; fi; done < <(git ls-files -z -- "composer.json" ":(glob)**/composer.json")',
            'timeout_seconds' => 600,
        ],
    ])->and(TaskCheck::query()->sole()->kind)->toBe(TaskCheckKind::Baseline);
});

it('reports missing dependencies instead of claiming the default branch is broken', function (?string $failedStep, string $output, string $reasonText): void {
    $app = OrbitApp::query()->create([
        'name' => 'baseline install failure',
        'slug' => 'baseline-install-failure',
        'repository_url' => 'git@example.test:baseline-install-failure.git',
        'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => 'baseline-failure-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.192',
        'wireguard_ip' => '10.44.0.192',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'baseline-failure',
        'checkout_path' => '/tmp/tasks-baseline-install-failure',
        'status' => 'reserved',
    ]);
    $group = TaskGroup::query()->create([
        'app_id' => $app->id,
        'title' => 'Baseline install failure',
        'brief' => 'Report dependency installation failure.',
        'status' => TaskGroupStatus::Running,
        'execution_mode' => TaskExecutionMode::Managed,
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    Task::query()->create([
        'task_group_id' => $group->id,
        'position' => 1,
        'title' => 'First task',
        'brief' => 'First task brief',
        'status' => TaskStatus::Running,
    ]);
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner([
        TaskCheckReading::finished(
            1,
            str_repeat('a', 40),
            str_repeat('b', 40),
            [],
            $output,
            null,
            str_repeat('b', 40),
            $failedStep,
        ),
    ]));
    app(TaskExtensionState::class)->enable();

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $reason = $group->fresh()?->assistance_reason;

    expect($group->fresh()?->assistance_requested)->toBeTrue()
        ->and($reason)->toBeString()
        ->and($reason)->toContain($reasonText)
        ->and($reason)->not->toContain('default branch or the task branch is broken')
        ->and(TaskCheck::query()->sole()->output)->toBe($output);
})->with([
    'Composer install failure' => ['[Orbit internal] Install Composer dependencies', "Could not install dependencies.\n", 'Composer dependency installation failed'],
    'nested project vendor tools missing' => [null, 'sh: 1: vendor/bin/pest: not found', 'Composer dependencies appear to be missing'],
]);
