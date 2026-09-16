<?php

declare(strict_types=1);

use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Processes\ProcessTarget;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;

it('renders an Orbit-owned systemd unit with fixed argv and the target identity', function (): void {
    $node = new Node(['name' => 'production', 'wireguard_ip' => '10.44.0.4']);
    $process = new Process([
        'name' => 'queue',
        'runtime_config' => [
            'command' => ['/usr/bin/php', 'artisan', 'queue:work', '--queue=high priority', '$LITERAL', '%n'],
            'environment_file' => '/var/www/docs/main/.env',
        ],
        'working_directory' => '/var/www/docs/main',
        'restart_policy' => 'on-failure',
    ]);
    $process->id = 17;
    $target = new ProcessTarget(
        node: $node,
        user: 'orbit-docs',
        checkoutPath: '/var/www/docs/main',
    );

    $renderer = new SystemdProcessRenderer;
    $unit = $renderer->render($process, $target);

    expect($renderer->unitName($process))
        ->toBe('orbit-process-17-queue.service')
        ->and($unit)
        ->toContain('X-Orbit-Process-ID=17')
        ->toContain('User=orbit-docs')
        ->toContain('WorkingDirectory=/var/www/docs/main')
        ->toContain('EnvironmentFile=-/var/www/docs/main/.env')
        ->toContain(
            'Environment=PATH=/usr/local/bin:/opt/orbit/composer/vendor/bin:/usr/bin:/bin',
        )
        ->toContain('Environment=NODE_USE_SYSTEM_CA=1')
        ->toContain('ExecStart="/usr/bin/php" "artisan" "queue:work" "--queue=high priority" "$$LITERAL" "%%n"')
        ->toContain('Restart=on-failure')
        ->not->toContain('VITE_DEV_SERVER_CERT')
        ->not->toContain('VITE_DEV_SERVER_KEY')
        ->not->toContain('ORBIT_DEV_SERVER_ORIGIN')
        ->not->toContain('/bin/bash')
        ->not->toContain('sh -c');

    expect(strpos(haystack: $unit, needle: 'Environment=PATH='))
        ->toBeLessThan(strpos(haystack: $unit, needle: 'EnvironmentFile='));
    expect(strpos(haystack: $unit, needle: 'Environment=NODE_USE_SYSTEM_CA=1'))
        ->toBeLessThan(strpos(haystack: $unit, needle: 'EnvironmentFile='));
});

it('omits EnvironmentFile for a Node Process without an environment file', function (): void {
    $process = new Process([
        'name' => 'herdr-observer',
        'runtime_config' => [
            'command' => ['/usr/local/bin/herdr-observer'],
            'environment_file' => '',
        ],
        'working_directory' => '/home/orbit',
        'restart_policy' => 'never',
    ]);
    $process->id = 9;
    $target = new ProcessTarget(
        node: new Node(['name' => 'beast']),
        user: 'orbit',
        checkoutPath: '/home/orbit',
        environmentFile: '',
    );

    $renderer = new SystemdProcessRenderer;
    $unit = $renderer->render($process, $target);

    expect($renderer->unitName($process))
        ->toBe('orbit-process-9-herdr-observer.service')
        ->and($unit)
        ->toContain('X-Orbit-Process-ID=9')
        ->toContain('User=orbit')
        ->toContain('WorkingDirectory=/home/orbit')
        ->not->toContain('EnvironmentFile=');
});

it('maps common restart policies to valid systemd values', function (string $policy, string $expected): void {
    $process = new Process([
        'name' => 'worker',
        'runtime_config' => [
            'command' => ['/usr/bin/sleep', '60'],
            'environment_file' => '/home/orbit/apps/docs/.env',
        ],
        'working_directory' => '/home/orbit/apps/docs',
        'restart_policy' => $policy,
    ]);
    $process->id = 2;
    $target = new ProcessTarget(
        node: new Node(['name' => 'dev']),
        user: 'orbit',
        checkoutPath: '/home/orbit/apps/docs',
    );

    expect(new SystemdProcessRenderer()->render($process, $target))->toContain("Restart={$expected}");
})->with([
    'never' => ['never', 'no'],
    'on failure' => ['on-failure', 'on-failure'],
    'always' => ['always', 'always'],
    'unless stopped' => ['unless-stopped', 'always'],
]);

