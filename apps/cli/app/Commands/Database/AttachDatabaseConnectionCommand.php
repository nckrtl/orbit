<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\DatabaseConnections\AttachDatabaseConnectionRequest;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseConnectionAttachmentResponse;

final class AttachDatabaseConnectionCommand extends DatabaseAttachmentCommand
{
    #[\Override]
    protected $signature = 'database:attach
        {slug : Database connection slug}
        {--instance= : Positive AppInstance ID or exact Route hostname}
        {--prefix= : Environment key prefix; defaults to DB}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Attach a Database connection to an AppInstance and write stored environment keys.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $slug = $this->slug();
        $instance = $this->appInstanceSelector();
        $prefix = $this->prefixOption();

        if ($slug === null || $instance === null || ($this->input->getOption('prefix') !== null && $prefix === null)) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $attachment = $this->send(
            $connector,
            new AttachDatabaseConnectionRequest(
                appInstance: $instance,
                slug: $slug,
                prefix: $prefix,
            ),
            DatabaseConnectionAttachmentResponse::class,
        );

        if (! $attachment instanceof DatabaseConnectionAttachmentResponse) {
            return self::FAILURE;
        }

        return $this->renderAttachment(
            $attachment,
            "Database connection [{$attachment->slug}] attached to AppInstance [{$attachment->appInstanceId}].",
        );
    }
}
