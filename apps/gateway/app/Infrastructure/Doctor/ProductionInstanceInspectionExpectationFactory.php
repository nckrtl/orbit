<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\InstanceEnvironmentRenderer;
use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Instances\ProductionPhpRuntimeConfigRenderer;
use App\Infrastructure\Metrics\ServiceMetricsProjection;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;

final readonly class ProductionInstanceInspectionExpectationFactory
{
    public function __construct(
        private InstanceEnvironmentContextResolver $contexts,
        private InstanceEnvironmentRenderer $environmentRenderer,
        private ProductionPhpRuntimeConfigRenderer $runtimeRenderer,
        private ?ServiceMetricsProjection $serviceMetrics = null,
        private ?NodeCaddyfileRenderer $builds = null,
    ) {}

    public function make(Instance $instance): ProductionInstanceInspectionExpectation
    {
        $instance->loadMissing(['project', 'node']);
        $context = $this->contexts->resolve($instance, requireActiveNode: false);
        $values = InstanceEnvironmentValue::query()
            ->where('instance_id', $instance->id)
            ->orderBy('env_key')
            ->get()
            ->mapWithKeys(static fn (InstanceEnvironmentValue $value): array => [
                $value->env_key => $value->env_value,
            ])
            ->all();
        $user = $instance->production_user;
        $home = $instance->production_home;
        $root = $instance->sourceRoot();

        if (! is_string($user) || ! is_string($home)) {
            throw new \InvalidArgumentException('The production inspection identity is incomplete.');
        }

        $runtime = null;
        $runtimeConfiguration = null;
        $initialRuntimeConfiguration = null;
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
            $metrics = $this->serviceMetrics?->enabled($instance->node) ?? false;
            $runtimeConfiguration = $this->runtimeRenderer->render($runtime, $metrics);
            $initialRuntimeConfiguration = $this->runtimeRenderer->render($runtime, $metrics, initialRelease: true);
        }

        return new ProductionInstanceInspectionExpectation(
            user: $user,
            home: $home,
            root: $root,
            applicationPath: $instance->applicationPath(),
            environment: $this->environmentRenderer->render($context, $values),
            caddySites: ($this->builds ?? app(NodeCaddyfileRenderer::class))
                ->render($instance->node)
                ->blocksFor("app-instance-{$instance->id}"),
            associationMatches: $associationMatches,
            runtime: $runtime,
            runtimeConfiguration: $runtimeConfiguration,
            initialRuntimeConfiguration: $initialRuntimeConfiguration,
        );
    }
}
