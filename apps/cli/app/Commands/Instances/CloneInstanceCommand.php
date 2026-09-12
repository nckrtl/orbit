<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\AppInstances\CloneAppInstanceRequest;
use Orbit\Sdk\Requests\Deployments\ListAppInstanceReleasesRequest;
use Orbit\Sdk\Responses\AppInstances\AppInstanceResponse;
use Orbit\Sdk\Responses\Deployments\DeploymentReleasesResponse;

final class CloneInstanceCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'instance:clone
        {candidate : Numeric candidate AppInstance ID}
        {node : Numeric destination Node ID}
        {name : Target production AppInstance name}
        {--preview-name= : Required preview name to combine with the production Node TLD}
        {--branch= : Optional target branch; omission inherits the candidate branch}
        {--sqlite-source-path= : Optional SQLite database path on the candidate}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Clone a candidate into a prepared production AppInstance.';

    #[\Override]
    protected $help = <<<'HELP'
The candidate supplies source, stored environment values, and an optional SQLite snapshot. The App supplies production Process and Schedule definitions; candidate-specific overrides are not copied.

Provision the production Node with node:provision --tld before cloning. Clean application state on the target only. Use env:update and env:sync for target configuration, then configure and deploy explicitly with instance:deployment-config and instance:deploy.
HELP;

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $candidateId = $this->positiveId('candidate', 'Candidate', 'instance.candidate_id_invalid');

        if ($candidateId === null) {
            return self::FAILURE;
        }

        $nodeId = $this->positiveId('node', 'Node', 'node.id_invalid');

        if ($nodeId === null) {
            return self::FAILURE;
        }

        $name = $this->stringArgument('name', 'Instance name', 'instance.name_required');

        if ($name === null) {
            return self::FAILURE;
        }

        $previewName = $this->stringOption('preview-name');

        if ($previewName === null) {
            return $this->renderGatewayFailure(
                'instance.preview_name_required',
                'Preview name is required.',
            );
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $instance = $this->send(
            $connector,
            new CloneAppInstanceRequest(
                candidateId: $candidateId,
                nodeId: $nodeId,
                name: $name,
                previewName: $previewName,
                branch: $this->stringOption('branch'),
                sqliteSourcePath: $this->stringOption('sqlite-source-path'),
            ),
            AppInstanceResponse::class,
        );

        if (! $instance instanceof AppInstanceResponse) {
            return self::FAILURE;
        }

        if ($instance->route === null || $instance->route->hostname === '') {
            return $this->renderGatewayFailure(
                'gateway.invalid_response',
                'Gateway response is invalid.',
                $instance->requestId,
            );
        }

        $releases = $this->send(
            $connector,
            new ListAppInstanceReleasesRequest($instance->id),
            DeploymentReleasesResponse::class,
        );

        if (! $releases instanceof DeploymentReleasesResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson([
                'target_id' => $instance->id,
                'configured_branch' => $instance->selectedBranch,
                'preview_hostname' => $instance->route->hostname,
                'selected_release' => $releases->selectedRelease,
                'request_ids' => [
                    'clone' => $instance->requestId,
                    'releases' => $releases->requestId,
                ],
            ]);

            return self::SUCCESS;
        }

        $this->info("Production AppInstance [{$instance->name}] cloned.");
        $this->line("Target ID: {$instance->id}");
        $this->line('Configured branch: '.($instance->selectedBranch ?? '-'));
        $this->line("Preview hostname: {$instance->route->hostname}");
        $this->line('Selected release: '.($releases->selectedRelease ?? '-'));
        $this->line("Clone request ID: {$instance->requestId}");
        $this->line("Release request ID: {$releases->requestId}");

        return self::SUCCESS;
    }
}