it('pins derived instance and workspace Vite TLS paths after the app environment file', function (string $scope): void {
    $process = new Process([
        'name' => 'vite',
        'runtime_config' => [
            'command' => ['/usr/bin/npm', 'run', 'dev'],
            'environment_file' => '/tmp/.env',
            'environment' => [
                'VITE_DEV_SERVER_CERT' => '/bad/cert.pem',
                'VITE_DEV_SERVER_KEY' => '/bad/key.pem',
            ],
        ],
        'working_directory' => '/tmp',
        'restart_policy' => 'never',
    ]);
    $process->id = 8;
    $target = new ProcessTarget(
        node: new Node(['name' => 'dev']),
        user: 'orbit',
        checkoutPath: '/tmp',
        certificateScope: $scope,
    );

    $unit = new SystemdProcessRenderer()->render(
        $process,
        $target,
        new ManagedUserAccount('orbit', 'orbit', '/srv/orbit home'),
    );

    expect($unit)
        ->toContain(
            "Environment=VITE_DEV_SERVER_CERT=/srv/orbit\\x20home/.orbit/certificates/{$scope}/current/cert.pem",
        )
        ->toContain("Environment=VITE_DEV_SERVER_KEY=/srv/orbit\\x20home/.orbit/certificates/{$scope}/current/key.pem")
        ->toContain(
            "ExecStart=\"/usr/bin/env\" \"VITE_DEV_SERVER_CERT=/srv/orbit home/.orbit/certificates/{$scope}/current/cert.pem\" \"VITE_DEV_SERVER_KEY=/srv/orbit home/.orbit/certificates/{$scope}/current/key.pem\" \"/usr/bin/npm\" \"run\" \"dev\"",
        )
        ->not->toContain('/bad/cert.pem')
        ->not->toContain('/bad/key.pem')
        ->not->toContain('ORBIT_DEV_SERVER_ORIGIN');
})->with([
    'instance' => ['instance-3'],
    'workspace' => ['workspace-4'],
]);

it('rejects missing or mismatched accounts for certificate scopes', function (?ManagedUserAccount $account): void {
    $process = new Process([
        'name' => 'vite',
        'runtime_config' => [
            'command' => ['/usr/bin/npm'],
            'environment_file' => '/tmp/.env',
        ],
        'working_directory' => '/tmp',
        'restart_policy' => 'never',
    ]);
    $process->id = 9;
    $target = new ProcessTarget(
        node: new Node(['name' => 'dev']),
        user: 'orbit',
        checkoutPath: '/tmp',
        certificateScope: 'instance-9',
    );

    expect(fn () => new SystemdProcessRenderer()->render($process, $target, $account))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'missing account' => [null],
    'mismatched account' => [new ManagedUserAccount('other', 'orbit', '/srv/orbit')],
]);

it('systemd-escapes accepted working and environment file paths with spaces', function (): void {
    $process = new Process([
        'name' => 'worker',
        'runtime_config' => [
            'command' => ['/usr/bin/php', 'artisan'],
            'environment_file' => '/home/orbit/Work Trees/docs/.env',
        ],
        'working_directory' => '/home/orbit/Work Trees/docs',
        'restart_policy' => 'never',
    ]);
    $process->id = 3;
    $target = new ProcessTarget(
        node: new Node(['name' => 'dev']),
        user: 'orbit',
        checkoutPath: '/home/orbit/Work Trees/docs',
    );

    expect(new SystemdProcessRenderer()->render($process, $target))
        ->toContain('WorkingDirectory=/home/orbit/Work\\x20Trees/docs')
        ->toContain('EnvironmentFile=-/home/orbit/Work\\x20Trees/docs/.env');
});

it('rejects a non-absolute executable from persisted runtime configuration', function (): void {
    $process = new Process([
        'name' => 'worker',
        'runtime_config' => [
            'command' => ['php', 'artisan'],
            'environment_file' => '/home/orbit/apps/docs/.env',
        ],
        'working_directory' => '/home/orbit/apps/docs',
        'restart_policy' => 'never',
    ]);
    $process->id = 4;
    $target = new ProcessTarget(
        node: new Node(['name' => 'dev']),
        user: 'orbit',
        checkoutPath: '/home/orbit/apps/docs',
    );

    expect(fn () => new SystemdProcessRenderer()->render($process, $target))
        ->toThrow(InvalidArgumentException::class, 'absolute executable');
});

