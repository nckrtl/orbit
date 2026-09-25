<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use Illuminate\Support\Facades\Route;

it('names the Node, build stage, and Caddy message in the error details of the caller error code', function (Closure $throw, string $code): void {
    Route::get('/api/v1/testing/caddy-build-failure', static fn () => $throw(new NodeCaddyBuildException('app-dev', 'validate', 'Error: loading certificates')));

    $this->getJson('/api/v1/testing/caddy-build-failure')
        ->assertJsonPath('error.code', $code)
        ->assertJsonPath('error.details.node', 'app-dev')
        ->assertJsonPath('error.details.stage', 'validate')
        ->assertJsonPath('error.details.message', 'Error: loading certificates');
})->with([
    'app-dev' => [static fn (NodeCaddyBuildException $build) => throw new RuntimeConvergenceException('caddy-config', 'app-dev.caddy_config_failed', $build->getMessage(), $build, $build->result()), 'app-dev.caddy_config_failed'],
    'app-prod role convergence' => [static fn (NodeCaddyBuildException $build) => throw new NodeRoleOperationException(
        'converge:app-prod-caddy-config',
        'node_role.convergence_failed',
        'app-prod.caddy_config_failed',
        $build->getMessage(),
        previous: new RuntimeConvergenceException('app-prod-caddy-config', 'app-prod.caddy_config_failed', $build->getMessage(), $build),
    ), 'node_role.convergence_failed'],
    'resource wrapper' => [static fn (NodeCaddyBuildException $build) => throw new ResourceOperationException('route.publication_failed', $build->getMessage(), 502, $build), 'route.publication_failed'],
]);
