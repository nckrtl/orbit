<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\AppInstances\Environment\AppInstanceEnvironmentContextResolver;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentRenderer;
use App\Domain\AppInstances\ProductionPhpRuntimeIdentity;
use App\Infrastructure\AppDev\AppDevCaddyConfigRenderer;
use App\Infrastructure\AppDev\AppDevSiteRepository;
use App\Infrastructure\AppInstances\ProductionPhpRuntimeConfigRenderer;
use App\Models\AppInstance;
use App\Models\AppInstanceEnvironmentValue;

final readonly class ProductionInstanceInspectionExpectationFactory
{
    public function __construct(
        private AppInstanceEnvironmentContextResolver $contexts,
        private AppInstanceEnvironmentRenderer $environmentRenderer,
        private AppDevSiteRepository $sites,
        private AppDevCaddyConfigRenderer $caddyRenderer,
        private ProductionPhpRuntimeConfigRenderer $runtimeRenderer,
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

        $runtime = $instance->selected_php_version === null
            ? null
            : ProductionPhpRuntimeIdentity::forProvisioning($instance, $instance->selected_php_version);
        if ($runtime instanceof ProductionPhpRuntimeIdentity) {
            $associationMatches =
                $instance->production_php_service === $runtime->service
                && $instance->production_php_pool === $runtime->pool
                && $instance->production_php_socket === $runtime->socket;
        } else {
            $associationMatches =
                $instance->production_php_service === null
                && $instance->production_php_pool === null
                && $instance->production_php_socket === null;
        }

        return new ProductionInstanceInspectionExpectation(
            user: $user,
            home: $home,
            root: $root,
            environment: $this->environmentRenderer->render($context, $values),
            caddy: $this->caddyRenderer->render($this->sites->forNode($instance->node)),
            associationMatches: $associationMatches,
            runtime: $runtime,
            runtimeConfiguration: $runtime instanceof ProductionPhpRuntimeIdentity
                ? $this->runtimeRenderer->render($runtime)
                : null,
        );
    }
}
