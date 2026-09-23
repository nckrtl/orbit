<?php

declare(strict_types=1);

use App\Infrastructure\AppDev\AppDevCaddyPublisher;
use Tests\Support\AppDevCaddyPublishHarness;
use Tests\Support\AppDevCaddyPublishScenario;

it('repairs 0700 hibernation ancestors so caddy can traverse markers and write logs', function (): void {
    $harness = new AppDevCaddyPublishHarness;
    $paths = hibernation_publish_paths($harness);

    try {
        $result = $harness->run(
            publisher: hibernation_path_publisher($harness, $paths),
            scenario: AppDevCaddyPublishScenario::packageDefault("package default\n", "package default\n"),
            beforePublish: function () use ($paths): void {
                mkdir($paths['marker_parent'], 0o700, true);
                chmod($paths['marker_parent'], 0o700);
                mkdir($paths['log_parent'], 0o700, true);
                chmod($paths['data_caddy'], 0o700);
                chmod($paths['log_parent'], 0o700);
            },
        );

        expect($result->exitCode)->toBe(0)
            ->and(file_get_contents($harness->rootPath().'/validate.log'))->toStartWith("user=caddy\n");
        expect_hibernation_directory_modes($paths);
    } finally {
        $harness->cleanup();
    }
});

it('creates missing hibernation ancestors as 0755 despite the publish umask', function (): void {
    $harness = new AppDevCaddyPublishHarness;
    $paths = hibernation_publish_paths($harness);

    try {
        $result = $harness->run(
            publisher: hibernation_path_publisher($harness, $paths),
            scenario: AppDevCaddyPublishScenario::packageDefault("package default\n", "package default\n"),
        );

        expect($result->exitCode)->toBe(0)
            ->and(file_get_contents($harness->rootPath().'/validate.log'))->toStartWith("user=caddy\n");
        expect_hibernation_directory_modes($paths);
    } finally {
        $harness->cleanup();
    }
});

/** @return array{marker: string, marker_parent: string, log: string, log_parent: string, data_caddy: string} */
function hibernation_publish_paths(AppDevCaddyPublishHarness $harness): array
{
    $marker = $harness->rootPath().'/dev/shm/orbit/hibernation';
    $log = $harness->rootPath().'/data/caddy/orbit/hibernation';

    return [
        'marker' => $marker,
        'marker_parent' => dirname($marker),
        'log' => $log,
        'log_parent' => dirname($log),
        'data_caddy' => dirname($log, 2),
    ];
}

/** @param array{marker: string, marker_parent: string, log: string, log_parent: string, data_caddy: string} $paths */
function hibernation_path_publisher(AppDevCaddyPublishHarness $harness, array $paths): AppDevCaddyPublisher
{
    return new AppDevCaddyPublisher(
        versionsDirectory: $harness->etcCaddyPath('orbit-versions'),
        liveCaddyfilePath: $harness->etcCaddyPath('Caddyfile'),
        caddyServiceName: 'caddy',
        lockPath: $harness->etcCaddyPath('orbit-locks/caddy.lock'),
        hibernationMarkerDirectory: $paths['marker'],
        hibernationAccessLogDirectory: $paths['log'],
    );
}

/** @param array{marker: string, marker_parent: string, log: string, log_parent: string, data_caddy: string} $paths */
function expect_hibernation_directory_modes(array $paths): void
{
    expect(fileperms($paths['marker_parent']) & 0o7777)->toBe(0o755)
        ->and(fileperms($paths['marker']) & 0o7777)->toBe(0o755)
        ->and(fileperms($paths['data_caddy']) & 0o7777)->toBe(0o755)
        ->and(fileperms($paths['log_parent']) & 0o7777)->toBe(0o755)
        ->and(fileperms($paths['log']) & 0o7777)->toBe(0o2775);
}
