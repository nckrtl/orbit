<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use App\Support\Console\DeploymentOutput;
use Orbit\Sdk\Requests\Deployments\ShowAppInstanceDeploymentRequest;
use Orbit\Sdk\Responses\Deployments\AppInstanceDeploymentEvent;
use Orbit\Sdk\Responses\Deployments\AppInstanceDeploymentResponse;

final class ShowDeploymentCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'instance:deployment:show
        {deployment : Numeric deployment ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show one recorded deployment, its phases and steps, and its log.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $deploymentId = $this->positiveId('deployment', 'Deployment', 'deployment.id_invalid');

        if ($deploymentId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $deployment = $this->sendWithProgress(
            $connector,
            new ShowAppInstanceDeploymentRequest($deploymentId),
            AppInstanceDeploymentResponse::class,
            ['Show deployment', 'Loading deployment', 'Loaded deployment'],
        );

        if (! $deployment instanceof AppInstanceDeploymentResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            ConsoleWriter::write($this->output, DeploymentOutput::encode($deployment->toArray())."\n");

            return self::SUCCESS;
        }

        $events = $deployment->events ?? [];

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("Deployment: {$deployment->id}", [
            'Instance ID' => $deployment->appInstanceId,
            'Release' => $deployment->release,
            'Branch' => $deployment->branch,
            'Commit' => $deployment->commit,
            'Started' => $deployment->startedAt,
            'Finished' => $deployment->finishedAt,
            'Duration' => $deployment->durationSeconds !== null ? "{$deployment->durationSeconds}s" : null,
            'Status' => $deployment->status,
            'Failed step' => $deployment->failedStep,
            'Error code' => $deployment->errorCode,
            'Selected release' => $deployment->selectedRelease,
            'Triggered by' => $deployment->triggeredBy,
            'Request ID' => $deployment->requestId,
        ]));

        $phases = array_values(array_filter($events, static fn (AppInstanceDeploymentEvent $event): bool => $event->type === 'phase'));

        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['Phase', 'Step'],
            array_map(
                static fn (AppInstanceDeploymentEvent $event): array => [
                    str_replace('_', ' ', (string) $event->phase),
                    $event->stepName ?? '—',
                ],
                $phases,
            ),
            'No recorded phases.',
        ));

        $this->writeLog($events);

        return self::SUCCESS;
    }

    /** @param list<AppInstanceDeploymentEvent> $events */
    private function writeLog(array $events): void
    {
        $hasOutput = false;

        foreach ($events as $event) {
            if ($event->type === 'output' && $event->value !== null) {
                $this->writeHumanMessage("{$event->stream}: ".DeploymentOutput::encode($event->value));
                $hasOutput = true;

                continue;
            }

            if ($event->type === 'output_truncated') {
                $this->writeHumanMessage('[output truncated]');
                $hasOutput = true;
            }
        }

        if (! $hasOutput) {
            $this->writeHumanMessage('No recorded log output.');
        }
    }
}
