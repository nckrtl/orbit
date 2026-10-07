<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleInterrupted;
use App\Support\Console\PromptAborted;
use Laravel\Prompts\TextPrompt;
use Orbit\Sdk\Requests\GatewayReleases\DeployGatewayReleaseRequest;
use Orbit\Sdk\Responses\GatewayReleases\GatewayReleaseResponse;

final class DeployGatewayReleaseCommand extends GatewayReleaseFollowCommand
{
    #[\Override]
    protected $signature = 'gateway:release:deploy
        {commit? : Hex SHA of the commit to release, 7 to 40 characters}
        {--json : Return the final release record as JSON}';

    #[\Override]
    protected $description = 'Release one commit to the active Gateway and follow it until it is live, switched back, or paused.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $commit = $this->argument('commit');

        if ($commit === null && $this->consoleMode()->mayPrompt) {
            try {
                $commit = $this->commandPrompts()->run(fn (): TextPrompt => new TextPrompt(
                    'Commit',
                    placeholder: '0123456789ab',
                    required: true,
                    validate: self::commitError(...),
                ));
            } catch (PromptAborted|ConsoleInterrupted) {
                return $this->renderGatewayFailure('input.cancelled', 'No commit was selected.');
            }
        }

        if (! is_string($commit) || self::commitError($commit) !== null) {
            return $this->renderGatewayFailure(
                'gateway.release_commit_invalid',
                'Name the commit by its hex SHA (7 to 40 characters).',
                details: ['field' => 'commit'],
            );
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        return $this->followRelease(
            $connector,
            'Deploy Gateway release '.substr($commit, 0, 12),
            ['prepare', 'migrate', 'switch', 'handoff', 'verify', 'scheduler', 'web', 'smoke'],
            fn (): GatewayReleaseResponse => $this->sendOrThrow($connector, new DeployGatewayReleaseRequest($commit), GatewayReleaseResponse::class),
        );
    }

    private static function commitError(string $commit): ?string
    {
        return preg_match('/\A[0-9a-f]{7,40}\z/D', $commit) === 1 ? null : 'Enter a hex SHA of 7 to 40 lowercase characters.';
    }
}
