<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Environment;

use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\AppInstanceEnvironmentValue;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;

final readonly class AppInstanceEnvironmentStore
{
    public function __construct(
        private AppInstanceEnvironmentContextResolver $contexts,
        private AppInstanceEnvironmentValidator $validator,
    ) {}

    /** @param array<string, string> $imported */
    public function import(
        AppInstanceEnvironmentContext $expected,
        #[\SensitiveParameter]
        array $imported,
        bool $replace,
    ): AppInstanceEnvironmentResult {
        /** @var AppInstanceEnvironmentResult $result */
        $result = DB::transaction(function () use ($expected, $imported, $replace): AppInstanceEnvironmentResult {
            $this->assertCurrent($expected, requireActiveNode: true);
            $stored = $this->storedValues($expected->appInstanceId);
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

                AppInstanceEnvironmentValue::query()->updateOrCreate(
                    ['app_instance_id' => $expected->appInstanceId, 'env_key' => $key],
                    ['env_value' => $value],
                );
                $changed = true;
            }

            return new AppInstanceEnvironmentResult(
                $expected->appInstanceId,
                'import',
                $changed,
                count($candidate),
            );
        });

        return $result;
    }

    public function update(
        AppInstanceEnvironmentContext $expected,
        string $key,
        #[\SensitiveParameter]
        string $value,
    ): AppInstanceEnvironmentResult {
        /** @var AppInstanceEnvironmentResult $result */
        $result = DB::transaction(function () use ($expected, $key, $value): AppInstanceEnvironmentResult {
            $this->assertCurrent($expected, requireActiveNode: false);
            $stored = $this->storedValues($expected->appInstanceId);
            $candidate = [...$stored, $key => $value];
            $this->validator->validate($candidate);
            $changed = ! array_key_exists($key, $stored) || $stored[$key] !== $value;

            if ($changed) {
                AppInstanceEnvironmentValue::query()->updateOrCreate(
                    ['app_instance_id' => $expected->appInstanceId, 'env_key' => $key],
                    ['env_value' => $value],
                );
            }

            return new AppInstanceEnvironmentResult(
                $expected->appInstanceId,
                'update',
                $changed,
                count($candidate),
            );
        });

        return $result;
    }

    public function synchronizationCapacity(AppInstanceEnvironmentContext $expected): int
    {
        /** @var int $requiredCapacity */
        $requiredCapacity = DB::transaction(function () use ($expected): int {
            $this->assertCurrent($expected, requireActiveNode: true);
            $rows = DB::table('app_instance_environment_values')
                ->where('app_instance_id', $expected->appInstanceId)
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

            return AppInstanceEnvironmentValidator::MaximumFileBytes + $encryptedStorageBytes;
        });

        return $requiredCapacity;
    }

    public function synchronizationSnapshot(
        AppInstanceEnvironmentContext $expected,
    ): AppInstanceEnvironmentSynchronizationSnapshot {
        /** @var AppInstanceEnvironmentSynchronizationSnapshot $snapshot */
        $snapshot = DB::transaction(function () use ($expected): AppInstanceEnvironmentSynchronizationSnapshot {
            $this->assertCurrent($expected, requireActiveNode: true);
            $values = $this->storedValues($expected->appInstanceId);

            if ($values === []) {
                $this->configurationMissing();
            }

            return new AppInstanceEnvironmentSynchronizationSnapshot($values);
        });

        return $snapshot;
    }

    public function copyForClone(
        AppInstanceEnvironmentContext $source,
        AppInstanceEnvironmentContext $target,
    ): void {
        DB::transaction(function () use ($source, $target): void {
            $this->assertCurrent($source, requireActiveNode: true);
            $this->assertCloneCurrent($target, requireActiveNode: true);
            $targetRows = AppInstanceEnvironmentValue::query()
                ->where('app_instance_id', $target->appInstanceId)
                ->lockForUpdate()
                ->exists();

            if ($targetRows) {
                return;
            }

            foreach ($this->storedValues($source->appInstanceId) as $key => $value) {
                AppInstanceEnvironmentValue::query()->create([
                    'app_instance_id' => $target->appInstanceId,
                    'env_key' => $key,
                    'env_value' => $value,
                ]);
            }
        });
    }

    public function cloneSynchronizationCapacity(AppInstanceEnvironmentContext $expected): int
    {
        /** @var int $requiredCapacity */
        $requiredCapacity = DB::transaction(function () use ($expected): int {
            $this->assertCloneCurrent($expected, requireActiveNode: true);
            $rows = DB::table('app_instance_environment_values')
                ->where('app_instance_id', $expected->appInstanceId)
                ->orderBy('env_key')
                ->get(['env_key', 'env_value']);
            $encryptedStorageBytes = 0;

            foreach ($rows as $row) {
                if (! is_string($row->env_key) || ! is_string($row->env_value)) {
                    $this->configurationUnreadable();
                }

                $encryptedStorageBytes += strlen($row->env_key) + strlen($row->env_value) + 4;
            }

            return AppInstanceEnvironmentValidator::MaximumFileBytes + $encryptedStorageBytes;
        });

        return $requiredCapacity;
    }

    public function cloneSynchronizationSnapshot(
        AppInstanceEnvironmentContext $expected,
    ): AppInstanceEnvironmentSynchronizationSnapshot {
        /** @var AppInstanceEnvironmentSynchronizationSnapshot $snapshot */
        $snapshot = DB::transaction(function () use ($expected): AppInstanceEnvironmentSynchronizationSnapshot {
            $this->assertCloneCurrent($expected, requireActiveNode: true);

            return new AppInstanceEnvironmentSynchronizationSnapshot(
                $this->storedValues($expected->appInstanceId),
            );
        });

        return $snapshot;
    }

    private function assertCurrent(AppInstanceEnvironmentContext $expected, bool $requireActiveNode): void
    {
        $instance = AppInstance::query()->lockForUpdate()->find($expected->appInstanceId);

        if (! $instance instanceof AppInstance) {
            $this->conflict();
        }

        try {
            $current = $expected->routeHostnameSource instanceof AppInstanceEnvironmentRouteHostname
                ? $this->contexts->resolveForRouteTransition(
                    $instance,
                    $expected->routeHostnameSource,
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

    private function assertCloneCurrent(AppInstanceEnvironmentContext $expected, bool $requireActiveNode): void
    {
        $instance = AppInstance::query()->lockForUpdate()->find($expected->appInstanceId);

        if (! $instance instanceof AppInstance) {
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
    private function storedValues(int $appInstanceId): array
    {
        try {
            $values = AppInstanceEnvironmentValue::query()
                ->where('app_instance_id', $appInstanceId)
                ->lockForUpdate()
                ->orderBy('env_key')
                ->get()
                ->mapWithKeys(static fn (AppInstanceEnvironmentValue $row): array => [$row->env_key => $row->env_value])
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
            message: 'The AppInstance environment owner changed during the operation.',
            status: 409,
        );
    }

    private function configurationMissing(): never
    {
        throw new ResourceOperationException(
            errorCode: 'env.configuration_missing',
            message: 'The AppInstance has no stored environment configuration.',
            status: 409,
        );
    }

    private function configurationUnreadable(): never
    {
        throw new ResourceOperationException(
            errorCode: 'env.configuration_unreadable',
            message: 'The stored AppInstance environment configuration cannot be read.',
            status: 409,
        );
    }
}
