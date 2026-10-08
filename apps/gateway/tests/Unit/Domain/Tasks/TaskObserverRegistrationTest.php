<?php

declare(strict_types=1);

use Pest\Plugins\Tia\WatchPatterns;
use Pest\Support\Container;
use Symfony\Component\Process\Process;

it('leaves task models unloaded and not observed when an unrelated test boots the app', function (): void {
    $root = dirname(__DIR__, 4);
    $models = [
        'App\\Models\\Task',
        'App\\Models\\TaskCheck',
        'App\\Models\\TaskComment',
        'App\\Models\\AgentThread',
    ];
    $reportPath = sys_get_temp_dir().'/orbit-task-observers-'.bin2hex(random_bytes(8)).'.json';
    // A worker may already have loaded these models for an earlier file. A fresh process shows
    // what booting the application itself loads, which is what test impact analysis records.
    $script = <<<'PHP'
        declare(strict_types=1);

        require $argv[1].'/tests/bootstrap.php';

        $app = require $argv[1].'/bootstrap/app.php';
        $app->loadEnvironmentFrom('.env.example');
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        /** @var list<class-string> $models */
        $models = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
        $dispatcher = $app->make(Illuminate\Contracts\Events\Dispatcher::class);
        $raw = $dispatcher instanceof Illuminate\Events\Dispatcher ? $dispatcher->getRawListeners() : [];
        $events = static fn (string $model): array => [
            'created' => "eloquent.created: {$model}",
            'updated' => "eloquent.updated: {$model}",
            'deleted' => "eloquent.deleted: {$model}",
        ];

        $loaded = array_values(array_filter(
            $models,
            static fn (string $model): bool => class_exists($model, false),
        ));
        $observed = [];

        foreach ($models as $model) {
            foreach ($events($model) as $name) {
                if (isset($raw[$name])) {
                    $observed[] = $name;
                }
            }
        }

        $listeners = [];

        foreach ($models as $model) {
            new $model;
            $raw = $dispatcher instanceof Illuminate\Events\Dispatcher ? $dispatcher->getRawListeners() : [];
            $listeners[$model] = [];

            foreach ($events($model) as $event => $name) {
                $listeners[$model][$event] = array_values(array_map(
                    static fn (mixed $listener): string => is_string($listener) ? $listener : 'unreadable',
                    $raw[$name] ?? [],
                ));
            }
        }

        file_put_contents($argv[3], json_encode([
            'loaded' => $loaded,
            'observed' => $observed,
            'listeners' => $listeners,
        ], JSON_THROW_ON_ERROR));
        PHP;
    $process = new Process(
        [PHP_BINARY, '-r', $script, $root, json_encode($models, JSON_THROW_ON_ERROR), $reportPath],
        $root,
    );
    $process->setTimeout(60);

    try {
        $exitCode = $process->run();

        expect($exitCode)->toBe(0, $process->getErrorOutput()."\n".$process->getOutput());
        expect(is_file($reportPath))->toBeTrue();

        /** @var array{loaded: list<string>, observed: list<string>, listeners: array<string, array<string, list<string>>>} $report */
        $report = json_decode((string) file_get_contents($reportPath), true, flags: JSON_THROW_ON_ERROR);
        $observer = 'App\\Domain\\Tasks\\TaskBroadcastObserver';
        $registered = [];

        foreach ($models as $model) {
            $registered[$model] = [
                'created' => ["{$observer}@created"],
                'updated' => ["{$observer}@updated"],
                'deleted' => ["{$observer}@deleted"],
            ];
        }

        expect([
            'loaded' => $report['loaded'],
            'observed' => $report['observed'],
        ])->toBe([
            'loaded' => [],
            'observed' => [],
        ]);
        expect($report['listeners'])->toBe($registered);
    } finally {
        if (is_file($reportPath)) {
            unlink($reportPath);
        }
    }
});

