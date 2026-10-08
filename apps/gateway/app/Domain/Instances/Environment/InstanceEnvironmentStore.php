<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;

final readonly class InstanceEnvironmentStore
{
    public function __construct(
        private InstanceEnvironmentContextResolver $contexts,
        private InstanceEnvironmentValidator $validator,
    ) {}

    /** @param array<string, string> $imported */
    public function import(
        InstanceEnvironmentContext $expected,
        #[\SensitiveParameter]
        array $imported,
        bool $replace,
    ): InstanceEnvironmentResult {
        $result = DB::transaction(function () use ($expected, $imported, $replace): InstanceEnvironmentResult {
            $this->assertCurrent($expected, requireActiveNode: true);
            $stored = $this->storedValues($expected->instanceId);
            $conflicts = array_intersect_key($imported, $stored);

            if (! $replace && $conflicts !== []) {
                throw new ResourceOperationException(
                    errorCode: 'env.import_conflict',
                    message: 'The import contains keys that are already stored.',
                    status: 409,
                );
            }

            $candidate = [...$stored, ...$imported];
            $this->validator->validate($candidate);
            $changed = false;

            foreach ($imported as $key => $value) {
                if (($stored[$key] ?? null) === $value && array_key_exists($key, $stored)) {
                    continue;
                }

                InstanceEnvironmentValue::query()->updateOrCreate(
                    ['instance_id' => $expected->instanceId, 'env_key' => $key],
                    ['env_value' => $value],
                );
                $changed = true;
            }

            return new InstanceEnvironmentResult(
                $expected->instanceId,
                'import',
                $changed,
                count($candidate),
            );
        });

        return $result;
    }

    /**
     * @param  array<string, string>  $values
     */
    public function putMany(
        InstanceEnvironmentContext $expected,
        #[\SensitiveParameter]
        array $values,
        string $operation,
    ): InstanceEnvironmentResult {
        $result = DB::transaction(function () use ($expected, $values, $operation): InstanceEnvironmentResult {
            $this->assertCurrent($expected, requireActiveNode: false);
            $stored = $this->storedValues($expected->instanceId);
            $candidate = [...$stored, ...$values];
            $this->validator->validate($candidate);
            $changed = false;

            foreach ($values as $key => $value) {
                if (($stored[$key] ?? null) === $value && array_key_exists($key, $stored)) {
                    continue;
                }

                InstanceEnvironmentValue::query()->updateOrCreate(
                    ['instance_id' => $expected->instanceId, 'env_key' => $key],
                    ['env_value' => $value],
                );
                $changed = true;
            }

            return new InstanceEnvironmentResult(
                $expected->instanceId,
                $operation,
                $changed,
                count($candidate),
            );
        });

        return $result;
    }

    /**
     * @param  list<string>  $keys
     */
    public function forget(
        InstanceEnvironmentContext $expected,
        array $keys,
        string $operation,
    ): InstanceEnvironmentResult {
        $result = DB::transaction(function () use ($expected, $keys, $operation): InstanceEnvironmentResult {
            $this->assertCurrent($expected, requireActiveNode: false);
            $stored = $this->storedValues($expected->instanceId);
            $removed = array_values(array_intersect($keys, array_keys($stored)));

            if ($removed !== []) {
                InstanceEnvironmentValue::query()
                    ->where('instance_id', $expected->instanceId)
                    ->whereIn('env_key', $removed)
                    ->delete();
            }

            $remaining = array_diff_key($stored, array_flip($removed));
            $this->validator->validate($remaining);

            return new InstanceEnvironmentResult(
                $expected->instanceId,
                $operation,
                $removed !== [],
                count($remaining),
            );
        });

        return $result;
    }

    public function update(
        InstanceEnvironmentContext $expected,
        string $key,
        #[\SensitiveParameter]
        string $value,
    ): InstanceEnvironmentResult {
        $result = DB::transaction(function () use ($expected, $key, $value): InstanceEnvironmentResult {
            $this->assertCurrent($expected, requireActiveNode: false);
            $stored = $this->storedValues($expected->instanceId);
            $candidate = [...$stored, $key => $value];
            $this->validator->validate($candidate);
            $changed = ! array_key_exists($key, $stored) || $stored[$key] !== $value;

            if ($changed) {
                InstanceEnvironmentValue::query()->updateOrCreate(
                    ['instance_id' => $expected->instanceId, 'env_key' => $key],
                    ['env_value' => $value],
                );
            }

            return new InstanceEnvironmentResult(
                $expected->instanceId,
                'update',
                $changed,
                count($candidate),
            );
        });

        return $result;
    }

    public function synchronizationCapacity(InstanceEnvironmentContext $expected): int
    {
        $requiredCapacity = DB::transaction(function () use ($expected): int {
            $this->assertCurrent($expected, requireActiveNode: true);
            $rows = DB::table('instance_environment_values')
                ->where('instance_id', $expected->instanceId)
                ->orderBy('env_key')
                ->get(['env_key', 'env_value']);

            if ($rows->isEmpty()) {
                $this->configurationMissing();
            }

            $encryptedStorageBytes = 0;

            foreach ($rows as $row) {
                if (! is_string($row->env_key) || ! is_string($row->env_value)) {
                    $this->configurationUnreadable();
                }

                $encryptedStorageBytes += strlen($row->env_key) + strlen($row->env_value) + 4;
            }

            return InstanceEnvironmentValidator::MaximumFileBytes + $encryptedStorageBytes;
        });

        return $requiredCapacity;
    }

    public function synchronizationSnapshot(
        InstanceEnvironmentContext $expected,
    ): InstanceEnvironmentSynchronizationSnapshot {
        $snapshot = DB::transaction(function () use ($expected): InstanceEnvironmentSynchronizationSnapshot {
            $this->assertCurrent($expected, requireActiveNode: true);
            $values = $this->storedValues($expected->instanceId);

            if ($values === []) {
                $this->configurationMissing();
            }

            return new InstanceEnvironmentSynchronizationSnapshot($values);
        });

        return $snapshot;
    }

    public function copyForClone(
        InstanceEnvironmentContext $source,
        InstanceEnvironmentContext $target,
    ): void {
        DB::transaction(function () use ($source, $target): void {
            $this->assertCurrent($source, requireActiveNode: true);
            $this->assertCloneCurrent($target, requireActiveNode: true);
            $targetRows = InstanceEnvironmentValue::query()
                ->where('instance_id', $target->instanceId)
                ->lockForUpdate()
                ->exists();

            if ($targetRows) {
                return;
            }

            foreach ($this->storedValues($source->instanceId) as $key => $value) {
                InstanceEnvironmentValue::query()->create([
                    'instance_id' => $target->instanceId,
                    'env_key' => $key,
                    'env_value' => $value,
                ]);
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

        $mode = $target->project->type === ProjectType::SymfonyApp
            ? ['APP_ENV' => 'prod', 'APP_DEBUG' => '0']
            : ['APP_ENV' => 'production', 'APP_DEBUG' => 'false'];

        foreach ($mode as $key => $value) {
            InstanceEnvironmentValue::query()->updateOrCreate(
                ['instance_id' => $target->id, 'env_key' => $key],
                ['env_value' => $value],
            );
        }
    }

    public function cloneSynchronizationCapacity(InstanceEnvironmentContext $expected): int
    {
        $requiredCapacity = DB::transaction(function () use ($expected): int {
            $this->assertCloneCurrent($expected, requireActiveNode: true);
            $rows = DB::table('instance_environment_values')
                ->where('instance_id', $expected->instanceId)
                ->orderBy('env_key')
                ->get(['env_key', 'env_value']);
            $encryptedStorageBytes = 0;

            foreach ($rows as $row) {
                if (! is_string($row->env_key) || ! is_string($row->env_value)) {
                    $this->configurationUnreadable();
                }

                $encryptedStorageBytes += strlen($row->env_key) + strlen($row->env_value) + 4;
            }

            return InstanceEnvironmentValidator::MaximumFileBytes + $encryptedStorageBytes;
        });

        return $requiredCapacity;
    }

    public function cloneSynchronizationSnapshot(
        InstanceEnvironmentContext $expected,
    ): InstanceEnvironmentSynchronizationSnapshot {
        $snapshot = DB::transaction(function () use ($expected): InstanceEnvironmentSynchronizationSnapshot {
            $this->assertCloneCurrent($expected, requireActiveNode: true);

            return new InstanceEnvironmentSynchronizationSnapshot(
                $this->storedValues($expected->instanceId),
            );
        });

        return $snapshot;
    }

    private function assertCurrent(InstanceEnvironmentContext $expected, bool $requireActiveNode): void
    {
        $instance = Instance::query()->lockForUpdate()->find($expected->instanceId);

        if (! $instance instanceof Instance) {
            $this->conflict();
        }

        try {
            $current = $expected->routeDomainSource instanceof InstanceEnvironmentRouteDomain
                ? $this->contexts->resolveForRouteTransition(
                    $instance,
                    $expected->routeDomainSource,
                    $requireActiveNode,
                    lockRoute: true,
                )
                : $this->contexts->resolve($instance, $requireActiveNode, lockRoute: true);
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
    private function storedValues(int $instanceId): array
    {
        try {
            $values = InstanceEnvironmentValue::query()
                ->where('instance_id', $instanceId)
                ->lockForUpdate()
                ->orderBy('env_key')
                ->get()
                ->mapWithKeys(static fn (InstanceEnvironmentValue $row): array => [$row->env_key => $row->env_value])
                ->all();
        } catch (DecryptException) {
            $this->configurationUnreadable();
        }

        return $values;
    }

    private function conflict(): never
    {
        throw new ResourceOperationException(
            errorCode: 'env.owner_changed',
            message: 'The Instance environment owner changed during the operation.',
            status: 409,
        );
    }

    private function configurationMissing(): never
    {
        throw new ResourceOperationException(
            errorCode: 'env.configuration_missing',
            message: 'The Instance has no stored environment configuration.',
            status: 409,
        );
    }

    private function configurationUnreadable(): never
    {
        throw new ResourceOperationException(
            errorCode: 'env.configuration_unreadable',
            message: 'The stored Instance environment configuration cannot be read.',
            status: 409,
        );
    }
}
