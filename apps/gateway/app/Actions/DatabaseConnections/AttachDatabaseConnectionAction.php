<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Data\DatabaseConnections\DatabaseConnectionAttachmentData;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentContextResolver;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentStore;
use App\Domain\DatabaseConnections\DatabaseConnectionEnvProjection;
use App\Domain\DatabaseConnections\DatabaseConnectionPrefix;
use App\Models\AppInstance;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

final readonly class AttachDatabaseConnectionAction
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
            $projected = $this->projection->project($connection, $instance, $normalizedPrefix);

            return DB::transaction(function () use (
                $instance,
                $connection,
                $normalizedPrefix,
                $context,
                $projected,
            ): DatabaseConnectionAttachmentData {
                DatabaseConnectionTarget::query()->updateOrCreate(
                    [
                        'app_instance_id' => $instance->id,
                        'prefix' => $normalizedPrefix,
                    ],
                    ['database_connection_id' => $connection->id],
                );

                $forgotten = $this->store->forget($context, $projected['forget'], 'attach');
                $written = $this->store->putMany($context, $projected['values'], 'attach');

                return new DatabaseConnectionAttachmentData(
                    appInstanceId: $instance->id,
                    slug: $connection->slug,
                    prefix: $normalizedPrefix,
                    keys: $projected['keys'],
                    host: $projected['host'],
                    port: $projected['port'],
                    operation: 'attach',
                    changed: $forgotten->changed || $written->changed,
                    keyCount: $written->keyCount,
                );
            });
        });
    }
}