it('pins the Route development-server origin after the app environment file', function (): void {
    $process = new Process([
        'name' => 'vite',
        'runtime_config' => [
            'command' => ['/usr/bin/npm', 'run', 'dev'],
            'environment_file' => '/tmp/.env',
        ],
        'working_directory' => '/tmp',
        'restart_policy' => 'never',
    ]);
    $process->id = 11;
    $target = new ProcessTarget(
        node: new Node(['name' => 'dev']),
        user: 'orbit',
        checkoutPath: '/tmp',
        certificateScope: 'app-instance-6',
        routeDomain: 'tasks.commander.test',
    );

    $unit = new SystemdProcessRenderer()->render(
        $process,
        $target,
        new ManagedUserAccount('orbit', 'orbit', '/home/orbit'),
    );

    expect($unit)
        ->toContain('Environment=ORBIT_DEV_SERVER_ORIGIN=https://tasks.commander.test/__orbit/vite')
        ->toContain('Environment=ORBIT_DEV_SERVER_HOST=tasks.commander.test')
        ->toContain('Environment=ORBIT_DEV_SERVER_PATH=/__orbit/vite')
        ->toContain('Environment=ORBIT_DEV_SERVER_PORT=5173')
        ->toContain(
            'Environment=VITE_DEV_SERVER_CERT=/home/orbit/.orbit/certificates/app-instance-6/current/cert.pem',
        )
        ->toContain(
            '"ORBIT_DEV_SERVER_ORIGIN=https://tasks.commander.test/__orbit/vite"',
        );
});

it('expands only the preset port and gives its owned environment file precedence', function (): void {
    $instance = new AppInstance(['vite_port' => 5210]);
    $instance->id = 64;
    $process = new Process(['name' => 'assets', 'runtime_config' => ['preset' => 'vp-dev', 'command' => ['/usr/local/bin/vp', 'dev'], 'environment_file' => '/apps/main/.env'], 'working_directory' => '/apps/main', 'restart_policy' => 'on-failure']);
    $process->id = 9;
    $target = new ProcessTarget(node: new Node(['name' => 'test']), user: 'orbit', checkoutPath: '/apps/main', appInstance: $instance, environmentFile: '/apps/main/.env', routeDomain: 'example.test');
    $unit = new SystemdProcessRenderer()->render($process, $target);
    expect($unit)->toContain('EnvironmentFile=/etc/orbit/vite/app-instance-64.env')->toContain('"--port=${ORBIT_DEV_SERVER_PORT}"')->toContain('"--strictPort"')->toContain('"--host=127.0.0.1"')->not->toContain('"ORBIT_DEV_SERVER_PORT=5210"');
    expect(strpos($unit, 'EnvironmentFile=-/apps/main/.env'))->toBeLessThan(strpos($unit, 'EnvironmentFile=/etc/orbit/vite/app-instance-64.env'));
});

it('projects AGENTATION_URL and expands the Agentation HTTP port', function (): void {
    $instance = new AppInstance(['agentation_port' => 4749]);
    $instance->id = 12;
    $process = new Process([
        'name' => 'agentation',
        'runtime_config' => [
            'preset' => 'agentation-mcp',
            'command' => ['/usr/local/bin/agentation-mcp', 'server'],
            'environment_file' => '/apps/commander/.env',
        ],
        'working_directory' => '/apps/commander',
        'restart_policy' => 'on-failure',
    ]);
    $process->id = 22;
    $target = new ProcessTarget(
        node: new Node(['name' => 'beast']),
        user: 'orbit',
        checkoutPath: '/apps/commander',
        appInstance: $instance,
        environmentFile: '/apps/commander/.env',
        routeDomain: 'commander.test',
    );

    $unit = new SystemdProcessRenderer()->render($process, $target);

    expect($unit)
        ->toContain('Environment=AGENTATION_URL=https://commander.test/__orbit/agentation')
        ->toContain('Environment=ORBIT_AGENTATION_PORT=4749')
        ->toContain('"/usr/local/bin/agentation-mcp"')
        ->toContain('"server"')
        ->toContain('"--port=${ORBIT_AGENTATION_PORT}"');
});

it('projects AGENTATION_URL onto the Antigravity watcher unit', function (): void {
    $instance = new AppInstance(['agentation_port' => 4747]);
    $process = new Process([
        'name' => 'watch',
        'runtime_config' => ['preset' => 'antigravity-watch', 'command' => ['/usr/local/bin/agy']],
        'working_directory' => '/apps/commander',
        'restart_policy' => 'always',
    ]);
    $process->id = 23;
    $target = new ProcessTarget(
        node: new Node(['name' => 'beast']),
        user: 'orbit',
        checkoutPath: '/apps/commander',
        appInstance: $instance,
        routeDomain: 'commander.test',
    );

    expect(new SystemdProcessRenderer()->render($process, $target))
        ->toContain('Environment=AGENTATION_URL=https://commander.test/__orbit/agentation')
        ->toContain('"/usr/local/bin/agy"')
        ->toContain('"--dangerously-skip-permissions"')
        ->toContain('"Call agentation_watch_annotations in a loop. For each annotation: acknowledge it, apply the requested UI/frontend change only (do not run Pest, artisan test, or other test commands), then resolve it with a summary. Continue watching until this process is stopped."')
        ->toContain('Restart=always');
});
