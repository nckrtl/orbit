<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Data\DatabaseConnections\DatabaseConnectionAttachmentData;
use App\Domain\DatabaseConnections\DatabaseConnectionEnvProjection;
use App\Domain\DatabaseConnections\DatabaseConnectionPrefix;
use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Instance;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

final readonly class AttachDatabaseConnectionAction
{
    public function __construct(
        private InstanceEnvironmentOperationLock $operations,
        private InstanceEnvironmentContextResolver $contexts,
        private InstanceEnvironmentStore $store,
        private DatabaseConnectionEnvProjection $projection,
    ) {}

    public function execute(
        Instance $instance,
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
            $projected = $this->projection->project($connection, $normalizedPrefix);

            return DB::transaction(function () use (
                $instance,
                $connection,
                $normalizedPrefix,
                $context,
                $projected,
            ): DatabaseConnectionAttachmentData {
                DatabaseConnectionTarget::query()->updateOrCreate(
                    [
                        'instance_id' => $instance->id,
                        'prefix' => $normalizedPrefix,
                    ],
                    ['database_connection_id' => $connection->id],
                );

                $forgotten = $this->store->forget($context, $projected['forget'], 'attach');
                $written = $this->store->putMany($context, $projected['values'], 'attach');

                return new DatabaseConnectionAttachmentData(
                    instanceId: $instance->id,
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
