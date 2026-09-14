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
        {--instance= : Positive AppInstance ID or exact Route hostname}
        {--prefix= : Environment key prefix; defaults to DB}
        {--force : Skip the destructive confirmation prompt}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove a Database connection from an AppInstance and clear stored environment keys.';

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

        if (! $this->confirmed()) {
            return self::FAILURE;
        }

        $attachment = $this->send(
            $connector,
            new RemoveInstanceDatabaseRequest(
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
            "Database connection [{$attachment->slug}] removed from AppInstance [{$attachment->appInstanceId}].",
        );
    }

    private function confirmed(): bool
    {
        if ($this->option('force') === true) {
            return true;
        }

        if ($this->option('json') !== true && $this->input->isInteractive()) {
            return $this->confirm('Confirm Database connection removal from the AppInstance?', false);
        }

        $this->renderGatewayFailure(
            'database.confirmation_required',
            'Use --force to confirm Database connection removal from the AppInstance.',
        );

        return false;
    }
}
