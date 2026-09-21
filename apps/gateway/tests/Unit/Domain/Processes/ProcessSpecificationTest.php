<?php

declare(strict_types=1);

use App\Data\Processes\AddProcessData;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessSpecification;
use App\Domain\Processes\ProcessTarget;
use App\Domain\Processes\ProcessTargetType;
use App\Models\Node;

function process_specification_data(array $environment): AddProcessData
{
    return new AddProcessData(
        targetType: ProcessTargetType::Node,
        targetId: 1,
        name: 'proxycli',
        runtime: ProcessRuntime::Systemd,
        command: ['/usr/bin/python3', '/var/lib/orbit/proxycli/server.py'],
        image: null,
        workingDirectory: '/var/lib/orbit/proxycli',
        environment: $environment,
        ports: ['127.0.0.1:8787:8787/tcp'],
        volumes: [],
        restartPolicy: 'unless-stopped',
        start: true,
    );
}

it('persists a systemd environment map in canonical key order', function (): void {
    $target = new ProcessTarget(
        node: new Node(['name' => 'beast']),
        user: 'orbit',
        checkoutPath: '/home/orbit',
        environmentFile: '',
    );

    $attributes = new ProcessSpecification()->attributes(
        process_specification_data([
            'PROXYCLI_READ_TOKEN' => 'read',
            'PROXYCLI_CACHE_HOST' => '10.44.0.8',
            'PROXYCLI_PORT' => '8787',
        ]),
        $target,
    );

    expect($attributes['runtime_config'])->toBe([
        'command' => ['/usr/bin/python3', '/var/lib/orbit/proxycli/server.py'],
        'environment_file' => '',
        'environment' => [
            'PROXYCLI_CACHE_HOST' => '10.44.0.8',
            'PROXYCLI_PORT' => '8787',
            'PROXYCLI_READ_TOKEN' => 'read',
        ],
    ]);
});

it('omits an empty systemd environment map so existing units stay identical', function (): void {
    $target = new ProcessTarget(
        node: new Node(['name' => 'beast']),
        user: 'orbit',
        checkoutPath: '/home/orbit',
        environmentFile: '',
    );

    $attributes = new ProcessSpecification()->attributes(process_specification_data([]), $target);

    expect($attributes['runtime_config'])->toBe([
        'command' => ['/usr/bin/python3', '/var/lib/orbit/proxycli/server.py'],
        'environment_file' => '',
    ]);
});
