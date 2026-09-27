<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\AppInstances\Environment\AppInstanceEnvironmentContextResolver;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentRenderer;
use App\Domain\AppInstances\ProductionPhpRuntimeIdentity;
use App\Infrastructure\AppInstances\ProductionPhpRuntimeConfigRenderer;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Metrics\ServiceMetricsProjection;
use App\Models\AppInstance;
use App\Models\AppInstanceEnvironmentValue;

final readonly class ProductionInstanceInspectionExpectationFactory
{
    public function __construct(
        private AppInstanceEnvironmentContextResolver $contexts,
        private AppInstanceEnvironmentRenderer $environmentRenderer,
        private ProductionPhpRuntimeConfigRenderer $runtimeRenderer,
        private ?ServiceMetricsProjection $serviceMetrics = null,
        private ?NodeCaddyfileRenderer $builds = null,
    ) {}

    public function make(AppInstance $instance): ProductionInstanceInspectionExpectation
    {
        $instance->loadMissing(['app', 'node']);
        $context = $this->contexts->resolve($instance, requireActiveNode: false);
        $values = AppInstanceEnvironmentValue::query()
            ->where('app_instance_id', $instance->id)
            ->orderBy('env_key')
            ->get()
            ->mapWithKeys(static fn (AppInstanceEnvironmentValue $value): array => [
                $value->env_key => $value->env_value,
            ])
            ->all();
        $user = $instance->production_user;
        $home = $instance->production_home;
        $root = $instance->root ?? $instance->app->root;

        if (! is_string($user) || ! is_string($home) || ! is_string($root)) {
            throw new \InvalidArgumentException('The production inspection identity is incomplete.');
        }

        $runtime = null;
        $runtimeConfiguration = null;
        $associationMatches = ProductionPhpRuntimeIdentity::isAbsent($instance);

        if (! $associationMatches) {
            if (
                ! is_string($instance->production_php_service)
                || $instance->production_php_service === ''
                || ! is_string($instance->selected_php_version)
                || $instance->selected_php_version === ''
            ) {
                throw new \InvalidArgumentException(
                    'The production PHP Instance requires a dedicated PHP-FPM service and PHP version.',
                );
            }

            $runtime = ProductionPhpRuntimeIdentity::forProvisioning($instance, $instance->selected_php_version);
            $associationMatches =
                $instance->production_php_service === $runtime->service
                && $instance->production_php_pool === $runtime->pool
                && $instance->production_php_socket === $runtime->socket;
            $runtimeConfiguration = $this->runtimeRenderer->render(
                $runtime,
                $this->serviceMetrics?->enabled($instance->node) ?? false,
            );
        }

        return new ProductionInstanceInspectionExpectation(
            user: $user,
            home: $home,
            root: $root,
            environment: $this->environmentRenderer->render($context, $values),
            caddySites: ($this->builds ?? app(NodeCaddyfileRenderer::class))
                ->render($instance->node)
                ->blocksFor("app-instance-{$instance->id}"),
            associationMatches: $associationMatches,
            runtime: $runtime,
            runtimeConfiguration: $runtimeConfiguration,
        );
    }
}
