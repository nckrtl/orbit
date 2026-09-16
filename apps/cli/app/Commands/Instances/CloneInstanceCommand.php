<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use App\Support\Console\ProgressState;
use App\Support\GatewayFailureRenderer;
use Orbit\Sdk\GatewayApiException;
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

Provision the production Node with node:add --tld before cloning. Clean application state on the target only. Use env:update and env:sync for target configuration, then configure deploy steps with instance:deploy-step:create and deploy with instance:deploy.
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

        $progress = $this->progressDisplay('Clone App instance');
        $progress->admit('clone', 'Clone source', 'Cloning source', 'Cloned source');
        $progress->admit('releases', 'Read selected release', 'Loading selected release', 'Loaded selected release');
        $instance = null;
        try {
            $instance = $progress->during('clone', function () use ($connector, $candidateId, $nodeId, $name, $previewName): AppInstanceResponse {
                $response = $this->sendOrThrow($connector, new CloneAppInstanceRequest(
                    candidateId: $candidateId,
                    nodeId: $nodeId,
                    name: $name,
                    previewName: $previewName,
                    branch: $this->stringOption('branch'),
                    sqliteSourcePath: $this->stringOption('sqlite-source-path'),
                ), AppInstanceResponse::class);
                if (! $response instanceof AppInstanceResponse || $response->route === null || $response->route->domain === '') {
                    throw new GatewayApiException('Gateway response is invalid.', 'gateway.invalid_response', requestId: $response instanceof AppInstanceResponse ? $response->requestId : null);
                }

                return $response;
            });
            $progress->complete('clone', ProgressState::Success);
            $releases = $progress->during('releases', fn (): object => $this->sendOrThrow(
                $connector, new ListAppInstanceReleasesRequest($instance->id), DeploymentReleasesResponse::class,
            ));
        } catch (GatewayApiException $exception) {
            if ($instance instanceof AppInstanceResponse) {
                $this->writeHumanMessage("App instance [{$instance->name}] (#{$instance->id}) was cloned; the selected release could not be read. Clone request ID: {$instance->requestId}");
            }

            return $this->renderGatewayFailure(
                $exception->errorCode() ?? 'gateway.request_failed', $exception->getMessage(), $exception->requestId(),
                details: GatewayFailureRenderer::safeDetails($exception->errorCode() ?? 'gateway.request_failed', $exception->details()),
            );
        }
        if (! $releases instanceof DeploymentReleasesResponse) {
            return self::FAILURE;
        }
        $progress->complete('releases', ProgressState::Success);
        $progress->finish('App instance cloned.');

        if ($this->option('json') === true) {
            $this->writeJson([
                'target_id' => $instance->id,
                'configured_branch' => $instance->selectedBranch,
                'preview_domain' => $instance->route->domain,
                'selected_release' => $releases->selectedRelease,
                'request_ids' => [
                    'clone' => $instance->requestId,
                    'releases' => $releases->requestId,
                ],
            ]);

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("App instance: {$instance->name}", [
            'Target ID' => $instance->id,
            'Configured branch' => $instance->selectedBranch,
            'Preview domain' => $instance->route->domain,
            'Selected release' => $releases->selectedRelease,
            'Clone request ID' => $instance->requestId,
            'Release request ID' => $releases->requestId,
        ]));

        return self::SUCCESS;
    }
}
