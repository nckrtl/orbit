<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\Database\DatabaseAttachmentCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\DatabaseConnections\RemoveInstanceDatabaseRequest;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseConnectionAttachmentResponse;

final class RemoveInstanceDatabaseCommand extends DatabaseAttachmentCommand
{
    #[\Override]
    protected $signature = 'instance:database:remove
        {slug : Database connection slug}
        {--instance= : Positive Instance ID or exact Route domain}
        {--prefix= : Environment key prefix; defaults to DB}
        {--force : Skip the destructive confirmation prompt}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove a Database connection from an Instance and clear stored environment keys.';

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

        $effectivePrefix = $prefix ?? 'DB';

        if (! $this->confirmAction(
            "Remove Database connection [{$slug}] from Instance [{$instance}] and clear stored {$effectivePrefix}_* keys? Workload .env stays unchanged.",
            'Database connection removal cancelled.',
            option: 'force',
            requiredCode: 'database.confirmation_required',
            requiredMessage: 'Use --force to confirm Database connection removal from the Instance.',
        )) {
            return self::FAILURE;
        }

        $attachment = $this->sendWithProgress(
            $connector,
            new RemoveInstanceDatabaseRequest(
                appInstance: $instance,
                slug: $slug,
                prefix: $prefix,
            ),
            DatabaseConnectionAttachmentResponse::class,
            ['Remove Database connection', 'Removing Database connection', 'Removed Database connection'],
        );

        if (! $attachment instanceof DatabaseConnectionAttachmentResponse) {
            return self::FAILURE;
        }

        return $this->renderAttachment(
            $attachment,
            "Database connection [{$attachment->slug}] removed from Instance [{$attachment->appInstanceId}].",
        );
    }
}
