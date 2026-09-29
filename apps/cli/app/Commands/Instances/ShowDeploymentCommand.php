<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Deployments\ShowInstanceDeploymentRequest;
use Orbit\Sdk\Responses\Deployments\InstanceDeploymentEvent;
use Orbit\Sdk\Responses\Deployments\InstanceDeploymentResponse;

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
            new ShowInstanceDeploymentRequest($deploymentId),
            InstanceDeploymentResponse::class,
            ['Show deployment', 'Loading deployment', 'Loaded deployment'],
        );

        if (! $deployment instanceof InstanceDeploymentResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($deployment->toArray());

            return self::SUCCESS;
        }

        $events = $deployment->events ?? [];

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("Deployment: {$deployment->id}", [
            'Instance ID' => $deployment->instanceId,
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

        $phases = array_values(array_filter($events, static fn (InstanceDeploymentEvent $event): bool => $event->type === 'phase'));

        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['Phase', 'Step'],
            array_map(
                static fn (InstanceDeploymentEvent $event): array => [
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

    /** @param list<InstanceDeploymentEvent> $events */
    private function writeLog(array $events): void
    {
        $lines = [];

        foreach ($events as $event) {
            if ($event->type === 'output' && $event->value !== null) {
                $lines[] = "{$event->stream}: {$event->value}";

                continue;
            }

            if ($event->type === 'output_truncated') {
                $lines[] = '[output truncated]';
            }
        }

        if ($lines === []) {
            $this->writeHumanMessage('No recorded log output.');

            return;
        }

        ConsoleWriter::write($this->output, implode('', $lines));
    }
}