it('selects only the tests that cover a non-PHP runtime resource', function (): void {
    $watch = Container::getInstance()->get(WatchPatterns::class);
    $root = dirname(__DIR__, 4);
    $paths = [
        'resources/tasks/check',
        'resources/tasks/turn',
        'resources/tasks/actions.json',
        'resources/mcp/tools.json',
        'resources/scripts/service-metrics.py',
        'resources/scripts/service-metrics-fpm.py',
        'resources/proxycli/server.py',
        'resources/instances/lifecycle.py',
        'resources/analytics/clickhouse/config.d/logs.xml',
        'resources/analytics/clickhouse/users.d/default-profile-low-resources-overrides.xml',
        'resources/analytics/clickhouse/README.md',
        'resources/scripts/horizon-queue.php',
        'resources/instances/tia-baseline.py',
        'resources/fpm/opcache-reset.php',
        'resources/private-dns/serve.php',
        'resources/annotator/manifest.json',
        'resources/compute/guest-git-bundle.py',
        'resources/compute/guest-workspace-source.py',
        'resources/compute/guest-pair-runtime.py',
        'resources/compute/retarget-vpn.sh',
        'resources/compute/sandbox-hub-network.py',
        'resources/compute/template-lock.py',
        'resources/compute/unlisted-program.py',
    ];
    $selected = [];

    foreach ($paths as $path) {
        $selected[$path] = $watch->matchedDirectories($root, [$path]);
    }

    expect($selected)->toBe([
        'resources/tasks/check' => ['tests/Feature/Infrastructure/Tasks/RemoteTaskCheckRunnerTest.php'],
        'resources/tasks/turn' => ['tests/Feature/Tasks/TurnReceiptTest.php'],
        'resources/tasks/actions.json' => ['tests/Feature/Tasks/TaskDefinitionValidationTest.php'],
        'resources/mcp/tools.json' => ['tests/Feature/Mcp'],
        'resources/scripts/service-metrics.py' => ['tests/Feature/Infrastructure/Metrics/ServiceMetricsProgramTest.php'],
        'resources/scripts/service-metrics-fpm.py' => ['tests/Feature/Infrastructure/Metrics/ServiceMetricsProgramTest.php'],
        'resources/proxycli/server.py' => ['tests/Unit/Infrastructure/ProxyCli/ProxyCliCollectorValkeyClientTest.php'],
        'resources/instances/lifecycle.py' => ['tests/Feature/Domain/ProjectLifecycleRunnerTest.php'],
        'resources/analytics/clickhouse/config.d/logs.xml' => ['tests/Feature/Infrastructure/Analytics/NativeAnalyticsClickhouseConfigurationManagerTest.php'],
        'resources/analytics/clickhouse/users.d/default-profile-low-resources-overrides.xml' => ['tests/Feature/Infrastructure/Analytics/NativeAnalyticsClickhouseConfigurationManagerTest.php'],
        'resources/analytics/clickhouse/README.md' => [],
        'resources/scripts/horizon-queue.php' => [],
        'resources/instances/tia-baseline.py' => ['tests/Feature/Domain/ProjectLifecycleRunnerTest.php', 'tests/Feature/Domain/TiaBaselineSetupTest.php'],
        'resources/fpm/opcache-reset.php' => ['tests/Feature/GatewayReleases/GatewayRuntimeHandoffTest.php'],
        'resources/private-dns/serve.php' => ['tests/Feature/Infrastructure/AppDev'],
        'resources/annotator/manifest.json' => [],
        'resources/compute/guest-git-bundle.py' => ['tests/Feature/Infrastructure/Compute/IncusSandboxTest.php', 'tests/Feature/Infrastructure/Tasks/SandboxGitBundlesTest.php'],
        'resources/compute/guest-workspace-source.py' => ['tests/Feature/Infrastructure/Tasks/SandboxWorkspaceSourceTest.php', 'tests/Feature/Infrastructure/Tasks/SandboxTemplateSourceTest.php'],
        'resources/compute/guest-pair-runtime.py' => ['tests/Feature/Infrastructure/Tasks/SandboxPairRuntimeTest.php', 'tests/Feature/Infrastructure/Tasks/SandboxTopologyAdmissionTest.php'],
        'resources/compute/retarget-vpn.sh' => ['tests/Feature/Infrastructure/Tasks/SandboxPairRuntimeTest.php'],
        'resources/compute/sandbox-hub-network.py' => ['tests/Feature/Infrastructure/Compute/UpCloudSandboxEnrollmentTest.php'],
        'resources/compute/template-lock.py' => ['tests/Feature/Infrastructure/Tasks/SandboxTemplateSourceTest.php'],
        'resources/compute/unlisted-program.py' => [],
    ]);
});
