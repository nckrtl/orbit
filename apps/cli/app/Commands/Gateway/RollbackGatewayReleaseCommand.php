<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleInterrupted;
use App\Support\Console\PromptAborted;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\GatewayReleases\ListGatewayReleasesRequest;
use Orbit\Sdk\Requests\GatewayReleases\RollbackGatewayReleaseRequest;
use Orbit\Sdk\Responses\GatewayReleases\GatewayReleaseResponse;
use Orbit\Sdk\Responses\GatewayReleases\GatewayReleasesResponse;

final class RollbackGatewayReleaseCommand extends GatewayReleaseFollowCommand
{
    #[\Override]
    protected $signature = 'gateway:release:rollback
        {release? : Release id, the first 12 hex digits of a retained release}
        {--force : Switch the code across a migration the target release does not have}
        {--yes : Confirm --force without a prompt}
        {--json : Return the final release record as JSON}';

    #[\Override]
    protected $description = 'Switch the active Gateway back to a retained release and follow it until it finishes.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $release = $this->argument('release');
        $connector = null;

        if ($release === null && $this->consoleMode()->mayPrompt) {
            $connector = $this->gatewayConnector($repository, $connectors);

            if ($connector === null) {
                return self::FAILURE;
            }

            $release = $this->selectRelease($connector);

            if ($release === null) {
                return self::FAILURE;
            }
        }

        if (! is_string($release) || preg_match('/\A[0-9a-f]{12}\z/D', $release) !== 1) {
            return $this->renderGatewayFailure(
                'gateway.release_id_invalid',
                'A release id is the first 12 hex digits of its commit.',
                details: ['field' => 'release'],
            );
        }

        $force = $this->option('force') === true;

        if ($force && ! $this->confirmAction(
            "Roll the Gateway back to release {$release} and leave the database schema of the current release in place?",
            'The rollback was not confirmed.',
        )) {
            return self::FAILURE;
        }

        $connector ??= $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        return $this->followRelease(
            $connector,
            'Roll back Gateway to release '.$release,
            ['switch', 'handoff', 'verify', 'scheduler', 'web', 'smoke'],
            fn (): GatewayReleaseResponse => $this->sendOrThrow($connector, new RollbackGatewayReleaseRequest($release, $force), GatewayReleaseResponse::class),
        );
    }

    /** Selects a release that verified before, newest first. */
    private function selectRelease(GatewayConnector $connector): ?string
    {
        try {
            $records = $this->spinnerDisplay()->during(
                'Loading releases',
                fn (): GatewayReleasesResponse => $this->sendOrThrow($connector, new ListGatewayReleasesRequest, GatewayReleasesResponse::class),
            );
        } catch (GatewayApiException $exception) {
            $this->renderApiFailure($exception);

            return null;
        }

        $rows = [];

        foreach ($records->releases as $record) {
            if ($record->release === null || $record->outcome !== 'verified' || isset($rows[$record->release])) {
                continue;
            }

            $rows[$record->release] = [$record->release, $record->trigger, $record->createdAt ?? '—'];
        }

        try {
            return (string) $this->commandPrompts()->selectEntity('Release', ['RELEASE', 'TRIGGER', 'VERIFIED'], $rows);
        } catch (PromptAborted|ConsoleInterrupted $exception) {
            $this->renderGatewayFailure(
                'input.cancelled',
                $exception instanceof PromptAborted && $exception->getMessage() !== '' ? $exception->getMessage() : 'No release was selected.',
            );

            return null;
        }
    }
}
