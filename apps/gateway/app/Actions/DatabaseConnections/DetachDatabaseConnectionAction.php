<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Data\DatabaseConnections\DatabaseConnectionAttachmentData;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentContextResolver;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentStore;
use App\Domain\DatabaseConnections\DatabaseConnectionEnvProjection;
use App\Domain\DatabaseConnections\DatabaseConnectionPrefix;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

final readonly class DetachDatabaseConnectionAction
{
    public function __construct(
        private AppInstanceEnvironmentOperationLock $operations,
        private AppInstanceEnvironmentContextResolver $contexts,
        private AppInstanceEnvironmentStore $store,
        private DatabaseConnectionEnvProjection $projection,
    ) {}

    public function execute(
        AppInstance $instance,
        DatabaseConnection $connection,
        #[SensitiveParameter]
        ?string $prefix,
    ): DatabaseConnectionAttachmentData {
        $normalizedPrefix = DatabaseConnectionPrefix::normalize($prefix);

        return $this->operations->run([$instance->id], function () use (
            $instance,
            $connection,
            $normalizedPrefix,
        ): DatabaseConnectionAttachmentData {
            $context = $this->contexts->resolve($instance->refresh(), requireActiveNode: false);
            $keys = $this->projection->managedKeys($normalizedPrefix);

            return DB::transaction(function () use (
                $instance,
                $connection,
                $normalizedPrefix,
                $context,
                $keys,
            ): DatabaseConnectionAttachmentData {
                $target = DatabaseConnectionTarget::query()
                    ->where('app_instance_id', $instance->id)
                    ->where('database_connection_id', $connection->id)
                    ->where('prefix', $normalizedPrefix)
                    ->lockForUpdate()
                    ->first();

                if (! $target instanceof DatabaseConnectionTarget) {
                    throw new ResourceOperationException(
                        errorCode: 'database.attachment_missing',
                        message: "Database connection [{$connection->slug}] is not attached to this AppInstance with prefix [{$normalizedPrefix}].",
                        status: 404,
                    );
                }

                $target->delete();
                $forgotten = $this->store->forget($context, $keys, 'detach');

                return new DatabaseConnectionAttachmentData(
                    appInstanceId: $instance->id,
                    slug: $connection->slug,
                    prefix: $normalizedPrefix,
                    keys: $keys,
                    host: null,
                    port: null,
                    operation: 'detach',
                    changed: true,
                    keyCount: $forgotten->keyCount,
                );
            });
        });
    }
}
