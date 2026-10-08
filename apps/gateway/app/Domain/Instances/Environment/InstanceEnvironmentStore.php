<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Domain\Instances\Apps\AppProjectionIdentity;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceAppProjectionStep;
use App\Models\InstanceEnvironmentValue;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;

final readonly class InstanceEnvironmentStore
{
    public function __construct(private InstanceEnvironmentContextResolver $contexts, private InstanceEnvironmentValidator $validator) {}

    /** @param array<string, string> $imported */
    public function import(InstanceEnvironmentContext $expected, #[\SensitiveParameter] array $imported, bool $replace): InstanceEnvironmentResult
    {
        return DB::transaction(function () use ($expected, $imported, $replace): InstanceEnvironmentResult {
            $this->assertCurrent($expected, true);
            $stored = $this->storedValues($expected);
            if (! $replace && array_intersect_key($imported, $stored) !== []) {
                throw new ResourceOperationException('env.import_conflict', 'The import contains keys that are already stored.', 409);
            }

            return $this->putValues($expected, $stored, $imported, 'import');
        });
    }

    /** @param array<string, string> $values */
    public function putMany(InstanceEnvironmentContext $expected, #[\SensitiveParameter] array $values, string $operation): InstanceEnvironmentResult
    {
        return DB::transaction(function () use ($expected, $values, $operation): InstanceEnvironmentResult {
            $this->assertCurrent($expected, false);

            return $this->putValues($expected, $this->storedValues($expected), $values, $operation);
        });
    }

    public function update(InstanceEnvironmentContext $expected, string $key, #[\SensitiveParameter] string $value): InstanceEnvironmentResult
    {
        return $this->putMany($expected, [$key => $value], 'update');
    }

    /** @param list<string> $keys */
    public function forget(InstanceEnvironmentContext $expected, array $keys, string $operation): InstanceEnvironmentResult
    {
        return DB::transaction(function () use ($expected, $keys, $operation): InstanceEnvironmentResult {
            $this->assertCurrent($expected, false);
            $stored = $this->storedValues($expected);
            $removed = array_values(array_intersect($keys, array_keys($stored)));
            if ($removed !== []) {
                InstanceEnvironmentValue::query()->where('instance_id', $expected->instanceId)->where('app', $expected->app)->whereIn('env_key', $removed)->delete();
            }
            $remaining = array_diff_key($stored, array_flip($removed));
            $this->validator->validate($remaining);

            return new InstanceEnvironmentResult($expected->instanceId, $operation, $removed !== [], count($remaining));
        });
    }

    public function synchronizationCapacity(InstanceEnvironmentContext $expected): int
    {
        return $this->capacity($expected, false);
    }

    public function cloneSynchronizationCapacity(InstanceEnvironmentContext $expected): int
    {
        return $this->capacity($expected, true);
    }

    public function synchronizationSnapshot(InstanceEnvironmentContext $expected): InstanceEnvironmentSynchronizationSnapshot
    {
        return DB::transaction(function () use ($expected): InstanceEnvironmentSynchronizationSnapshot {
            $this->assertCurrent($expected, true);
            $values = $this->storedValues($expected);
            if ($values === []) {
                $this->configurationMissing();
            }

            return new InstanceEnvironmentSynchronizationSnapshot($values);
        });
    }

    /** App projection permits an empty app, but still verifies its published owner before decryption. */
    public function projectionSnapshot(InstanceEnvironmentContext $published, InstanceAppProjectionStep $step): InstanceEnvironmentSynchronizationSnapshot
    {
        return DB::transaction(function () use ($published, $step): InstanceEnvironmentSynchronizationSnapshot {
            $recorded = InstanceAppProjectionStep::query()->lockForUpdate()->find($step->id);
            $instance = Instance::query()->lockForUpdate()->find($published->instanceId);
            if (! $recorded instanceof InstanceAppProjectionStep || ! $instance instanceof Instance
                || $recorded->instance_app_projection_id !== $step->instance_app_projection_id || $recorded->receipt_id !== $step->receipt_id
                || $recorded->plan_digest !== $step->plan_digest || AppProjectionIdentity::digest($recorded->intent) !== AppProjectionIdentity::digest($step->intent)
                || ($recorded->intent['phase'] ?? null) !== 'prepare' || ($recorded->intent['app'] ?? null) !== $published->app) {
                $this->conflict();
            }
            $current = $this->contexts->resolveForProjection($instance, $step->instance_app_projection_id, $published->app);
            if (! $published->samePlacement($current)) {
                $this->conflict();
            }

            return new InstanceEnvironmentSynchronizationSnapshot($this->storedValues($published));
        });
    }

    public function cloneSynchronizationSnapshot(InstanceEnvironmentContext $expected): InstanceEnvironmentSynchronizationSnapshot
    {
        return DB::transaction(function () use ($expected): InstanceEnvironmentSynchronizationSnapshot {
            $this->assertCloneCurrent($expected, true);

            return new InstanceEnvironmentSynchronizationSnapshot($this->storedValues($expected));
        });
    }

    public function copyForClone(InstanceEnvironmentContext $source, InstanceEnvironmentContext $target): void
    {
        DB::transaction(function () use ($source, $target): void {
            $this->assertCurrent($source, true);
            $this->assertCloneCurrent($target, true);
            if (InstanceEnvironmentValue::query()->where('instance_id', $target->instanceId)->where('app', $target->app)->lockForUpdate()->exists()) {
                return;
            }
            foreach ($this->storedValues($source) as $key => $value) {
                InstanceEnvironmentValue::query()->create(['instance_id' => $target->instanceId, 'app' => $target->app, 'env_key' => $key, 'env_value' => $value]);
            }
        });
    }

    /**
     * Pin production mode on an app-prod Instance. Symfony names its production environment `prod`.
     */
    public function forceAppProdMode(Instance $target): void
    {
        $target->loadMissing(['node.roles', 'project']);
        if (! $target->placedOnAppProd()) {
            return;
        }
        $configuration = $target->appConfiguration();
        $app = $configuration['name'];
        $mode = $configuration['type'] === ProjectType::SymfonyApp->value
            ? ['APP_ENV' => 'prod', 'APP_DEBUG' => '0']
            : ['APP_ENV' => 'production', 'APP_DEBUG' => 'false'];
        foreach ($mode as $key => $value) {
            InstanceEnvironmentValue::query()->updateOrCreate(['instance_id' => $target->id, 'app' => $app, 'env_key' => $key], ['env_value' => $value]);
        }
    }

    /** @param array<string, string> $stored
     * @param  array<string, string>  $values
     */
    private function putValues(InstanceEnvironmentContext $context, #[\SensitiveParameter] array $stored, #[\SensitiveParameter] array $values, string $operation): InstanceEnvironmentResult
    {
        $candidate = [...$stored, ...$values];
        $this->validator->validate($candidate);
        $changed = false;
        foreach ($values as $key => $value) {
            if (($stored[$key] ?? null) === $value && array_key_exists($key, $stored)) {
                continue;
            }
            InstanceEnvironmentValue::query()->updateOrCreate(['instance_id' => $context->instanceId, 'app' => $context->app, 'env_key' => $key], ['env_value' => $value]);
            $changed = true;
        }

        return new InstanceEnvironmentResult($context->instanceId, $operation, $changed, count($candidate));
    }

    private function capacity(InstanceEnvironmentContext $context, bool $clone): int
    {
        return DB::transaction(function () use ($context, $clone): int {
            if ($clone) {
                $this->assertCloneCurrent($context, true);
            } else {
                $this->assertCurrent($context, true);
            }
            $rows = DB::table('instance_environment_values')->where('instance_id', $context->instanceId)->where('app', $context->app)->orderBy('env_key')->get(['env_key', 'env_value']);
            if (! $clone && $rows->isEmpty()) {
                $this->configurationMissing();
            }
            $bytes = 0;
            foreach ($rows as $row) {
                if (! is_string($row->env_key) || ! is_string($row->env_value)) {
                    $this->configurationUnreadable();
                }
                $bytes += strlen($row->env_key) + strlen($row->env_value) + 4;
            }

            return InstanceEnvironmentValidator::MaximumFileBytes + $bytes;
        });
    }

    private function assertCurrent(InstanceEnvironmentContext $expected, bool $requireActiveNode): void
    {
        $instance = Instance::query()->lockForUpdate()->find($expected->instanceId);
        if (! $instance instanceof Instance) {
            $this->conflict();
        }
        try {
            $current = $expected->routeDomainSource instanceof InstanceEnvironmentRouteDomain
                ? $this->contexts->resolveForRouteTransition($instance, $expected->routeDomainSource, $requireActiveNode, lockRoute: true, app: $expected->app)
                : $this->contexts->resolve($instance, $requireActiveNode, lockRoute: true, app: $expected->app);
        } catch (ResourceOperationException) {
            $this->conflict();
        }
        if (! $expected->samePlacement($current)) {
            $this->conflict();
        }
    }

    private function assertCloneCurrent(InstanceEnvironmentContext $expected, bool $requireActiveNode): void
    {
        $instance = Instance::query()->lockForUpdate()->find($expected->instanceId);
        if (! $instance instanceof Instance) {
            $this->conflict();
        }
        try {
            $current = $this->contexts->resolveForClone($instance, $requireActiveNode, lockRoute: true);
        } catch (ResourceOperationException) {
            $this->conflict();
        }
        if (! $expected->samePlacement($current)) {
            $this->conflict();
        }
    }

    /** @return array<string, string> */
    private function storedValues(InstanceEnvironmentContext $context): array
    {
        try {
            return InstanceEnvironmentValue::query()->where('instance_id', $context->instanceId)->where('app', $context->app)->lockForUpdate()->orderBy('env_key')->get()->mapWithKeys(static fn (InstanceEnvironmentValue $row): array => [$row->env_key => $row->env_value])->all();
        } catch (DecryptException) {
            $this->configurationUnreadable();
        }
    }

    private function conflict(): never
    {
        throw new ResourceOperationException('env.owner_changed', 'The Instance environment owner changed during the operation.', 409);
    }

    private function configurationMissing(): never
    {
        throw new ResourceOperationException('env.configuration_missing', 'The Instance has no stored environment configuration.', 409);
    }

    private function configurationUnreadable(): never
    {
        throw new ResourceOperationException('env.configuration_unreadable', 'The stored Instance environment configuration cannot be read.', 409);
    }
}
