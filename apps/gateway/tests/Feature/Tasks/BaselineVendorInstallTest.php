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
use Symfony\Component\Process\Process;
use Tests\Support\FakeTaskCheckRunner;

it('baseline installs vendor before check on a fresh workspace', function (): void {
    $app = OrbitApp::query()->create([
        'name' => 'baseline vendor install',
        'slug' => 'baseline-vendor-install',
        'repository_url' => 'git@example.test:baseline-vendor-install.git',
        'default_branch' => 'main',
        'task_check' => 'composer check',
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
            'command' => 'while IFS= read -r -d "" manifest; do project="${manifest%/composer.json}"; [ "$project" = "$manifest" ] && project="."; if { [ "$project" = "." ] || [ -f "$project/composer.lock" ]; } && [ ! -f "$project/vendor/autoload.php" ]; then (cd "$project" && if [ -f composer.lock ]; then composer install --no-interaction --prefer-dist; else composer install --no-interaction --prefer-dist && rm -f composer.lock; fi) || exit $?; fi; done < <(git ls-files -z -- "composer.json" ":(glob)**/composer.json")',
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
        'task_check' => 'composer check',
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
    'nested project vendor tools missing' => [null, 'sh: 1: vendor/bin/pest: not found', 'Project dependencies appear to be missing'],
]);

it('installs the root Composer package without a lockfile, removes the lockfile it writes, and skips nested manifests without one', function (): void {
    $directory = sys_get_temp_dir().'/orbit-baseline-install-'.bin2hex(random_bytes(6));
    $checkout = $directory.'/checkout';
    $bin = $directory.'/bin';
    mkdir($bin, 0755, true);
    (new Process(['git', 'init', '--quiet', $checkout]))->mustRun();
    mkdir($checkout.'/packages/locked', 0755, true);
    mkdir($checkout.'/tests/Fixtures/unlocked', 0755, true);
    foreach (['composer.json', 'packages/locked/composer.json', 'packages/locked/composer.lock', 'tests/Fixtures/unlocked/composer.json'] as $file) {
        file_put_contents($checkout.'/'.$file, '{}');
    }
    (new Process(['git', 'add', '.'], $checkout))->mustRun();
    file_put_contents($bin.'/composer', "#!/usr/bin/env bash\nmkdir -p vendor && touch vendor/autoload.php && echo '{\"written\":true}' > composer.lock && pwd >> \"{$directory}/installs\"\n");
    chmod($bin.'/composer', 0755);

    try {
        $app = OrbitApp::query()->create([
            'name' => 'package without lockfile',
            'slug' => 'package-without-lockfile',
            'repository_url' => 'git@example.test:package-without-lockfile.git',
            'task_check' => 'composer check',
        ]);
        $node = Node::query()->create([
            'name' => 'baseline-lockless-node',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '10.44.0.193',
            'wireguard_ip' => '10.44.0.193',
        ]);
        $instance = AppInstance::query()->create([
            'app_id' => $app->id,
            'node_id' => $node->id,
            'name' => 'baseline-lockless',
            'checkout_path' => $checkout,
            'status' => 'reserved',
        ]);
        $group = TaskGroup::query()->create([
            'app_id' => $app->id,
            'title' => 'Lockless package',
            'brief' => 'Install the root package.',
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
        $checks = new FakeTaskCheckRunner;
        app()->instance(TaskCheckRunner::class, $checks);
        app(TaskExtensionState::class)->enable();

        app(TaskScheduler::class)->tick();

        $install = collect($checks->setups[0])->firstWhere('name', '[Orbit internal] Install Composer dependencies');
        (new Process(['bash', '-c', $install['command']], $checkout, ['PATH' => $bin.':'.getenv('PATH')]))->mustRun();

        $installs = array_map(realpath(...), file($directory.'/installs', FILE_IGNORE_NEW_LINES) ?: []);
        expect($installs)->toBe([realpath($checkout), realpath($checkout.'/packages/locked')])
            ->and(file_exists($checkout.'/composer.lock'))->toBeFalse()
            ->and(file_get_contents($checkout.'/packages/locked/composer.lock'))->toContain('written');
        $status = (new Process(['git', 'status', '--porcelain', '--untracked-files=all', '--', '*.lock'], $checkout))->mustRun()->getOutput();
        expect($status)->toBe('AM packages/locked/composer.lock'.PHP_EOL);
    } finally {
        (new Process(['rm', '-rf', $directory]))->run();
    }
});
