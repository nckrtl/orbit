<?php

declare(strict_types=1);

use App\Domain\AppInstances\Environment\AppInstanceEnvironmentContext;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentRenderer;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;

it('resolves each selected owner from one stored expression while retaining literals', function (): void {
    $renderer = new AppInstanceEnvironmentRenderer;
    $values = [
        'APP_ENV' => '{{app_instance.environment}}',
        'APP_KEY' => 'base64:literal-key',
        'APP_URL' => 'https://{{app_instance.hostname}}/path',
    ];

    $development = \Dotenv\Dotenv::parse($renderer->render(
        rendering_environment_context('development.example.test', 'development'),
        $values,
    ));
    $production = \Dotenv\Dotenv::parse($renderer->render(
        rendering_environment_context('production.example.test', 'production'),
        $values,
    ));

    expect($development)
        ->toBe([
            'APP_ENV' => 'development',
            'APP_KEY' => 'base64:literal-key',
            'APP_URL' => 'https://development.example.test/path',
        ])
        ->and($production)
        ->toBe([
            'APP_ENV' => 'production',
            'APP_KEY' => 'base64:literal-key',
            'APP_URL' => 'https://production.example.test/path',
        ]);
});

it('renders deterministic dotenv that round trips every accepted literal shape', function (): void {
    $values = [
        'Z_LAST' => ' trailing ',
        'A_EMPTY' => '',
        'D_DOLLAR' => '${NOT_A_REFERENCE} $VALUE literal',
        'C_QUOTES' => "single ' and double \"",
        'B_LINES' => "first\nsecond\rthird\tend",
        'E_SLASHES' => 'C:\\path\\to\\file',
    ];
    $renderer = new AppInstanceEnvironmentRenderer;
    $context = rendering_environment_context('literal.example.test', 'development');
    $first = $renderer->render($context, $values);
    $second = $renderer->render($context, array_reverse($values, true));

    expect($second)
        ->toBe($first)
        ->and(array_keys(\Dotenv\Dotenv::parse($first)))
        ->toBe(['A_EMPTY', 'B_LINES', 'C_QUOTES', 'D_DOLLAR', 'E_SLASHES', 'Z_LAST'])
        ->and(\Dotenv\Dotenv::parse($first))
        ->toBe([
            'A_EMPTY' => '',
            'B_LINES' => "first\nsecond\rthird\tend",
            'C_QUOTES' => "single ' and double \"",
            'D_DOLLAR' => '${NOT_A_REFERENCE} $VALUE literal',
            'E_SLASHES' => 'C:\\path\\to\\file',
            'Z_LAST' => ' trailing ',
        ]);
});

it('refuses an unavailable stored reference without returning rendered values', function (): void {
    $sentinel = 'secret-sentinel';

    try {
        new AppInstanceEnvironmentRenderer()->render(
            rendering_environment_context('example.test', 'development'),
            ['SECRET' => "{$sentinel}-{{app_instance.missing}}"],
        );
        $this->fail('The unavailable reference unexpectedly rendered.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)
            ->toBe('env.reference_unavailable')
            ->and($exception->getMessage())
            ->not->toContain($sentinel);
    }
});

function rendering_environment_context(string $hostname, string $environment): AppInstanceEnvironmentContext
{
    return new AppInstanceEnvironmentContext(
        appInstanceId: 1,
        appId: 1,
        nodeId: 1,
        environment: $environment,
        path: '/srv/orbit/example',
        executionUser: 'orbit',
        laravel: true,
        routeId: 1,
        routeHostname: $hostname,
        nodeStatus: 'active',
        node: new Node,
    );
}
