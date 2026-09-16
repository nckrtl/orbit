<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\Database\DatabaseAttachmentCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\DatabaseConnections\AddInstanceDatabaseRequest;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseConnectionAttachmentResponse;

final class AddInstanceDatabaseCommand extends DatabaseAttachmentCommand
{
    #[\Override]
    protected $signature = 'instance:database:add
        {slug : Database connection slug}
        {--instance= : Positive AppInstance ID or exact Route domain}
        {--prefix= : Environment key prefix; defaults to DB}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Add a Database connection on an AppInstance and write stored environment keys.';

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

        $attachment = $this->sendWithProgress(
            $connector,
            new AddInstanceDatabaseRequest(
                appInstance: $instance,
                slug: $slug,
                prefix: $prefix,
            ),
            DatabaseConnectionAttachmentResponse::class,
            ['Add Database connection', 'Adding Database connection', 'Added Database connection'],
        );

        if (! $attachment instanceof DatabaseConnectionAttachmentResponse) {
            return self::FAILURE;
        }

        return $this->renderAttachment(
            $attachment,
            "Database connection [{$attachment->slug}] added to AppInstance [{$attachment->appInstanceId}].",
        );
    }
}
