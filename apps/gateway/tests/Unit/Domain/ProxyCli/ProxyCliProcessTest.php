<?php

declare(strict_types=1);

use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTargetType;
use App\Domain\ProxyCli\ProxyCliProcess;
use App\Models\Node;

it('uses the absolute python interpreter and persists PROXYCLI environment', function (): void {
    $node = new Node(['name' => 'beast']);
    $node->id = 8;
    $environment = [
        'PROXYCLI_CLIPROXY_URL' => 'http://127.0.0.1:8317',
        'PROXYCLI_MANAGEMENT_KEY' => 'management-key',
        'PROXYCLI_READ_TOKEN' => 'read-token',
        'PROXYCLI_CONTROL_TOKEN' => 'control-token',
        'PROXYCLI_CACHE_HOST' => '10.44.0.8',
        'PROXYCLI_CACHE_PORT' => '6379',
        'PROXYCLI_CACHE_USERNAME' => '',
        'PROXYCLI_CACHE_PASSWORD' => '',
        'PROXYCLI_PORT' => '8787',
    ];

    $data = ProxyCliProcess::data($node, $environment);

    expect($data->targetType)->toBe(ProcessTargetType::Node)
        ->and($data->targetId)->toBe(8)
        ->and($data->name)->toBe('cli-proxy-api-collector')
        ->and($data->runtime)->toBe(ProcessRuntime::Systemd)
        ->and($data->command)->toBe([ProxyCliProcess::EXECUTABLE, '/var/lib/orbit/proxycli/server.py'])
        ->and($data->command[0])->toStartWith('/')
        ->and($data->workingDirectory)->toBe('/var/lib/orbit/proxycli')
        ->and($data->environment)->toBe($environment);
});
